<?php

namespace Drupal\markaspot_feedback;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Class FeedbackService.
 *
 * Gets all service requests that need a refreshment.
 */
class FeedbackService implements FeedbackServiceInterface {

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
   * Constructs a new FeedbackService object.
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
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  public function get($uuid) {

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('uuid', $uuid);
    $query->accessCheck(FALSE);

    $nid = $query->execute();
    $nid = array_values($nid);

    if (!empty($nid)) {
      return $storage->load($nid[0]);
    } else {
      return FALSE;
    }
  }

  /**
   * Load nodes by status.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of entity objects indexed by their IDs. Returns an empty array
   *   if no matching entities are found.
   */
  public function load(): array {
    $config = $this->configFactory->get('markaspot_feedback.settings');

    $days = $config->get('days');
    $tids = $this->arrayFlatten($config->get('status_resubmissive'));
    $storage = $this->entityTypeManager->getStorage('node');

    foreach ($days as $key => $day) {
      $category_tid = $key;

      $date = ($day !== '') ? strtotime(' - ' . $day . 'days') : strtotime(' - ' . 30 . 'days');
      $query = $storage->getQuery()
        // ->condition('field_category', $category_tid, 'IN')
        ->condition('changed', $date, '<=')
        ->condition('type', 'service_request')
        ->condition('field_status', $tids, 'IN');
      $query->accessCheck(FALSE);

      $nids[] = $query->execute();

    }
    $nids = array_reduce($nids, 'array_merge', []);
    return $storage->loadMultiple($nids);
  }

  /**
   * Create Status Note Paragraph.
   *
   * @return array
   *   Return paragraph reference.
   */
  public function createParagraph() {

    $paragraph = Paragraph::create([
      'type' => 'status',
      'field_status_term' => ['target_id' => 5],
      'field_status_notes' => ['value' => "sdsdss"],
    ]);
    $paragraph->save();
    if (null !== $paragraph->id()) {
      return [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }
  }
}
