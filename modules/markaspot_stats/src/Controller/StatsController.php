<?php

namespace Drupal\markaspot_stats\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

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
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a StatsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(Connection $database, LanguageManagerInterface $language_manager) {
    $this->database = $database;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('language_manager')
    );
  }

  /**
   * Gets statistics by service request status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getStatusStats() {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

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
        AND t.langcode = :langcode
      GROUP BY t.tid, t.name, h.field_status_hex_color, t.weight
      ORDER BY t.weight ASC
    ", [':langcode' => $langcode]);

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
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

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
        AND t.langcode = :langcode
      GROUP BY t.tid, t.name, h.field_category_hex_color, t.weight
      ORDER BY t.weight ASC
    ", [':langcode' => $langcode]);

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

  /**
   * Gets hierarchical statistics by service request category.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getHierarchicalCategoryStats() {
    try {
      $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

      // Get all term relationships to build our hierarchy map.
      $hierarchy_query = $this->database->select('taxonomy_term__parent', 'tp');
      $hierarchy_query->fields('tp', ['entity_id', 'parent_target_id']);
      $hierarchy_query->condition('tp.bundle', 'service_category');
      $hierarchy_results = $hierarchy_query->execute()->fetchAll();

      // Build a hierarchy map of parent->children.
      $children_map = [];
      $parent_map = [];

      foreach ($hierarchy_results as $term_relation) {
        $parent_id = $term_relation->parent_target_id;
        $child_id = $term_relation->entity_id;

        if (!isset($children_map[$parent_id])) {
          $children_map[$parent_id] = [];
        }
        $children_map[$parent_id][] = $child_id;
        $parent_map[$child_id] = $parent_id;
      }

      // Get all terms with their data (filtered by current language).
      $terms_query = $this->database->select('taxonomy_term_field_data', 't');
      $terms_query->fields('t', ['tid', 'name']);
      $terms_query->addField('h', 'field_category_hex_color', 'color');
      $terms_query->leftJoin('taxonomy_term__field_category_hex', 'h', 't.tid = h.entity_id AND h.deleted = 0');
      $terms_query->condition('t.vid', 'service_category');
      $terms_query->condition('t.langcode', $langcode);
      $terms_results = $terms_query->execute()->fetchAllAssoc('tid');

      // Get all terms with their counts (filtered by current language).
      $counts_query = $this->database->select('taxonomy_term_field_data', 't');
      $counts_query->fields('t', ['tid']);
      $counts_query->addExpression('COUNT(DISTINCT n.nid)', 'count');
      $counts_query->leftJoin('node__field_category', 'fc', 't.tid = fc.field_category_target_id AND fc.deleted = 0');
      $counts_query->leftJoin('node_field_data', 'n', 'fc.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
      $counts_query->condition('t.vid', 'service_category');
      $counts_query->condition('t.langcode', $langcode);
      $counts_query->groupBy('t.tid');
      $counts_results = $counts_query->execute()->fetchAllAssoc('tid');

      // Find root categories (terms without parents or with parent 0)
      $root_categories = [];
      foreach ($terms_results as $tid => $term) {
        if (!isset($parent_map[$tid]) || $parent_map[$tid] == 0) {
          $root_categories[] = $tid;
        }
      }

      // Build the hierarchical output.
      $output = [];

      foreach ($root_categories as $root_tid) {
        if (isset($terms_results[$root_tid])) {
          $term = $terms_results[$root_tid];
          $count = isset($counts_results[$root_tid]) ? $counts_results[$root_tid]->count : 0;

          $category = [
            'tid' => $term->tid,
            'category' => $term->name,
            'count' => $count,
            'color' => $term->color,
            'children' => $this->getChildrenForCategory($term->tid, $children_map, $terms_results, $counts_results),
          ];

          $output[] = $category;
        }
      }

      return new JsonResponse($output);
    }
    catch (\Exception $e) {
      // Log the error.
      $this->getLogger('markaspot_stats')->error('Error retrieving hierarchical category statistics: @error', ['@error' => $e->getMessage()]);

      // Return an error response.
      return new JsonResponse(['error' => 'An error occurred while retrieving statistics'], 500);
    }
  }

  /**
   * Helper function to recursively build child categories.
   *
   * @param int $parent_tid
   *   The parent term ID.
   * @param array $children_map
   *   Map of parent TIDs to child TIDs.
   * @param array $terms_results
   *   All terms with their data.
   * @param array $counts_results
   *   All terms with their counts.
   *
   * @return array
   *   Array of child categories with counts.
   */
  protected function getChildrenForCategory($parent_tid, $children_map, $terms_results, $counts_results) {
    $children = [];

    if (!isset($children_map[$parent_tid])) {
      return $children;
    }

    foreach ($children_map[$parent_tid] as $child_tid) {
      if (isset($terms_results[$child_tid])) {
        $term = $terms_results[$child_tid];
        $count = isset($counts_results[$child_tid]) ? $counts_results[$child_tid]->count : 0;

        $child = [
          'tid' => $term->tid,
          'category' => $term->name,
          'count' => $count,
          'color' => $term->color,
        ];

        // Add children if any exist.
        if (isset($children_map[$child_tid])) {
          $child['children'] = $this->getChildrenForCategory($child_tid, $children_map, $terms_results, $counts_results);
        }

        $children[] = $child;
      }
    }

    return $children;
  }

}
