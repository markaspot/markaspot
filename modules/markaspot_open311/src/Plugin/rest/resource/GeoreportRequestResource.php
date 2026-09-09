<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Traits\LanguageNegotiationTrait;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_request_resource",
 *   label = @Translation("Georeport request"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/requests/{id}",
 *     "https://www.drupal.org/link-relations/create" =
 *   "/georeport/v2/requests/{id}",
 *     "defaults"  = {"_format": "json"},
 *   }
 * )
 */
class GeoreportRequestResource extends ResourceBase {

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
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null
   */
  protected $hierarchyResolver;

  /**
   * The jurisdiction scope validator.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionScopeValidator|null
   */
  protected $jurisdictionScopeValidator;

  /**
   * The workspace visibility service.
   */
  protected WorkspaceVisibilityInterface $workspaceVisibility;

  /**
   * The flood control service.
   *
   * Consumed by Open311RateLimitTrait::checkRateLimit() to gate GET traffic
   * and POST traffic on the UPDATE endpoint per-IP / per-UID. Without it, an
   * authenticated api-key consumer with `access open311 advanced properties`
   * could flood the endpoint and amplify watchdog writes during a broken-mail
   * outage (security review of 100ebc2 finding 7).
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

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
   *   The language manager.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood control service.
   * @param \Drupal\markaspot_group\Service\JurisdictionScopeValidator $jurisdiction_scope_validator
   *   The jurisdiction scope validator.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchy_resolver
   *   The jurisdiction hierarchy resolver.
   * @param \Drupal\markaspot_group\Service\WorkspaceVisibilityInterface $workspace_visibility
   *   The workspace visibility service.
   * @param \Drupal\markaspot_nuxt\Service\FeatureScopeResolver|null $featureScopeResolver
   *   The effective feature scope resolver.
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
    FloodInterface $flood,
    JurisdictionScopeValidator $jurisdiction_scope_validator,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
    WorkspaceVisibilityInterface $workspace_visibility,
    protected ?FeatureScopeResolver $featureScopeResolver = NULL,
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
    $this->jurisdictionScopeValidator = $jurisdiction_scope_validator;
    $this->hierarchyResolver = $hierarchy_resolver;
    $this->workspaceVisibility = $workspace_visibility;
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
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
      $container->get('flood'),
      $container->get('markaspot_group.jurisdiction_scope_validator'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('markaspot_group.workspace_visibility'),
      $container->get('markaspot_nuxt.feature_scope_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = new RouteCollection();

    $definition = $this->getPluginDefinition();
    $canonical_path = $definition['uri_paths']['canonical'] ?? '/' . strtr($this->pluginId, ':', '/') . '/{id}';
    $route_name = strtr($this->pluginId, ':', '.');

    $methods = $this->availableMethods();
    foreach ($methods as $method) {
      $route = $this->getBaseRoute($canonical_path, $method);
      switch ($method) {

        case 'POST':
          foreach ($this->serializerFormats as $format_name) {
            $format_route = clone $route;
            $format_route->setRequirement('_csrf_request_header_token', 'FALSE');
            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.
            $format_route->addRequirements(
              [
                '_content_type_format' =>
                  implode('|', $this->serializerFormats),
              ]);
            $collection->add("$route_name.$method.$format_name", $format_route);
          }
          break;

        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format_name) {

            // Expose one route per available format.
            $format_route = clone $route;
            $format_route->addOptions(['_format' => $format_name]);
            $collection->add("$route_name.$method.$format_name", $format_route);

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
   * @param string $id
   *   The Service Request ID.
   *
   * @return array
   *   Returns the Service Request matching the ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function get(string $id) {
    // Apply rate limiting to protect against DoS attacks.
    // Moderation, editorial, and admin users are exempt.
    $this->checkRateLimit('georeport_api_get');

    $parameters = UrlHelper::filterQueryParameters($this->requestStack->getCurrentRequest()->query->all());

    // Internal markers set further down; never accept them from the wire.
    unset(
      $parameters['_jurisdiction_read_scope'],
      $parameters['_extensions_summary'],
      $parameters['_request_list_sort'],
      $parameters['_request_list_pagination'],
      $parameters['_request_list_total']
    );

    // Resolve language code from Accept-Language header or query parameter.
    $parameters['langcode'] = $this->resolveLanguageCode($parameters);

    // Start with the secure base query.
    $query = $this->georeportProcessor->createNodeQuery($parameters, $this->currentUser);

    // Add bundle condition.
    $bundle = $this->config->get('bundle') ?? 'service_request';
    $query->condition('type', $bundle);

    // Handle the main request ID.
    if ($id != "") {
      $query->condition('request_id', $this->getRequestId($id));
    }

    // Handle additional ID parameter if present.
    if (isset($parameters['id'])) {
      $query->condition('request_id', $parameters['id']);
    }

    // Read path: membership in the requested jurisdiction is not a hard
    // gate (markaspot-ui#427, degrade-don't-deny). The serialization
    // shape is scoped below instead.
    $readScope = NULL;
    $node = $this->loadScopedRequestNode($id, $parameters, FALSE);
    if ($node) {
      $query->condition('nid', $node->id());

      // Scope the serialization shape to the claimed jurisdiction, or to
      // the request's own jurisdiction when no claim is given.
      // getResults() downgrades dashboard-capable non-members of that
      // jurisdiction to the anonymous/public response shape.
      if ($this->hasJurisdictionClaim($parameters)) {
        $readScope = $this->georeportProcessor->resolveJurisdictionId($parameters);
      }
      $readScope = $readScope ?: $this->resolveNodeJurisdictionId($node);
    }
    else {
      $query->condition('nid', [0], 'IN');
    }

    return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters, $readScope);
  }

  /**
   * Responds to POST requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($id, $request_data) {
    // Per-IP / per-UID rate limit before any work happens. Closes the
    // gap that allowed an authenticated api-key consumer to flood the
    // UPDATE endpoint and amplify watchdog writes during a broken-mail
    // outage (security review of 100ebc2 finding 7). Same flood key as
    // the create endpoint so a single hostile actor cannot side-step
    // the limit by alternating create + update calls.
    $this->checkRateLimit('georeport_api_post');

    try {
      if (!$this->currentUser->hasPermission('access open311 advanced properties')) {
        throw new AccessDeniedHttpException();
      }

      $parameters = UrlHelper::filterQueryParameters($this->requestStack->getCurrentRequest()->query->all());
      // Internal markers; never accept them from the wire (defensive mirror
      // of the GET-path strip).
      unset(
        $parameters['_jurisdiction_read_scope'],
        $parameters['_request_list_sort'],
        $parameters['_request_list_pagination'],
        $parameters['_request_list_total']
      );
      $parameters['langcode'] = $this->resolveLanguageCode($parameters);
      $scopeParameters = $this->mergeJurisdictionClaims($parameters, $request_data);
      $node = $this->loadScopedRequestNode($id, $scopeParameters);
      if (!$node) {
        throw new NotFoundHttpException('Service request not found.');
      }
      $this->enforceWorkspaceWritable($node);
      $request_data = $this->canonicalizeUpdateJurisdiction($request_data, $node, $scopeParameters);

      // Return result to handler for formatting and response.
      return $this->updateNode($id, $request_data, $node);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }
    catch (GeoreportException $e) {
      // Open311 contract exceptions carry intentional API error codes and are
      // mapped by GeoreportEventSubscriber.
      throw $e;
    }
    catch (HttpExceptionInterface $e) {
      // Pre-mapped HTTP exceptions (NotFoundHttpException,
      // AccessDeniedHttpException) carry intentional status codes — let them
      // propagate verbatim.
      throw $e;
    }
    catch (\Throwable $e) {
      // post-save hooks (ECA `action_send_email`, markaspot_mail
      // notifications, custom presave validators) can throw long after
      // the entity row is committed. Without this catch the throw
      // bubbles to Drupal's REST exception subscriber which historically
      // wrapped the message verbatim into a 4xx response — leaking
      // implementation detail through what should be an English Open311
      // contract. Translate to a generic 502 with no architectural
      // detail; operators investigate via watchdog using the request id
      // as correlation key.
      $this->logger->error('Unhandled @class (code @code) during POST /georeport/v2/requests/@id.json. Message redacted in log.', [
        '@class' => $e::class,
        '@code' => $e->getCode(),
        '@id' => $id,
      ]);
      // Retry-After carries 60-90s of randomised backoff so a fleet of
      // Open311 clients failing simultaneously does not all retry at the
      // same instant — pure 60s would create a thundering herd against
      // whatever upstream subsystem just went sour.
      $headers = ['Retry-After' => (string) (60 + random_int(0, 30))];
      throw new HttpException(502, 'An unexpected error occurred. Please retry after 60 seconds; contact support if the problem persists.', $e, $headers);
    }

  }

  /**
   * Checks whether the current request contains an API key.
   */
  protected function currentRequestUsesApiKey(): bool {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return FALSE;
    }

    $headerName = $this->apiKeyAuthConfig->get('api_key_request_header_name');
    $postName = $this->apiKeyAuthConfig->get('api_key_post_parameter_name');
    $queryName = $this->apiKeyAuthConfig->get('api_key_get_parameter_name');

    return ($queryName && !empty($request->query->get($queryName)))
      || ($postName && !empty($request->request->get($postName)))
      || ($headerName && !empty($request->headers->get($headerName)));
  }

  /**
   * Loads a single request through the same scoped query as GET responses.
   *
   * @param string $id
   *   The service request ID from the route.
   * @param array $parameters
   *   Query (and merged body) parameters, possibly with jurisdiction claims.
   * @param bool $enforceMembership
   *   Whether membership in the request's jurisdiction is a hard access
   *   gate. TRUE (default) for write paths: non-members receive a 403 via
   *   validateJurisdictionAccess(). FALSE for read paths
   *   (markaspot-ui#427, degrade-don't-deny): non-members still get the
   *   node, and the caller scopes the serialization shape instead. API-key
   *   jurisdiction scoping and invalid-claim handling are unaffected by
   *   this flag.
   */
  protected function loadScopedRequestNode(string $id, array $parameters, bool $enforceMembership = TRUE): ?ContentEntityInterface {
    $requestId = $id !== '' ? $this->getRequestId($id) : ($parameters['id'] ?? '');
    if ($requestId === '') {
      return NULL;
    }

    $scopeJurisdictionIds = $this->resolveSingleRequestJurisdictionScope($parameters, $enforceMembership);
    $query = $this->georeportProcessor->createNodeQuery(
      $this->stripJurisdictionClaims($parameters),
      $this->currentUser
    );

    $bundle = $this->config->get('bundle') ?? 'service_request';
    $query->condition('type', $bundle);
    $query->condition('request_id', $requestId);

    $nids = $query->execute();
    if (empty($nids)) {
      return NULL;
    }

    $matches = [];
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    foreach ($nodes as $node) {
      if (!$node instanceof ContentEntityInterface) {
        continue;
      }
      if (!$this->requestNodeMatchesScope(
        $node,
        $scopeJurisdictionIds,
        !$enforceMembership && $this->currentRequestUsesApiKey(),
      )) {
        continue;
      }
      $matches[(int) $node->id()] = $node;
    }

    if (count($matches) > 1) {
      throw new BadRequestHttpException('jurisdiction_id required to disambiguate service_request_id.');
    }

    if ($matches === []) {
      return NULL;
    }

    $node = reset($matches);
    if ($enforceMembership
      && !$this->currentUser->isAnonymous()
      && !$this->currentRequestUsesApiKey()
      && $scopeJurisdictionIds === NULL) {
      $this->validateScopedRequestAccess($node);
    }

    return $node;
  }

  /**
   * Resolves and validates the optional single-request jurisdiction scope.
   *
   * @param array $parameters
   *   Query (and merged body) parameters, possibly with jurisdiction claims.
   * @param bool $enforceMembership
   *   Whether jurisdiction membership is enforced with a 403 for
   *   session-authenticated claims (write paths). FALSE on read paths
   *   (markaspot-ui#427): the claim is still resolved and validated for
   *   existence (400 on invalid claims) and API-key scoping still applies,
   *   but non-membership no longer denies access.
   *
   * @return int[]|null
   *   Jurisdiction IDs that the request lookup may search, or NULL when the
   *   lookup is not jurisdiction-scoped.
   */
  protected function resolveSingleRequestJurisdictionScope(array $parameters, bool $enforceMembership = TRUE): ?array {
    if ($this->hasJurisdictionClaim($parameters)) {
      $jurisdictionId = $this->georeportProcessor
        ->resolveJurisdictionId($parameters);
      if (!$jurisdictionId || !$this->isJurisdictionGroupId($jurisdictionId)) {
        $anonymousEquivalentRead = $this->currentUser->isAnonymous()
          || (!$enforceMembership && $this->currentRequestUsesApiKey());
        if (!$anonymousEquivalentRead) {
          if ($this->jurisdictionScopeValidator) {
            $this->jurisdictionScopeValidator->logViolation(
              (int) $this->currentUser->id(),
              NULL,
              $this->jurisdictionScopeValidator->getAllowedJurisdictionIds($this->currentUser),
              400,
              'invalid_claim'
            );
          }
          throw new BadRequestHttpException('Invalid jurisdiction_id.');
        }

        // Anonymous callers get an empty scoped result instead of an existence
        // oracle for numeric tenant IDs.
        return [0];
      }

      if ($this->currentRequestUsesApiKey()
        && $this->jurisdictionScopeValidator
        && !$this->currentUser->isAnonymous()) {
        if ($enforceMembership) {
          $jurisdictionId = $this->jurisdictionScopeValidator
            ->resolveSubmissionJurisdiction($jurisdictionId, $this->currentUser);
        }
        else {
          if (!$this->workspaceVisibility->canAnonymousView($jurisdictionId)) {
            return [0];
          }
          return $this->jurisdictionScopeValidator
            ->resolveReadScope($jurisdictionId, $this->currentUser);
        }
      }
      elseif (!$this->currentUser->isAnonymous() && $enforceMembership) {
        $this->georeportProcessor
          ->validateJurisdictionAccess($jurisdictionId, $this->currentUser);
      }

      return [$jurisdictionId];
    }

    if ($this->currentRequestUsesApiKey()
      && $this->jurisdictionScopeValidator
      && !$this->currentUser->isAnonymous()) {
      if ($enforceMembership) {
        return [
          $this->jurisdictionScopeValidator
            ->resolveSubmissionJurisdiction(NULL, $this->currentUser),
        ];
      }

      return $this->jurisdictionScopeValidator
        ->resolveReadScope(NULL, $this->currentUser);
    }

    return NULL;
  }

  /**
   * Removes jurisdiction claims before the exact request ID candidate lookup.
   */
  protected function stripJurisdictionClaims(array $parameters): array {
    unset($parameters['jurisdiction_id'], $parameters['jurisdiction'], $parameters['gid']);
    return $parameters;
  }

  /**
   * Checks whether a loaded request node matches the resolved scope.
   */
  protected function requestNodeMatchesScope(
    ContentEntityInterface $node,
    ?array $scopeJurisdictionIds,
    bool $apiKeyRead = FALSE,
  ): bool {
    $jurisdictionIds = $this->resolveNodeJurisdictionIds($node);
    $anonymousEquivalentRead = $this->currentUser->isAnonymous() || $apiKeyRead;
    if ($anonymousEquivalentRead
      && $this->featureScopeResolver?->isSelfServicePlatform() === TRUE
      && $jurisdictionIds === []) {
      return FALSE;
    }

    if ($anonymousEquivalentRead) {
      foreach ($jurisdictionIds as $jurisdictionId) {
        if (!$this->workspaceVisibility->canAnonymousView($jurisdictionId)) {
          return FALSE;
        }
      }
    }

    foreach ($jurisdictionIds as $jurisdictionId) {
      if ($this->workspaceVisibility->getVisibility($jurisdictionId) === 'form_only') {
        $organisationIds = $node->hasField('field_organisation')
          ? array_map('intval', array_column($node->get('field_organisation')->getValue(), 'target_id')) : [];
        if (!$this->workspaceVisibility->allowsReportReadFor($this->currentUser, $jurisdictionId, $organisationIds)) {
          return FALSE;
        }
      }
    }

    if ($scopeJurisdictionIds !== NULL) {
      foreach ($scopeJurisdictionIds as $scopeJurisdictionId) {
        if ($this->nodeBelongsToJurisdiction($node, $scopeJurisdictionId)) {
          return TRUE;
        }
      }

      return FALSE;
    }

    return TRUE;
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
   * Merges query and body jurisdiction claims for update scoping.
   */
  protected function mergeJurisdictionClaims(array $parameters, array $requestData): array {
    $queryClaims = $this->extractJurisdictionClaims($parameters);
    $bodyClaims = $this->extractJurisdictionClaims($requestData);
    if ($queryClaims !== [] && $bodyClaims !== []) {
      $queryJurisdictionId = $this->georeportProcessor
        ->resolveJurisdictionId($queryClaims);
      $bodyJurisdictionId = $this->georeportProcessor
        ->resolveJurisdictionId($bodyClaims);
      if (!$queryJurisdictionId || !$bodyJurisdictionId || $queryJurisdictionId !== $bodyJurisdictionId) {
        throw new BadRequestHttpException('Conflicting jurisdiction_id.');
      }
    }

    foreach (['jurisdiction_id', 'jurisdiction', 'gid'] as $key) {
      if (!array_key_exists($key, $requestData) || trim((string) $requestData[$key]) === '') {
        continue;
      }

      $parameters[$key] = $requestData[$key];
    }

    return $parameters;
  }

  /**
   * Extracts non-empty jurisdiction claim parameters.
   */
  protected function extractJurisdictionClaims(array $data): array {
    $claims = [];
    foreach (['jurisdiction_id', 'jurisdiction', 'gid'] as $key) {
      if (array_key_exists($key, $data) && trim((string) $data[$key]) !== '') {
        $claims[$key] = $data[$key];
      }
    }

    return $claims;
  }

  /**
   * Validates update body claims and pins mapping to the loaded node tenant.
   */
  protected function canonicalizeUpdateJurisdiction(array $requestData, ContentEntityInterface $node, array $scopeParameters): array {
    $jurisdictionId = $this->resolveNodeJurisdictionId($node);
    if ($this->hasJurisdictionClaim($scopeParameters)) {
      $claimedJurisdictionId = $this->georeportProcessor
        ->resolveJurisdictionId($scopeParameters);
      if (!$claimedJurisdictionId || !$this->nodeBelongsToJurisdiction($node, $claimedJurisdictionId)) {
        throw new BadRequestHttpException('jurisdiction_id does not match service_request_id.');
      }
    }

    if ($jurisdictionId !== NULL) {
      $requestData['jurisdiction_id'] = $jurisdictionId;
    }
    unset($requestData['jurisdiction'], $requestData['gid']);

    return $requestData;
  }

  /**
   * Checks whether a request node belongs to a jurisdiction hierarchy.
   */
  protected function nodeBelongsToJurisdiction(ContentEntityInterface $node, int $jurisdictionId): bool {
    if (!$this->isJurisdictionGroupId($jurisdictionId)) {
      return FALSE;
    }

    $nodeJurisdictionId = $this->resolveNodeJurisdictionId($node);
    if ($nodeJurisdictionId === NULL || !$this->isJurisdictionGroupId($nodeJurisdictionId)) {
      return FALSE;
    }

    if (!$this->hierarchyResolver) {
      return $nodeJurisdictionId === $jurisdictionId;
    }

    return in_array($nodeJurisdictionId, $this->hierarchyResolver->getDescendantIds($jurisdictionId), TRUE);
  }

  /**
   * Validates access against the loaded request's own jurisdiction.
   */
  protected function validateScopedRequestAccess(ContentEntityInterface $node): void {
    // API-key access was already scoped before node loading, including parent
    // jurisdiction hierarchies and explicit jurisdiction claims.
    if ($this->currentRequestUsesApiKey() && $this->jurisdictionScopeValidator) {
      return;
    }

    $jurisdictionId = $this->resolveNodeJurisdictionId($node);
    $this->georeportProcessor->validateJurisdictionAccess($jurisdictionId, $this->currentUser);
  }

  /**
   * Denies updates to requests in a blocked workspace.
   */
  protected function enforceWorkspaceWritable(ContentEntityInterface $node): void {
    // Read and write must agree on what "blocked" means. The anonymous read
    // path checks every jurisdiction a request belongs to, so a request under
    // a blocked parent with an unblocked deepest child is hidden; checking
    // only the deepest one here would leave that same request writable.
    $jurisdictionId = NULL;
    foreach ($this->resolveNodeJurisdictionIds($node) as $candidateId) {
      if ($this->workspaceVisibility->isBlocked($candidateId)) {
        $jurisdictionId = $candidateId;
        break;
      }
    }
    if ($jurisdictionId === NULL) {
      return;
    }

    // Flood-gated detection log. See sibling rate-limiting on the Index POST
    // path: same threat (sustained bot retries) but on PATCH, same mitigation
    // (1/60s per (workspace, IP)).
    $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '0.0.0.0';
    $floodKey = 'markaspot_open311.blocked_patch.' . $jurisdictionId . '.' . $clientIp;
    if ($this->flood->isAllowed($floodKey, 1, 60)) {
      $this->flood->register($floodKey, 60);
      $this->logger->warning(
        'Blocked workspace update rejected: uid=@uid ip=@ip jid=@jid nid=@nid',
        [
          '@uid' => (int) $this->currentUser->id(),
          '@ip' => $clientIp,
          '@jid' => $jurisdictionId,
          '@nid' => (int) $node->id(),
        ]
      );
    }
    throw new AccessDeniedHttpException('Workspace is blocked.');
  }

  /**
   * Resolves the most specific jurisdiction ID from a service request node.
   */
  protected function resolveNodeJurisdictionId(object $node): ?int {
    $relationshipJurisdictionId = $this->resolveNodeJurisdictionIdFromRelationships($node);
    if ($relationshipJurisdictionId !== NULL) {
      return $relationshipJurisdictionId;
    }

    if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
      $jurisdictionId = (int) $node->get('field_jurisdiction')->target_id;
      if ($this->isJurisdictionGroupId($jurisdictionId)) {
        return $jurisdictionId;
      }
    }

    return NULL;
  }

  /**
   * Resolves every jurisdiction assigned to a service request.
   *
   * Anonymous visibility is conservative across this multi-value field: one
   * restrictive target makes the request unreadable even when another target
   * is public. Relationship and field assignments are combined so neither
   * storage path can hide a restriction.
   *
   * @return int[]
   *   Unique valid jurisdiction group IDs.
   */
  protected function resolveNodeJurisdictionIds(object $node): array {
    $jurisdictionIds = [];
    try {
      $relationships = $this->entityTypeManager->getStorage('group_relationship')->loadByProperties([
        'entity_id' => $node->id(),
        'plugin_id' => 'group_node:service_request',
      ]);
      foreach ($relationships as $relationship) {
        $group = $relationship->getGroup();
        if ($group && $this->isJurisdictionGroup($group)) {
          $jurisdictionIds[(int) $group->id()] = (int) $group->id();
        }
      }
    }
    catch (\Exception) {
      // Legacy stacks may not have relationship storage available.
    }

    if ($node->hasField('field_jurisdiction')) {
      foreach ($node->get('field_jurisdiction') as $item) {
        $jurisdictionId = (int) $item->target_id;
        if ($jurisdictionId > 0 && $this->isJurisdictionGroupId($jurisdictionId)) {
          $jurisdictionIds[$jurisdictionId] = $jurisdictionId;
        }
      }
    }

    return array_values($jurisdictionIds);
  }

  /**
   * Resolves the most specific jurisdiction from group_relationship rows.
   */
  protected function resolveNodeJurisdictionIdFromRelationships(object $node): ?int {
    try {
      $relationships = $this->entityTypeManager->getStorage('group_relationship')->loadByProperties([
        'entity_id' => $node->id(),
        'plugin_id' => 'group_node:service_request',
      ]);
    }
    catch (\Exception) {
      return NULL;
    }

    $jurisdictions = [];
    $parentIds = [];
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if (!$group || !$this->isJurisdictionGroup($group)) {
        continue;
      }

      $groupId = (int) $group->id();
      $jurisdictions[$groupId] = $groupId;
      if ($group->hasField('field_parent_jurisdiction') && !$group->get('field_parent_jurisdiction')->isEmpty()) {
        $parentIds[(int) $group->get('field_parent_jurisdiction')->target_id] = TRUE;
      }
    }

    if ($jurisdictions === []) {
      return NULL;
    }

    foreach ($jurisdictions as $jurisdictionId) {
      if (!isset($parentIds[$jurisdictionId])) {
        return $jurisdictionId;
      }
    }

    return reset($jurisdictions) ?: NULL;
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
   * Updates a node of type service_request.
   *
   * @param string $id
   *   The Node ID (nid) of the node to update.
   * @param array $request_data
   *   An associative array containing the update data.
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The preloaded, scope-validated service request node.
   *
   * @return array
   *   An array with the updated node's service request ID.
   *
   * @throws \Exception
   *   Throws exception if the node cannot be found or validation fails.
   */
  protected function updateNode(string $id, array $request_data, ContentEntityInterface $node): array {
    $request_id = $this->getRequestId($id);

    // Prepare node properties for update.
    $request_data['service_request_id'] = $request_id;
    $values = $this->georeportProcessor->prepareNodeProperties($request_data, 'update');

    // Process the update fields.
    $this->processUpdateFields($node, $values);

    // Validation and saving logic.
    if ($this->validateAndUpdateNode($node, $values)) {
      $this->logger->notice('Updated entity %type with ID %request_id.', [
        '%type' => $node->getEntityTypeId(),
        '%request_id' => $request_id,
      ]);

      return ['service_requests' => ['request' => ['service_request_id' => $request_id]]];
    }

    throw new \Exception('Node validation failed.');
  }

  /**
   * Processes the fields for updating the node.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node entity to update.
   * @param array $values
   *   An associative array of field values to update.
   */
  protected function processUpdateFields(ContentEntityInterface $node, array $values): void {
    // Handle media updates first.
    if (isset($values['_media_updates'])) {
      if (!$node->hasField('field_request_media')) {
        throw new BadRequestHttpException('Service request media updates are not available for this request.');
      }
      foreach ([
        'update open311 plus all properties',
        'update open311 request media publication',
      ] as $permission) {
        if (!$this->currentUser->hasPermission($permission)) {
          throw new AccessDeniedHttpException('Service request media updates are not permitted for this API account.');
        }
      }
      $this->georeportProcessor->updateMediaPublishedStatus($values['_media_updates'], $node);

      // Don't process this as a field.
      unset($values['_media_updates']);
    }

    foreach ($values as $field_name => $value) {
      // Skip special handling fields; they are processed separately.
      // field_status is handled in specialFieldHandling to bypass
      // field_permissions access checks: the Open311 update permission gate
      // in post() is the authoritative guard, not per-field edit permissions.
      if (in_array($field_name, ['field_status', 'field_status_notes', 'revision_log_message', 'type'])) {
        continue;
      }

      // Skip if field doesn't exist on the node.
      if (!$node->hasField($field_name)) {
        continue;
      }

      $field = $node->get($field_name);
      $fieldAccess = $field->access('edit', NULL, TRUE);
      if (!$fieldAccess->isAllowed()) {
        continue;
      }

      $fieldType = $field->getFieldDefinition()->getType();

      // For entity references, except for 'field_request_media'.
      if ($fieldType == 'entity_reference' && $field_name != 'field_request_media') {
        $node->set($field_name, ['target_id' => $value]);
      }
      // For formatted text fields, set with format.
      elseif (in_array($fieldType, ['text_long', 'text_with_summary'])) {
        // Check if value already has format structure.
        if (is_array($value) && isset($value['value'])) {
          $node->set($field_name, $value);
        }
        else {
          // Wrap plain string with user's default format.
          $node->set($field_name, ['value' => $value, 'format' => filter_default_format()]);
        }
      }
      else {
        $node->set($field_name, $value);
      }
    }

    $this->specialFieldHandling($node, $values);
  }

  /**
   * Handles special field logic including status notes and revisions.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node entity to update.
   * @param array $values
   *   An associative array of field values to update.
   */
  protected function specialFieldHandling(ContentEntityInterface $node, array $values): void {
    // field_status: map TID directly, bypassing field_permissions.
    // The post() access gate (access open311 advanced properties) is the
    // authoritative guard. field_permissions restricts UI editing but must
    // not block privileged API writes.
    if (isset($values['field_status']) && $node->hasField('field_status')) {
      $node->set('field_status', ['target_id' => (int) $values['field_status']]);
    }

    // Handling of field_status_notes.
    // Access check uses our own permission rather than field_permissions, for
    // the same reason as field_status above.
    if (isset($values['field_status_notes'])) {
      if (
        $node->hasField('field_status_notes') &&
        $this->currentUser->hasPermission('access open311 advanced properties')
      ) {
        // Resolve the effective status TID: prefer the newly set status, fall
        // back to the node's current status if no status change was submitted.
        $status = $values['field_status'] ?? $node->get('field_status')->target_id;
        $paragraph = $this->georeportProcessor->createStatusNoteParagraph([
          'status_term_id' => $status,
          'note' => $values['field_status_notes'],
        ], $node->language()->getId());

        $current = $node->get('field_status_notes')->getValue();
        $current[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
        $node->set('field_status_notes', $current);
      }
    }

    // Handling of revision creation.
    if (isset($values['revision_log_message'])) {
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage($values['revision_log_message']);
      $node->setRevisionCreationTime($this->time->getRequestTime());

      // Attribute the revision to the acting user so the "last edited by"
      // indicator (markaspot-ui#472) reflects the editor instead of an empty
      // author. For anonymous Open311 writes this resolves to uid 0, which is
      // the same author the revision would otherwise carry.
      $node->setRevisionUserId((int) $this->currentUser->id());
    }
  }

  /**
   * Validates and updates the node.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node entity to update.
   * @param array $values
   *   An associative array of field values to update.
   *
   * @return bool
   *   TRUE if the node was successfully validated and updated, FALSE otherwise.
   */
  protected function validateAndUpdateNode(ContentEntityInterface $node, array $values): bool {
    // Implement validation logic.
    $isValid = $this->validate($node);

    if ($isValid) {
      $node->save();
      $this->logger->notice('Updated entity %type with ID %request_id.', [
        '%type' => $node->getEntityTypeId(),
        '%request_id' => $node->request_id->value,
      ]);
      return TRUE;
    }
    else {
      $this->logger->error('Updated entity %type with ID %request_id.', [
        '%type' => $node->getEntityTypeId(),
        '%request_id' => $node->request_id->value,
      ]);
      return FALSE;
    }
  }

  /**
   * Return the service_request_id.
   *
   * @param string $id_param
   *   The first part of service_request_id.format uri.
   *
   * @return string
   *   The Request ID
   */
  public function getRequestId($id_param) {
    $param = explode('.', $id_param);
    $id = $param[0];
    return $id;
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
      $errors = [];
      foreach ($violations as $violation) {
        $fullPath = $violation->getPropertyPath();
        $message = $violation->getMessage();
        $messageText = is_object($message) ? (string) $message : ($message ?? '');

        // Get the invalid value for debugging.
        $invalidValue = $violation->getInvalidValue();
        $valueInfo = is_scalar($invalidValue) ? $invalidValue : gettype($invalidValue);

        $errors[$fullPath] = [
          'message' => $messageText,
          'value' => $valueInfo,
        ];

        $this->logger->error('Node validation error - Path: @fullpath, Message: @message, Value: @value', [
          '@fullpath' => $fullPath,
          '@message' => $messageText,
          '@value' => is_scalar($invalidValue) ? $invalidValue : json_encode($invalidValue),
        ]);
      }

      // Convert errors to a string for the response.
      $detailedMessage = json_encode($errors);
      throw new GeoreportException($detailedMessage, 400);

    }
    else {
      return TRUE;
    }
  }

}
