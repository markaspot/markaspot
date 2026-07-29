<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Plugin\Action;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\Address;

/**
 * Provides the shared throttled dispatch path for notification actions.
 *
 * Both explicit-key and status-term actions must pass through one path so a
 * new trigger cannot bypass the per-recipient flood protection.
 */
abstract class NotificationMailActionBase extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  private const RECIPIENT_FLOOD_EVENT = 'markaspot_mail.notification_recipient';

  private const DEFAULT_RECIPIENT_FLOOD_LIMIT = 5;

  private const DEFAULT_RECIPIENT_FLOOD_WINDOW = 3600;

  private const MAX_FLOOD_IDENTIFIER_LENGTH = 128;

  /**
   * Constructs a notification mail action.
   *
   * Final so that create()'s `new static()` is safe for every subclass
   * (PHPStan: unsafe-new-static) — subclasses inject nothing of their own.
   */
  final public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly Token $token,
    protected readonly MailManagerInterface $mailManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FloodInterface $flood,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('token'),
      $container->get('plugin.manager.mail'),
      $container->get('config.factory'),
      $container->get('flood'),
      $container->get('logger.factory')->get('markaspot_mail'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'recipient' => '[node:field_e_mail:value]',
    ] + parent::defaultConfiguration();
  }

  /**
   * Builds the shared recipient configuration element.
   */
  protected function recipientConfigurationElement(): array {
    return [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient email address'),
      '#default_value' => $this->configuration['recipient'],
      '#maxlength' => 254,
      '#description' => $this->t('You may use tokens, e.g. [node:field_e_mail:value]. Separate multiple recipients with a comma.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['recipient'] = (string) $form_state->getValue('recipient');
  }

  /**
   * Sends one notification through the common recipient throttle.
   */
  protected function sendNotification(NodeInterface $entity, string $notificationKey): void {
    if (empty($this->configuration['node'])) {
      $this->configuration['node'] = $entity;
    }

    $resolved_recipient = trim(Html::decodeEntities(
      $this->token->replace((string) $this->configuration['recipient'], $this->configuration)
    ));
    if ($resolved_recipient === '') {
      $this->logger->warning('markaspot_mail_send_notification: recipient resolved to an empty string for node @nid.', ['@nid' => $entity->id()]);
      return;
    }

    $recipient_mailboxes = $this->parseRecipientMailboxes($resolved_recipient);
    if ($recipient_mailboxes === []) {
      $this->logger->warning('markaspot_mail_send_notification: recipient resolved without a usable address for node @nid.', ['@nid' => $entity->id()]);
      return;
    }
    $recipient = implode(', ', array_map(
      static fn (Address $address): string => $address->toString(),
      $recipient_mailboxes,
    ));
    $recipient_addresses = array_values(array_unique(array_map(
      static fn (Address $address): string => mb_strtolower($address->getAddress()),
      $recipient_mailboxes,
    )));

    $flood_config = $this->configFactory->get('markaspot_mail.settings');
    $flood_limit = (int) $flood_config->get('recipient_flood.limit');
    $flood_window = (int) $flood_config->get('recipient_flood.window');
    $flood_limit = $flood_limit > 0 ? $flood_limit : self::DEFAULT_RECIPIENT_FLOOD_LIMIT;
    $flood_window = $flood_window > 0 ? $flood_window : self::DEFAULT_RECIPIENT_FLOOD_WINDOW;

    $flood_buckets = [];
    foreach ($recipient_addresses as $recipient_address) {
      $recipient_hash = substr(hash('sha256', $recipient_address), 0, 12);
      $recipient_identifier = $this->recipientFloodIdentifier($recipient_address);
      $flood_buckets[] = [
        'identifier' => $recipient_identifier,
        'recipient_hash' => $recipient_hash,
      ];

      try {
        $allowed = $this->flood->isAllowed(
          self::RECIPIENT_FLOOD_EVENT,
          $flood_limit,
          $flood_window,
          $recipient_identifier,
        );
      }
      catch (\Throwable $e) {
        // Flood storage failures must not block the notification path.
        $allowed = TRUE;
        $this->logger->warning('Recipient mail throttle check failed for address hash @recipient_hash on node @nid (@exception).', [
          '@recipient_hash' => $recipient_hash,
          '@nid' => $entity->id(),
          '@exception' => get_debug_type($e),
        ]);
      }

      if ($allowed) {
        continue;
      }

      $this->logger->warning('Recipient mail throttle reached for address hash @recipient_hash; skipping notification "@key" for node @nid.', [
        '@recipient_hash' => $recipient_hash,
        '@key' => $notificationKey,
        '@nid' => $entity->id(),
      ]);
      return;
    }

    $langcode = $entity->language()->getId();
    $params = [
      'node' => $entity,
      'notification_key' => $notificationKey,
    ];

    $message = $this->mailManager->mail('markaspot_mail', 'notification_' . $notificationKey, $recipient, $langcode, $params);
    if ($message['result']) {
      foreach ($flood_buckets as $flood_bucket) {
        try {
          $this->flood->register(
            self::RECIPIENT_FLOOD_EVENT,
            $flood_window,
            $flood_bucket['identifier'],
          );
        }
        catch (\Throwable $e) {
          // A sent mail cannot be rolled back if flood registration fails.
          $this->logger->warning('Recipient mail throttle registration failed for address hash @recipient_hash on node @nid (@exception).', [
            '@recipient_hash' => $flood_bucket['recipient_hash'],
            '@nid' => $entity->id(),
            '@exception' => get_debug_type($e),
          ]);
        }
      }
      $this->logger->info('Sent notification "@key" to %recipient for node @nid.', [
        '@key' => $notificationKey,
        '%recipient' => $recipient,
        '@nid' => $entity->id(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowed();
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Parses recipients before throttling so display names cannot change buckets.
   *
   * @return list<\Symfony\Component\Mime\Address>
   *   Valid recipient mailboxes, or an empty list when any entry is invalid.
   */
  private function parseRecipientMailboxes(string $recipient): array {
    $mailboxes = [];
    foreach (str_getcsv($recipient, escape: '\\') as $recipient_entry) {
      $recipient_entry = trim((string) $recipient_entry);
      if ($recipient_entry === '') {
        continue;
      }
      try {
        $mailboxes[] = Address::create($recipient_entry);
      }
      catch (\Throwable) {
        // Invalid mailbox syntax must not make flood control fail the action.
        return [];
      }
    }
    return $mailboxes;
  }

  /**
   * Keeps ordinary addresses readable while respecting flood storage limits.
   */
  private function recipientFloodIdentifier(string $recipientAddress): string {
    if (strlen($recipientAddress) <= self::MAX_FLOOD_IDENTIFIER_LENGTH) {
      return $recipientAddress;
    }
    return 'sha256:' . hash('sha256', $recipientAddress);
  }

}
