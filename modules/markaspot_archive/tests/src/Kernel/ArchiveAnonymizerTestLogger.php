<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Kernel;

use Psr\Log\AbstractLogger;

/**
 * Logger sink for anonymizer assertions.
 */
final class ArchiveAnonymizerTestLogger extends AbstractLogger {

  /**
   * Collected log records.
   *
   * @var array<int, array{level: mixed, message: string|\Stringable, context: array}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'message' => $message,
      'context' => $context,
    ];
  }

}
