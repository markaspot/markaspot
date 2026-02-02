<?php

namespace Drupal\markaspot_publisher;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class PublisherService finds all service requests that can be published.
 */
class PublisherService implements PublisherServiceInterface {

  /**
   * Entity manager Service Object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new PublisherService object.
   */
  public function __construct(EntityTypeManager $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * Helper function to flatten array.
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
   * Load service requests eligible for publishing.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Return nodes.
   */
  public function load() {
    $config = $this->configFactory->get('markaspot_publisher.settings');

    // Global default days and term overrides.
    // Default to 30 days if not set.
    $default_days = (int) $config->get('default_days') ?: 30;
    $tids = $this->arrayFlatten($config->get('status_publishable'));

    // Ensure we have valid status IDs.
    if (empty($tids)) {
      \Drupal::logger('markaspot_publisher')->warning('No publishable status terms configured. Using default statuses.');
      // Get some default status terms as fallback.
      $query = \Drupal::entityQuery('taxonomy_term')
        ->condition('vid', 'service_status')
        ->range(0, 5);
      $query->accessCheck(FALSE);
      $tids = $query->execute();

      if (empty($tids)) {
        \Drupal::logger('markaspot_publisher')->error('No status terms found. Cannot proceed with publishing.');
        return [];
      }
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Get category processing data from config or set default.
    $days = $config->get('days');
    if (empty($days)) {
      // Initialize with categories from the database.
      $query = \Drupal::entityQuery('taxonomy_term')
        ->condition('vid', 'service_category');
      $query->accessCheck(FALSE);
      $category_tids = $query->execute();
      $days = array_fill_keys($category_tids, $default_days);

      // Save to config.
      \Drupal::configFactory()->getEditable('markaspot_publisher.settings')
        ->set('days', $days)
        ->save();
    }

    // Process all categories in each run to avoid missing nodes due to rotation.
    $categories = !empty($days) ? array_keys($days) : [];
    $nids = [];

    \Drupal::logger('markaspot_publisher')->notice('Processing @count categories in this run', ['@count' => count($categories)]);

    foreach ($categories as $category_tid) {
      // Determine threshold days: default or term override.
      $day = $default_days;
      $term = $term_storage->load($category_tid);
      if ($term && $term->hasField('field_publish_days') && !$term->get('field_publish_days')->isEmpty()) {
        $override = (int) $term->get('field_publish_days')->value;
        if ($override > 0) {
          $day = $override;
        }
      }

      $date = strtotime('-' . $day . ' days');

      // Get threshold for manual unpublishing in seconds (from hours)
      $threshold_hours = (int) $config->get('manual_unpublish_threshold') ?: 6;
      $threshold_seconds = $threshold_hours * 3600;
      $manual_change_threshold = time() - $threshold_seconds;

      $query = $storage->getQuery()
        ->condition('field_category', $category_tid)
      // Use creation date instead of changed date.
        ->condition('created', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $tids, 'IN')
      // Only unpublished nodes.
        ->condition('status', 0)
        // Add condition to detect intentional unpublishing
        // The condition below ensures that nodes that were modified long after creation are excluded.
        ->condition('changed', strtotime('+' . $threshold_hours . ' hours', $date), '<=')
        // Limit to 20 nodes per category.
        ->range(0, 20);
      $query->accessCheck(FALSE);

      $result = $query->execute();
      if (!empty($result)) {
        $nids = array_merge($nids, $result);
        \Drupal::logger('markaspot_publisher')->notice('Found @count publishable nodes for category @cat', [
          '@count' => count($result),
          '@cat' => $category_tid,
        ]);
      }
    }

    // Return a limited number of nodes.
    $nids = array_slice($nids, 0, 50);
    \Drupal::logger('markaspot_publisher')->notice('Returning @count nodes for publishing', ['@count' => count($nids)]);

    return $storage->loadMultiple($nids);
  }

}
