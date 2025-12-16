<?php

namespace Drupal\markaspot_archive;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class ArchiveService finds all service requests that can be archived.
 */
class ArchiveService implements ArchiveServiceInterface {

  /**
   * Entity manager Service Object.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ArchiveService object.
   */
  public function __construct(EntityTypeManager $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * Helper function.
   *
   * @param array $array
   *   Array with keys.
   *
   * @return array
   *   Return flattened array
   */
  public function arrayFlatten(array $array) {
    $result = [];
    foreach ($array as $value) {
      array_push($result, $value);
    }
    return $result;
  }

  /**
   * Load service requests.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Return nodes.
   */
  public function load() {
    $config = $this->configFactory->get('markaspot_archive.settings');
    // Global default days before archive if term override is empty.
    $default_days = (int) $config->get('default_days');
    // Status terms eligible for archiving.
    $status_tids = $this->arrayFlatten($config->get('status_archivable'));
    $node_storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    // Load service category term IDs.
    $category_items = $term_storage->loadTree('service_category');
    $category_ids = array_map(function ($item) {
      return $item->tid;
    }, $category_items);

    // Get a rotating subset of categories to process.
    $state = \Drupal::state();
    $last_processed_index = $state->get('markaspot_archive.last_category_index', 0);

    // Process up to 10 categories per run, with rotation for fairness.
    $total_categories = count($category_ids);
    $batch_size = 10;

    if ($last_processed_index >= $total_categories) {
      $last_processed_index = 0;
    }

    // Get the categories for this run.
    if ($total_categories <= $batch_size) {
      // If we have fewer than batch_size categories, just process all of them.
      $categories = $category_ids;
    }
    else {
      // Create a rotating window through the categories.
      $categories = array_slice($category_ids, $last_processed_index, $batch_size);
      // If we're near the end, wrap around to the beginning.
      if (count($categories) < $batch_size) {
        $categories = array_merge(
          $categories,
          array_slice($category_ids, 0, $batch_size - count($categories))
        );
      }
      // Update the index for next time.
      $state->set('markaspot_archive.last_category_index',
        ($last_processed_index + $batch_size) % $total_categories);
    }

    \Drupal::logger('markaspot_archive')->notice(
      'Processing categories @start to @end of @total (batch size: @batch)',
      [
        '@start' => $last_processed_index + 1,
        '@end' => min($last_processed_index + $batch_size, $total_categories),
        '@total' => $total_categories,
        '@batch' => $batch_size,
      ]
    );
    $nids = [];
    \Drupal::logger('markaspot_archive')->notice('Processing @count categories in this run', ['@count' => count($categories)]);
    foreach ($categories as $category_tid) {
      // Determine archive threshold days: term override or default.
      $day = $default_days;
      $term = $term_storage->load($category_tid);
      if ($term && $term->hasField('field_archive_days') && !$term->get('field_archive_days')->isEmpty()) {
        $override = (int) $term->get('field_archive_days')->value;
        if ($override > 0) {
          $day = $override;
        }
      }
      // Calculate cutoff timestamp.
      $date = strtotime('-' . $day . ' days');

      // Perform a count query first to determine how many potential nodes would match.
      $count_query = $node_storage->getQuery()
        ->condition('field_category', $category_tid)
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $status_tids, 'IN');
      $count_query->accessCheck(FALSE);
      $count_result = $count_query->count()->execute();

      // Debug the query.
      \Drupal::logger('markaspot_archive')->notice(
        'Category @cat (@term): days=@days, cutoff=@cutoff, status_tids=@status, potential_matches=@count',
        [
          '@cat' => $category_tid,
          '@term' => $term ? $term->label() : 'unknown',
          '@days' => $day,
          '@cutoff' => date('Y-m-d H:i:s', $date),
          '@status' => implode(',', $status_tids),
          '@count' => $count_result,
        ]
      );

      // Now run the actual query with range limit.
      $query = $node_storage->getQuery()
        ->condition('field_category', $category_tid)
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $status_tids, 'IN')
        // Limit to 20 nodes per category.
        ->range(0, 20);
      $query->accessCheck(FALSE);

      $result = $query->execute();
      if (!empty($result)) {
        $nids = array_merge($nids, $result);
        \Drupal::logger('markaspot_archive')->notice('Found @count archivable nodes for category @cat', [
          '@count' => count($result),
          '@cat' => $category_tid,
        ]);
      }
    }

    // Return a limited number of nodes to process this run.
    $nids = array_slice($nids, 0, 50);
    \Drupal::logger('markaspot_archive')->notice('Returning @count nodes for archiving', ['@count' => count($nids)]);
    return $node_storage->loadMultiple($nids);
  }

}
