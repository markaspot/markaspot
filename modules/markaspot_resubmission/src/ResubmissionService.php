<?php

namespace Drupal\markaspot_resubmission;

use Drupal\Core\Entity\EntityTypeManager;
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
   * Constructs a new ResubmissionService object.
   */
  public function __construct(EntityTypeManager $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * Helper function.
   *
   * @return array
   *   return $result.
   */
  public function arrayFlatten($array) {
    $result = [];
    foreach ($array as $value) {
      $result[] = $value;
    }
    return $result;
  }

  /**
   * Load nodes by status.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of entity objects indexed by their IDs. Returns an empty array
   *   if no matching entities are found.
   */
  public function load(): array {
    $config = $this->configFactory->get('markaspot_resubmission.settings');

    $days = $config->get('days');
    $tids = $this->arrayFlatten($config->get('status_resubmissive'));
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
    $nids = array_reduce($nids, 'array_merge', []);
    return $storage->loadMultiple($nids);
  }

}
