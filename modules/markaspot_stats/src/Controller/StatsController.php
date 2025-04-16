<?php

namespace Drupal\markaspot_stats\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class StatsController.
 *
 * Provides REST endpoints for Mark-a-Spot statistics.
 */
class StatsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a StatsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Gets statistics by service request status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getStatusStats() {
    $query = $this->database->query("
      SELECT 
        t.name AS status, 
        COUNT(DISTINCT n.nid) AS count,
        h.field_status_hex_color AS color
      FROM taxonomy_term_field_data t
      LEFT JOIN taxonomy_term__field_status_hex h ON t.tid = h.entity_id AND h.deleted = 0
      LEFT JOIN node__field_status fs ON t.tid = fs.field_status_target_id AND fs.deleted = 0
      LEFT JOIN node_field_data n ON fs.entity_id = n.nid AND n.type = 'service_request'
      WHERE t.vid = 'service_status'
      GROUP BY t.tid, t.name, h.field_status_hex_color
      ORDER BY t.weight ASC
    ");

    $results = $query->fetchAll();
    $output = [];

    foreach ($results as $row) {
      $output[] = [
        'status' => $row->status,
        'count' => $row->count,
        'color' => $row->color,
      ];
    }

    return new JsonResponse($output);
  }

  /**
   * Gets statistics by service request category.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getCategoryStats() {
    $query = $this->database->query("
      SELECT 
        t.name AS category, 
        COUNT(DISTINCT n.nid) AS count,
        h.field_category_hex_color AS color
      FROM taxonomy_term_field_data t
      LEFT JOIN taxonomy_term__field_category_hex h ON t.tid = h.entity_id AND h.deleted = 0
      LEFT JOIN node__field_category fc ON t.tid = fc.field_category_target_id AND fc.deleted = 0
      LEFT JOIN node_field_data n ON fc.entity_id = n.nid AND n.type = 'service_request'
      WHERE t.vid = 'service_category'
      GROUP BY t.tid, t.name, h.field_category_hex_color
      ORDER BY t.weight ASC
    ");

    $results = $query->fetchAll();
    $output = [];

    foreach ($results as $row) {
      $output[] = [
        'category' => $row->category,
        'count' => $row->count,
        'color' => $row->color,
      ];
    }

    return new JsonResponse($output);
  }

}