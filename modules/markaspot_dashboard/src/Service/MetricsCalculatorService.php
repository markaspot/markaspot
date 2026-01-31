<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for calculating dashboard KPI metrics.
 *
 * Provides real-time SQL-based calculations for:
 * - Forwarding rate (organization changes between revisions)
 * - First-Contact-Resolution (FCR) rate
 * - Average processing time
 * - Status distribution
 */
class MetricsCalculatorService {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a MetricsCalculatorService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('markaspot_dashboard');
  }

  /**
   * Calculate all KPI metrics.
   *
   * @param array $filters
   *   Filter parameters:
   *   - start_date: Start date (UNIX timestamp or Y-m-d format)
   *   - end_date: End date (UNIX timestamp or Y-m-d format)
   *   - jurisdiction_id: Filter by jurisdiction group ID
   *   - organization_id: Filter by organization group ID
   *   - category_id: Filter by category taxonomy term ID
   *
   * @return array
   *   Array containing all KPI metrics.
   */
  public function calculateAllKpis(array $filters = []): array {
    $node_ids = $this->getFilteredNodeIds($filters);

    return [
      'forwarding_rate' => $this->calculateForwardingRate($node_ids),
      'fcr_rate' => $this->calculateFcrRate($node_ids),
      'avg_processing_time' => $this->calculateAvgProcessingTime($node_ids),
      'status_distribution' => $this->getStatusDistribution($node_ids),
      'total_requests' => count($node_ids),
      'filters_applied' => $this->getAppliedFiltersInfo($filters),
    ];
  }

  /**
   * Get filtered node IDs based on provided filters.
   *
   * @param array $filters
   *   Filter parameters.
   *
   * @return array
   *   Array of node IDs.
   */
  protected function getFilteredNodeIds(array $filters): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.type', 'service_request');

    // Date filters.
    if (!empty($filters['start_date'])) {
      $start_timestamp = $this->parseDate($filters['start_date']);
      if ($start_timestamp) {
        $query->condition('n.created', $start_timestamp, '>=');
      }
    }

    if (!empty($filters['end_date'])) {
      $end_timestamp = $this->parseDate($filters['end_date']);
      if ($end_timestamp) {
        // Add end of day (23:59:59).
        $query->condition('n.created', $end_timestamp + 86399, '<=');
      }
    }

    // Category filter.
    if (!empty($filters['category_id'])) {
      $query->innerJoin('node__field_category', 'fc', 'n.nid = fc.entity_id AND fc.deleted = 0');
      $query->condition('fc.field_category_target_id', $filters['category_id']);
    }

    // Status filter.
    if (!empty($filters['status_id'])) {
      $query->innerJoin('node__field_status', 'fs', 'n.nid = fs.entity_id AND fs.deleted = 0');
      $query->condition('fs.field_status_target_id', $filters['status_id']);
    }

    // Jurisdiction filter (via group_relationship).
    if (!empty($filters['jurisdiction_id'])) {
      $query->innerJoin('group_relationship_field_data', 'gr', "n.nid = gr.entity_id AND gr.plugin_id = 'group_node:service_request'");
      $query->innerJoin('groups_field_data', 'g', 'gr.gid = g.id');
      $query->condition('g.type', 'jur');
      $query->condition('g.id', $filters['jurisdiction_id']);
    }

    // Organization filter.
    if (!empty($filters['organization_id'])) {
      $query->innerJoin('node__field_organisation', 'fo', 'n.nid = fo.entity_id AND fo.deleted = 0');
      $query->condition('fo.field_organisation_target_id', $filters['organization_id']);
    }

    $result = $query->execute()->fetchCol();
    return array_map('intval', $result);
  }

  /**
   * Calculate forwarding rate.
   *
   * Forwarding is detected when field_organisation changes between revisions.
   *
   * @param array $node_ids
   *   Array of node IDs to analyze.
   *
   * @return array
   *   Forwarding statistics.
   */
  public function calculateForwardingRate(array $node_ids): array {
    if (empty($node_ids)) {
      return [
        'forwarded_count' => 0,
        'total_count' => 0,
        'rate' => 0.0,
      ];
    }

    $placeholders = implode(',', array_fill(0, count($node_ids), '?'));

    // Count nodes where organisation changed between revisions.
    // We compare each revision with the previous one and check if organisation differs.
    $forwarded_query = $this->database->query("
      SELECT COUNT(DISTINCT nfo.entity_id) as forwarded_count
      FROM {node_revision__field_organisation} nfo
      INNER JOIN {node_revision} nr ON nfo.revision_id = nr.vid
      WHERE nfo.entity_id IN ($placeholders)
        AND nfo.deleted = 0
        AND EXISTS (
          SELECT 1
          FROM {node_revision__field_organisation} nfo2
          INNER JOIN {node_revision} nr2 ON nfo2.revision_id = nr2.vid
          WHERE nfo2.entity_id = nfo.entity_id
            AND nfo2.deleted = 0
            AND nr2.vid < nr.vid
            AND nfo2.field_organisation_target_id != nfo.field_organisation_target_id
        )
    ", $node_ids);

    $forwarded_count = (int) $forwarded_query->fetchField();
    $total_count = count($node_ids);
    $rate = $total_count > 0 ? round(($forwarded_count / $total_count) * 100, 2) : 0.0;

    return [
      'forwarded_count' => $forwarded_count,
      'total_count' => $total_count,
      'rate' => $rate,
    ];
  }

  /**
   * Calculate First-Contact-Resolution (FCR) rate.
   *
   * FCR criteria:
   * - Node has exactly 2 paragraphs in field_status_notes (Open -> Closed)
   * - No organisation change between revisions
   *
   * @param array $node_ids
   *   Array of node IDs to analyze.
   *
   * @return array
   *   FCR statistics.
   */
  public function calculateFcrRate(array $node_ids): array {
    if (empty($node_ids)) {
      return [
        'fcr_count' => 0,
        'eligible_count' => 0,
        'rate' => 0.0,
      ];
    }

    $placeholders = implode(',', array_fill(0, count($node_ids), '?'));

    // Get the "Closed" status term ID.
    $closed_tid = $this->getClosedStatusTid();
    if (!$closed_tid) {
      $this->logger->warning('Closed status term not found. FCR calculation may be incorrect.');
      return [
        'fcr_count' => 0,
        'eligible_count' => count($node_ids),
        'rate' => 0.0,
        'error' => 'Closed status term not found',
      ];
    }

    // Find nodes that:
    // 1. Have exactly 2 status_notes paragraphs
    // 2. Are currently closed (have a paragraph with closed status)
    // 3. Have no organisation changes.
    $fcr_query = $this->database->query("
      SELECT n.nid
      FROM {node_field_data} n
      -- Count status_notes paragraphs
      INNER JOIN (
        SELECT entity_id, COUNT(*) as paragraph_count
        FROM {node__field_status_notes}
        WHERE deleted = 0
        GROUP BY entity_id
        HAVING COUNT(*) = 2
      ) sn_count ON n.nid = sn_count.entity_id
      -- Check for closed status paragraph
      INNER JOIN {node__field_status_notes} fsn ON n.nid = fsn.entity_id AND fsn.deleted = 0
      INNER JOIN {paragraph__field_status_term} pst ON fsn.field_status_notes_target_id = pst.entity_id AND pst.deleted = 0
      WHERE n.nid IN ($placeholders)
        AND n.type = 'service_request'
        AND pst.field_status_term_target_id = ?
        -- No organisation change check
        AND NOT EXISTS (
          SELECT 1
          FROM {node_revision__field_organisation} nfo1
          INNER JOIN {node_revision} nr1 ON nfo1.revision_id = nr1.vid
          INNER JOIN {node_revision__field_organisation} nfo2 ON nfo2.entity_id = nfo1.entity_id
          INNER JOIN {node_revision} nr2 ON nfo2.revision_id = nr2.vid
          WHERE nfo1.entity_id = n.nid
            AND nfo1.deleted = 0
            AND nfo2.deleted = 0
            AND nr2.vid > nr1.vid
            AND nfo2.field_organisation_target_id != nfo1.field_organisation_target_id
        )
      GROUP BY n.nid
    ", array_merge($node_ids, [$closed_tid]));

    $fcr_nodes = $fcr_query->fetchCol();
    $fcr_count = count($fcr_nodes);

    // Eligible are closed requests only.
    $eligible_query = $this->database->query("
      SELECT COUNT(DISTINCT n.nid) as count
      FROM {node_field_data} n
      INNER JOIN {node__field_status} fs ON n.nid = fs.entity_id AND fs.deleted = 0
      WHERE n.nid IN ($placeholders)
        AND n.type = 'service_request'
        AND fs.field_status_target_id = ?
    ", array_merge($node_ids, [$closed_tid]));

    $eligible_count = (int) $eligible_query->fetchField();
    $rate = $eligible_count > 0 ? round(($fcr_count / $eligible_count) * 100, 2) : 0.0;

    return [
      'fcr_count' => $fcr_count,
      'eligible_count' => $eligible_count,
      'rate' => $rate,
    ];
  }

  /**
   * Calculate average processing time.
   *
   * Processing time = time between first paragraph created and last paragraph
   * with "closed" status.
   *
   * @param array $node_ids
   *   Array of node IDs to analyze.
   *
   * @return array
   *   Processing time statistics.
   */
  public function calculateAvgProcessingTime(array $node_ids): array {
    if (empty($node_ids)) {
      return [
        'avg_seconds' => 0,
        'avg_hours' => 0.0,
        'avg_days' => 0.0,
        'median_seconds' => 0,
        'median_hours' => 0.0,
        'median_days' => 0.0,
        'closed_count' => 0,
      ];
    }

    $placeholders = implode(',', array_fill(0, count($node_ids), '?'));
    $closed_tid = $this->getClosedStatusTid();

    if (!$closed_tid) {
      return [
        'avg_seconds' => 0,
        'avg_hours' => 0.0,
        'avg_days' => 0.0,
        'median_seconds' => 0,
        'median_hours' => 0.0,
        'median_days' => 0.0,
        'closed_count' => 0,
        'error' => 'Closed status term not found',
      ];
    }

    // Calculate processing time for each closed request.
    // First paragraph created -> Last paragraph with closed status.
    $query = $this->database->query("
      SELECT
        n.nid,
        MIN(p_first.created) as first_created,
        MAX(p_closed.created) as closed_created
      FROM {node_field_data} n
      -- Get first paragraph created time
      INNER JOIN {node__field_status_notes} fsn_first ON n.nid = fsn_first.entity_id AND fsn_first.deleted = 0
      INNER JOIN {paragraphs_item_field_data} p_first ON fsn_first.field_status_notes_target_id = p_first.id
      -- Get closed paragraph time
      INNER JOIN {node__field_status_notes} fsn_closed ON n.nid = fsn_closed.entity_id AND fsn_closed.deleted = 0
      INNER JOIN {paragraphs_item_field_data} p_closed ON fsn_closed.field_status_notes_target_id = p_closed.id
      INNER JOIN {paragraph__field_status_term} pst ON p_closed.id = pst.entity_id AND pst.deleted = 0
      WHERE n.nid IN ($placeholders)
        AND n.type = 'service_request'
        AND pst.field_status_term_target_id = ?
      GROUP BY n.nid
      HAVING MIN(p_first.created) IS NOT NULL AND MAX(p_closed.created) IS NOT NULL
    ", array_merge($node_ids, [$closed_tid]));

    $results = $query->fetchAll();
    $total_time = 0;
    $count = 0;
    $processing_times = [];

    foreach ($results as $row) {
      if ($row->closed_created > $row->first_created) {
        $time_diff = $row->closed_created - $row->first_created;
        $total_time += $time_diff;
        $processing_times[] = $time_diff;
        $count++;
      }
    }

    $avg_seconds = $count > 0 ? (int) ($total_time / $count) : 0;
    $avg_hours = $count > 0 ? round($total_time / $count / 3600, 2) : 0.0;
    $avg_days = $count > 0 ? round($total_time / $count / 86400, 2) : 0.0;

    // Calculate median.
    $median_seconds = (int) $this->calculateMedian($processing_times);
    $median_hours = $count > 0 ? round($median_seconds / 3600, 2) : 0.0;
    $median_days = $count > 0 ? round($median_seconds / 86400, 2) : 0.0;

    return [
      'avg_seconds' => $avg_seconds,
      'avg_hours' => $avg_hours,
      'avg_days' => $avg_days,
      'median_seconds' => $median_seconds,
      'median_hours' => $median_hours,
      'median_days' => $median_days,
      'closed_count' => $count,
    ];
  }

  /**
   * Get status distribution for filtered nodes.
   *
   * @param array $node_ids
   *   Array of node IDs to analyze.
   *
   * @return array
   *   Array of status counts with colors.
   */
  public function getStatusDistribution(array $node_ids): array {
    if (empty($node_ids)) {
      // Return all statuses with zero counts.
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

      $results = $query->fetchAll();
      $output = [];
      foreach ($results as $row) {
        $output[] = [
          'tid' => (int) $row->tid,
          'status' => $row->status,
          'count' => 0,
          'color' => $row->color,
        ];
      }
      return $output;
    }

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

    $results = $query->fetchAll();
    $output = [];

    foreach ($results as $row) {
      $output[] = [
        'tid' => (int) $row->tid,
        'status' => $row->status,
        'count' => (int) $row->count,
        'color' => $row->color,
      ];
    }

    return $output;
  }

  /**
   * Get the taxonomy term IDs for "Closed" status from Open311 configuration.
   *
   * @return array
   *   Array of closed status term IDs.
   */
  protected function getClosedStatusTids(): array {
    $config = $this->configFactory->get('markaspot_open311.settings');
    $closed_status = $config->get('status_closed');

    if (empty($closed_status)) {
      $this->logger->warning('No closed status configured in markaspot_open311.settings');
      return [];
    }

    // Handle both array and single value configurations.
    // Use array_values() to ensure numeric keys for consistent array access.
    return is_array($closed_status) ? array_values(array_map('intval', $closed_status)) : [(int) $closed_status];
  }

  /**
   * Get the first taxonomy term ID for "Closed" status.
   *
   * @return int|null
   *   The term ID or NULL if not found.
   *
   * @deprecated Use getClosedStatusTids() for multiple closed statuses.
   */
  protected function getClosedStatusTid(): ?int {
    $tids = $this->getClosedStatusTids();
    return !empty($tids) ? $tids[0] : NULL;
  }

  /**
   * Parse date string to UNIX timestamp.
   *
   * @param string|int $date
   *   Date string (Y-m-d) or UNIX timestamp.
   *
   * @return int|null
   *   UNIX timestamp or NULL on failure.
   */
  protected function parseDate(string|int $date): ?int {
    if (is_numeric($date)) {
      return (int) $date;
    }

    $timestamp = strtotime($date);
    return $timestamp !== FALSE ? $timestamp : NULL;
  }

  /**
   * Calculate time series volume data (created/closed counts over time).
   *
   * @param array $filters
   *   Filter parameters:
   *   - start_date: Start date (UNIX timestamp or Y-m-d format)
   *   - end_date: End date (UNIX timestamp or Y-m-d format)
   *   - granularity: Time grouping (day|week|month)
   *   - jurisdiction_id: Filter by jurisdiction group ID
   *   - category_id: Filter by category taxonomy term ID
   *
   * @return array
   *   Array of time series data points.
   */
  public function calculateTimeSeriesVolume(array $filters): array {
    $granularity = $filters['granularity'] ?? 'day';
    $date_format = $this->getDateFormatForGranularity($granularity);
    $group_expression = $this->getGroupExpressionForGranularity($granularity);

    // Get closed status TID.
    $closed_tid = $this->getClosedStatusTid();

    // Build base query for created counts.
    $created_query = $this->database->select('node_field_data', 'n');
    $created_query->addExpression($group_expression, 'period');
    $created_query->addExpression("FROM_UNIXTIME(MIN(n.created), '$date_format')", 'period_start');
    $created_query->addExpression('COUNT(DISTINCT n.nid)', 'created_count');
    $created_query->condition('n.type', 'service_request');
    $created_query->groupBy('period');
    $created_query->orderBy('period');

    // Apply filters.
    $this->applyTimeSeriesFilters($created_query, $filters);

    $created_results = $created_query->execute()->fetchAllAssoc('period');

    // Build query for closed counts (nodes closed in this period).
    // We look at when the closed paragraph was created.
    $closed_query = $this->database->select('node_field_data', 'n');
    $closed_query->innerJoin('node__field_status_notes', 'fsn', 'n.nid = fsn.entity_id AND fsn.deleted = 0');
    $closed_query->innerJoin('paragraphs_item_field_data', 'p', 'fsn.field_status_notes_target_id = p.id');
    $closed_query->innerJoin('paragraph__field_status_term', 'pst', 'p.id = pst.entity_id AND pst.deleted = 0');

    // Use paragraph created time for grouping closed events.
    $closed_group_expr = str_replace('n.created', 'p.created', $group_expression);
    $closed_query->addExpression($closed_group_expr, 'period');
    $closed_query->addExpression("FROM_UNIXTIME(MIN(p.created), '$date_format')", 'period_start');
    $closed_query->addExpression('COUNT(DISTINCT n.nid)', 'closed_count');
    $closed_query->condition('n.type', 'service_request');

    if ($closed_tid) {
      $closed_query->condition('pst.field_status_term_target_id', $closed_tid);
    }

    // Apply date filters to paragraph creation time.
    if (!empty($filters['start_date'])) {
      $start_timestamp = $this->parseDate($filters['start_date']);
      if ($start_timestamp) {
        $closed_query->condition('p.created', $start_timestamp, '>=');
      }
    }

    if (!empty($filters['end_date'])) {
      $end_timestamp = $this->parseDate($filters['end_date']);
      if ($end_timestamp) {
        $closed_query->condition('p.created', $end_timestamp + 86399, '<=');
      }
    }

    // Jurisdiction filter.
    if (!empty($filters['jurisdiction_id'])) {
      $closed_query->innerJoin('group_relationship_field_data', 'gr', "n.nid = gr.entity_id AND gr.plugin_id = 'group_node:service_request'");
      $closed_query->innerJoin('groups_field_data', 'g', 'gr.gid = g.id');
      $closed_query->condition('g.type', 'jur');
      $closed_query->condition('g.id', $filters['jurisdiction_id']);
    }

    // Category filter.
    if (!empty($filters['category_id'])) {
      $closed_query->innerJoin('node__field_category', 'fc', 'n.nid = fc.entity_id AND fc.deleted = 0');
      $closed_query->condition('fc.field_category_target_id', $filters['category_id']);
    }

    $closed_query->groupBy('period');
    $closed_query->orderBy('period');

    $closed_results = $closed_query->execute()->fetchAllAssoc('period');

    // Merge results.
    $all_periods = array_unique(array_merge(
      array_keys($created_results),
      array_keys($closed_results)
    ));
    sort($all_periods);

    $output = [];
    foreach ($all_periods as $period) {
      $created = $created_results[$period] ?? NULL;
      $closed = $closed_results[$period] ?? NULL;

      $output[] = [
        'period' => $period,
        'period_start' => $created->period_start ?? $closed->period_start ?? $period,
        'new_requests' => (int) ($created->created_count ?? 0),
        'closed_requests' => (int) ($closed->closed_count ?? 0),
      ];
    }

    return [
      'data' => $output,
      'granularity' => $granularity,
      'filters_applied' => $this->getAppliedFiltersInfo($filters),
    ];
  }

  /**
   * Calculate time series processing data (processing time trends).
   *
   * @param array $filters
   *   Filter parameters.
   *
   * @return array
   *   Array of processing time trends.
   */
  public function calculateTimeSeriesProcessing(array $filters): array {
    $granularity = $filters['granularity'] ?? 'day';
    $date_format = $this->getDateFormatForGranularity($granularity);

    $closed_tid = $this->getClosedStatusTid();

    if (!$closed_tid) {
      return [
        'data' => [],
        'granularity' => $granularity,
        'error' => 'Closed status term not found',
        'filters_applied' => $this->getAppliedFiltersInfo($filters),
      ];
    }

    // Calculate processing time for each closed request,
    // grouped by the period when it was closed.
    // Processing time = first paragraph created -> closed paragraph created.
    $query = "
      SELECT
        closed_period.period,
        closed_period.period_start,
        AVG(closed_period.processing_seconds) as avg_processing_seconds,
        COUNT(*) as sample_size,
        GROUP_CONCAT(closed_period.processing_seconds ORDER BY closed_period.processing_seconds) as all_times
      FROM (
        SELECT
          n.nid,
          %s as period,
          FROM_UNIXTIME(p_closed.created, '%s') as period_start,
          (p_closed.created - first_para.first_created) as processing_seconds
        FROM {node_field_data} n
        -- Get first paragraph created time
        INNER JOIN (
          SELECT fsn.entity_id, MIN(p.created) as first_created
          FROM {node__field_status_notes} fsn
          INNER JOIN {paragraphs_item_field_data} p ON fsn.field_status_notes_target_id = p.id
          WHERE fsn.deleted = 0
          GROUP BY fsn.entity_id
        ) first_para ON n.nid = first_para.entity_id
        -- Get closed paragraph time
        INNER JOIN {node__field_status_notes} fsn_closed ON n.nid = fsn_closed.entity_id AND fsn_closed.deleted = 0
        INNER JOIN {paragraphs_item_field_data} p_closed ON fsn_closed.field_status_notes_target_id = p_closed.id
        INNER JOIN {paragraph__field_status_term} pst ON p_closed.id = pst.entity_id AND pst.deleted = 0
        %s
        WHERE n.type = 'service_request'
          AND pst.field_status_term_target_id = :closed_tid
          AND p_closed.created > first_para.first_created
          %s
      ) closed_period
      GROUP BY closed_period.period, closed_period.period_start
      ORDER BY closed_period.period
    ";

    // Build dynamic parts of the query.
    $group_expr = str_replace('n.created', 'p_closed.created', $this->getGroupExpressionForGranularity($granularity));
    $joins = '';
    $conditions = '';
    $args = [':closed_tid' => $closed_tid];

    // Jurisdiction filter.
    if (!empty($filters['jurisdiction_id'])) {
      $joins .= "
        INNER JOIN {group_relationship_field_data} gr ON n.nid = gr.entity_id AND gr.plugin_id = 'group_node:service_request'
        INNER JOIN {groups_field_data} g ON gr.gid = g.id AND g.type = 'jur'
      ";
      $conditions .= ' AND g.id = :jurisdiction_id';
      $args[':jurisdiction_id'] = $filters['jurisdiction_id'];
    }

    // Category filter.
    if (!empty($filters['category_id'])) {
      $joins .= "
        INNER JOIN {node__field_category} fc ON n.nid = fc.entity_id AND fc.deleted = 0
      ";
      $conditions .= ' AND fc.field_category_target_id = :category_id';
      $args[':category_id'] = $filters['category_id'];
    }

    // Date filters (based on closed time).
    if (!empty($filters['start_date'])) {
      $start_timestamp = $this->parseDate($filters['start_date']);
      if ($start_timestamp) {
        $conditions .= ' AND p_closed.created >= :start_date';
        $args[':start_date'] = $start_timestamp;
      }
    }

    if (!empty($filters['end_date'])) {
      $end_timestamp = $this->parseDate($filters['end_date']);
      if ($end_timestamp) {
        $conditions .= ' AND p_closed.created <= :end_date';
        $args[':end_date'] = $end_timestamp + 86399;
      }
    }

    $final_query = sprintf($query, $group_expr, $date_format, $joins, $conditions);
    $results = $this->database->query($final_query, $args)->fetchAll();

    $output = [];
    foreach ($results as $row) {
      // Calculate median from the concatenated times.
      $median_hours = 0.0;
      if (!empty($row->all_times)) {
        $times = array_map('intval', explode(',', $row->all_times));
        $median_seconds = $this->calculateMedian($times);
        $median_hours = round($median_seconds / 3600, 2);
      }

      $output[] = [
        'period' => $row->period,
        'period_start' => $row->period_start,
        'avg_time' => round((float) $row->avg_processing_seconds / 3600, 2),
        'median_time' => $median_hours,
        'sample_size' => (int) $row->sample_size,
      ];
    }

    return [
      'data' => $output,
      'granularity' => $granularity,
      'filters_applied' => $this->getAppliedFiltersInfo($filters),
    ];
  }

  /**
   * Calculate forwarding details breakdown.
   *
   * @param array $filters
   *   Filter parameters.
   *
   * @return array
   *   Forwarding breakdown data.
   */
  public function calculateForwardingDetails(array $filters): array {
    $node_ids = $this->getFilteredNodeIds($filters);

    if (empty($node_ids)) {
      return [
        'by_source_organization' => [],
        'by_category' => [],
        'total_forwards' => 0,
        'filters_applied' => $this->getAppliedFiltersInfo($filters),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($node_ids), '?'));

    // Get forwarding details by source organization.
    // Track which org forwarded to which org.
    $by_source_query = $this->database->query("
      SELECT
        g_source.label as source_org,
        g_source.id as source_org_id,
        g_target.label as target_org,
        g_target.id as target_org_id,
        COUNT(*) as forward_count
      FROM {node_revision__field_organisation} nfo_prev
      INNER JOIN {node_revision} nr_prev ON nfo_prev.revision_id = nr_prev.vid
      INNER JOIN {node_revision__field_organisation} nfo_next ON nfo_next.entity_id = nfo_prev.entity_id
      INNER JOIN {node_revision} nr_next ON nfo_next.revision_id = nr_next.vid
      -- Source organization (group).
      INNER JOIN {group_relationship_field_data} gr_source ON nfo_prev.field_organisation_target_id = gr_source.entity_id
        AND gr_source.plugin_id LIKE 'group_node:%'
      INNER JOIN {groups_field_data} g_source ON gr_source.gid = g_source.id
      -- Target organization (group).
      INNER JOIN {group_relationship_field_data} gr_target ON nfo_next.field_organisation_target_id = gr_target.entity_id
        AND gr_target.plugin_id LIKE 'group_node:%'
      INNER JOIN {groups_field_data} g_target ON gr_target.gid = g_target.id
      WHERE nfo_prev.entity_id IN ($placeholders)
        AND nfo_prev.deleted = 0
        AND nfo_next.deleted = 0
        AND nr_next.vid > nr_prev.vid
        AND nfo_next.field_organisation_target_id != nfo_prev.field_organisation_target_id
        AND NOT EXISTS (
          SELECT 1 FROM {node_revision} nr_between
          INNER JOIN {node_revision__field_organisation} nfo_between ON nr_between.vid = nfo_between.revision_id
          WHERE nfo_between.entity_id = nfo_prev.entity_id
            AND nfo_between.deleted = 0
            AND nr_between.vid > nr_prev.vid
            AND nr_between.vid < nr_next.vid
        )
        AND g_source.type = 'organisation'
        AND g_target.type = 'organisation'
      GROUP BY g_source.id, g_source.label, g_target.id, g_target.label
      ORDER BY forward_count DESC
    ", $node_ids);

    $by_source = [];
    foreach ($by_source_query->fetchAll() as $row) {
      $source_key = $row->source_org_id;
      if (!isset($by_source[$source_key])) {
        $by_source[$source_key] = [
          'organization_id' => (int) $row->source_org_id,
          'organization_name' => $row->source_org,
          'total_forwards' => 0,
          'forwards_to' => [],
        ];
      }
      $by_source[$source_key]['total_forwards'] += (int) $row->forward_count;
      $by_source[$source_key]['forwards_to'][] = [
        'target_id' => (int) $row->target_org_id,
        'target_name' => $row->target_org,
        'count' => (int) $row->forward_count,
      ];
    }

    // First, get total count per category for all filtered nodes.
    $total_by_category_query = $this->database->query("
      SELECT
        t.tid,
        t.name as category,
        COUNT(DISTINCT fc.entity_id) as total_count
      FROM {node__field_category} fc
      INNER JOIN {taxonomy_term_field_data} t ON fc.field_category_target_id = t.tid AND t.default_langcode = 1
      WHERE fc.entity_id IN ($placeholders)
        AND fc.deleted = 0
      GROUP BY t.tid, t.name
      ORDER BY total_count DESC
    ", $node_ids);

    // Build a map of total counts per category.
    $category_totals = [];
    foreach ($total_by_category_query->fetchAll() as $row) {
      $category_totals[$row->tid] = [
        'tid' => (int) $row->tid,
        'category' => $row->category,
        'total_count' => (int) $row->total_count,
        'forwarded_count' => 0,
      ];
    }

    // Get forwarding by category.
    $by_category_query = $this->database->query("
      SELECT
        t.tid,
        t.name as category,
        COUNT(DISTINCT nfo_prev.entity_id) as forwarded_count
      FROM {node_revision__field_organisation} nfo_prev
      INNER JOIN {node_revision} nr_prev ON nfo_prev.revision_id = nr_prev.vid
      INNER JOIN {node_revision__field_organisation} nfo_next ON nfo_next.entity_id = nfo_prev.entity_id
      INNER JOIN {node_revision} nr_next ON nfo_next.revision_id = nr_next.vid
      INNER JOIN {node__field_category} fc ON nfo_prev.entity_id = fc.entity_id AND fc.deleted = 0
      INNER JOIN {taxonomy_term_field_data} t ON fc.field_category_target_id = t.tid AND t.default_langcode = 1
      WHERE nfo_prev.entity_id IN ($placeholders)
        AND nfo_prev.deleted = 0
        AND nfo_next.deleted = 0
        AND nr_next.vid > nr_prev.vid
        AND nfo_next.field_organisation_target_id != nfo_prev.field_organisation_target_id
      GROUP BY t.tid, t.name
      ORDER BY forwarded_count DESC
    ", $node_ids);

    // Update forwarded counts in the category map.
    foreach ($by_category_query->fetchAll() as $row) {
      if (isset($category_totals[$row->tid])) {
        $category_totals[$row->tid]['forwarded_count'] = (int) $row->forwarded_count;
      }
    }

    // Build final by_category array with all required fields.
    $by_category = [];
    $total_forwards = 0;
    foreach ($category_totals as $tid => $data) {
      $forwarded = $data['forwarded_count'];
      $total = $data['total_count'];
      $not_forwarded = $total - $forwarded;
      $rate = $total > 0 ? round(($forwarded / $total) * 100, 2) : 0.0;

      $by_category[] = [
        'tid' => $data['tid'],
        'category' => $data['category'],
        'forwarded_count' => $forwarded,
        'not_forwarded_count' => $not_forwarded,
        'forwarding_rate' => $rate,
      ];
      $total_forwards += $forwarded;
    }

    // Sort by forwarded_count descending.
    usort($by_category, fn($a, $b) => $b['forwarded_count'] <=> $a['forwarded_count']);

    return [
      'by_source_organization' => array_values($by_source),
      'by_category' => $by_category,
      'total_forwards' => $total_forwards,
      'filters_applied' => $this->getAppliedFiltersInfo($filters),
    ];
  }

  /**
   * Get SQL date format string for granularity.
   *
   * @param string $granularity
   *   The time granularity (day|week|month).
   *
   * @return string
   *   MySQL date format string.
   */
  protected function getDateFormatForGranularity(string $granularity): string {
    return match ($granularity) {
      'week' => '%Y-W%u',
      'month' => '%Y-%m',
      default => '%Y-%m-%d',
    };
  }

  /**
   * Get SQL GROUP BY expression for granularity.
   *
   * @param string $granularity
   *   The time granularity (day|week|month).
   *
   * @return string
   *   SQL expression for grouping.
   */
  protected function getGroupExpressionForGranularity(string $granularity): string {
    return match ($granularity) {
      'week' => "CONCAT(YEAR(FROM_UNIXTIME(n.created)), '-W', LPAD(WEEK(FROM_UNIXTIME(n.created), 1), 2, '0'))",
      'month' => "DATE_FORMAT(FROM_UNIXTIME(n.created), '%Y-%m')",
      default => "DATE(FROM_UNIXTIME(n.created))",
    };
  }

  /**
   * Apply time series filters to a query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query to modify.
   * @param array $filters
   *   Filter parameters.
   */
  protected function applyTimeSeriesFilters($query, array $filters): void {
    if (!empty($filters['start_date'])) {
      $start_timestamp = $this->parseDate($filters['start_date']);
      if ($start_timestamp) {
        $query->condition('n.created', $start_timestamp, '>=');
      }
    }

    if (!empty($filters['end_date'])) {
      $end_timestamp = $this->parseDate($filters['end_date']);
      if ($end_timestamp) {
        $query->condition('n.created', $end_timestamp + 86399, '<=');
      }
    }

    if (!empty($filters['jurisdiction_id'])) {
      $query->innerJoin('group_relationship_field_data', 'gr', "n.nid = gr.entity_id AND gr.plugin_id = 'group_node:service_request'");
      $query->innerJoin('groups_field_data', 'g', 'gr.gid = g.id');
      $query->condition('g.type', 'jur');
      $query->condition('g.id', $filters['jurisdiction_id']);
    }

    if (!empty($filters['category_id'])) {
      $query->innerJoin('node__field_category', 'fc', 'n.nid = fc.entity_id AND fc.deleted = 0');
      $query->condition('fc.field_category_target_id', $filters['category_id']);
    }

    if (!empty($filters['status_id'])) {
      $query->innerJoin('node__field_status', 'fs', 'n.nid = fs.entity_id AND fs.deleted = 0');
      $query->condition('fs.field_status_target_id', $filters['status_id']);
    }
  }

  /**
   * Calculate median value from an array of numbers.
   *
   * @param array $values
   *   Array of numeric values.
   *
   * @return float
   *   The median value.
   */
  protected function calculateMedian(array $values): float {
    if (empty($values)) {
      return 0.0;
    }

    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = (int) floor($count / 2);

    if ($count % 2 === 0) {
      return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    return (float) $values[$middle];
  }

  /**
   * Get information about applied filters.
   *
   * @param array $filters
   *   Filter parameters.
   *
   * @return array
   *   Information about applied filters.
   */
  protected function getAppliedFiltersInfo(array $filters): array {
    $info = [];

    if (!empty($filters['start_date'])) {
      $info['start_date'] = is_numeric($filters['start_date'])
        ? date('Y-m-d', (int) $filters['start_date'])
        : $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
      $info['end_date'] = is_numeric($filters['end_date'])
        ? date('Y-m-d', (int) $filters['end_date'])
        : $filters['end_date'];
    }

    if (!empty($filters['jurisdiction_id'])) {
      $info['jurisdiction_id'] = (int) $filters['jurisdiction_id'];
    }

    if (!empty($filters['organization_id'])) {
      $info['organization_id'] = (int) $filters['organization_id'];
    }

    if (!empty($filters['category_id'])) {
      $info['category_id'] = (int) $filters['category_id'];
    }

    if (!empty($filters['status_id'])) {
      $info['status_id'] = (int) $filters['status_id'];
    }

    return $info;
  }

  /**
   * Calculate hazard statistics for filtered service requests.
   *
   * @param array $filters
   *   Filter parameters:
   *   - start_date: Start date (UNIX timestamp or Y-m-d format)
   *   - end_date: End date (UNIX timestamp or Y-m-d format)
   *   - jurisdiction_id: Filter by jurisdiction group ID
   *   - organization_id: Filter by organization group ID
   *   - category_id: Filter by category taxonomy term ID
   *
   * @return array
   *   Array containing hazard statistics.
   */
  public function calculateHazardStatistics(array $filters = []): array {
    // Check if field_hazard_level exists - it's optional config.
    if (!$this->database->schema()->tableExists('node__field_hazard_level')) {
      return [
        'by_level' => $this->getEmptyHazardLevelDistribution(),
        'critical_count' => 0,
        'high_priority_count' => 0,
        'total_hazards' => 0,
        'total_requests' => 0,
        'filters_applied' => $this->getAppliedFiltersInfo($filters),
        'field_missing' => TRUE,
      ];
    }

    $node_ids = $this->getFilteredNodeIds($filters);

    if (empty($node_ids)) {
      return [
        'by_level' => $this->getEmptyHazardLevelDistribution(),
        'critical_count' => 0,
        'high_priority_count' => 0,
        'total_hazards' => 0,
        'total_requests' => 0,
        'filters_applied' => $this->getAppliedFiltersInfo($filters),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($node_ids), '?'));

    // Get hazard level distribution.
    $query = $this->database->query("
      SELECT
        COALESCE(fhl.field_hazard_level_value, 0) as hazard_level,
        COUNT(DISTINCT n.nid) as count
      FROM {node_field_data} n
      LEFT JOIN {node__field_hazard_level} fhl ON n.nid = fhl.entity_id AND fhl.deleted = 0
      WHERE n.nid IN ($placeholders)
        AND n.type = 'service_request'
      GROUP BY COALESCE(fhl.field_hazard_level_value, 0)
      ORDER BY hazard_level ASC
    ", $node_ids);

    $results = $query->fetchAllKeyed();

    // Build distribution with all levels.
    $level_labels = [
      0 => 'None',
      1 => 'Low',
      2 => 'Medium',
      3 => 'High',
      4 => 'Critical',
    ];

    $by_level = [];
    $total_hazards = 0;
    $critical_count = 0;
    $high_priority_count = 0;

    foreach ($level_labels as $level => $label) {
      $count = (int) ($results[$level] ?? 0);
      $by_level[] = [
        'level' => $level,
        'label' => $label,
        'count' => $count,
      ];

      // Count hazards (level > 0).
      if ($level > 0) {
        $total_hazards += $count;
      }

      // Critical count (level 4).
      if ($level === 4) {
        $critical_count = $count;
      }

      // High priority count (level >= 3).
      if ($level >= 3) {
        $high_priority_count += $count;
      }
    }

    // Get hazard category breakdown.
    $category_query = $this->database->query("
      SELECT
        m.field_ai_hazard_category_value as category,
        COUNT(DISTINCT n.nid) as count
      FROM {node_field_data} n
      INNER JOIN {node__field_request_media} frm ON n.nid = frm.entity_id AND frm.deleted = 0
      INNER JOIN {media__field_ai_hazard_category} m ON frm.field_request_media_target_id = m.entity_id AND m.deleted = 0
      WHERE n.nid IN ($placeholders)
        AND n.type = 'service_request'
        AND m.field_ai_hazard_category_value IS NOT NULL
        AND m.field_ai_hazard_category_value != ''
      GROUP BY m.field_ai_hazard_category_value
      ORDER BY count DESC
    ", $node_ids);

    $by_category = [];
    foreach ($category_query->fetchAll() as $row) {
      $by_category[] = [
        'category' => $row->category,
        'count' => (int) $row->count,
      ];
    }

    return [
      'by_level' => $by_level,
      'by_category' => $by_category,
      'critical_count' => $critical_count,
      'high_priority_count' => $high_priority_count,
      'total_hazards' => $total_hazards,
      'total_requests' => count($node_ids),
      'filters_applied' => $this->getAppliedFiltersInfo($filters),
    ];
  }

  /**
   * Get empty hazard level distribution for when no data exists.
   *
   * @return array
   *   Array of hazard level entries with zero counts.
   */
  protected function getEmptyHazardLevelDistribution(): array {
    return [
      ['level' => 0, 'label' => 'None', 'count' => 0],
      ['level' => 1, 'label' => 'Low', 'count' => 0],
      ['level' => 2, 'label' => 'Medium', 'count' => 0],
      ['level' => 3, 'label' => 'High', 'count' => 0],
      ['level' => 4, 'label' => 'Critical', 'count' => 0],
    ];
  }

}
