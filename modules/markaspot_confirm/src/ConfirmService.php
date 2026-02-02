<?php

namespace Drupal\markaspot_confirm;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_nuxt\Service\FrontendUrlService;

/**
 * Service for handling service request confirmations.
 */
class ConfirmService implements ConfirmServiceInterface {

  /**
   * Entity type manager Service Object.
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
   * The frontend URL service.
   *
   * @var \Drupal\markaspot_nuxt\Service\FrontendUrlService
   */
  protected $frontendUrlService;

  /**
   * Constructs a new ArchiveService object.
   */
  public function __construct(EntityTypeManager $entity_type_manager, ConfigFactoryInterface $config_factory, FrontendUrlService $frontend_url_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->frontendUrlService = $frontend_url_service;
  }

  /**
   * Loads service request nodes by UUID.
   *
   * @param string $uuid
   *   The UUID of the service request.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of loaded node entities.
   */
  public function load($uuid) {
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->condition('uuid', $uuid);
    $query->accessCheck(FALSE);

    $nids = $query->execute();
    return $storage->loadMultiple($nids);
  }

  /**
   * Generate a confirmation URL for a given UUID.
   *
   * @param string $uuid
   *   The UUID of the service request.
   *
   * @return string
   *   The confirmation URL.
   */
  public function generateConfirmationUrl($uuid) {
    // Use the central frontend URL service.
    return $this->frontendUrlService->generateConfirmationUrl($uuid);
  }

}
