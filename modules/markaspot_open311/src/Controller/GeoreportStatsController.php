<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\Controller;

use Drupal\group\Entity\GroupMembership;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for GeoReport v2 statistics endpoint.
 *
 * Provides /georeport/v2/stats endpoint with group_filter support.
 * Returns counts of service requests grouped by status taxonomy terms.
 */
class GeoreportStatsController extends ControllerBase {

  use JurisdictionIdResolverTrait;

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
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null
   */
  protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Constructs a GeoreportStatsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null $hierarchy_resolver
   *   The jurisdiction hierarchy resolver (optional).
   */
  public function __construct(
    Connection $database,
    RequestStack $request_stack,
    ?JurisdictionHierarchyResolverInterface $hierarchy_resolver = NULL,
  ) {
    $this->database = $database;
    $this->requestStack = $request_stack;
    $this->hierarchyResolver = $hierarchy_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('request_stack'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL
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
    $jurisdiction_id = $this->resolveJurisdictionIdFromRequest($request);

    // Check if group filtering is requested and user is authenticated.
    $use_group_filter = FALSE;
    $node_ids = [];

    if ($group_filter && !$this->currentUser()->isAnonymous()) {
      $config = $this->config('markaspot_open311.settings');
      $group_filter_enabled = $config->get('group_filter_enabled') ?? FALSE;

      if ($group_filter_enabled) {
        $use_group_filter = TRUE;
        $group_type = $config->get('group_filter_type') ?? 'org';
        $specific_gid = $jurisdiction_id ? (int) $jurisdiction_id : NULL;
        $node_ids = $this->getNodeIdsInUserGroups($group_type, $specific_gid);
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
        WHERE t.vid = 'service_status' AND t.default_langcode = 1
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
        WHERE t.vid = 'service_status' AND t.default_langcode = 1
        GROUP BY t.tid, t.name, h.field_status_hex_color, t.weight
        ORDER BY t.weight ASC
      ");
    }
    elseif ($jurisdiction_id) {
      // No group filter but jurisdiction-scoped via jurisdiction_id parameter.
      // Uses hierarchy resolver to include child jurisdiction nodes.
      $jur_node_ids = $this->getNodeIdsForJurisdiction((int) $jurisdiction_id);
      if (!empty($jur_node_ids)) {
        $placeholders = implode(',', array_fill(0, count($jur_node_ids), '?'));
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
          WHERE t.vid = 'service_status' AND t.default_langcode = 1
          GROUP BY t.tid, t.name, h.field_status_hex_color, t.weight
          ORDER BY t.weight ASC
        ", $jur_node_ids);
      }
      else {
        // No nodes in this group - return zeros.
        $query = $this->database->query("
          SELECT
            t.tid,
            t.name AS status,
            0 AS count,
            h.field_status_hex_color AS color,
            t.weight
          FROM {taxonomy_term_field_data} t
          LEFT JOIN {taxonomy_term__field_status_hex} h ON t.tid = h.entity_id AND h.deleted = 0
          WHERE t.vid = 'service_status' AND t.default_langcode = 1
          GROUP BY t.tid, t.name, h.field_status_hex_color, t.weight
          ORDER BY t.weight ASC
        ");
      }
    }
    else {
      // No group filter, no jurisdiction_id - count all service requests.
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
        WHERE t.vid = 'service_status' AND t.default_langcode = 1
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
   *   The group type machine name (e.g., 'org', 'jur').
   * @param int|null $specific_gid
   *   Optional specific group ID to filter by. When provided, only returns
   *   node IDs from this group (if the user is a member).
   *
   * @return array<int>
   *   Array of node IDs belonging to the user's groups.
   */
  protected function getNodeIdsInUserGroups(string $group_type, ?int $specific_gid = NULL): array {
    if (!$this->moduleHandler()->moduleExists('group')) {
      return [];
    }

    $user = $this->currentUser();
    $memberships = GroupMembership::loadByUser($user);
    $group_ids = [];

    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if ($group && $group->bundle() === $group_type) {
        if ($specific_gid === NULL || (int) $membership->getGroupId() === $specific_gid) {
          $group_ids[] = $membership->getGroupId();
        }
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

    return array_values(array_unique($node_ids));
  }

  /**
   * Gets node IDs that belong to a specific group.
   *
   * This method queries group relationships to find all service_request nodes
   * assigned to a specific group, regardless of user membership.
   *
   * @param int $gid
   *   The group ID to query.
   *
   * @return array<int>
   *   Array of node IDs belonging to the group.
   */
  protected function getNodeIdsInGroup(int $gid): array {
    if (!$this->moduleHandler()->moduleExists('group')) {
      return [];
    }

    $relationship_storage = $this->entityTypeManager()->getStorage('group_relationship');
    $relationship_ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $gid)
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

    return array_values(array_unique($node_ids));
  }

  /**
   * Gets node IDs for a jurisdiction, including child jurisdictions.
   *
   * Uses the hierarchy resolver when available to include nodes from
   * descendant jurisdictions. Falls back to flat single-group query.
   *
   * @param int $gid
   *   The jurisdiction group ID.
   *
   * @return array<int>
   *   Array of node IDs belonging to the jurisdiction subtree.
   */
  protected function getNodeIdsForJurisdiction(int $gid): array {
    if ($this->hierarchyResolver) {
      return $this->hierarchyResolver->getNodeIdsInJurisdiction($gid);
    }
    return $this->getNodeIdsInGroup($gid);
  }

  /**
   * Resolves jurisdiction_id from request with backward compat for 'gid'.
   *
   * Supports both numeric IDs and slugs via JurisdictionIdResolverTrait.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return int|null
   *   The resolved numeric group ID, or NULL if not specified/found.
   */
  private function resolveJurisdictionIdFromRequest(Request $request): ?int {
    $value = $request->query->get('jurisdiction_id')
      ?? $request->query->get('gid');
    if ($request->query->has('gid') && !$request->query->has('jurisdiction_id')) {
      $this->getLogger('markaspot_open311')->notice(
        'Deprecated API parameter "gid" on stats endpoint. Use "jurisdiction_id".'
      );
    }
    return $this->resolveJurisdictionId($value);
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
    $limit = min(100, max(1, (int) ($request->query->get('limit') ?? 10)));
    $jurisdiction_id = $this->resolveJurisdictionIdFromRequest($request);

    // Check if group filtering is requested and user is authenticated.
    $use_group_filter = FALSE;
    $node_ids = [];

    if ($group_filter && !$this->currentUser()->isAnonymous()) {
      $config = $this->config('markaspot_open311.settings');
      $group_filter_enabled = $config->get('group_filter_enabled') ?? FALSE;

      if ($group_filter_enabled) {
        $use_group_filter = TRUE;
        $group_type = $config->get('group_filter_type') ?? 'org';
        $specific_gid = $jurisdiction_id ? (int) $jurisdiction_id : NULL;
        $node_ids = $this->getNodeIdsInUserGroups($group_type, $specific_gid);
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
        WHERE t.vid = 'service_category' AND t.default_langcode = 1
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
    elseif ($jurisdiction_id) {
      // No group filter but jurisdiction-scoped via jurisdiction_id parameter.
      // Uses hierarchy resolver to include child jurisdiction nodes.
      $jur_node_ids = $this->getNodeIdsForJurisdiction((int) $jurisdiction_id);
      if (!empty($jur_node_ids)) {
        $placeholders = implode(',', array_fill(0, count($jur_node_ids), '?'));
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
          WHERE t.vid = 'service_category' AND t.default_langcode = 1
          GROUP BY t.tid, t.name, h.field_category_hex_color, i.field_category_icon_value
          ORDER BY count DESC
          LIMIT $limit
        ", $jur_node_ids);
      }
      else {
        // No nodes in this group - return empty.
        return new JsonResponse([
          'stats' => [],
          'total' => 0,
          'group_filter' => FALSE,
        ]);
      }
    }
    else {
      // No group filter, no jurisdiction_id - count all service requests.
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
        WHERE t.vid = 'service_category' AND t.default_langcode = 1
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
