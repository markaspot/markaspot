<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service\Exception;

/**
 * Thrown when a DELETE targets a custom key still referenced by ECA.
 *
 * Carries the referencing eca.eca.* model IDs so the controller can surface
 * them in the 409 response body's `referenced_by` list.
 */
final class MailTextsConflictException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $message
   *   The error message.
   * @param list<string> $referencedBy
   *   The eca.eca.* model IDs (config `id`) that still reference the key.
   */
  public function __construct(string $message, private readonly array $referencedBy) {
    parent::__construct($message);
  }

  /**
   * Gets the referencing eca.eca.* model IDs.
   *
   * @return list<string>
   *   The model IDs, e.g. ['process_confirm_report'].
   */
  public function getReferencedBy(): array {
    return $this->referencedBy;
  }

}
