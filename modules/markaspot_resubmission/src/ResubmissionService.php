<?php

namespace Drupal\markaspot_resubmission;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class ResubmissionService.
 *
 * Gets all service requests that need a refreshment.
 */
class ResubmissionService implements ResubmissionServiceInterface {

  /**
   * Entity manager Service Object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The reminder manager.
   *
   * @var \Drupal\markaspot_resubmission\ReminderManager
   */
  protected $reminderManager;

  /**
   * Constructs a new ResubmissionService object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, ReminderManager $reminder_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->reminderManager = $reminder_manager;
  }

  /**
   * Helper function.
   *
   * @param array|null $array
   *   The array to flatten.
   *
   * @return array
   *   return $result.
   */
  public function arrayFlatten($array) {
    $result = [];
    if (!empty($array) && is_array($array)) {
      foreach ($array as $value) {
        $result[] = $value;
      }
    }
    return $result;
  }

  /**
   * Load nodes by status.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of entity objects indexed by their IDs.
   */
  public function load(): array {
    $config = $this->configFactory->get('markaspot_resubmission.settings');
    // Global default days before reminder if term override is empty.
    $default_days = (int) $config->get('default_resubmission_days') ?: 42;
    // Status terms eligible for resubmission.
    $status_tids = $this->arrayFlatten($config->get('status_resubmissive'));

    $node_storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Load service category term IDs.
    $category_items = $term_storage->loadTree('service_category');
    $category_ids = array_map(function ($item) {
      return $item->tid;
    }, $category_items);

    // Get a rotating subset of categories to process.
    $state = \Drupal::state();
    $last_processed_index = $state->get('markaspot_resubmission.last_category_index', 0);

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
      $state->set('markaspot_resubmission.last_category_index',
        ($last_processed_index + $batch_size) % $total_categories);
    }

    \Drupal::logger('markaspot_resubmission')->notice(
      'Processing categories @start to @end of @total (batch size: @batch)',
      [
        '@start' => $last_processed_index + 1,
        '@end' => min($last_processed_index + $batch_size, $total_categories),
        '@total' => $total_categories,
        '@batch' => $batch_size,
      ]
    );

    $nids = [];
    \Drupal::logger('markaspot_resubmission')->notice('Processing @count categories in this run', ['@count' => count($categories)]);

    foreach ($categories as $category_tid) {
      // Determine resubmission threshold days: term override or default.
      $day = $default_days;
      $term = $term_storage->load($category_tid);
      if ($term && $term->hasField('field_resubmission_days') && !$term->get('field_resubmission_days')->isEmpty()) {
        $override = (int) $term->get('field_resubmission_days')->value;
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
      \Drupal::logger('markaspot_resubmission')->notice(
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

      // Query for nodes that need reminders.
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
        \Drupal::logger('markaspot_resubmission')->notice('Found @count nodes needing reminders for category @cat', [
          '@count' => count($result),
          '@cat' => $category_tid,
        ]);
      }
    }

    // Limit to 50 total nodes per run to prevent timeouts.
    $nids = array_slice($nids, 0, 50);
    \Drupal::logger('markaspot_resubmission')->notice('Returning @count nodes for reminder processing', ['@count' => count($nids)]);

    // Load nodes and filter by reminder eligibility.
    if (!empty($nids)) {
      $nodes = $node_storage->loadMultiple($nids);
      $eligible_nodes = [];

      foreach ($nodes as $node) {
        // Check if this node should receive a reminder.
        if ($this->reminderManager->shouldSendReminder($node)) {
          $eligible_nodes[] = $node;
        }
      }

      \Drupal::logger('markaspot_resubmission')->notice('After eligibility check: @count nodes eligible for reminders', ['@count' => count($eligible_nodes)]);
      return $eligible_nodes;
    }

    return [];
  }

}
