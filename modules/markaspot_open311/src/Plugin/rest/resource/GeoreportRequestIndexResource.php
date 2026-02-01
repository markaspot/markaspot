<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\SearchApiQueryService;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_request_index_resource",
 *   label = @Translation("Georeport requests index"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/requests",
 *     "https://www.drupal.org/link-relations/create" = "/georeport/v2/requests",
 *     "defaults"  = {"_format": "json"},
 *   }
 * )
 */
class GeoreportRequestIndexResource extends ResourceBase {

  use StringTranslationTrait;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The markaspot_open311.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The Georeport Processor.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorService
   */
  protected $georeportProcessor;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The Search API query service.
   *
   * @var \Drupal\markaspot_open311\Service\SearchApiQueryService
   */
  protected $searchApiQueryService;

  /**
   * The flood service for rate limiting.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * Rate limit: max requests per window for regular users.
   */
  protected const RATE_LIMIT_THRESHOLD = 60;

  /**
   * Rate limit window in seconds (1 minute).
   */
  protected const RATE_LIMIT_WINDOW = 60;

  /**
   * Roles exempt from rate limiting.
   *
   * Staff roles that need unrestricted API access for moderation.
   * Note: api_user is NOT exempt - we check session UID instead to
   * distinguish frontend app users from external API consumers.
   */
  protected const RATE_LIMIT_EXEMPT_ROLES = [
    'administrator',
    'moderator',
    'editorial_board',
    'api_editor',
    'api_municipality',
  ];

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config object.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\markaspot_open311\Service\GeoreportProcessorService $georeport_processor
   *   The processor service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\markaspot_open311\Service\SearchApiQueryService $search_api_query_service
   *   The Search API query service.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service for rate limiting.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config,
    TranslationInterface $string_translation,
    TimeInterface $time,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
    GeoreportProcessorService $georeport_processor,
    LanguageManagerInterface $language_manager,
    SearchApiQueryService $search_api_query_service,
    FloodInterface $flood,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->config = $config->get('markaspot_open311.settings');
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->georeportProcessor = $georeport_processor;
    $this->languageManager = $language_manager;
    $this->searchApiQueryService = $search_api_query_service;
    $this->flood = $flood;
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
      $container->get('config.factory'),
      $container->get('string_translation'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_open311.processor'),
      $container->get('language_manager'),
      $container->get('markaspot_open311.search_api_query'),
      $container->get('flood')
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
          $georeport_formats = ['json', 'xml', 'form'];
          foreach ($georeport_formats as $format) {
            $format_route = clone $route;

            $format_route->setPath($create_path . '.' . $format);
            $format_route->setRequirement('_csrf_request_header_token', 'FALSE');

            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.
            $format_route->addRequirements(
              [
                '_content_type_format'
                => implode('|', $this->serializerFormats),
              ]);
            $collection->add("$route_name.$method.$format", $format_route);
          }
          break;

        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format) {
            $georeport_formats = ['json', 'xml'];
            foreach ($georeport_formats as $format) {

              // Expose one route per available format.
              $format_route = clone $route;
              // Create path with format.name.
              $format_route->setPath($format_route->getPath() . '.' . $format);
              $collection->add("$route_name.$method.$format", $format_route);
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
   * Responds to GET requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * The 'q' search parameter respects field-level permissions using the
   * field_permissions module. Only fields the current user has 'view' access
   * to will be included in the search query. This prevents:
   * - Anonymous users from searching personal data fields (email, phone, names)
   * - Authenticated users from searching admin-only fields (internal notes)
   * - Unauthorized access to sensitive information through search results
   *
   * Search behavior:
   * - Base fields (title, body, request_id): Available to all users
   * - Public fields (field_address): Available to all users
   * - Custom permission fields: Only searched if user has 'view [field_name]' permission
   * - Private fields: Only searched by admins or entity owners
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get() {
    // Apply rate limiting to protect against DoS attacks.
    // Moderation, editorial, and admin users are exempt.
    $this->checkRateLimit('georeport_api_get');

    $request_time = $this->time->getRequestTime();

    // Get all query parameters first.
    $allParameters = $this->requestStack->getCurrentRequest()->query->all();

    // Preserve important API parameters before filtering.
    // UrlHelper::filterQueryParameters excludes 'q' by default (Drupal search),
    // but we need it for our Search API integration.
    $preservedParams = [];
    if (isset($allParameters['extensions'])) {
      $preservedParams['extensions'] = $allParameters['extensions'];
    }
    if (isset($allParameters['langcode'])) {
      $preservedParams['langcode'] = $allParameters['langcode'];
    }
    if (isset($allParameters['q'])) {
      $preservedParams['q'] = $allParameters['q'];
    }

    // Filter standard Drupal parameters (q, page, _format)
    $parameters = UrlHelper::filterQueryParameters($allParameters);

    // Restore preserved API parameters.
    $parameters = array_merge($parameters, $preservedParams);

    // Resolve language code from Accept-Language header or query param.
    $parameters['langcode'] = $this->resolveLanguageCode($parameters);

    // Start with the secure base query from the processor service.
    $query = $this->georeportProcessor->createNodeQuery($parameters, $this->currentUser);

    // Apply common filters.
    $bundle = $this->config->get('bundle') ?? 'service_request';
    $query->condition('changed', $request_time, '<')
      ->condition('type', $bundle);

    // Optimize query for common cases - direct ID lookup is fastest.
    if (isset($parameters['id'])) {
      $query->condition('request_id', $parameters['id']);
      return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters);
    }

    // Direct NID lookup is also fast.
    if (isset($parameters['nids'])) {
      $nids = explode(',', $parameters['nids']);
      $query->condition('nid', $nids, 'IN');
    }
    else {
      // Handle pagination parameters.
      $limit = isset($parameters['limit']) ? (int) $parameters['limit'] : 100;
      $offset = 0;

      // Support both 'page' (1-based) and 'offset' (0-based) parameters.
      if (isset($parameters['page']) && $parameters['page'] > 0) {
        $page = (int) $parameters['page'];
        $offset = ($page - 1) * $limit;
      }
      elseif (isset($parameters['offset']) && $parameters['offset'] >= 0) {
        $offset = (int) $parameters['offset'];
      }

      // Performance protection: require explicit limits for queries without date filters.
      if (!isset($parameters['start_date']) && !isset($parameters['updated'])) {
        $limit = min($limit, 100);
      }
      else {
        // Apply limit for date-filtered queries (can be larger since they're more specific)
        $limit = min($limit, 500);
      }

      $query->range($offset, $limit);

      // Handle explicit date range filters only.
      if (isset($parameters['start_date']) && $parameters['start_date'] != '') {
        $start_timestamp = strtotime($parameters['start_date']);
        if ($start_timestamp !== FALSE) {
          $query->condition('created', $start_timestamp, '>=');
        }
      }

      if (isset($parameters['end_date']) && $parameters['end_date'] != '') {
        $end_timestamp = strtotime($parameters['end_date']);
        if ($end_timestamp !== FALSE) {
          $query->condition('created', $end_timestamp, '<=');
        }
      }

      // -----------------------------------------------------------------------
      // SORTING (Mark-a-Spot Extension)
      // -----------------------------------------------------------------------
      // Note: The Open311 GeoReport v2 standard does not define a sort
      // parameter. This is a Mark-a-Spot extension for enhanced usability.
      //
      // RECOMMENDED: JSON:API style (use this for new implementations)
      //   sort=field      Ascending order
      //   sort=-field     Descending order (prefix with minus)
      //
      // Available sort fields:
      //   - created       Request creation date (default)
      //   - updated       Last modification date
      //   - status        Status field
      //   - service_code  Category/service type
      //   - request_id    String-based request ID (e.g., "47-2026")
      //   - nid           Numeric node ID (for proper numeric sorting)
      //
      // Examples:
      //   sort=-created   Newest first (default behavior)
      //   sort=created    Oldest first
      //   sort=-nid       Highest ID first (numeric)
      //   sort=nid        Lowest ID first (numeric)
      //
      // DEPRECATED (kept for backward compatibility):
      //   sort=DESC       Equivalent to sort=-created
      //   sort=ASC        Equivalent to sort=created
      //   These legacy values will continue to work but new clients should
      //   use the JSON:API style format above.
      // -----------------------------------------------------------------------
      $sortField = 'created';
      $sortDirection = 'ASC';

      if (isset($parameters['sort'])) {
        $sortParam = $parameters['sort'];

        // DEPRECATED: Legacy sort=DESC or sort=ASC (backward compatibility).
        // Maps to 'created' field only. Use JSON:API style for other fields.
        if (strcasecmp($sortParam, 'DESC') === 0) {
          $sortDirection = 'DESC';
        }
        elseif (strcasecmp($sortParam, 'ASC') === 0) {
          $sortDirection = 'ASC';
        }
        else {
          // JSON:API style: '-' prefix indicates descending order.
          if (str_starts_with($sortParam, '-')) {
            $sortDirection = 'DESC';
            $sortParam = substr($sortParam, 1);
          }
          else {
            $sortDirection = 'ASC';
          }

          // Map API sort field names to Drupal entity fields.
          // Note: 'nid' provides numeric sorting vs 'request_id' string sorting.
          $fieldMapping = [
            'created' => 'created',
            'updated' => 'changed',
            'status' => 'field_status',
            'service_code' => 'field_category',
            'request_id' => 'request_id',
            'nid' => 'nid',
          ];

          if (isset($fieldMapping[$sortParam])) {
            $sortField = $fieldMapping[$sortParam];
          }
        }
      }

      // Apply the updated filter if present (overrides sort for this use case).
      if (isset($parameters['updated'])) {
        $query->condition('changed', strtotime($parameters['updated']), '>=')
          ->sort('changed', 'DESC');
      }
      else {
        $query->sort($sortField, $sortDirection);
      }
    }

    // Handle custom field filters - move these after main filters.
    if (!empty($parameters)) {
      $fields = array_filter(
        $parameters,
        function ($key) {
          return(strpos($key, 'field_') !== FALSE);
        },
        ARRAY_FILTER_USE_KEY
      );
      foreach ($fields as $field => $value) {
        $query->condition($field, $value, '=');
      }
    }

    // Handle bounding box.
    if (isset($parameters['bbox'])) {
      $bbox = explode(',', $parameters['bbox']);
      $query->condition('field_geolocation.lat', $bbox[1], '>')
        ->condition('field_geolocation.lat', $bbox[3], '<')
        ->condition('field_geolocation.lng', $bbox[0], '>')
        ->condition('field_geolocation.lng', $bbox[2], '<');
    }

    // Handle search query using Search API for full-text search.
    // Falls back to basic LIKE search if Search API is not available.
    if (isset($parameters['q']) && strlen(trim($parameters['q'])) >= 2) {
      $searchQuery = trim($parameters['q']);
      $searchNids = [];

      // Try Search API first for better full-text search.
      if ($this->searchApiQueryService->isAvailable()) {
        $searchOptions = [
          'limit' => $limit ?? 100,
          'offset' => $offset ?? 0,
          'langcode' => $parameters['langcode'] ?? NULL,
        ];

        $searchNids = $this->searchApiQueryService->search(
          $searchQuery,
          $this->currentUser,
          $searchOptions
        );

        // If Search API returned results, restrict entity query to those nids.
        if (!empty($searchNids)) {
          $query->condition('nid', $searchNids, 'IN');
        }
        else {
          // Search API found no results - return empty.
          // Only fall back to LIKE search if Search API explicitly failed.
          $this->logger->debug('Search API returned no results for query: @query', [
            '@query' => $searchQuery,
          ]);
          // Return empty results when Search API finds nothing.
          return [];
        }
      }
      else {
        // Fall back to basic LIKE search if Search API is not available.
        $this->logger->notice('Search API not available, using LIKE fallback for query: @query', [
          '@query' => $searchQuery,
        ]);

        $group = $query->orConditionGroup()
          ->condition('request_id', '%' . $searchQuery . '%', 'LIKE')
          ->condition('title', '%' . $searchQuery . '%', 'LIKE');

        // Text search on body is expensive, only do if query > 3 chars.
        if (strlen($searchQuery) > 3) {
          $group->condition('body', '%' . $searchQuery . '%', 'LIKE');
        }

        // Search address field columns (publicly visible fields).
        $group->condition('field_address.address_line1', '%' . $searchQuery . '%', 'LIKE')
          ->condition('field_address.address_line2', '%' . $searchQuery . '%', 'LIKE')
          ->condition('field_address.locality', '%' . $searchQuery . '%', 'LIKE')
          ->condition('field_address.postal_code', '%' . $searchQuery . '%', 'LIKE');

        $query->condition($group);
      }
    }

    // Handle status filtering.
    if (isset($parameters['status'])) {
      $tids = $this->georeportProcessor->mapStatusToTaxonomyIds($parameters['status']);
      if (!empty($tids)) {
        $query->condition('field_status', $tids, 'IN');
      }
    }

    // Handle service code filtering.
    if (isset($parameters['service_code'])) {
      $service_codes = explode(',', $parameters['service_code']);
      if (count($service_codes) == 1) {
        // Single service code lookup is simpler.
        $tid = $this->georeportProcessor->mapServiceCodeToTaxonomy($service_codes[0]);
        $query->condition('field_category', $tid);
      }
      else {
        // Multiple codes need OR condition.
        $categoryTids = [];
        foreach ($service_codes as $service_code) {
          try {
            $tid = $this->georeportProcessor->mapServiceCodeToTaxonomy($service_code);
            $categoryTids[] = $tid;
          }
          catch (\Exception $e) {
            // Skip invalid service codes.
          }
        }
        if (!empty($categoryTids)) {
          $query->condition('field_category', $categoryTids, 'IN');
        }
      }
    }

    return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters);
  }

  /**
   * Responds to POST requests.
   *
   * Returns request id for created service_request.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($request_data) {
    // Apply stricter rate limiting for POST requests (creates new content).
    // Moderation, editorial, and admin users are exempt.
    $this->checkRateLimit('georeport_api_post');

    try {
      // Return result to handler for formatting and response.
      return $this->createNode($request_data);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }
  }

  /**
   * Create Node in Drupal from request data.
   *
   * @param array $request_data
   *   The request data as defined in open311 POST method.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If node has not been saved.
   */
  public function createNode(array $request_data) {
    $values = $this->georeportProcessor->prepareNodeProperties($request_data, 'create');
    $node = $this->entityTypeManager->getStorage('node')->create($values);

    // Make sure it's a content entity.
    if ($node instanceof ContentEntityInterface) {
      $validation = $this->validate($node);
      if ($validation === TRUE) {
        // Add an initial paragraph on valid post.
        $status_open = $this->config->get('status_open_start');
        // @todo put this in config.
        $status_note_initial = $this->t('The service request has been created.');

        $paragraph = Paragraph::create([
          'type' => 'status',
          'field_status_note' => [
            "value"  => $status_note_initial,
            "format" => "full_html",
          ],
          'field_status_term' => [
            "target_id"  => $status_open[0],
          ],
        ]);
        $paragraph->save();

        $node->field_status_notes = [
          [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ],
        ];
        if (isset($node->field_gdpr)) {
          $node->field_gdpr->value = 1;
        }

        // Set the referenced term field.
        $node->field_status = [
          [
            'target_id' => $status_open,
          ],
        ];

        $node->save();
        $this->logger->notice('Created entity %type with ID %request_id.', [
          '%type' => $node->getEntityTypeId(),
          '%request_id' => $node->request_id->value,
        ]);
        // Get the UUID to put it into the response.
        $request_id = $node->request_id->value;

        $service_request = [];
        if (isset($node)) {
          $service_request['service_requests']['request']['service_request_id'] = $request_id;
        }
        return $service_request;
      }
    }
  }

  /**
   * Verifies that the whole entity does not violate any validation constraints.
   *
   * @param object $node
   *   The node object.
   *
   * @return \http\Exception
   *   return exception.
   */
  protected function validate(object $node) {
    $violations = $node->validate();
    if (count($violations) > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $dotPosition = strpos($violation->getPropertyPath(), '.');

        $propertyPath = $dotPosition !== FALSE ? substr($violation->getPropertyPath(), $dotPosition + 1) : $violation->getPropertyPath();
        $messages[$propertyPath] = $violation->getMessage();
        $this->logger->error('Node validation error: @message', ['@message' => $violation->getMessage()]);

      }

      // Convert messages to a string or format that you want to show in the response.
      $detailedMessage = json_encode($messages);
      throw new GeoreportException($detailedMessage, 400);

    }
    else {
      return TRUE;
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

  /**
   * Checks if the current user is exempt from rate limiting.
   *
   * Exemptions:
   * 1. Staff roles (admin, moderator, editorial_board, api_editor, api_municipality)
   * 2. Authenticated frontend users with valid session (uid > 0)
   *
   * This allows distinguishing between:
   * - Nuxt frontend users (session-based) → exempt
   * - External API consumers (api_key only, no session) → rate limited
   *
   * @return bool
   *   TRUE if the user is exempt from rate limiting, FALSE otherwise.
   */
  protected function isExemptFromRateLimit(): bool {
    // Check if user has any exempt staff role.
    $userRoles = $this->currentUser->getRoles();
    foreach (self::RATE_LIMIT_EXEMPT_ROLES as $exemptRole) {
      if (in_array($exemptRole, $userRoles, TRUE)) {
        return TRUE;
      }
    }

    // Check for valid session (frontend app users).
    // Session UID > 0 means authenticated via passwordless login.
    $request = $this->requestStack->getCurrentRequest();
    if ($request->hasSession()) {
      $session = $request->getSession();
      $uid = $session->get('uid');
      if (!empty($uid) && $uid > 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks flood control and throws exception if rate limit exceeded.
   *
   * @param string $name
   *   The flood event name (e.g., 'georeport_api_get', 'georeport_api_search').
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   Throws 429 Too Many Requests if rate limit exceeded.
   */
  protected function checkRateLimit(string $name): void {
    // Skip rate limiting for exempt users (staff roles).
    if ($this->isExemptFromRateLimit()) {
      return;
    }

    // Get client identifier (IP for anonymous, user ID for authenticated).
    $identifier = $this->currentUser->isAnonymous()
      ? $this->requestStack->getCurrentRequest()->getClientIp()
      : (string) $this->currentUser->id();

    // Check if rate limit exceeded.
    if (!$this->flood->isAllowed($name, self::RATE_LIMIT_THRESHOLD, self::RATE_LIMIT_WINDOW, $identifier)) {
      $this->logger->warning('Rate limit exceeded for @name by @identifier', [
        '@name' => $name,
        '@identifier' => $identifier,
      ]);
      $exception = new GeoreportException('Too many requests. Please slow down.', 429);
      $exception->setHeaders(['Retry-After' => (string) self::RATE_LIMIT_WINDOW]);
      throw $exception;
    }

    // Register this request.
    $this->flood->register($name, self::RATE_LIMIT_WINDOW, $identifier);
  }

}
