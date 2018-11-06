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

  /**
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  public function load() {
    $config = $this->configFactory->get('markaspot_archive.settings');
    $days = strtotime(' - ' . $config->get('days') . 'seconds');

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->
      condition('changed', $days, '<')->
      condition('field_status.entity.tid', $config->get('status_archivable'));


    $nids = $query->execute();
    var_dump($nids);


    // var_dump($query);
    return $storage->loadMultiple($nids);
  }


}
