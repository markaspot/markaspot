<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\node\NodeInterface;

/**
 * Fired when an inbound email is a reply to an existing service request.
 *
 * The pipeline detects replies via Message-ID threading (In-Reply-To and
 * References headers matching field_email_message_id) and intentionally
 * does NOT create a second request. Subscribers and ECA models can use
 * this event to notify assigned staff or acknowledge the citizen.
 */
class InboundReplyReceivedEvent extends Event {

  /**
   * The event name.
   */
  const EVENT_NAME = 'markaspot_mail_inbound.reply_received';

  /**
   * Constructs an InboundReplyReceivedEvent.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The existing service request node the reply belongs to.
   * @param \Drupal\markaspot_mail_inbound\Dto\InboundMessage $message
   *   The reply email message.
   */
  public function __construct(
    protected NodeInterface $node,
    protected InboundMessage $message,
  ) {
  }

  /**
   * Gets the service request node the reply belongs to.
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * Gets the reply email message.
   */
  public function getMessage(): InboundMessage {
    return $this->message;
  }

}
