<?php

namespace Drupal\markaspot_cap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\markaspot_cap\Service\CapProcessorService;
use Drupal\markaspot_cap\Encoder\CapEncoder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for CAP Alert endpoints.
 *
 * Provides CAP 1.2 XML export for service requests.
 */
class CapAlertController extends ControllerBase {

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

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
  protected $capProcessor;

  /**
   * The CAP Encoder.
   *
   * @var \Drupal\markaspot_cap\Encoder\CapEncoder
   */
  protected $capEncoder;

  /**
   * Constructs a CapAlertController object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\markaspot_cap\Service\CapProcessorService $cap_processor
   *   The CAP processor service.
   * @param \Drupal\markaspot_cap\Encoder\CapEncoder $cap_encoder
   *   The CAP encoder.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
    CapProcessorService $cap_processor,
    CapEncoder $cap_encoder
  ) {
    $this->currentUser = $current_user;
    $this->config = $config->get('markaspot_cap.settings');
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->capProcessor = $cap_processor;
    $this->capEncoder = $cap_encoder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_cap.processor'),
      $container->get('markaspot_cap.encoder')
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
   */
  public function index(Request $request): Response {
    $request_time = $this->time->getRequestTime();

    // Get query parameters.
    $parameters = UrlHelper::filterQueryParameters($request->query->all());

    // Create base query.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE);

    // Apply common filters.
    $bundle = $this->config->get('bundle') ?? 'service_request';
    $query->condition('changed', $request_time, '<')
      ->condition('type', $bundle);

    // Only include requests created after emergency mode activation.
    $emergencyConfig = \Drupal::config('markaspot_emergency.settings');
    $activatedAt = $emergencyConfig->get('emergency_mode.activated_at');
    if ($activatedAt) {
      $query->condition('created', $activatedAt, '>=');
    }

    // Handle pagination.
    $limit = isset($parameters['limit']) ? (int) $parameters['limit'] : 100;
    $offset = 0;

    if (isset($parameters['page']) && $parameters['page'] > 0) {
      $page = (int) $parameters['page'];
      $offset = ($page - 1) * $limit;
    }
    elseif (isset($parameters['offset']) && $parameters['offset'] >= 0) {
      $offset = (int) $parameters['offset'];
    }

    // Limit to reasonable size for CAP feed.
    $limit = min($limit, 100);
    $query->range($offset, $limit);

    // Handle date range filters.
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

    // Sort by creation date, newest first.
    $query->sort('created', 'DESC');

    // Handle status filtering.
    if (isset($parameters['status'])) {
      $tids = $this->mapStatusToTaxonomyIds($parameters['status']);
      if (!empty($tids)) {
        $query->condition('field_status', $tids, 'IN');
      }
    }

    // Handle service code filtering.
    if (isset($parameters['service_code'])) {
      $service_codes = explode(',', $parameters['service_code']);
      if (count($service_codes) == 1) {
        $tid = $this->mapServiceCodeToTaxonomy($service_codes[0]);
        if ($tid) {
          $query->condition('field_category', $tid);
        }
      }
      else {
        $categoryTids = [];
        foreach ($service_codes as $service_code) {
          $tid = $this->mapServiceCodeToTaxonomy($service_code);
          if ($tid) {
            $categoryTids[] = $tid;
          }
        }
        if (!empty($categoryTids)) {
          $query->condition('field_category', $categoryTids, 'IN');
        }
      }
    }

    // Execute query.
    $nids = $query->execute();

    // Convert nodes to CAP format.
    $alerts = [];
    if (!empty($nids)) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      foreach ($nodes as $node) {
        $alerts[] = $this->capProcessor->nodeToCapAlert($node);
      }
    }

    // Encode to CAP XML.
    $xml = $this->capEncoder->encode($alerts, 'cap');

    // Return XML response.
    $response = new Response($xml);
    $response->headers->set('Content-Type', 'application/cap+xml; charset=UTF-8');
    return $response;
  }

  /**
   * Returns a single CAP alert for a service request.
   *
   * @param string $id
   *   The Service Request ID.
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
    // Parse ID (remove .cap extension if present).
    $requestId = $this->getRequestId($id);

    // Create query to find the node.
    $bundle = $this->config->get('bundle') ?? 'service_request';
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('request_id', $requestId);

    $nids = $query->execute();

    if (empty($nids)) {
      throw new NotFoundHttpException('Service request not found.');
    }

    $nid = reset($nids);
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      throw new NotFoundHttpException('Service request not found.');
    }

    // Convert to CAP format.
    $capAlert = $this->capProcessor->nodeToCapAlert($node);

    // Encode to CAP XML.
    $xml = $this->capEncoder->encode($capAlert, 'cap');

    // Return XML response.
    $response = new Response($xml);
    $response->headers->set('Content-Type', 'application/cap+xml; charset=UTF-8');
    return $response;
  }

  /**
   * Extract request ID from path parameter.
   *
   * @param string $id_param
   *   The ID parameter from the URL.
   *
   * @return string
   *   The Request ID.
   */
  private function getRequestId(string $id_param): string {
    // Remove .cap extension if present.
    $param = explode('.', $id_param);
    return $param[0];
  }

  /**
   * Map status parameter to taxonomy IDs.
   *
   * @param string $status
   *   The status parameter.
   *
   * @return array
   *   Array of taxonomy term IDs.
   */
  private function mapStatusToTaxonomyIds(string $status): array {
    $statuses = explode(',', $status);
    $tids = [];

    foreach ($statuses as $status_value) {
      // Map Open311 status to Mark-a-Spot taxonomy.
      $status_map = [
        'open' => 'open',
        'closed' => 'closed',
        'in_progress' => 'in_progress',
      ];

      $mapped_status = $status_map[$status_value] ?? $status_value;

      // Load taxonomy term by name.
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => 'status',
          'name' => $mapped_status,
        ]);

      if (!empty($terms)) {
        $term = reset($terms);
        $tids[] = $term->id();
      }
    }

    return $tids;
  }

  /**
   * Map service code to taxonomy term ID.
   *
   * @param string $service_code
   *   The service code.
   *
   * @return int|null
   *   The taxonomy term ID or NULL.
   */
  private function mapServiceCodeToTaxonomy(string $service_code): ?int {
    // Load taxonomy term by machine name or label.
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'category',
        'field_category_id' => $service_code,
      ]);

    if (!empty($terms)) {
      $term = reset($terms);
      return (int) $term->id();
    }

    return NULL;
  }

}
