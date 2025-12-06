<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for GeoReport v2 statistics endpoint.
 *
 * Provides /georeport/v2/stats endpoint with group_filter support.
 * Returns counts of service requests grouped by status taxonomy terms.
 */
class GeoreportStatsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a GeoreportStatsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    Connection $database,
    RequestStack $request_stack,
  ) {
    $this->database = $database;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('request_stack')
    );
  }

  /**
   * Returns statistics by status.
   *
   * Supports ?group_filter=true to filter by current user's group memberships.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status statistics containing:
   *   - stats: array of {tid, status, count, color} per status term
   *   - total: total count of all service requests
   *   - group_filter: boolean indicating if group filter was applied
   */
  public function getStatusStats(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    $group_filter = $request->query->get('group_filter');

    // Check if group filtering is requested and user is authenticated.
    $use_group_filter = FALSE;
    $node_ids = [];

    if ($group_filter && !$this->currentUser()->isAnonymous()) {
      $config = $this->config('markaspot_open311.settings');
      $group_filter_enabled = $config->get('group_filter_enabled') ?? FALSE;

      if ($group_filter_enabled) {
        $use_group_filter = TRUE;
        $group_type = $config->get('group_filter_type') ?? 'organisation';
        $node_ids = $this->getNodeIdsInUserGroups($group_type);
      }
    }

    // Build the query based on filter type.
    if ($use_group_filter && !empty($node_ids)) {
      // Query with group filter - only count nodes in user's groups.
      // Group filtered queries include both published and unpublished nodes.
      $placeholders = implode(',', array_fill(0, count($node_ids), '?'));
      $query = $this->database->query("
        SELECT
          t.tid,
          t.name AS status,
          COUNT(DISTINCT n.nid) AS count,
          h.field_status_hex_color AS color,
          t.weight
        FROM {taxonomy_term_field_data} t
        LEFT JOIN {taxonomy_term__field_status_hex} h ON t.tid = h.entity_id AND h.deleted = 0
        LEFT JOIN {node__field_status} fs ON t.tid = fs.field_status_target_id AND fs.deleted = 0
        LEFT JOIN {node_field_data} n ON fs.entity_id = n.nid AND n.type = 'service_request' AND n.nid IN ($placeholders)
        WHERE t.vid = 'service_status'
        GROUP BY t.tid, t.name, h.field_status_hex_color, t.weight
        ORDER BY t.weight ASC
      ", $node_ids);
    }
    elseif ($use_group_filter && empty($node_ids)) {
      // User has group filter but no group memberships - return zeros.
      $query = $this->database->query("
        SELECT
          t.tid,
          t.name AS status,
          0 AS count,
          h.field_status_hex_color AS color,
          t.weight
        FROM {taxonomy_term_field_data} t
        LEFT JOIN {taxonomy_term__field_status_hex} h ON t.tid = h.entity_id AND h.deleted = 0
        WHERE t.vid = 'service_status'
        GROUP BY t.tid, t.name, h.field_status_hex_color, t.weight
        ORDER BY t.weight ASC
      ");
    }
    else {
      // No group filter - count all service requests (published and unpublished).
      $query = $this->database->query("
        SELECT
          t.tid,
          t.name AS status,
          COUNT(DISTINCT n.nid) AS count,
          h.field_status_hex_color AS color,
          t.weight
        FROM {taxonomy_term_field_data} t
        LEFT JOIN {taxonomy_term__field_status_hex} h ON t.tid = h.entity_id AND h.deleted = 0
        LEFT JOIN {node__field_status} fs ON t.tid = fs.field_status_target_id AND fs.deleted = 0
        LEFT JOIN {node_field_data} n ON fs.entity_id = n.nid AND n.type = 'service_request'
        WHERE t.vid = 'service_status'
        GROUP BY t.tid, t.name, h.field_status_hex_color, t.weight
        ORDER BY t.weight ASC
      ");
    }

    $results = $query->fetchAll();
    $output = [];
    $total = 0;

    foreach ($results as $row) {
      $count = (int) $row->count;
      $total += $count;
      $output[] = [
        'tid' => (int) $row->tid,
        'status' => $row->status,
        'count' => $count,
        'color' => $row->color,
      ];
    }

    $response = new JsonResponse([
      'stats' => $output,
      'total' => $total,
      'group_filter' => $use_group_filter,
    ]);

    // Add cache headers - cache for 3 minutes to match other georeport endpoints.
    $response->setMaxAge(180);
    $response->setSharedMaxAge(180);
    $response->headers->set('X-Cache-Policy', 'public, max-age=180');

    return $response;
  }

  /**
   * Gets node IDs that belong to the user's groups.
   *
   * This method queries the Group module to find all service_request nodes
   * that are related to groups where the current user is a member.
   *
   * @param string $group_type
   *   The group type machine name (e.g., 'organisation', 'juris').
   *
   * @return array<int>
   *   Array of node IDs belonging to the user's groups.
   */
  protected function getNodeIdsInUserGroups(string $group_type): array {
    if (!$this->moduleHandler()->moduleExists('group')) {
      return [];
    }

    $user = $this->currentUser();
    $memberships = \Drupal\group\Entity\GroupMembership::loadByUser($user);
    $group_ids = [];

    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if ($group && $group->bundle() === $group_type) {
        $group_ids[] = $membership->getGroupId();
      }
    }

    if (empty($group_ids)) {
      return [];
    }

    // Query group relationships to get node IDs.
    $relationship_storage = $this->entityTypeManager()->getStorage('group_relationship');
    $relationship_ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group_ids, 'IN')
      ->condition('plugin_id', 'group_node:service_request')
      ->execute();

    if (empty($relationship_ids)) {
      return [];
    }

    $relationships = $relationship_storage->loadMultiple($relationship_ids);
    $node_ids = [];

    foreach ($relationships as $relationship) {
      $node_ids[] = (int) $relationship->get('entity_id')->target_id;
    }

    return array_unique($node_ids);
  }

  /**
   * Returns statistics by category.
   *
   * Supports ?group_filter=true to filter by current user's group memberships.
   * Supports ?limit=N to limit the number of results (default: 10).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with category statistics.
   */
  public function getCategoryStats(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    $group_filter = $request->query->get('group_filter');
    $limit = (int) ($request->query->get('limit') ?? 10);

    // Check if group filtering is requested and user is authenticated.
    $use_group_filter = FALSE;
    $node_ids = [];

    if ($group_filter && !$this->currentUser()->isAnonymous()) {
      $config = $this->config('markaspot_open311.settings');
      $group_filter_enabled = $config->get('group_filter_enabled') ?? FALSE;

      if ($group_filter_enabled) {
        $use_group_filter = TRUE;
        $group_type = $config->get('group_filter_type') ?? 'organisation';
        $node_ids = $this->getNodeIdsInUserGroups($group_type);
      }
    }

    // Build the query based on filter type.
    if ($use_group_filter && !empty($node_ids)) {
      $placeholders = implode(',', array_fill(0, count($node_ids), '?'));
      $query = $this->database->query("
        SELECT
          t.tid,
          t.name AS category,
          COUNT(DISTINCT n.nid) AS count,
          h.field_category_hex_color AS color,
          i.field_category_icon_value AS icon
        FROM {taxonomy_term_field_data} t
        LEFT JOIN {taxonomy_term__field_category_hex} h ON t.tid = h.entity_id AND h.deleted = 0
        LEFT JOIN {taxonomy_term__field_category_icon} i ON t.tid = i.entity_id AND i.deleted = 0
        INNER JOIN {node__field_category} fc ON t.tid = fc.field_category_target_id AND fc.deleted = 0
        INNER JOIN {node_field_data} n ON fc.entity_id = n.nid AND n.type = 'service_request' AND n.nid IN ($placeholders)
        WHERE t.vid = 'service_category'
        GROUP BY t.tid, t.name, h.field_category_hex_color, i.field_category_icon_value
        ORDER BY count DESC
        LIMIT $limit
      ", $node_ids);
    }
    elseif ($use_group_filter && empty($node_ids)) {
      // User has group filter but no group memberships - return empty.
      return new JsonResponse([
        'stats' => [],
        'total' => 0,
        'group_filter' => TRUE,
      ]);
    }
    else {
      // No group filter - count all service requests.
      $query = $this->database->query("
        SELECT
          t.tid,
          t.name AS category,
          COUNT(DISTINCT n.nid) AS count,
          h.field_category_hex_color AS color,
          i.field_category_icon_value AS icon
        FROM {taxonomy_term_field_data} t
        LEFT JOIN {taxonomy_term__field_category_hex} h ON t.tid = h.entity_id AND h.deleted = 0
        LEFT JOIN {taxonomy_term__field_category_icon} i ON t.tid = i.entity_id AND i.deleted = 0
        INNER JOIN {node__field_category} fc ON t.tid = fc.field_category_target_id AND fc.deleted = 0
        INNER JOIN {node_field_data} n ON fc.entity_id = n.nid AND n.type = 'service_request'
        WHERE t.vid = 'service_category'
        GROUP BY t.tid, t.name, h.field_category_hex_color, i.field_category_icon_value
        ORDER BY count DESC
        LIMIT $limit
      ");
    }

    $results = $query->fetchAll();
    $output = [];
    $total = 0;

    foreach ($results as $row) {
      $count = (int) $row->count;
      $total += $count;
      $output[] = [
        'tid' => (int) $row->tid,
        'category' => $row->category,
        'count' => $count,
        'color' => $row->color,
        'icon' => $row->icon,
      ];
    }

    $response = new JsonResponse([
      'stats' => $output,
      'total' => $total,
      'group_filter' => $use_group_filter,
    ]);

    $response->setMaxAge(180);
    $response->setSharedMaxAge(180);

    return $response;
  }

}
