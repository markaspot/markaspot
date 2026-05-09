<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\markaspot_health\SmokeCheckPluginManager;
use Drupal\markaspot_health\SmokeCheckResult;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Mark-a-Spot smoke checks.
 */
class SmokeCommands extends DrushCommands {

  /**
   * Constructs SmokeCommands.
   */
  public function __construct(
    protected SmokeCheckPluginManager $pluginManager,
  ) {
    parent::__construct();
  }

  /**
   * Runs the Mark-a-Spot tenant API smoke suite.
   *
   * @param array $options
   *   Command options.
   *
   * @option mode
   *   Run mode: read-only (default, prod-safe) or full (mutating, test only).
   * @option severity
   *   Filter by severity: error, warning, or info. Defaults to all.
   * @option category
   *   Filter by category: http_sanity, drupal_internal, wrap, ...
   * @option format
   *   Output format: table or json. Defaults to table.
   * @option exit-non-zero
   *   When set, exit code equals the number of failed error-severity checks.
   * @option jurisdiction
   *   Optional jurisdiction id passed as context to plugins.
   * @option jurisdiction-other
   *   Optional second jurisdiction id, used by cross-tenant checks like the
   *   F-21 scope-lock acceptance test to claim a foreign jurisdiction.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields|null
   *   Structured rows for table output, NULL when format is json.
   */
  #[CLI\Command(name: 'markaspot:smoke', aliases: ['mas:smoke'])]
  #[CLI\Option(name: 'mode', description: 'Run mode: read-only (default) or full.')]
  #[CLI\Option(name: 'severity', description: 'Filter by severity: error, warning, info.')]
  #[CLI\Option(name: 'category', description: 'Filter by category, e.g. http_sanity, drupal_internal, wrap.')]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Option(name: 'exit-non-zero', description: 'Exit non-zero when error-severity checks fail.')]
  #[CLI\Option(name: 'jurisdiction', description: 'Jurisdiction id passed to plugins as context.')]
  #[CLI\Option(name: 'jurisdiction-other', description: 'Second jurisdiction id for cross-tenant checks (e.g. F-21 scope-lock).')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Label',
    'status' => 'Status',
    'severity' => 'Severity',
    'category' => 'Category',
    'count' => 'Count',
    'message' => 'Message',
    'fix' => 'Fix',
  ])]
  #[CLI\Usage(name: 'drush markaspot:smoke', description: 'Run prod-safe smoke suite, print a table.')]
  #[CLI\Usage(name: 'drush markaspot:smoke --severity=error --exit-non-zero', description: 'CI gate; non-zero exit on any error-severity failure.')]
  #[CLI\Usage(name: 'drush markaspot:smoke --format=json', description: 'Print JSON for machine consumption.')]
  public function smoke(
    array $options = [
      'mode' => SmokeCheckResult::MODE_READ_ONLY,
      'severity' => NULL,
      'category' => NULL,
      'format' => 'table',
      'exit-non-zero' => FALSE,
      'jurisdiction' => NULL,
      'jurisdiction-other' => NULL,
    ],
  ): ?RowsOfFields {
    $context = $this->buildContext($options);
    $results = $this->pluginManager->runAll($context);

    $severityFilter = $options['severity'] ?? NULL;
    if ($severityFilter !== NULL && $severityFilter !== '') {
      $results = array_values(array_filter(
        $results,
        static fn(SmokeCheckResult $r): bool => $r->severity === $severityFilter,
      ));
    }

    $errorCount = 0;
    foreach ($results as $result) {
      if ($result->failed() && $result->severity === 'error') {
        $errorCount++;
      }
    }

    $format = $options['format'] ?? 'table';
    if ($format === 'json') {
      $this->output()->writeln((string) json_encode(
        array_map(
          static fn(SmokeCheckResult $r): array => $r->toArray(),
          $results,
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
      ));
      $this->maybeFail($options, $errorCount);
      return NULL;
    }

    $rows = [];
    foreach ($results as $result) {
      $rows[] = [
        'id' => $result->id(),
        'label' => $result->label,
        'status' => strtoupper($result->status),
        'severity' => $result->severity,
        'category' => $result->category,
        'count' => $result->count,
        'message' => $result->message,
        'fix' => $result->passed() ? '' : ($result->fixHint ?? ''),
      ];
    }

    $this->maybeFail($options, $errorCount);
    return new RowsOfFields($rows);
  }

  /**
   * Builds a normalised context array from CLI options.
   *
   * @param array<string, mixed> $options
   *   CLI options.
   *
   * @return array<string, mixed>
   *   Context handed to plugin run() calls.
   */
  protected function buildContext(array $options): array {
    $context = [];

    $mode = $options['mode'] ?? SmokeCheckResult::MODE_READ_ONLY;
    $context['mode'] = $mode === SmokeCheckResult::MODE_FULL
      ? SmokeCheckResult::MODE_FULL
      : SmokeCheckResult::MODE_READ_ONLY;

    if (!empty($options['category'])) {
      $context['category'] = (string) $options['category'];
    }

    $jurisdiction = $options['jurisdiction'] ?? NULL;
    if ($jurisdiction !== NULL && $jurisdiction !== '') {
      $jid = filter_var($jurisdiction, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($jid === FALSE) {
        throw new \RuntimeException(sprintf('--jurisdiction must be a positive integer, got "%s".', (string) $jurisdiction));
      }
      $context['jurisdiction'] = $jid;
    }

    $jurisdictionOther = $options['jurisdiction-other'] ?? NULL;
    if ($jurisdictionOther !== NULL && $jurisdictionOther !== '') {
      $jidOther = filter_var($jurisdictionOther, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($jidOther === FALSE) {
        throw new \RuntimeException(sprintf('--jurisdiction-other must be a positive integer, got "%s".', (string) $jurisdictionOther));
      }
      $context['jurisdiction_other'] = $jidOther;
    }

    return $context;
  }

  /**
   * Throws a command failure when --exit-non-zero is set and errors exist.
   */
  protected function maybeFail(array $options, int $errorCount): void {
    if (!empty($options['exit-non-zero']) && $errorCount > 0) {
      throw new \RuntimeException(sprintf(
        '%d error-severity smoke check(s) failed.',
        $errorCount,
      ));
    }
  }

}
