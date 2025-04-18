<?php

namespace Drupal\markaspot_stats\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

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
   * Check access for statistics endpoints.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // First check if user has custom 'access statistics' permission.
    if ($account->hasPermission('access markaspot statistics')) {
      return AccessResult::allowed();
    }
    
    // Fall back to 'access content' permission.
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  /**
   * Gets statistics by service request status.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response with cache metadata.
   */
  public function getStatusStats() {
    try {
      $query = $this->database->select('taxonomy_term_field_data', 't');
      $query->fields('t', ['name']);
      $query->addField('h', 'field_status_hex_color', 'color');
      $query->addExpression('COUNT(DISTINCT n.nid)', 'count');
      $query->leftJoin('taxonomy_term__field_status_hex', 'h', 't.tid = h.entity_id AND h.deleted = 0');
      $query->leftJoin('node__field_status', 'fs', 't.tid = fs.field_status_target_id AND fs.deleted = 0');
      $query->leftJoin('node_field_data', 'n', 'fs.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
      $query->condition('t.vid', 'service_status');
      $query->groupBy('t.tid');
      $query->groupBy('t.name');
      $query->groupBy('h.field_status_hex_color');
      $query->orderBy('t.weight', 'ASC');
      
      $results = $query->execute()->fetchAll();
      $output = [];
      
      foreach ($results as $row) {
        $output[] = [
          'status' => $row->name,
          'count' => $row->count,
          'color' => $row->color,
        ];
      }
      
      // Create a response with cache metadata.
      $response = new CacheableJsonResponse($output);
      
      // Add cache metadata.
      $cache_metadata = new CacheableMetadata();
      $cache_metadata->setCacheTags([
        'node_list:service_request',
        'taxonomy_term_list:service_status',
      ]);
      $cache_metadata->setCacheMaxAge(3600); // Cache for 1 hour.
      $response->addCacheableDependency($cache_metadata);
      
      return $response;
    }
    catch (\Exception $e) {
      // Log the error.
      $this->getLogger('markaspot_stats')->error('Error retrieving status statistics: @error', ['@error' => $e->getMessage()]);
      
      // Return an error response.
      return new JsonResponse(['error' => 'An error occurred while retrieving statistics'], 500);
    }
  }

  /**
   * Gets statistics by service request category.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response with cache metadata.
   */
  public function getCategoryStats() {
    try {
      $query = $this->database->select('taxonomy_term_field_data', 't');
      $query->fields('t', ['tid', 'name']);
      $query->addField('h', 'field_category_hex_color', 'color');
      $query->addExpression('COUNT(DISTINCT n.nid)', 'count');
      $query->leftJoin('taxonomy_term__field_category_hex', 'h', 't.tid = h.entity_id AND h.deleted = 0');
      $query->leftJoin('node__field_category', 'fc', 't.tid = fc.field_category_target_id AND fc.deleted = 0');
      $query->leftJoin('node_field_data', 'n', 'fc.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
      $query->condition('t.vid', 'service_category');
      $query->groupBy('t.tid');
      $query->groupBy('t.name');
      $query->groupBy('h.field_category_hex_color');
      $query->orderBy('t.weight', 'ASC');
      
      $results = $query->execute()->fetchAll();
      $output = [];
      
      foreach ($results as $row) {
        $output[] = [
          'tid' => $row->tid,
          'category' => $row->name,
          'count' => $row->count,
          'color' => $row->color,
        ];
      }
      
      // Create a response with cache metadata.
      $response = new CacheableJsonResponse($output);
      
      // Add cache metadata.
      $cache_metadata = new CacheableMetadata();
      $cache_metadata->setCacheTags([
        'node_list:service_request',
        'taxonomy_term_list:service_category',
      ]);
      $cache_metadata->setCacheMaxAge(3600); // Cache for 1 hour.
      $response->addCacheableDependency($cache_metadata);
      
      return $response;
    }
    catch (\Exception $e) {
      // Log the error.
      $this->getLogger('markaspot_stats')->error('Error retrieving category statistics: @error', ['@error' => $e->getMessage()]);
      
      // Return an error response.
      return new JsonResponse(['error' => 'An error occurred while retrieving statistics'], 500);
    }
  }

  /**
   * Gets hierarchical statistics by service request category.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response with cache metadata.
   */
  public function getHierarchicalCategoryStats() {
    try {
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
      
      // Get all terms with their data.
      $terms_query = $this->database->select('taxonomy_term_field_data', 't');
      $terms_query->fields('t', ['tid', 'name']);
      $terms_query->addField('h', 'field_category_hex_color', 'color');
      $terms_query->leftJoin('taxonomy_term__field_category_hex', 'h', 't.tid = h.entity_id AND h.deleted = 0');
      $terms_query->condition('t.vid', 'service_category');
      $terms_results = $terms_query->execute()->fetchAllAssoc('tid');
      
      // Get all terms with their counts.
      $counts_query = $this->database->select('taxonomy_term_field_data', 't');
      $counts_query->fields('t', ['tid']);
      $counts_query->addExpression('COUNT(DISTINCT n.nid)', 'count');
      $counts_query->leftJoin('node__field_category', 'fc', 't.tid = fc.field_category_target_id AND fc.deleted = 0');
      $counts_query->leftJoin('node_field_data', 'n', 'fc.entity_id = n.nid AND n.type = :type', [':type' => 'service_request']);
      $counts_query->condition('t.vid', 'service_category');
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
      
      // Create a response with cache metadata.
      $response = new CacheableJsonResponse($output);
      
      // Add cache metadata.
      $cache_metadata = new CacheableMetadata();
      $cache_metadata->setCacheTags([
        'node_list:service_request',
        'taxonomy_term_list:service_category',
      ]);
      $cache_metadata->setCacheMaxAge(3600); // Cache for 1 hour.
      $response->addCacheableDependency($cache_metadata);
      
      return $response;
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