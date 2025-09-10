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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
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
      $container->get('language_manager')
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
      $moduleHandler = \Drupal::service('module_handler');
      if ($moduleHandler->moduleExists('markaspot_groups')) {
        $to = $this->getGroupField($node);
      }
      else {
        $to = $this->getOrganisationTermField($node);
      }

      if (empty($to)) {
        $this->logger->warning('No recipient email found for node @nid.', ['@nid' => $nid]);
        return;
      }

      // Prepare mail parameters.
      $module = "markaspot_resubmission";
      $key = 'resubmit_request';
      $params['message'] = $this->getBody($node, $mailText);
      $params['node_title'] = $node->label();
      $langcode = $this->languageManager->getDefaultLanguage()->getId();

      // Send the email.
      $this->logger->notice('Sending resubmission notification to: @to', ['@to' => $to]);
      $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, 'no-reply@example.com', TRUE);

      if ($result['result']) {
        $this->logger->notice('Resubmission notification sent for node @nid.', ['@nid' => $nid]);
      }
      else {
        $this->logger->error('Failed to send resubmission notification for node @nid.', ['@nid' => $nid]);
      }
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
    return \Drupal::token()->replace($mailText, $data);
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
      foreach ($group_ids as $group) {
        $affectedGroup = \Drupal\group\Entity\Group::load($group);
        $headOrganisationEmails = $affectedGroup->get('field_head_organisation_e_mail')->getString();
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