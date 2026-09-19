<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Exception;

/**
 * Preserves a rejection decision without retaining the provider request/body.
 */
final class ProviderRequestException extends \RuntimeException {

  /**
   * Constructs a bounded API failure with its rejected parameter names.
   */
  public function __construct(string $message, int $status, public readonly array $rejectedParameters) {
    parent::__construct($message, $status);
  }

}
