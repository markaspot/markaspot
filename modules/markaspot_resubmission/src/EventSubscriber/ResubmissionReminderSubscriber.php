<?php

namespace Drupal\markaspot_resubmission\EventSubscriber;

use Drupal\markaspot_resubmission\Event\ResubmissionReminderEvent;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Token;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for resubmission reminder events.
 *
 * Sends email notifications when a resubmission reminder event is fired.
 */
class ResubmissionReminderSubscriber implements EventSubscriberInterface {

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
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs a ResubmissionReminderSubscriber object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    LoggerChannelInterface $logger,
    Token $token
  ) {
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->logger = $logger;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ResubmissionReminderEvent::EVENT_NAME => ['onReminderSend', 100],
    ];
  }

  /**
   * Responds to resubmission reminder events.
   *
   * @param \Drupal\markaspot_resubmission\Event\ResubmissionReminderEvent $event
   *   The event object.
   */
  public function onReminderSend(ResubmissionReminderEvent $event) {
    $node = $event->getNode();
    $recipient = $event->getRecipientEmail();
    $reminder_count = $event->getReminderCount();

    // Get language for the email (use node language if available).
    $langcode = $node->language()->getId();

    // Prepare email parameters.
    // Token replacement happens in hook_mail() using the mail config.
    $params = [
      'node' => $node,
      'node_title' => $node->getTitle(),
      'recipient' => $recipient,
      'reminder_count' => $reminder_count,
    ];

    // Send the email.
    $result = $this->mailManager->mail(
      'markaspot_resubmission',
      'resubmit_request',
      $recipient,
      $langcode,
      $params,
      NULL,
      TRUE
    );

    if ($result['result']) {
      $this->logger->info('Sent resubmission reminder email #@count for node @nid to @email', [
        '@count' => $reminder_count,
        '@nid' => $node->id(),
        '@email' => $recipient,
      ]);
    }
    else {
      $this->logger->error('Failed to send resubmission reminder email for node @nid to @email', [
        '@nid' => $node->id(),
        '@email' => $recipient,
      ]);
    }
  }

}
