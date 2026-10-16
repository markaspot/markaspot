<?php

namespace Drupal\markaspot_open311\Service;

/**
 * Thrown when a remote Open311 media_url is rejected or cannot be fetched.
 */
class RemoteImageException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $reason
   *   Machine-readable reason (e.g. blocked_address, not_an_image).
   * @param string $message
   *   Client-safe message without internal details.
   * @param \Throwable|null $previous
   *   The underlying error, if any.
   */
  public function __construct(
    protected string $reason,
    string $message,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Returns the machine-readable reason.
   */
  public function getReason(): string {
    return $this->reason;
  }

}
