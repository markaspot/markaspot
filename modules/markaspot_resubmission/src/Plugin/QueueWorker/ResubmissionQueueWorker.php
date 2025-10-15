<?php

namespace Drupal\markaspot_resubmission\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_resubmission\ReminderManager;
use Drupal\markaspot_resubmission\Event\ResubmissionReminderEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Processes resubmission notifications for service request nodes.
 *
 * @QueueWorker(
 *   id = "markaspot_resubmission_queue_worker",
 *   title = @Translation("Resubmission Service Request Notification"),
 *   cron = {"time" = 60}
 * )
 */
class ResubmissionQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The reminder manager.
   *
   * @var \Drupal\markaspot_resubmission\ReminderManager
   */
  protected $reminderManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Constructs a new ResubmissionQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\markaspot_resubmission\ReminderManager $reminder_manager
   *   The reminder manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    ReminderManager $reminder_manager,
    ModuleHandlerInterface $module_handler,
    Token $token,
    EventDispatcherInterface $event_dispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->reminderManager = $reminder_manager;
    $this->moduleHandler = $module_handler;
    $this->token = $token;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('markaspot_resubmission'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('markaspot_resubmission.reminder_manager'),
      $container->get('module_handler'),
      $container->get('token'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $nid = isset($data['nid']) ? $data['nid'] : NULL;
    if (!$nid) {
      $this->logger->warning('Queue item is missing a node ID.');
      return;
    }

    $this->logger->notice('Processing resubmission notification for node ID: @nid', ['@nid' => $nid]);

    try {
      // Load the node by ID.
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node) {
        $this->logger->warning('Node with ID @nid not found.', ['@nid' => $nid]);
        return;
      }

      // Get configuration.
      $config = $this->configFactory->get('markaspot_resubmission.settings');
      $mailText = $config->get('mailtext');

      // Determine the recipient email.
      if ($this->moduleHandler->moduleExists('markaspot_groups')) {
        $to = $this->getGroupField($node);
      }
      else {
        $to = $this->getOrganisationTermField($node);
      }

      if (empty($to)) {
        $this->logger->warning('No recipient email found for node @nid.', ['@nid' => $nid]);
        return;
      }

      // Get reminder count for this node.
      $reminder_count = $this->reminderManager->getReminderCount($node->id()) + 1;

      // Dispatch event for EventSubscriber to handle email sending.
      $event = new ResubmissionReminderEvent($node, $to, $mailText, $reminder_count);
      $this->eventDispatcher->dispatch($event, ResubmissionReminderEvent::EVENT_NAME);

      $this->logger->notice('Dispatched resubmission reminder event for node @nid to @email (reminder #@count)', [
        '@nid' => $nid,
        '@email' => $to,
        '@count' => $reminder_count,
      ]);

      // Create reminder record.
      // Note: EventSubscriber handles email sending.
      $this->reminderManager->createReminder($node, $to, 'sent');
    }
    catch (\Exception $e) {
      $this->logger->critical('Error processing resubmission notification for node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage() . "\n" . $e->getTraceAsString(),
      ]);
    }
  }

  /**
   * Gets the email body for resubmission notification.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $mailText
   *   The mail text template.
   *
   * @return string
   *   The formatted email body.
   */
  protected function getBody($node, $mailText) {
    $data = [
      'node' => $node,
    ];
    return $this->token->replace($mailText, $data);
  }

  /**
   * Gets the email from group field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string|null
   *   The email address or null if not found.
   */
  protected function getGroupField($node) {
    $group_ids = [];
    $headOrganisationEmails = NULL;

    $group_contents = \Drupal\group\Entity\GroupRelationship::loadByEntity($node);
    foreach ($group_contents as $group_content) {
      $group_ids[] = $group_content->getGroup()->id();
    }

    foreach ($group_ids as $group) {
      $affectedGroup = \Drupal\group\Entity\Group::load($group);
      if ($affectedGroup && $affectedGroup->hasField('field_head_organisation_e_mail')) {
        $headOrganisationEmails = $affectedGroup->get('field_head_organisation_e_mail')->getString();
        if (!empty($headOrganisationEmails)) {
          break; // Get first non-empty email.
        }
      }
    }

    return $headOrganisationEmails;
  }

  /**
   * Gets the email from service provider term field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string|null
   *   The email address or null if not found.
   */
  protected function getOrganisationTermField($node) {
    // Check if the field exists
    if (!$node->hasField('field_service_provider')) {
      $this->logger->warning('field_service_provider does not exist on node @nid', [
        '@nid' => $node->id(),
      ]);
      return NULL;
    }
    
    // Get the service provider ID
    $tid = $node->get('field_service_provider')->target_id;
    if ($tid !== NULL) {
      $term = \Drupal\taxonomy\Entity\Term::load($tid);
      if ($term && $term->hasField('field_sp_email')) {
        return $term->get('field_sp_email')->getString();
      } elseif ($term && $term->hasField('field_head_organisation_e_mail')) {
        return $term->get('field_head_organisation_e_mail')->getString();
      }
    }
    $this->logger->notice('No service provider email address found for node @nid', [
      '@nid' => $node->id(),
    ]);
    return NULL;
  }
}