<?php

namespace Drupal\markaspot_resubmission\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\node\NodeInterface;

/**
 * Event fired when a resubmission reminder should be sent.
 *
 * This event allows ECA or other event subscribers to handle
 * the actual email sending with custom templates.
 */
class ResubmissionReminderEvent extends Event {

  /**
   * The event name.
   */
  const EVENT_NAME = 'markaspot_resubmission.send_reminder';

  /**
   * The service request node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * The recipient email address.
   *
   * @var string
   */
  protected $recipientEmail;

  /**
   * The mail template text.
   *
   * @var string
   */
  protected $mailText;

  /**
   * The reminder count for this node.
   *
   * @var int
   */
  protected $reminderCount;

  /**
   * Constructs a ResubmissionReminderEvent object.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $recipient_email
   *   The recipient email address.
   * @param string $mail_text
   *   The mail template text (for fallback).
   * @param int $reminder_count
   *   The reminder count for this node.
   */
  public function __construct(NodeInterface $node, $recipient_email, $mail_text, $reminder_count = 1) {
    $this->node = $node;
    $this->recipientEmail = $recipient_email;
    $this->mailText = $mail_text;
    $this->reminderCount = $reminder_count;
  }

  /**
   * Gets the service request node.
   *
   * @return \Drupal\node\NodeInterface
   *   The node.
   */
  public function getNode() {
    return $this->node;
  }

  /**
   * Gets the recipient email address.
   *
   * @return string
   *   The email address.
   */
  public function getRecipientEmail() {
    return $this->recipientEmail;
  }

  /**
   * Gets the mail template text.
   *
   * @return string
   *   The mail text.
   */
  public function getMailText() {
    return $this->mailText;
  }

  /**
   * Gets the reminder count.
   *
   * @return int
   *   The reminder count.
   */
  public function getReminderCount() {
    return $this->reminderCount;
  }

}
