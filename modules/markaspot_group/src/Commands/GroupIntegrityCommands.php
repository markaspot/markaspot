<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Commands;

use Drupal\markaspot_group\Service\GroupIntegrityChecker;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for group integrity checks and repairs.
 */
class GroupIntegrityCommands extends DrushCommands {

  /**
   * Constructs GroupIntegrityCommands.
   */
  public function __construct(
    protected GroupIntegrityChecker $integrityChecker,
  ) {
    parent::__construct();
  }

  /**
   * Checks group relationship and denormalized field integrity.
   */
  #[CLI\Command(name: 'markaspot:group:integrity', aliases: ['mas:group:integrity', 'mas:check-group-integrity'])]
  #[CLI\Option(name: 'details', description: 'Print row-level details for failing checks.')]
  #[CLI\Usage(name: 'markaspot:group:integrity', description: 'Show group integrity issue counts.')]
  #[CLI\Usage(name: 'markaspot:group:integrity --details', description: 'Show counts and affected entity IDs.')]
  public function check(array $options = ['details' => FALSE]): int {
    $results = $this->integrityChecker->check();
    $hasIssues = FALSE;

    foreach ($results as $id => $result) {
      $count = count($result['rows']);
      $hasIssues = $hasIssues || $count > 0;
      $status = $count === 0 ? 'OK' : 'FAIL';
      $this->output()->writeln(sprintf('%s %s: %d', $status, $id, $count));
      $this->output()->writeln('  ' . $result['description']);

      if ($options['details'] && $count > 0) {
        foreach (array_slice($result['rows'], 0, 50) as $row) {
          $this->output()->writeln('  - ' . json_encode($row, JSON_THROW_ON_ERROR));
        }
        if ($count > 50) {
          $this->output()->writeln(sprintf('  ... %d more', $count - 50));
        }
      }
    }

    return $hasIssues ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Checks expected service request group assignment correctness.
   */
  #[CLI\Command(name: 'markaspot:group:correctness', aliases: ['mas:check-group-correctness'])]
  #[CLI\Option(name: 'details', description: 'Print row-level details for failing checks.')]
  #[CLI\Usage(name: 'markaspot:group:correctness', description: 'Show expected-vs-actual service request group assignment issue counts.')]
  #[CLI\Usage(name: 'markaspot:group:correctness --details', description: 'Show counts and affected entity IDs.')]
  public function correctness(array $options = ['details' => FALSE]): int {
    $results = $this->integrityChecker->correctness();
    $hasIssues = FALSE;

    foreach ($results as $id => $result) {
      $count = count($result['rows']);
      $hasIssues = $hasIssues || $count > 0;
      $status = $count === 0 ? 'OK' : 'FAIL';
      $this->output()->writeln(sprintf('%s %s: %d', $status, $id, $count));
      $this->output()->writeln('  ' . $result['description']);

      if ($options['details'] && $count > 0) {
        foreach (array_slice($result['rows'], 0, 50) as $row) {
          $this->output()->writeln('  - ' . json_encode($row, JSON_THROW_ON_ERROR));
        }
        if ($count > 50) {
          $this->output()->writeln(sprintf('  ... %d more', $count - 50));
        }
      }
    }

    return $hasIssues ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Repairs safely recoverable group integrity drift.
   */
  #[CLI\Command(name: 'markaspot:group:repair', aliases: ['mas:repair-group-integrity'])]
  #[CLI\Option(name: 'apply', description: 'Apply repairs. Without this option the command runs as a dry-run.')]
  #[CLI\Option(name: 'expected-org-backfill', description: 'With --apply, also apply retroactive expected organisation assignment backfills. Without this option the backfill is reported as a dry-run.')]
  #[CLI\Usage(name: 'markaspot:group:repair', description: 'Dry-run orphan cleanup, mirror drift repair, and expected organisation backfill.')]
  #[CLI\Usage(name: 'markaspot:group:repair --apply', description: 'Apply safely recoverable orphan and mirror drift repairs. Expected organisation backfill remains dry-run.')]
  #[CLI\Usage(name: 'markaspot:group:repair --apply --expected-org-backfill', description: 'Also apply conservative retroactive expected organisation assignment backfills.')]
  public function repair(array $options = ['apply' => FALSE, 'expected-org-backfill' => FALSE]): int {
    $apply = (bool) $options['apply'];
    $applyExpectedOrgBackfill = $apply && (bool) $options['expected-org-backfill'];
    $orphanResults = $this->integrityChecker->repairOrphanRelationships(!$apply);
    $mirrorResults = $this->integrityChecker->repairMirrorDrift(!$apply);
    $expectedOrgResults = $this->integrityChecker->repairExpectedOrganisationAssignments(!$applyExpectedOrgBackfill);

    $this->output()->writeln($apply ? 'Applying repairs.' : 'Dry-run only. Pass --apply to apply repairs.');
    $this->output()->writeln('Relationships with missing groups are reported but skipped by this repair command.');
    if ($apply && !$applyExpectedOrgBackfill) {
      $this->output()->writeln('Expected organisation assignment backfill is dry-run only. Pass --expected-org-backfill with --apply to apply it.');
    }
    $this->output()->writeln('Orphan relationship repair:');
    foreach ($orphanResults as $id => $count) {
      $this->output()->writeln(sprintf('%s: %d', $id, $count));
    }

    $this->output()->writeln('Mirror drift repair:');
    foreach ($mirrorResults as $id => $count) {
      $this->output()->writeln(sprintf('%s: %d', $id, $count));
    }

    $this->output()->writeln('Expected organisation assignment backfill:');
    foreach ($expectedOrgResults as $id => $count) {
      $this->output()->writeln(sprintf('%s: %d', $id, $count));
    }

    return self::EXIT_SUCCESS;
  }

}
