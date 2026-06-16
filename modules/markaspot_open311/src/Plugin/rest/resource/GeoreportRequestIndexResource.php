<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\SearchApiQueryService;
use Drupal\markaspot_open311\Traits\LanguageNegotiationTrait;
use Drupal\markaspot_validation\Service\BoundaryValidator;
use Drupal\user\Entity\Role;

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
final class GeoreportRequestIndexResource extends ResourceBase {

  use StringTranslationTrait;
  use LanguageNegotiationTrait;
  use \Drupal\markaspot_open311\RateLimit\Open311RateLimitTrait;

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
   * The services_api_key_auth.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $apiKeyAuthConfig;

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
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected $hierarchyResolver;

  /**
   * The flood service for rate limiting.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The workspace visibility service (optional, from markaspot_fastmap).
   *
   * @var object|null
   */
  protected $workspaceVisibility;

  /**
   * The jurisdiction scope validator.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionScopeValidator
   */
  protected $jurisdictionScopeValidator;

  /**
   * The boundary validator.
   *
   * @var \Drupal\markaspot_validation\Service\BoundaryValidator
   */
  protected $boundaryValidator;

  /**
   * Maximum Search API candidate IDs loaded before EntityQuery pagination.
   */
  protected const SEARCH_API_CANDIDATE_LIMIT = 10000;

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
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null $hierarchy_resolver
   *   The jurisdiction hierarchy resolver.
   * @param object|null $workspace_visibility
   *   The workspace visibility service (optional).
   * @param \Drupal\markaspot_group\Service\JurisdictionScopeValidator $jurisdiction_scope_validator
   *   The jurisdiction scope validator.
   * @param \Drupal\markaspot_validation\Service\BoundaryValidator $boundary_validator
   *   The boundary validator.
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
    ?JurisdictionHierarchyResolverInterface $hierarchy_resolver = NULL,
    ?object $workspace_visibility = NULL,
    ?JurisdictionScopeValidator $jurisdiction_scope_validator = NULL,
    ?BoundaryValidator $boundary_validator = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->config = $config->get('markaspot_open311.settings');
    $this->apiKeyAuthConfig = $config->get('services_api_key_auth.settings');
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->georeportProcessor = $georeport_processor;
    $this->languageManager = $language_manager;
    $this->searchApiQueryService = $search_api_query_service;
    $this->flood = $flood;
    $this->hierarchyResolver = $hierarchy_resolver;
    $this->workspaceVisibility = $workspace_visibility;
    $this->jurisdictionScopeValidator = $jurisdiction_scope_validator;
    $this->boundaryValidator = $boundary_validator;
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
      $container->get('flood'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->has('markaspot_fastmap.workspace_visibility') ? $container->get('markaspot_fastmap.workspace_visibility') : NULL,
      $container->get('markaspot_group.jurisdiction_scope_validator'),
      $container->get('markaspot_validation.boundary_validator')
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
   * - Custom permission fields: Only searched with 'view [field_name]'.
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

    // Internal markers set further down; never accept them from the wire.
    unset(
      $parameters['_jurisdiction_read_scope'],
      $parameters['_request_list_sort'],
      $parameters['_request_list_pagination'],
      $parameters['_request_list_total']
    );

    // Resolve language code from Accept-Language header or query param.
    $parameters['langcode'] = $this->resolveLanguageCode($parameters);

    // Start with the secure base query from the processor service.
    $query = $this->georeportProcessor->createNodeQuery($parameters, $this->currentUser);

    // Jurisdiction isolation for dashboard-level users (tenant admins,
    // moderators, editorial) reading foreign jurisdictions follows a
    // degrade-don't-deny model (markaspot-ui#427): membership in the
    // requested jurisdiction is no longer a hard 403 gate for list reads.
    // Instead the resolved jurisdiction is handed to getResults() as the
    // serialization read scope, where non-members holding 'access open311
    // advanced properties' are downgraded to the anonymous/public response
    // shape. Which nodes are readable at all keeps being governed by Group
    // module's query-level access grants (jur-outsider = published-only).
    // Writes keep strict membership enforcement via
    // validateJurisdictionAccess() in GeoreportRequestResource.
    $resolvedJurisdictionId = $this->georeportProcessor->resolveJurisdictionId($parameters);
    $this->applyInvalidJurisdictionClaimScope($query, $parameters, $resolvedJurisdictionId);
    $anonymousJurisdictionClaimIsUnreadable = $this->anonymousJurisdictionClaimIsUnreadable($parameters, $resolvedJurisdictionId);
    $readScope = NULL;
    if ($anonymousJurisdictionClaimIsUnreadable) {
      $query->condition('nid', [0], 'IN');
    }
    else {
      $this->applyApiKeyJurisdictionReadScope($query, $parameters, $resolvedJurisdictionId);
      if ($resolvedJurisdictionId) {
        $readScope = $resolvedJurisdictionId;
      }
    }

    $this->applyAnonymousWorkspaceReadScope($query);

    // Apply common filters.
    $bundle = $this->config->get('bundle') ?? 'service_request';
    $query->condition('changed', $request_time, '<')
      ->condition('type', $bundle);

    if ($anonymousJurisdictionClaimIsUnreadable) {
      return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters, $readScope);
    }

    // Optimize query for common cases - direct ID lookup is fastest.
    if (isset($parameters['id'])) {
      $query->condition('request_id', $parameters['id']);
      return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters, $readScope);
    }

    // Direct NID lookup is also fast.
    if (isset($parameters['nids'])) {
      $nids = explode(',', $parameters['nids']);
      $query->condition('nid', $nids, 'IN');
    }
    else {
      $sort = $this->georeportProcessor->normalizeRequestListSort($parameters);
      $pagination = $this->georeportProcessor->normalizeRequestListPagination($parameters, $sort);
      $parameters['_request_list_sort'] = $sort;
      $parameters['_request_list_pagination'] = $pagination;

      $limit = $pagination['limit'];
      $offset = $pagination['offset'];
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

      // Apply the updated filter if present (overrides sort for this use case).
      if (isset($parameters['updated'])) {
        $query->condition('changed', strtotime($parameters['updated']), '>=');
      }
    }

    // Handle custom field filters.
    //
    // The endpoint is reachable anonymously, so the previous substring
    // match `str_contains($key, 'field_')` let any caller probe entity
    // schema by passing arbitrary `field_*` query parameters: invalid
    // names surface as 500s and valid ones run as `WHERE field_* = …`.
    // Both behaviours are useful to an attacker mapping field structure.
    // The allowlist below mirrors the intentionally filterable surface
    // and is enforced via the named helper so the unit test can pin it.
    if (!empty($parameters)) {
      $fields = self::filterAllowedFieldParameters($parameters);
      foreach ($fields as $field => $value) {
        // filterAllowedFieldParameters() emits an array when the caller sent
        // a comma-separated list (multi-select filter) and a scalar when the
        // caller sent a single value. Mirror that into the entity query: IN
        // for arrays, = for scalars. The values themselves are parameterised
        // by PDO either way, so SQL injection is not in scope on the value
        // side.
        if (is_array($value)) {
          $query->condition($field, $value, 'IN');
        }
        else {
          $query->condition($field, $value, '=');
        }
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
    if (isset($parameters['q']) && strlen(trim($parameters['q'])) >= 2) {
      $searchQuery = trim($parameters['q']);
      $searchNids = [];

      // Try Search API first for better full-text search.
      if ($this->searchApiQueryService->isAvailable()) {
        // Search API is only used to produce a text-match candidate set.
        // EntityQuery applies structured filters, sorting, count, and the
        // public offset range exactly once below. Passing the request offset
        // into both layers would skip page 2+ results.
        $searchOptions = [
          'limit' => self::SEARCH_API_CANDIDATE_LIMIT,
          'offset' => 0,
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
        elseif ($this->searchApiQueryService->didLastSearchFail()) {
          if ($this->searchApiQueryService->applySafeFallbackSearch($query, $searchQuery)) {
            $this->logger->notice('Search API failed, using request_id fallback for query: @query', [
              '@query' => $searchQuery,
            ]);
          }
          else {
            $this->logger->debug('Search API failed; full-text query skipped to avoid unbounded LIKE fallback.');
            $query->condition('nid', [0], 'IN');
          }
        }
        else {
          // Search API found no results - return empty.
          $this->logger->debug('Search API returned no results for query: @query', [
            '@query' => $searchQuery,
          ]);
          // Return empty results when Search API finds nothing.
          return [];
        }
      }
      else {
        // Search API is the required full-text backend. Without it, only exact
        // request_id lookup is allowed; never fall back to broad
        // leading-wildcard LIKE scans over title, body, or address fields.
        if ($this->searchApiQueryService->applySafeFallbackSearch($query, $searchQuery)) {
          $this->logger->notice('Search API not available, using request_id fallback for query: @query', [
            '@query' => $searchQuery,
          ]);
        }
        else {
          $this->logger->debug('Search API not available; full-text query skipped to avoid unbounded LIKE fallback.');
          $query->condition('nid', [0], 'IN');
        }
      }
    }

    // Get jurisdiction ID for downstream status/service_code filtering.
    // Reuse the already-resolved ID from the access check above (supports
    // slugs, numeric IDs, and deprecated aliases).
    $jurisdictionId = $resolvedJurisdictionId;

    // Jurisdiction node filtering is already handled in createNodeQuery()
    // via resolveJurisdictionId() + getNodeIdsInJurisdiction(), which correctly
    // resolves both root and child jurisdictions through group membership.
    // Handle status filtering (jurisdiction-aware).
    if (isset($parameters['status'])) {
      $tids = $this->georeportProcessor->mapStatusToTaxonomyIds($parameters['status'], $jurisdictionId);
      if (!empty($tids)) {
        $query->condition('field_status', $tids, 'IN');
      }
    }
    if (isset($parameters['service_code'])) {
      $service_codes = explode(',', $parameters['service_code']);
      if (count($service_codes) == 1) {
        // Single service code lookup is simpler.
        $tid = $this->georeportProcessor->mapServiceCodeToTaxonomy($service_codes[0], $jurisdictionId);
        $query->condition('field_category', $tid);
      }
      else {
        // Multiple codes need OR condition.
        $categoryTids = [];
        foreach ($service_codes as $service_code) {
          try {
            $tid = $this->georeportProcessor->mapServiceCodeToTaxonomy($service_code, $jurisdictionId);
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

    // Apply keyset pagination after all filters, and preserve meta.total as
    // the full filtered result count instead of the remaining cursor window.
    if (!empty($parameters['_request_list_sort']) && !empty($parameters['_request_list_pagination'])) {
      $pagination = $parameters['_request_list_pagination'];
      $includeMetadata = !empty($parameters['meta']) &&
        (strtolower((string) $parameters['meta']) === 'true' || $parameters['meta'] === '1');
      if ($includeMetadata && ($pagination['cursor'] ?? NULL) !== NULL) {
        $countQuery = clone $query;
        $countQuery->range(NULL, NULL);
        $parameters['_request_list_total'] = (int) $countQuery->count()->execute();
      }

      $this->georeportProcessor->applyRequestListCursor(
        $query,
        $pagination['cursor'],
        $parameters['_request_list_sort']
      );
      $this->georeportProcessor->applyRequestListSort($query, $parameters['_request_list_sort']);
    }

    return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters, $readScope);
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
    // Stricter, create-specific rate limiting for report creation (#474,
    // anti-bombing defense-in-depth). Staff roles are exempt; configurable
    // via rate_limit.create_threshold.
    $this->checkRateLimit('georeport_api_create');

    try {
      $claimedJurisdictionId = $this->resolveClaimedJurisdictionId($request_data);
      $jurisdictionId = $this->jurisdictionScopeValidator
        ->resolveSubmissionJurisdiction($claimedJurisdictionId, $this->currentUser);

      // Canonicalize deprecated aliases and implicit single-scope requests
      // before the processor maps category, status, and group relationships.
      $request_data['jurisdiction_id'] = $jurisdictionId;

      // Block check covers both the claimed jurisdiction AND any boundary-
      // resolved child jurisdiction (including ancestors of the deepest
      // match). Without the boundary fan-out, a bot can claim a public parent
      // workspace while the coordinates fall into a blocked child —
      // node_presave would still throw, but the response would surface as a
      // 502 via the generic \Throwable trap, hiding the real reason.
      if ($jurisdictionId && $this->workspaceVisibility) {
        $coordinates = $this->getRequestCoordinates($request_data);
        if ($this->workspaceVisibility->isBlockedForSubmission(
            $jurisdictionId,
            $coordinates[0] ?? NULL,
            $coordinates[1] ?? NULL,
          )
        ) {
          // Detection signal: blocked submission attempts must be visible to
          // operators investigating spam bots. Flood-gated to one watchdog
          // entry per (workspace, IP) per 60s, otherwise a sustained bot at
          // 1000 RPS would write 1000 dblog rows/s and starve other
          // diagnostics. First hit gets the warning; suppressed retries are
          // still rejected with 403, just not re-logged.
          $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '0.0.0.0';
          $floodKey = 'markaspot_open311.blocked_post.' . $jurisdictionId . '.' . $clientIp;
          if ($this->flood->isAllowed($floodKey, 1, 60)) {
            $this->flood->register($floodKey, 60);
            $this->logger->warning(
              'Blocked workspace submission rejected: uid=@uid ip=@ip claimed_jid=@claimed lat=@lat lng=@lng',
              [
                '@uid' => (int) $this->currentUser->id(),
                '@ip' => $clientIp,
                '@claimed' => $jurisdictionId,
                '@lat' => $coordinates[0] ?? 'null',
                '@lng' => $coordinates[1] ?? 'null',
              ]
            );
          }
          throw new GeoreportException('Workspace is blocked.', 403);
        }
      }

      $this->enforceSubmissionBoundary($request_data, $jurisdictionId);

      // Workspace visibility enforcement: block anonymous POST for
      // authenticated-only workspaces.
      if ($jurisdictionId && $this->workspaceVisibility && $this->currentUser->isAnonymous()) {
        if (!$this->workspaceVisibility->canAnonymousSubmit($jurisdictionId)) {
          throw new GeoreportException('Authentication required to submit to this workspace.', 403);
        }
      }

      // Return result to handler for formatting and response.
      return $this->createNode($request_data);
    }
    catch (EntityStorageException $e) {
      // Drupal's storage layer wraps some exceptions from presave hooks
      // (e.g. UnprocessableEntityHttpException from the privacy-notice
      // enforcement hook) into EntityStorageException. Unwrap and re-throw
      // so the kernel exception subscriber maps them to their real HTTP
      // status instead of a generic 500.
      $previous = $e->getPrevious();
      if ($previous instanceof HttpException) {
        throw $previous;
      }
      throw new HttpException(500, 'Internal Server Error', $e);
    }
    catch (GeoreportException $e) {
      // Open311 contract exceptions carry intentional API error codes and are
      // mapped by GeoreportEventSubscriber.
      throw $e;
    }
    catch (HttpExceptionInterface $e) {
      // Pre-mapped HTTP exceptions (AccessDeniedHttpException, BadRequest…)
      // carry intentional status codes — let them propagate verbatim.
      throw $e;
    }
    catch (\Throwable $e) {
      // post-save hooks (ECA `action_send_email`, markaspot_mail
      // notifications) can throw long after the entity row is committed.
      // Without this catch the throw bubbles to Drupal's REST exception
      // subscriber which historically wrapped the message verbatim into
      // a 4xx response — leaking implementation detail through what
      // should be an English Open311 contract. Translate to a generic
      // 502 with no architectural detail; operators investigate via
      // watchdog correlating on the timestamp.
      $this->logger->error('Unhandled @class (code @code) during POST /georeport/v2/requests.json. Message redacted in log.', [
        '@class' => $e::class,
        '@code' => $e->getCode(),
      ]);
      // Retry-After carries 60-90s of randomised backoff so a fleet of
      // Open311 clients failing simultaneously does not all retry at the
      // same instant.
      $headers = ['Retry-After' => (string) (60 + random_int(0, 30))];
      throw new HttpException(502, 'An unexpected error occurred. Please retry after 60 seconds; contact support if the problem persists.', $e, $headers);
    }
  }

  /**
   * Resolves an optional claimed jurisdiction from canonical and legacy keys.
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   Throws 400 when a claim exists but cannot resolve to a jurisdiction.
   */
  protected function resolveClaimedJurisdictionId(array $requestData): ?int {
    if (!$this->hasJurisdictionClaim($requestData)) {
      return NULL;
    }

    $jurisdictionId = $this->georeportProcessor->resolveJurisdictionId($requestData);
    if (!$jurisdictionId) {
      $allowed = $this->jurisdictionScopeValidator
        ? $this->jurisdictionScopeValidator->getAllowedJurisdictionIds($this->currentUser)
        : [];
      $this->jurisdictionScopeValidator?->logViolation(
        (int) $this->currentUser->id(),
        NULL,
        $allowed,
        400,
        'invalid_claim'
      );
      throw new GeoreportException('Invalid jurisdiction_id.', 400);
    }

    return $jurisdictionId;
  }

  /**
   * Checks if request data contains a non-empty jurisdiction claim.
   */
  protected function hasJurisdictionClaim(array $requestData): bool {
    foreach (['jurisdiction_id', 'jurisdiction', 'gid'] as $key) {
      if (array_key_exists($key, $requestData) && trim((string) $requestData[$key]) !== '') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Enforces jurisdiction boundary on valid coordinate payloads.
   */
  protected function enforceSubmissionBoundary(array $requestData, int $jurisdictionId): void {
    $coordinates = $this->getRequestCoordinates($requestData);
    if ($coordinates === NULL || !$this->boundaryValidator) {
      return;
    }

    [$lat, $lng] = $coordinates;
    if ($this->boundaryValidator->isWithinJurisdictionBoundary($jurisdictionId, $lat, $lng)) {
      return;
    }

    if ($this->hasExplicitBoundaryBypassPermission()) {
      $this->logger->warning(
        'submission.boundary_bypass caller_uid=@uid jurisdiction=@jurisdiction lat=@lat lng=@lng result=accepted',
        [
          '@uid' => (int) $this->currentUser->id(),
          '@jurisdiction' => $jurisdictionId,
          '@lat' => $lat,
          '@lng' => $lng,
        ]
      );
      return;
    }

    $allowed = $this->jurisdictionScopeValidator
      ? $this->jurisdictionScopeValidator->getAllowedJurisdictionIds($this->currentUser)
      : [];
    $this->jurisdictionScopeValidator?->logViolation(
      (int) $this->currentUser->id(),
      $jurisdictionId,
      $allowed,
      422,
      'outside_boundary'
    );
    throw new HttpException(422, 'coordinates outside jurisdiction boundary');
  }

  /**
   * Checks role config for an explicit boundary bypass grant.
   *
   * This deliberately avoids AccountInterface::hasPermission(), because uid 1
   * receives every permission implicitly through Drupal's superuser policy.
   */
  protected function hasExplicitBoundaryBypassPermission(): bool {
    if ($this->currentUser->isAnonymous()) {
      return FALSE;
    }

    foreach ($this->currentUser->getRoles(TRUE) as $roleId) {
      $role = Role::load($roleId);
      if ($role && in_array('bypass jurisdiction boundary', $role->getPermissions(), TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extracts valid latitude and longitude values for boundary checks.
   *
   * Invalid coordinate payloads are validated later by the processor.
   *
   * @return array{0: float, 1: float}|null
   *   Latitude and longitude, or NULL when no valid pair is present.
   */
  protected function getRequestCoordinates(array $requestData): ?array {
    if (!array_key_exists('lat', $requestData)) {
      return NULL;
    }

    $lngKey = array_key_exists('long', $requestData) ? 'long' : 'lng';
    if (!array_key_exists($lngKey, $requestData)) {
      return NULL;
    }

    $lat = filter_var($requestData['lat'], FILTER_VALIDATE_FLOAT);
    $lng = filter_var($requestData[$lngKey], FILTER_VALIDATE_FLOAT);
    if ($lat === FALSE || $lng === FALSE) {
      return NULL;
    }

    $lat = (float) $lat;
    $lng = (float) $lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
      return NULL;
    }

    return [$lat, $lng];
  }

  /**
   * Restricts API-key reads to the owner's jurisdiction memberships.
   */
  protected function applyApiKeyJurisdictionReadScope(QueryInterface $query, array $parameters, ?int $resolvedJurisdictionId): void {
    if (!$this->currentRequestUsesApiKey()
      || !$this->jurisdictionScopeValidator
      || $this->currentUser->isAnonymous()) {
      return;
    }

    if (!$resolvedJurisdictionId && $this->hasJurisdictionClaim($parameters)) {
      $this->jurisdictionScopeValidator->logViolation(
        (int) $this->currentUser->id(),
        NULL,
        $this->jurisdictionScopeValidator->getAllowedJurisdictionIds($this->currentUser),
        400,
        'invalid_claim'
      );
      throw new GeoreportException('Invalid jurisdiction_id.', 400);
    }

    if ($resolvedJurisdictionId) {
      $this->jurisdictionScopeValidator
        ->resolveSubmissionJurisdiction($resolvedJurisdictionId, $this->currentUser);
      return;
    }

    $allowedJurisdictionIds = $this->jurisdictionScopeValidator
      ->getAllowedJurisdictionIds($this->currentUser);
    if ($allowedJurisdictionIds === []) {
      $query->condition('nid', [0], 'IN');
      return;
    }

    $nodeIds = [];
    foreach ($allowedJurisdictionIds as $jurisdictionId) {
      $nodeIds = array_merge(
        $nodeIds,
        $this->hierarchyResolver
          ? $this->hierarchyResolver->getNodeIdsInJurisdiction($jurisdictionId)
          : []
      );
    }

    $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
    $query->condition('nid', $nodeIds ?: [0], 'IN');
  }

  /**
   * Restricts anonymous reads to publicly visible workspaces.
   */
  protected function applyAnonymousWorkspaceReadScope(QueryInterface $query): void {
    if (!$this->workspaceVisibility || !$this->currentUser->isAnonymous()) {
      return;
    }

    $query->condition('field_jurisdiction', $this->getAnonymousVisibleJurisdictionIds() ?: [0], 'IN');
  }

  /**
   * Gets jurisdiction IDs whose requests are visible to anonymous users.
   *
   * @return int[]
   *   Public jurisdiction group IDs.
   */
  protected function getAnonymousVisibleJurisdictionIds(): array {
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType())
      ->execute();

    $visible = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      if ($this->workspaceVisibility->canAnonymousView($id)) {
        $visible[] = $id;
      }
    }

    return $visible;
  }

  /**
   * Applies caller-appropriate behavior for invalid jurisdiction claims.
   */
  protected function applyInvalidJurisdictionClaimScope(QueryInterface $query, array $parameters, ?int $resolvedJurisdictionId): void {
    if (!$this->hasJurisdictionClaim($parameters)) {
      return;
    }

    $valid = $resolvedJurisdictionId !== NULL
      && $this->isJurisdictionGroupId($resolvedJurisdictionId);
    if ($valid) {
      return;
    }

    if (!$this->currentUser->isAnonymous()) {
      throw new GeoreportException('Invalid jurisdiction_id.', 400);
    }

    // Anonymous callers get an empty scoped result instead of an existence
    // oracle for numeric tenant IDs.
    $query->condition('nid', [0], 'IN');
  }

  /**
   * Checks whether an anonymous jurisdiction claim must resolve as empty.
   */
  protected function anonymousJurisdictionClaimIsUnreadable(array $parameters, ?int $resolvedJurisdictionId): bool {
    if (!$this->workspaceVisibility
      || !$this->currentUser->isAnonymous()
      || !$this->hasJurisdictionClaim($parameters)) {
      return FALSE;
    }

    if ($resolvedJurisdictionId === NULL || !$this->isJurisdictionGroupId($resolvedJurisdictionId)) {
      return TRUE;
    }

    return !$this->workspaceVisibility->canAnonymousView($resolvedJurisdictionId);
  }

  /**
   * Checks whether an ID resolves to a jurisdiction group.
   */
  protected function isJurisdictionGroupId(int $groupId): bool {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    return $group && $this->isJurisdictionGroup($group);
  }

  /**
   * Checks whether a group is the configured jurisdiction bundle.
   */
  protected function isJurisdictionGroup(object $group): bool {
    return method_exists($group, 'bundle') && $group->bundle() === $this->jurisdictionGroupType();
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->config->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Checks whether the current request authenticated through an API key.
   */
  protected function currentRequestUsesApiKey(): bool {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return FALSE;
    }

    $headerName = $this->apiKeyAuthConfig->get('api_key_request_header_name');
    $postName = $this->apiKeyAuthConfig->get('api_key_post_parameter_name');
    $queryName = $this->apiKeyAuthConfig->get('api_key_get_parameter_name');

    return ($queryName && $request->query->has($queryName))
      || ($postName && $request->request->has($postName))
      || ($headerName && $request->headers->has($headerName))
      || $request->query->has('api_key')
      || $request->request->has('api_key')
      || $request->headers->has('apikey')
      || $request->headers->has('x-api-key');
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
        // Determine initial status (jurisdiction-aware).
        $jurisdictionId = isset($request_data['jurisdiction_id']) ? (int) $request_data['jurisdiction_id'] : NULL;
        $initialStatusTid = $this->georeportProcessor->getInitialStatusTid($jurisdictionId);

        // Uses status term description as note text; falls back to the site
        // default language because service request nodes are language-neutral.
        $langcode = $this->languageManager->getDefaultLanguage()->getId();
        $paragraph = $this->georeportProcessor->createStatusNoteParagraph([
          'status_term_id' => $initialStatusTid,
        ], $langcode);
        if ($paragraph->get('field_status_note')->isEmpty()) {
          $paragraph->set('field_status_note', [
            'value' => $this->t('The service request has been created.', [], ['langcode' => $langcode]),
            'format' => 'plain_text',
          ]);
          $paragraph->save();
        }

        $node->field_status_notes = [
          [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ],
        ];

        // Set the referenced term field.
        $node->field_status = [
          [
            'target_id' => $initialStatusTid,
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

        $propertyPath = $dotPosition !== FALSE
          ? substr($violation->getPropertyPath(), $dotPosition + 1)
          : $violation->getPropertyPath();
        $messages[$propertyPath] = $violation->getMessage();
        $this->logger->error('Node validation error: @message', ['@message' => $violation->getMessage()]);

      }

      // Convert messages to a string for the response.
      $detailedMessage = json_encode($messages);
      throw new GeoreportException($detailedMessage, 400);

    }
    else {
      return TRUE;
    }
  }

  /**
   * Validates that jurisdiction_id is provided when multiple roots exist.
   *
   * In a multi-tenant setup (multiple root jurisdiction groups), requests
   * without a jurisdiction_id cannot be assigned to a group and become
   * orphaned. This method detects that condition and rejects the request
   * early with a clear error message.
   *
   * Single-tenant instances (0 or 1 root jurisdiction) are not affected.
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   Throws 400 Bad Request when jurisdiction_id is missing in multi-tenant.
   */
  protected function validateMultiTenantJurisdiction(): void {
    $jurType = $this->jurisdictionGroupType();

    try {
      // accessCheck(FALSE) is intentional: we check platform topology
      // (how many root groups exist), not returning group data to the
      // caller. The result is used as a boolean gate only.
      $rootIds = $this->entityTypeManager->getStorage('group')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $jurType)
        ->notExists('field_parent_jurisdiction')
        ->execute();
    }
    catch (\Exception $e) {
      // Group module not installed or field missing. Skip gracefully.
      return;
    }

    // Only the count matters. Discard IDs to prevent accidental exposure.
    $isMultiTenant = count($rootIds) > 1;
    unset($rootIds);

    if ($isMultiTenant) {
      throw new GeoreportException(
        'jurisdiction_id is required.',
        400
      );
    }
  }

  /**
   * Allowlist of node fields filterable via direct field_* query parameters.
   *
   * The endpoint is reachable anonymously. Limiting equality filtering to
   * this explicit set prevents schema probing through arbitrary field_*
   * names and matches the surface the frontend actually queries.
   *
   * SECURITY: before adding a field here, confirm it carries no per-row
   * access constraints that the entity query bypasses. The query is built
   * with accessCheck(), but field-permissions-gated fields (e.g.
   * field_internal_remark) must NEVER be in this list.
   */
  public const ALLOWED_FIELD_FILTERS = [
    'field_status',
    'field_category',
    'field_district',
    'field_sublocality',
    'field_hazard_level',
    'field_facility',
  ];

  /**
   * Hard cap on the number of comma-separated values for a single field.
   *
   * The frontend's multi-select UI realistically caps out around a handful
   * of values; anything beyond this is either a poorly designed integration
   * or a probe. Drop the trailing entries instead of throwing — the query
   * still runs with the first N values.
   */
  private const MAX_MULTI_VALUES_PER_FIELD = 50;

  /**
   * Return only the query parameters that target allowlisted node fields.
   *
   * Each accepted entry is normalised so the caller can branch on shape:
   *   - string value  → single-equality filter ($query->condition($f, $v, '=')).
   *   - array  value  → multi-value IN filter ($query->condition($f, $v, 'IN')).
   *
   * Two channels yield a multi-value array:
   *   1. comma-separated string (e.g. ?field_district=148,149) — frontend
   *      multi-select UI emits this shape;
   *   2. array submission (e.g. ?field_district[]=148&field_district[]=149) —
   *      historical API contract, retained for backward compatibility.
   *
   * In both channels the resulting array is trimmed, empties are dropped,
   * duplicates are removed, and the length is capped at
   * MAX_MULTI_VALUES_PER_FIELD. Non-string scalars are dropped (the public
   * GET contract is "string parameter").
   *
   * Extracted from the main index() flow so the security boundary has a
   * named, unit-tested surface.
   */
  public static function filterAllowedFieldParameters(array $parameters): array {
    $candidates = array_filter(
      $parameters,
      fn($key) => in_array($key, self::ALLOWED_FIELD_FILTERS, TRUE),
      ARRAY_FILTER_USE_KEY
    );
    $safe = [];
    foreach ($candidates as $field => $value) {
      if (is_array($value)) {
        $parts = self::normaliseMultiValues($value);
        if ($parts !== []) {
          $safe[$field] = $parts;
        }
        continue;
      }
      if (is_string($value)) {
        if (str_contains($value, ',')) {
          $parts = self::normaliseMultiValues(explode(',', $value));
          if ($parts !== []) {
            $safe[$field] = $parts;
          }
        }
        else {
          $safe[$field] = $value;
        }
        continue;
      }
      // Other scalars (numbers, booleans) and objects: drop. The contract
      // is string-typed query parameters; anything else is unexpected.
    }
    return $safe;
  }

  /**
   * Trim, dedupe, drop empties, and cap a list of candidate values.
   *
   * Used by filterAllowedFieldParameters() for both array submissions and
   * comma-separated string splits. Keeps the bound on Drupal entity-query
   * IN-clause size predictable (MAX_MULTI_VALUES_PER_FIELD) regardless of
   * which channel the client used.
   *
   * @param array<int|string, mixed> $values
   *   Raw value list as received.
   *
   * @return string[]
   *   Sanitised string values, possibly empty.
   */
  private static function normaliseMultiValues(array $values): array {
    $strings = array_filter($values, 'is_string');
    $trimmed = array_map('trim', $strings);
    $nonEmpty = array_filter($trimmed, fn($v) => $v !== '');
    $deduped = array_values(array_unique($nonEmpty));
    if (count($deduped) > self::MAX_MULTI_VALUES_PER_FIELD) {
      $deduped = array_slice($deduped, 0, self::MAX_MULTI_VALUES_PER_FIELD);
    }
    return $deduped;
  }

}
