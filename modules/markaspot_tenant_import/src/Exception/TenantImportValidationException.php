<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Exception;

/**
 * Reports all validation failures found before a tenant import starts.
 */
final class TenantImportValidationException extends \RuntimeException {

  /**
   * Constructs a tenant import validation exception.
   *
   * @param string[] $errors
   *   Collected validation errors.
   */
  public function __construct(
    private readonly array $errors,
  ) {
    parent::__construct(implode(PHP_EOL, $errors));
  }

  /**
   * Returns the collected validation errors.
   *
   * @return string[]
   *   Validation errors in input order.
   */
  public function getErrors(): array {
    return $this->errors;
  }

}
