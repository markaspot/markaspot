<?php

declare(strict_types=1);

namespace Drupal\markaspot_icons\Commands;

use Drupal\markaspot_icons\IconMigrationService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush gate for taxonomy icon migration integrity.
 */
class IconValidateCommands extends DrushCommands {

  /**
   * Constructs IconValidateCommands.
   */
  public function __construct(
    protected IconMigrationService $migration,
  ) {
    parent::__construct();
  }

  /**
   * Reports and optionally repairs invalid taxonomy icon values.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'markaspot:icons-validate', aliases: ['mas:icons-validate'])]
  #[CLI\Option(name: 'fix', description: 'Apply aliases and fallback icons. Without this option no data is changed.')]
  #[CLI\Usage(name: 'drush markaspot:icons-validate', description: 'Report invalid category and status icons and exit non-zero when found.')]
  #[CLI\Usage(name: 'drush markaspot:icons-validate --fix', description: 'Repair aliases and unresolved icons, then report every affected term.')]
  public function validate(array $options = ['fix' => FALSE]): int {
    $fix = !empty($options['fix']);
    $report = $this->migration->validateAndRepair($fix);

    if ($report === []) {
      $this->output()->writeln('OK: no invalid taxonomy icon values found.');
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln("TID\tTerm\tField\tLang\tValue\tReplacement\tIssue\tAction");
    $fixed = 0;
    $remaining = 0;
    foreach ($report as $row) {
      $action = (string) $row['action'];
      $fixed += $action === 'fixed' ? 1 : 0;
      $remaining += $action === 'unresolved' ? 1 : 0;
      $this->output()->writeln(implode("\t", [
        (string) $row['term_id'],
        $this->sanitizeCell((string) $row['term']),
        (string) $row['field'],
        (string) $row['langcode'],
        (string) $row['value'],
        (string) ($row['replacement'] ?? ''),
        (string) $row['issue'],
        $action,
      ]));
    }

    if (!$fix) {
      $this->output()->writeln(sprintf(
        'FAIL: %d invalid or unverifiable icon value(s) found. Re-run with --fix.',
        count($report),
      ));
      return self::EXIT_FAILURE;
    }

    $this->output()->writeln(sprintf(
      'Result: %d repaired, %d require manual review.',
      $fixed,
      $remaining,
    ));
    return $remaining > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Removes control whitespace from a report cell.
   */
  private function sanitizeCell(string $value): string {
    return preg_replace('/\s+/', ' ', $value) ?? $value;
  }

}
