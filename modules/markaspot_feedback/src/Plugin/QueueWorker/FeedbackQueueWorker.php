<?php

namespace Drupal\markaspot_feedback\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Processes feedback emails for service request nodes.
 *
 * @QueueWorker(
 *   id = "markaspot_feedback_queue_worker",
 *   title = @Translation("Feedback Email Processor"),
 *   cron = {"time" = 60}
 * )
 */
class FeedbackQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new FeedbackQueueWorker.
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
      $container->get('logger.factory')->get('markaspot_feedback'),
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

    $this->logger->notice('Processing feedback email for node ID: @nid', ['@nid' => $nid]);

    try {
      // Load the node by ID.
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node) {
        $this->logger->warning('Node with ID @nid not found.', ['@nid' => $nid]);
        return;
      }
      
      // Get config to check what statuses should be processed
      $config = $this->configFactory->get('markaspot_feedback.settings');
      $eligible_statuses = $this->arrayFlatten($config->get('status_resubmissive'));
      $current_status = $node->get('field_status')->target_id;
      
      // Skip already archived nodes or nodes that are no longer in the eligible status
      if (!in_array($current_status, $eligible_statuses)) {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($current_status);
        $status_name = $term ? $term->label() : 'Unknown';
        $this->logger->notice('Skipping node @nid: Status is @status (@name) which is not eligible for feedback', [
          '@nid' => $nid,
          '@status' => $current_status,
          '@name' => $status_name
        ]);
        return;
      }

      // Get configuration.
      $config = $this->configFactory->get('markaspot_feedback.settings');
      $mailText = $config->get('mailtext');

      // Get recipient email.
      $to = $node->field_e_mail->value;
      if (empty($to)) {
        $this->logger->warning('No recipient email found for node @nid.', ['@nid' => $nid]);
        return;
      }

      // Prepare mail parameters.
      $module = "markaspot_feedback";
      $key = 'resubmit_request';
      $params['message'] = $this->getBody($node, $mailText);
      $params['node_title'] = $node->label();
      $langcode = $this->languageManager->getDefaultLanguage()->getId();

      // Send the email.
      $this->logger->notice('Sending feedback email to: @to', ['@to' => $to]);
      $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, 'no-reply@site', TRUE);

      if ($result['result']) {
        $this->logger->notice('Feedback email sent for node @nid.', ['@nid' => $nid]);
        
        // Update the node status to archive.
        $node->field_status->target_id = key($config->get('set_archive_tid'));
        $node->save();
        $this->logger->notice('Node @nid status updated to archived after feedback sent.', ['@nid' => $nid]);
      }
      else {
        $this->logger->error('Failed to send feedback email for node @nid.', ['@nid' => $nid]);
      }
    }
    catch (\Exception $e) {
      $this->logger->critical('Error processing feedback email for node @nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage() . "\n" . $e->getTraceAsString(),
      ]);
    }
  }

  /**
   * Gets the email body for feedback.
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
   * Helper function to flatten an array.
   *
   * @param array $array
   *   The array to flatten.
   *
   * @return array
   *   The flattened array.
   */
  protected function arrayFlatten($array) {
    $result = [];
    foreach ($array as $key => $value) {
      $result[] = $key;
    }
    return $result;
  }
}