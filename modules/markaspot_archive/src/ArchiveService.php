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

    $days = $config->get('days');
    $tids = $this->arrayFlatten($config->get('status_archivable'));
    $storage = $this->entityTypeManager->getStorage('node');
    
    // Get a limited number of categories per run
    $categories = array_slice(array_keys($days), 0, 3, true);
    $nids = [];
    
    \Drupal::logger('markaspot_archive')->notice('Processing @count categories in this run', ['@count' => count($categories)]);
    
    foreach ($categories as $category_tid) {
      $day = $days[$category_tid];
      
      $date = ($day !== '') ? strtotime(' - ' . $day . 'days') : strtotime(' - ' . 30 . 'days');
      
      $query = $storage->getQuery()
        ->condition('field_category', $category_tid)
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $tids, 'IN')
        // Limit to 20 nodes per category
        ->range(0, 20);
      $query->accessCheck(FALSE);

      $result = $query->execute();
      if (!empty($result)) {
        $nids = array_merge($nids, $result);
        \Drupal::logger('markaspot_archive')->notice('Found @count archivable nodes for category @cat', [
          '@count' => count($result),
          '@cat' => $category_tid
        ]);
      }
    }
    
    // Rotate the processed categories to the end of the list
    if (!empty($categories)) {
      foreach ($categories as $category_tid) {
        $value = $days[$category_tid];
        unset($days[$category_tid]);
        $days[$category_tid] = $value;
      }
      $config_factory = \Drupal::configFactory()->getEditable('markaspot_archive.settings');
      $config_factory->set('days', $days)->save();
    }
    
    // Return a limited number of nodes
    $nids = array_slice($nids, 0, 50);
    \Drupal::logger('markaspot_archive')->notice('Returning @count nodes for archiving', ['@count' => count($nids)]);
    
    return $storage->loadMultiple($nids);
  }

}
