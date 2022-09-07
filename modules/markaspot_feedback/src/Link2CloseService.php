<?php

namespace Drupal\markaspot_feedback;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;


/**
 * Class ArchiveService.
 */
class Link2CloseService implements Link2CloseServiceInterface {

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
  public function load($uuid) {
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->condition('uuid', $uuid);
    $query->accessCheck(FALSE);

    $nids  = $query->execute();
    return $storage->loadMultiple($nids);
  }


}
