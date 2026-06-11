<?php

namespace Drupal\markaspot_cap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\markaspot_cap\Service\CapProcessorService;
use Drupal\markaspot_cap\Encoder\CapEncoder;
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
 * Jurisdiction scoping: when field_jurisdiction exists on service_request
 * nodes, the ?jurisdiction_id parameter is required (400 otherwise) and used
 * as a query condition. On single-tenant installs without that field the
 * parameter is accepted but silently ignored.
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
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
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
      $container->get('state')
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
    $parameters = UrlHelper::filterQueryParameters($request->query->all());

    $bundle = $this->config->get('bundle') ?? 'service_request';

    // Resolve jurisdiction scoping.
    $jurisdictionId = $this->resolveJurisdictionId($parameters, $bundle);

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('changed', $requestTime, '<')
      ->condition('type', $bundle);

    // Apply jurisdiction scope when the field exists.
    if ($jurisdictionId !== NULL) {
      $query->condition('field_jurisdiction', $jurisdictionId);
    }

    // Only include requests created after emergency mode activation.
    $activatedAt = $this->state->get('markaspot_emergency.activated_at');
    if ($activatedAt) {
      $query->condition('created', $activatedAt, '>=');
    }

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
    }

    // Service code filter: use real vocabulary vid 'service_category'
    // and real field 'field_service_code' (B5).
    if (!empty($parameters['service_code'])) {
      $serviceCodes = explode(',', $parameters['service_code']);
      $categoryTids = [];
      foreach ($serviceCodes as $code) {
        $tid = $this->mapServiceCodeToTaxonomy(trim($code));
        if ($tid) {
          $categoryTids[] = $tid;
        }
      }
      if (!empty($categoryTids)) {
        $query->condition('field_category', $categoryTids, 'IN');
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

    $siteUuid = (string) ($this->configFactory->get('system.site')->get('uuid') ?? '');
    $encodeContext = $siteUuid
      ? ['cap_feed_id' => 'urn:markaspot:cap:feed:' . $siteUuid]
      : [];

    $xml = $this->capEncoder->encode($alerts, 'cap', $encodeContext);

    $response = new Response($xml);
    $response->headers->set('Content-Type', 'application/cap+xml; charset=UTF-8');
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
    $parameters = UrlHelper::filterQueryParameters($request->query->all());
    $jurisdictionId = $this->resolveJurisdictionId($parameters, $bundle);

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('request_id', $requestId);

    if ($jurisdictionId !== NULL) {
      $query->condition('field_jurisdiction', $jurisdictionId);
    }

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
   * Resolves the jurisdiction ID for the request.
   *
   * When the bundle has a field_jurisdiction field, the parameter is
   * required -- a 400 is thrown if absent. On bundles without the field
   * the parameter is accepted but NULL is returned (field ignored).
   *
   * @param array $parameters
   *   Filtered query parameters.
   * @param string $bundle
   *   The node bundle to check.
   *
   * @return int|null
   *   The jurisdiction ID, or NULL when not applicable.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the field exists and the parameter is missing.
   */
  protected function resolveJurisdictionId(array $parameters, string $bundle): ?int {
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    $hasField = isset($fields['field_jurisdiction']);

    if (!$hasField) {
      return NULL;
    }

    if (empty($parameters['jurisdiction_id'])) {
      throw new BadRequestHttpException('The jurisdiction_id parameter is required for multi-tenant installations.');
    }

    return (int) $parameters['jurisdiction_id'];
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
   *
   * @return int|null
   *   The taxonomy term ID or NULL.
   */
  private function mapServiceCodeToTaxonomy(string $serviceCode): ?int {
    // Guard: only query field_service_code if it actually exists on the bundle.
    $fields = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'service_category');
    if (!isset($fields['field_service_code'])) {
      return NULL;
    }

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'service_category',
        'field_service_code' => $serviceCode,
      ]);

    if (!empty($terms)) {
      $term = reset($terms);
      return (int) $term->id();
    }

    return NULL;
  }

}
