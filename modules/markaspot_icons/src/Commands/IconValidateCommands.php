<?php

declare(strict_types=1);

namespace Drupal\markaspot_icons\Commands;

use Drupal\markaspot_icons\IconMigrationService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush gate for icon migration integrity on all icon fields.
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
   * Reports and optionally repairs invalid icon values on all icon fields.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'markaspot:icons-validate', aliases: ['mas:icons-validate'])]
  #[CLI\Option(name: 'fix', description: 'Convert legacy notations, mappings and aliases to i-lucide-*. Unresolvable values are kept. Without this option no data is changed.')]
  #[CLI\Usage(name: 'drush markaspot:icons-validate', description: 'Report invalid category, status and page icons and exit non-zero when found.')]
  #[CLI\Usage(name: 'drush markaspot:icons-validate --fix', description: 'Repair convertible icons, then report every affected entity and the values that need an editorial choice.')]
  public function validate(array $options = ['fix' => FALSE]): int {
    $fix = !empty($options['fix']);
    $report = $this->migration->validateAndRepair($fix);

    if ($report === []) {
      $this->output()->writeln('OK: no invalid icon values found.');
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln("Entity\tID\tLabel\tField\tLang\tValue\tReplacement\tIssue\tAction");
    $fixed = 0;
    $remaining = 0;
    foreach ($report as $row) {
      $action = (string) $row['action'];
      $fixed += $action === 'fixed' ? 1 : 0;
      $remaining += in_array($action, ['unresolved', 'error'], TRUE) ? 1 : 0;
      $this->output()->writeln(implode("\t", [
        (string) $row['entity_type'],
        (string) $row['entity_id'],
        $this->sanitizeCell((string) $row['label']),
        (string) $row['field'],
        (string) $row['langcode'],
        $this->sanitizeCell((string) $row['value']),
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
      'Result: %d repaired, %d require manual review or could not be saved.',
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
