<?php

namespace Drupal\markaspot_archive;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;


/**
 * Class ArchiveService.
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
    $config = $this->configFactory->get('markaspot_archive.settings');
    $days = strtotime(' - ' . $config->get('days') . 'days');
    $tids = $this->array_flatten($config->get('status_archivable'));
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->condition('changed', $days, '<=')
      ->condition('type', 'service_request')
      ->condition('field_status', $tids, 'IN');
    $query->accessCheck(FALSE);

    $nids = $query->execute();
    return $storage->loadMultiple($nids);
  }


}


/*
 *
 *


public function load() {
  $config = $this->configFactory->get('markaspot_archive.settings');
  $timestamp_from = strtotime(' - ' . $config->get('days') . 'days');
  $timestamp_from = strtotime(' - ' . 1  . 'days');

  $storage = $this->entityTypeManager->getStorage('node');

  $query = $storage->getQuery()
    //->condition('changed', $timestamp_from, '<')
    //->condition('type', 'service_request')
    ->condition('field_status', 15, 'IN');

  $nids = $query->execute();



  *
 * */