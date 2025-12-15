<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_service_index_resource",
 *   label = @Translation("Georeport service index"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/services",
 *     "https://www.drupal.org/link-relations/create" = "/georeport/v2/services",
 *     "defaults"  = {"_format": "json"},
 *   }
 * )
 */
class GeoreportServiceIndexResource extends ResourceBase {
  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The markaspot_open311.settings config object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The Georeport Processor.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorService
   */
  protected $georeportProcessor;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config object.
   * @param \Drupal\markaspot_open311\Service\GeoreportProcessorService $georeport_processor
   *   The processor service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager,
    ConfigFactoryInterface $config,
    GeoreportProcessorService $georeport_processor,
    RequestStack $request_stack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->config = $config->getEditable('markaspot_open311.settings');
    $this->currentUser = $current_user;
    $this->georeportProcessor = $georeport_processor;
    $this->languageManager = $language_manager;
    $this->requestStack = $request_stack;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('markaspot_open311'),
      $container->get('current_user'),
      $container->get('language_manager'),
      $container->get('config.factory'),
      $container->get('markaspot_open311.processor'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = new RouteCollection();

    $definition = $this->getPluginDefinition();
    $canonical_path = $definition['uri_paths']['canonical'] ?? '/' . strtr($this->pluginId, ':', '/');
    $create_path = $definition['uri_paths']['https://www.drupal.org/link-relations/create'] ?? '/' . strtr($this->pluginId, ':', '/');
    $route_name = strtr($this->pluginId, ':', '.');

    $methods = $this->availableMethods();
    foreach ($methods as $method) {
      $route = $this->getBaseRoute($canonical_path, $method);
      switch ($method) {
        case 'POST':
          $georeport_formats = ['json', 'xml'];
          foreach ($georeport_formats as $format) {
            $format_route = clone $route;

            $format_route->setPath($create_path . '.' . $format);
            $format_route->setRequirement('_access_rest_csrf', 'FALSE');

            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.
            $format_route->addRequirements(
              [
                '_content_type_format' =>
                implode('|', $this->serializerFormats),
              ]);
            $collection->add("$route_name.$method.$format", $format_route);
          }
          break;

        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format) {
            $georeport_formats = ['json', 'xml'];
            foreach ($georeport_formats as $geo_format) {

              // Expose one route per available format.
              $format_route = clone $route;
              // Create path with format.name.
              $format_route->setPath($format_route->getPath() . '.' . $geo_format);
              $collection->add("$route_name.$method.$geo_format", $format_route);
            }

          }
          break;

        default:
          $collection->add("$route_name.$method", $route);
          break;
      }
    }
    return $collection;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBaseRoute($canonical_path, $method) {
    $lower_method = strtolower($method);
    $route = new Route($canonical_path, [
      '_controller' => 'Drupal\markaspot_open311\GeoreportRequestHandler::handle',
      // Pass the resource plugin ID along as default property.
      '_plugin' => $this->pluginId,
    ], [
      '_permission' => "restful $lower_method $this->pluginId",
    ],
      [],
      '',
      [],
      // The HTTP method is a requirement for this route.
      [$method]
    );
    return $route;
  }

  /**
   * Responds to GET services.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get() {
    $parameters = UrlHelper::filterQueryParameters($this->requestStack->getCurrentRequest()->query->all());

    // Get language code with priority: query param > Accept-Language header > site default.
    $langcode = $this->resolveLanguageCode($parameters);

    $services = $this->georeportProcessor->getTaxonomyTree('service_category', $langcode);

    if (!empty($services)) {
      return $services;
    }
    else {
      throw new HttpException(404, "Service code not found");
    }
  }

  /**
   * Resolves the language code from request parameters and headers.
   *
   * Priority order:
   * 1. Query parameter 'langcode' (for backwards compatibility and explicit override)
   * 2. Accept-Language HTTP header
   * 3. Site default language.
   *
   * @param array $parameters
   *   The query parameters from the request.
   *
   * @return string
   *   The resolved language code.
   */
  protected function resolveLanguageCode(array $parameters): string {
    $languages = $this->languageManager->getLanguages();
    $defaultLangcode = $this->languageManager->getDefaultLanguage()->getId();

    // Priority 1: Explicit query parameter (backwards compatibility).
    if (!empty($parameters['langcode'])) {
      $langcode = $parameters['langcode'];
      if (isset($languages[$langcode])) {
        return $langcode;
      }
      // Invalid langcode in query param - fall through to header.
    }

    // Priority 2: Accept-Language header.
    $request = $this->requestStack->getCurrentRequest();
    $acceptLanguage = $request->headers->get('Accept-Language');

    if ($acceptLanguage) {
      // Parse Accept-Language header to extract language codes.
      // Format: "de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7".
      $langcode = $this->parseAcceptLanguageHeader($acceptLanguage, $languages);
      if ($langcode) {
        return $langcode;
      }
    }

    // Priority 3: Site default language.
    return $defaultLangcode;
  }

  /**
   * Parses the Accept-Language header and returns the best matching language.
   *
   * @param string $acceptLanguage
   *   The Accept-Language header value.
   * @param array $availableLanguages
   *   Array of available language objects keyed by language code.
   *
   * @return string|null
   *   The best matching language code or null if no match found.
   */
  protected function parseAcceptLanguageHeader(string $acceptLanguage, array $availableLanguages): ?string {
    // Parse header into language-quality pairs.
    $languageRanges = [];
    $parts = explode(',', $acceptLanguage);

    foreach ($parts as $part) {
      $part = trim($part);
      if (empty($part)) {
        continue;
      }

      // Split on semicolon to separate language from quality factor.
      $segments = explode(';', $part);
      $lang = trim($segments[0]);
      $quality = 1.0;

      // Check for quality factor (q=0.x).
      if (isset($segments[1])) {
        $qPart = trim($segments[1]);
        if (preg_match('/^q=([0-9.]+)$/i', $qPart, $matches)) {
          $quality = (float) $matches[1];
        }
      }

      $languageRanges[$lang] = $quality;
    }

    // Sort by quality factor (highest first).
    arsort($languageRanges);

    // Find best match.
    foreach ($languageRanges as $lang => $quality) {
      // Try exact match first (e.g., "de" or "en").
      if (isset($availableLanguages[$lang])) {
        return $lang;
      }

      // Try base language from regional variant (e.g., "de" from "de-DE").
      $baseLang = strtok($lang, '-');
      if ($baseLang && isset($availableLanguages[$baseLang])) {
        return $baseLang;
      }
    }

    return NULL;
  }

}
