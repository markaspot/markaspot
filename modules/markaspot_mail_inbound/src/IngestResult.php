<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound;

/**
 * Outcome of running one inbound message through MailIngestOrchestrator.
 *
 * The affected entity id is exposed as a NEUTRAL $entityId rather than a node
 * id (review LOW 6): a STAGED, DISCARDED or REPLY-to-staged result carries an
 * inbound_mail id, while CREATED, PROMOTED, REPLY-to-node and DUPLICATE carry a
 * service_request node id. The $kind discriminator says which, so a consumer
 * never dereferences an inbound_mail id as a node.
 */
final class IngestResult {

  public const CREATED = 'created';
  public const STAGED = 'staged';
  public const PROMOTED = 'promoted';
  public const DISCARDED = 'discarded';
  public const DUPLICATE = 'duplicate';
  public const REPLY = 'reply';
  public const BLOCKED = 'blocked';
  public const INVALID = 'invalid';

  /**
   * The result references no entity.
   */
  public const KIND_NONE = 'none';

  /**
   * The $entityId is an inbound_mail entity id.
   */
  public const KIND_MAIL = 'inbound_mail';

  /**
   * The $entityId is a service_request node id.
   */
  public const KIND_NODE = 'node';

  /**
   * Constructs an IngestResult.
   *
   * @param string $status
   *   One of the status constants.
   * @param int|null $entityId
   *   The affected entity id, or NULL. Its meaning is given by $kind: an
   *   inbound_mail id (KIND_MAIL) or a service_request node id (KIND_NODE).
   * @param string $kind
   *   One of the KIND_* constants describing what $entityId refers to.
   * @param string $reason
   *   Human readable reason, safe for logs (never contains message bodies).
   */
  public function __construct(
    public readonly string $status,
    public readonly ?int $entityId = NULL,
    public readonly string $kind = self::KIND_NONE,
    public readonly string $reason = '',
  ) {
  }

  /**
   * Creates a CREATED result (a service_request node).
   *
   * Retained for backward compatibility. The rebuilt orchestrator stages mail
   * instead of creating a service request, so it emits STAGED; an explicit
   * promotion emits PROMOTED.
   */
  public static function created(int $nid): self {
    return new self(self::CREATED, $nid, self::KIND_NODE);
  }

  /**
   * Creates a STAGED result (a new inbound_mail awaiting triage).
   *
   * @param int $mailId
   *   The staged inbound_mail entity id.
   */
  public static function staged(int $mailId): self {
    return new self(self::STAGED, $mailId, self::KIND_MAIL, 'Staged inbound mail');
  }

  /**
   * Creates a PROMOTED result (an inbound_mail turned into a service request).
   *
   * @param int $nid
   *   The created service request node id.
   */
  public static function promoted(int $nid): self {
    return new self(self::PROMOTED, $nid, self::KIND_NODE, 'Promoted to service request');
  }

  /**
   * Creates a DISCARDED result (spam / not-a-report, recorded for audit).
   *
   * @param int $mailId
   *   The discarded inbound_mail entity id.
   */
  public static function discarded(int $mailId): self {
    return new self(self::DISCARDED, $mailId, self::KIND_MAIL, 'Discarded (not a report)');
  }

  /**
   * Creates a DUPLICATE result against an already-ingested inbound_mail.
   *
   * @param int $mailId
   *   The existing inbound_mail entity id.
   */
  public static function duplicate(int $mailId): self {
    return new self(self::DUPLICATE, $mailId, self::KIND_MAIL, 'Message-ID already ingested');
  }

  /**
   * Creates a REPLY result threaded onto a staged inbound_mail.
   *
   * @param int $mailId
   *   The staged inbound_mail entity id the reply was appended to.
   */
  public static function replyToMail(int $mailId): self {
    return new self(self::REPLY, $mailId, self::KIND_MAIL, 'Threaded reply onto staged mail');
  }

  /**
   * Creates a REPLY result threaded onto a promoted service request node.
   *
   * @param int $nid
   *   The promoted service request node id the reply was appended to.
   */
  public static function replyToNode(int $nid): self {
    return new self(self::REPLY, $nid, self::KIND_NODE, 'Threaded reply to existing request');
  }

  /**
   * Creates a BLOCKED result.
   */
  public static function blocked(string $reason): self {
    return new self(self::BLOCKED, NULL, self::KIND_NONE, $reason);
  }

  /**
   * Creates an INVALID result.
   */
  public static function invalid(string $reason): self {
    return new self(self::INVALID, NULL, self::KIND_NONE, $reason);
  }

}
