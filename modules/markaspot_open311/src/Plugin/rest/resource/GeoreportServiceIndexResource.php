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
   * The language manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config object.
   * @param \Drupal\markaspot_open311\Service\GeoreportProcessorService $georeport_processor
   *   The processor service.
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
    RequestStack $request_stack
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

    // Get the language code from the query parameters, default to 'en' if not provided
    $langcode = $parameters['langcode'] ?? 'en';

    // Validate the language code
    $languages = $this->languageManager->getLanguages();
    if (!isset($languages[$langcode])) {
      throw new HttpException(400, "Invalid language code provided");
    }

    $services = $this->georeportProcessor->getTaxonomyTree('service_category', $langcode);

    if (!empty($services)) {
      return $services;
    } else {
      throw new HttpException(404, "Service code not found");
    }
  }

}
