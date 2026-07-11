<?php

namespace Drupal\markaspot_cap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\markaspot_cap\Service\CapProcessorService;
use Drupal\markaspot_cap\Service\CapFeedMutationTracker;
use Drupal\markaspot_cap\Encoder\CapEncoder;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for CAP Alert endpoints.
 *
 * Provides CAP 1.2 XML export for service requests.
 * Only available when emergency mode is active (gated by CapFormatSubscriber).
 *
 * Emergency state is scoped to the resolved root jurisdiction. Feed data keeps
 * the concrete requested jurisdiction scope, including its descendants.
 */
class CapAlertController extends ControllerBase {

  /**
   * Maximum number of alerts per request.
   */
  const MAX_LIMIT = 100;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The markaspot_cap.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The CAP Processor.
   *
   * @var \Drupal\markaspot_cap\Service\CapProcessorService
   */
  protected CapProcessorService $capProcessor;

  /**
   * The CAP Encoder.
   *
   * @var \Drupal\markaspot_cap\Encoder\CapEncoder
   */
  protected CapEncoder $capEncoder;

  /**
   * Jurisdiction-scoped emergency mode service.
   *
   * @var \Drupal\markaspot_emergency\Service\EmergencyModeService
   */
  protected EmergencyModeService $emergencyService;

  /**
   * Canonical jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Runtime readiness gate for the staff approval field.
   */
  protected StateInterface $state;

  /**
   * Constructs a CapAlertController object.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    CapProcessorService $cap_processor,
    CapEncoder $cap_encoder,
    EmergencyModeService $emergency_service,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
    StateInterface $state,
  ) {
    $this->currentUser = $current_user;
    // Store the factory on the ControllerBase-inherited $configFactory property
    // so system.site can be read for the Atom feed ID.
    $this->configFactory = $config;
    $this->config = $config->get('markaspot_cap.settings');
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->capProcessor = $cap_processor;
    $this->capEncoder = $cap_encoder;
    $this->emergencyService = $emergency_service;
    $this->hierarchyResolver = $hierarchy_resolver;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('markaspot_cap.processor'),
      $container->get('markaspot_cap.encoder'),
      $container->get('markaspot_emergency.service'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('state'),
    );
  }

  /**
   * Returns a list of CAP alerts for service requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The CAP XML response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When jurisdiction_id is required but missing.
   */
  public function index(Request $request): Response {
    $requestTime = $this->time->getRequestTime();
    $parameters = $this->validateQueryParameters(
      UrlHelper::filterQueryParameters($request->query->all()),
    );

    $bundle = $this->config->get('bundle') ?? 'service_request';

    $context = $this->resolveEmergencyContext($parameters);
    $modeState = $this->getActiveModeState($context['root_id']);

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('changed', $requestTime, '<=')
      ->condition('type', $bundle);

    $this->applyNodeJurisdictionScope($query, $bundle, $context['requested_id']);
    $this->applyCapEligibilityScope($query, $bundle, $context['root_id']);

    // Only include requests created after emergency mode activation.
    $query->condition('created', $modeState['activated_at'], '>=');

    // Clamp limit: min 1, max MAX_LIMIT (B5).
    $limit = isset($parameters['limit']) ? max(1, min((int) $parameters['limit'], self::MAX_LIMIT)) : self::MAX_LIMIT;

    $offset = 0;
    if (isset($parameters['page']) && $parameters['page'] > 0) {
      $offset = ((int) $parameters['page'] - 1) * $limit;
    }
    elseif (isset($parameters['offset']) && $parameters['offset'] >= 0) {
      $offset = (int) $parameters['offset'];
    }
    $query->range($offset, $limit);

    // Date range filters.
    if (!empty($parameters['start_date'])) {
      $ts = strtotime($parameters['start_date']);
      if ($ts !== FALSE) {
        $query->condition('created', $ts, '>=');
      }
    }
    if (!empty($parameters['end_date'])) {
      $ts = strtotime($parameters['end_date']);
      if ($ts !== FALSE) {
        $query->condition('created', $ts, '<=');
      }
    }

    $query->sort('created', 'DESC');

    // Status filter: use real vocabulary vid 'service_status' (B5).
    if (!empty($parameters['status'])) {
      $tids = $this->mapStatusToTaxonomyIds($parameters['status']);
      if (!empty($tids)) {
        $query->condition('field_status', $tids, 'IN');
      }
      else {
        $query->condition('field_status', [0], 'IN');
      }
    }

    // Service code filter: use real vocabulary vid 'service_category'
    // and real field 'field_service_code' (B5).
    if (!empty($parameters['service_code'])) {
      $serviceCodes = explode(',', $parameters['service_code']);
      $categoryTids = [];
      foreach ($serviceCodes as $code) {
        $code = trim($code);
        if ($code === '') {
          continue;
        }
        $categoryTids = array_merge(
          $categoryTids,
          $this->mapServiceCodeToTaxonomyIds($code, $context['root_id']),
        );
      }
      $categoryTids = array_values(array_unique($categoryTids));
      if (!empty($categoryTids)) {
        $query->condition('field_category', $categoryTids, 'IN');
      }
      else {
        $query->condition('field_category', [0], 'IN');
      }
    }

    $nids = $query->execute();

    $alerts = [];
    if (!empty($nids)) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      foreach ($nodes as $node) {
        $alerts[] = $this->capProcessor->nodeToCapAlert($node);
      }
    }

    $siteConfig = $this->configFactory->get('system.site');
    $siteUuid = (string) ($siteConfig->get('uuid') ?? '');
    $siteName = trim((string) ($siteConfig->get('name') ?? ''));
    $feedUpdated = max(
      (int) ($modeState['changed_at'] ?? $modeState['activated_at']),
      (int) $this->state->get(CapFeedMutationTracker::stateKey($context['root_id']), 0),
    );
    $encodeContext = [
      'cap_feed_author' => $siteName !== '' ? $siteName : 'Mark-a-Spot',
      'cap_feed_updated' => gmdate('Y-m-d\TH:i:s\Z', $feedUpdated),
    ];
    if ($siteUuid !== '') {
      $encodeContext['cap_feed_id'] = sprintf(
        'urn:markaspot:cap:feed:%s:root-%d:scope-%d',
        $siteUuid,
        $context['root_id'],
        $context['requested_id'],
      );
    }

    $xml = $this->capEncoder->encode($alerts, 'cap', $encodeContext);

    $response = new Response($xml);
    $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');
    // PII may be present in the feed; prevent HTTP-layer caching (B1).
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Returns a single CAP alert for a service request.
   *
   * @param string $id
   *   The Service Request ID (may carry a .cap extension).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The CAP XML response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the service request is not found.
   */
  public function show(string $id, Request $request): Response {
    $requestId = $this->getRequestId($id);

    $bundle = $this->config->get('bundle') ?? 'service_request';
    $parameters = $this->validateQueryParameters(
      UrlHelper::filterQueryParameters($request->query->all()),
    );
    $context = $this->resolveEmergencyContext($parameters);
    $modeState = $this->getActiveModeState($context['root_id']);

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('request_id', $requestId);

    $this->applyNodeJurisdictionScope($query, $bundle, $context['requested_id']);
    $this->applyCapEligibilityScope($query, $bundle, $context['root_id']);

    $query->condition('created', $modeState['activated_at'], '>=');

    $nids = $query->execute();

    if (empty($nids)) {
      throw new NotFoundHttpException('Service request not found.');
    }

    $node = $this->entityTypeManager->getStorage('node')->load(reset($nids));
    if (!$node) {
      throw new NotFoundHttpException('Service request not found.');
    }

    $capAlert = $this->capProcessor->nodeToCapAlert($node);
    $xml = $this->capEncoder->encode($capAlert, 'cap');

    $response = new Response($xml);
    $response->headers->set('Content-Type', 'application/cap+xml; charset=UTF-8');
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Resolves requested and root jurisdiction through the canonical service.
   *
   * @return array{requested_id: int, root_id: int}
   *   The concrete request scope and emergency-state root scope.
   */
  private function resolveEmergencyContext(array $parameters): array {
    $identifier = $parameters['jurisdiction_id'] ?? NULL;
    if ($identifier !== NULL && !is_string($identifier) && !is_int($identifier)) {
      throw new BadRequestHttpException('The jurisdiction_id parameter must be a scalar ID or slug.');
    }
    try {
      return $this->emergencyService->resolveJurisdictionContext($identifier);
    }
    catch (\InvalidArgumentException $exception) {
      throw new BadRequestHttpException($exception->getMessage(), $exception);
    }
  }

  /**
   * Validates and normalizes public CAP query values before typed operations.
   *
   * Symfony represents repeated bracket parameters as arrays. Passing those
   * into strtotime(), explode(), or integer casts can otherwise produce a
   * public TypeError/500 response.
   *
   * @return array<string, mixed>
   *   Query parameters with supported scalar values normalized to strings.
   */
  private function validateQueryParameters(array $parameters): array {
    $maxLengths = [
      'jurisdiction_id' => 128,
      'limit' => 10,
      'page' => 10,
      'offset' => 10,
      'start_date' => 64,
      'end_date' => 64,
      'status' => 256,
      'service_code' => 256,
    ];
    foreach ($maxLengths as $key => $maxLength) {
      if (!array_key_exists($key, $parameters)) {
        continue;
      }
      $value = $parameters[$key];
      if (!is_string($value) && !is_int($value)) {
        throw new BadRequestHttpException(sprintf('The %s parameter must be a scalar value.', $key));
      }
      $normalized = (string) $value;
      if (strlen($normalized) > $maxLength) {
        throw new BadRequestHttpException(sprintf('The %s parameter is too long.', $key));
      }
      if (in_array($key, ['limit', 'page', 'offset'], TRUE)
        && !preg_match('/^\d+$/', $normalized)) {
        throw new BadRequestHttpException(sprintf('The %s parameter must be a non-negative integer.', $key));
      }
      $numericMaximums = [
        'limit' => self::MAX_LIMIT,
        'page' => 10_000,
        'offset' => 1_000_000,
      ];
      if (isset($numericMaximums[$key]) && (int) $normalized > $numericMaximums[$key]) {
        throw new BadRequestHttpException(sprintf('The %s parameter exceeds the supported maximum.', $key));
      }
      if (in_array($key, ['status', 'service_code'], TRUE)) {
        $values = array_filter(array_map('trim', explode(',', $normalized)), 'strlen');
        if (count($values) > 20
          || array_filter($values, static fn(string $item): bool => strlen($item) > 64) !== []) {
          throw new BadRequestHttpException(sprintf('The %s filter contains too many or oversized values.', $key));
        }
        $normalized = implode(',', $values);
      }
      $parameters[$key] = $normalized;
    }
    return $parameters;
  }

  /**
   * Applies the requested jurisdiction subtree to a node query.
   */
  private function applyNodeJurisdictionScope(QueryInterface $query, string $bundle, int $requestedId): void {
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    if (!isset($fields['field_jurisdiction'])) {
      if ($requestedId > 0) {
        $query->condition('nid', [0], 'IN');
      }
      return;
    }

    // Scope 0 is only valid on legacy installs without a jurisdiction field.
    if ($requestedId <= 0) {
      $query->condition('nid', [0], 'IN');
      return;
    }

    $nodeIds = array_values(array_unique(array_map(
      'intval',
      $this->hierarchyResolver->getNodeIdsInJurisdiction($requestedId),
    )));
    $query->condition('nid', $nodeIds !== [] ? $nodeIds : [0], 'IN');
  }

  /**
   * Requires explicit staff approval and a category in the live root catalog.
   */
  private function applyCapEligibilityScope(QueryInterface $query, string $bundle, int $rootId): void {
    if ($this->state->get('markaspot_cap.approval_field_ready') !== TRUE) {
      $query->condition('nid', [0], 'IN');
      return;
    }

    $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    if (!isset($fields['field_cap_publish'], $fields['field_category'])) {
      $query->condition('nid', [0], 'IN');
      return;
    }

    $query->condition('field_cap_publish', 1);
    $categoryIds = array_values(array_map(
      static fn(TermInterface $term): int => (int) $term->id(),
      $this->emergencyService->getAvailableCategoryTerms($rootId),
    ));
    $query->condition('field_category', $categoryIds !== [] ? $categoryIds : [0], 'IN');
  }

  /**
   * Reads one active mode snapshot or refuses to expose CAP data.
   */
  private function getActiveModeState(int $rootId): array {
    $state = $this->emergencyService->getModeState($rootId);
    if ($state['status'] !== 'active' || $state['activated_at'] === NULL) {
      throw new NotFoundHttpException('CAP feed is unavailable outside an active emergency revision.');
    }
    return $state;
  }

  /**
   * Extract request ID from path parameter (removes .cap extension if present).
   */
  private function getRequestId(string $id_param): string {
    $param = explode('.', $id_param);
    return $param[0];
  }

  /**
   * Map status parameter value(s) to taxonomy term IDs.
   *
   * Uses the real vocabulary vid 'service_status' (B5).
   *
   * @param string $status
   *   Comma-separated status values.
   *
   * @return array
   *   Array of taxonomy term IDs.
   */
  private function mapStatusToTaxonomyIds(string $status): array {
    $statuses = explode(',', $status);
    $tids = [];

    foreach ($statuses as $statusValue) {
      $statusValue = trim($statusValue);
      if ($statusValue === '') {
        continue;
      }

      $terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => 'service_status',
          'name' => $statusValue,
        ]);

      if (!empty($terms)) {
        $term = reset($terms);
        $tids[] = (int) $term->id();
      }
    }

    return $tids;
  }

  /**
   * Map service code to taxonomy term ID.
   *
   * Uses the real vocabulary vid 'service_category' and the real field
   * 'field_service_code' (B5). Guards for field existence.
   *
   * @param string $serviceCode
   *   The service code value.
   * @param int $rootJurisdictionId
   *   The emergency-state root jurisdiction ID.
   *
   * @return int[]
   *   Matching taxonomy term IDs inside the selected root tree.
   */
  private function mapServiceCodeToTaxonomyIds(string $serviceCode, int $rootJurisdictionId): array {
    // Guard: only query field_service_code if it actually exists on the bundle.
    $fields = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'service_category');
    if (!isset($fields['field_service_code'])) {
      return [];
    }

    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'service_category')
      ->condition('field_service_code', $serviceCode)
      ->accessCheck(FALSE);
    if ($rootJurisdictionId > 0) {
      if (!isset($fields['field_jurisdiction'])) {
        return [];
      }
      $jurisdictionIds = $this->hierarchyResolver->getTermJurisdictionIds($rootJurisdictionId);
      if ($jurisdictionIds === []) {
        return [];
      }
      $query->condition('field_jurisdiction', $jurisdictionIds, 'IN');
    }
    return array_values(array_map('intval', $query->execute()));
  }

}
