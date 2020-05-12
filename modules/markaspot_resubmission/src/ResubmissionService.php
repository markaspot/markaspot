<?php

namespace Drupal\markaspot_resubmission;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;


/**
 * Class ResubmissionService.
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

  function array_flatten($array) {
    $result = [];
    foreach ($array as $key => $value) {
      array_push($result,$value);
    }
    return $result;
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  public function load() {
    $config = $this->configFactory->get('markaspot_resubmission.settings');

    $days = $config->get('days');
    $tids = $this->array_flatten($config->get('status_resubmissive'));
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
    $nids = array_reduce($nids, 'array_merge', array());
    return $storage->loadMultiple($nids);
  }
}



