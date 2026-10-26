<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\node\NodeInterface;

/**
 * Fired after an inbound email was turned into a new service request.
 *
 * Allows ECA models or event subscribers to send an auto-reply to the
 * citizen, notify staff, or post-process the created node.
 */
class InboundRequestCreatedEvent extends Event {

  /**
   * The event name.
   */
  const EVENT_NAME = 'markaspot_mail_inbound.request_created';

  /**
   * Constructs an InboundRequestCreatedEvent.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The created service request node.
   * @param \Drupal\markaspot_mail_inbound\Dto\InboundMessage $message
   *   The originating email message.
   * @param bool $staffInitiated
   *   TRUE when staff promoted the mail by hand, FALSE for the auto-promote
   *   worker and for callers that predate the flag.
   */
  public function __construct(
    protected NodeInterface $node,
    protected InboundMessage $message,
    protected bool $staffInitiated = FALSE,
  ) {
  }

  /**
   * Gets the created service request node.
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * Gets the originating email message.
   */
  public function getMessage(): InboundMessage {
    return $this->message;
  }

  /**
   * Whether staff promoted the mail by hand (dashboard API, admin form).
   */
  public function isStaffInitiated(): bool {
    return $this->staffInitiated;
  }

}
