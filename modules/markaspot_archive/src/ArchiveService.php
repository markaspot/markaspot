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

    foreach ($days as $key => $day) {
      $category_tid = $key;

      $date = ($day !== '') ? strtotime(' - ' . $day . 'days') : strtotime(' - ' . 30 . 'days');
      $query = $storage->getQuery()
        ->condition('field_category', $category_tid, 'IN')
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $tids, 'IN');
      $query->accessCheck(FALSE);

      $nids[] = $query->execute();

    }
    // $nids = $this->arrayFlatten($nids);
    $nids = array_reduce($nids, 'array_merge', []);

    return $storage->loadMultiple($nids);
  }

}
