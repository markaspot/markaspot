<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Exception;

/**
 * Thrown when a mail cannot be promoted because of a concurrent promotion.
 *
 * Either another request holds the promotion lock for the same mail, or the
 * stored mail is no longer staged (a colleague or the auto-promote worker
 * created the report first). Extends RuntimeException so callers that treat
 * a RuntimeException as "do not retry" (the auto-promote worker) keep doing
 * so; the dashboard API catches it first and answers 409.
 */
class PromotionConflictException extends \RuntimeException {

  /**
   * Another request holds the promotion lock for this mail.
   */
  public const IN_PROGRESS = 'promotion_in_progress';

  /**
   * The stored mail is no longer staged.
   */
  public const NOT_STAGED = 'not_staged';

  /**
   * Constructs a PromotionConflictException.
   *
   * @param string $reason
   *   One of the class constants.
   * @param string $message
   *   The internal message (logged, never sent to a client).
   */
  public function __construct(
    public readonly string $reason,
    string $message = '',
  ) {
    parent::__construct($message);
  }

}
