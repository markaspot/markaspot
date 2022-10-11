<?php

namespace Drupal\markaspot_feedback;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new FeedbackService object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(EntityTypeManager $entity_type_manager, ConfigFactoryInterface $config_factory, LoggerInterface $logger, MessengerInterface $messenger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('markaspot_feedback'),
      $container->get('messenger')
    );
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

    $date = ($days !== '') ? strtotime(' - ' . $days . 'days') : strtotime(' - ' . 30 . 'days');
    $query = $storage->getQuery()
      // ->condition('field_category', $category_tid, 'IN')
      ->condition('created', $date, '<=')
      ->condition('type', 'service_request')
      ->condition('field_status', $tids, 'IN');
    $query->accessCheck(FALSE);

    $nids[] = $query->execute();
    // $string = $query->__toString();


    $nids = array_reduce($nids, 'array_merge', []);
    $this->logger->notice('Markaspot has found %count requests to collect feedback', ['%count' => count($nids)]);
    // die;
    return $storage->loadMultiple($nids);
  }

  /**
   * Save nodes with new status and status note.
   *
   */
  public function saveNode($form_state) {
    $config = $this->configFactory->get('markaspot_feedback.settings');
    $node = $this->get($form_state->getValue('uuid'));
    $node->field_feedback->value = $form_state->getValue('feedback');
    if ($form_state->getValue('set_status') == 1) {
      $node->field_status->target_id = key($config->get('set_progress_tid'));
      $change_status = TRUE;
    } else {
      $change_status = FALSE;
    }
    $new_status_note = $this->createParagraph($change_status);
    $node->field_status_notes[] = $new_status_note;

    $node->save();
  }

  /**
   * Create Status Note Paragraph.
   *
   * @return array
   *   Return paragraph reference.
   */
  public function createParagraph($change_status) {

    $config = $this->configFactory->get('markaspot_feedback.settings');
    if (isset($change_status)){
      $tid = key($config->get('set_progress_tid'));
    } else {
      $tid = key($config->get('set_archive_tid'));
    }
    $paragraph = Paragraph::create([
      'type' => 'status',
      'field_status_term' => ['target_id' => $tid],
      'field_status_note' => ['value' => $config->get('set_status_note')],
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
