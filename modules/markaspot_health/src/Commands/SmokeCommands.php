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
   *   Output format: pretty (default — glyph/colour TTY render mirroring
   *   smoke-external.sh), table (RowsOfFields for --field/--filter/csv/yaml/
   *   list autoderivation), json, tap (v13 for prove/bun test), junit (XML
   *   for CI dashboards).
   * @option exit-non-zero
   *   When set, exit code equals the number of failed error-severity checks.
   * @option jurisdiction
   *   Optional jurisdiction id passed as context to plugins.
   * @option jurisdiction-other
   *   Optional second jurisdiction id, used by cross-tenant checks like the
   *   F-21 scope-lock acceptance test to claim a foreign jurisdiction.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields|null
   *   Structured rows for table output, NULL when format is json/tap/junit.
   */
  #[CLI\Command(name: 'markaspot:smoke', aliases: ['mas:smoke'])]
  #[CLI\Option(name: 'mode', description: 'Run mode: read-only (default) or full.')]
  #[CLI\Option(name: 'severity', description: 'Filter by severity: error, warning, info.')]
  #[CLI\Option(name: 'category', description: 'Filter by category, e.g. http_sanity, drupal_internal, wrap.')]
  #[CLI\Option(name: 'format', description: 'Output format: pretty (default), table, json, tap, junit.')]
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
  #[CLI\Usage(name: 'drush markaspot:smoke', description: 'Run prod-safe smoke suite with the pretty glyph/colour render.')]
  #[CLI\Usage(name: 'drush markaspot:smoke --severity=error --exit-non-zero', description: 'CI gate; non-zero exit on any error-severity failure.')]
  #[CLI\Usage(name: 'drush markaspot:smoke --format=table --fields=id,status', description: 'Structured rows for scripts (--field/--fields/--filter/csv/yaml derive from this).')]
  #[CLI\Usage(name: 'drush markaspot:smoke --format=json', description: 'Print JSON for machine consumption.')]
  #[CLI\Usage(name: 'drush markaspot:smoke --format=tap', description: 'Print TAP v13 for prove/bun test consumers.')]
  #[CLI\Usage(name: 'drush markaspot:smoke --format=junit > smoke.xml', description: 'Print JUnit XML for CI dashboard upload.')]
  public function smoke(
    array $options = [
      'mode' => SmokeCheckResult::MODE_READ_ONLY,
      'severity' => NULL,
      'category' => NULL,
      'format' => 'pretty',
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

    $format = $options['format'] ?? 'pretty';
    switch ($format) {
      case 'json':
        $this->output()->writeln((string) json_encode(
          array_map(
            static fn(SmokeCheckResult $r): array => $r->toArray(),
            $results,
          ),
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
        $this->maybeFail($options, $errorCount);
        return NULL;

      case 'tap':
        $this->output()->writeln($this->formatTap($results));
        $this->maybeFail($options, $errorCount);
        return NULL;

      case 'junit':
        $this->output()->writeln($this->formatJunit($results));
        $this->maybeFail($options, $errorCount);
        return NULL;

      case 'table':
        // Structured-data path: returns RowsOfFields so Drush autoderives
        // --field=id, --filter='status=fail', --format=csv|yaml|list.
        // Operators who want a glyph/colour table use the default
        // (pretty); scripts that consumed RowsOfFields under the previous
        // default need to add --format=table explicitly.
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

    // Default `pretty`: glyph + colour render mirroring smoke-external.sh.
    // Falls back to ASCII glyphs and no colour when the output stream is
    // not decorated (NO_COLOR set, --no-ansi passed, or non-interactive
    // shell that Symfony Console flagged), so logs stay clean either way.
    $this->output()->writeln($this->formatPretty($results, $context));
    $this->maybeFail($options, $errorCount);
    return NULL;
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

  /**
   * Formats results as a pretty TTY table with glyphs and colour.
   *
   * Mirrors the visual language of smoke-external.sh: bold cyan title +
   * dim mode metadata, ✓/✗/⚠/⊘ glyphs, colour-coded status, dim category
   * column, right-aligned duration column with green/yellow/red bands,
   * truncated message column, summary footer, fix hint for the first
   * failure when present.
   *
   * Falls back to ASCII glyphs and no colour when stdout is not a TTY or
   * NO_COLOR is set in the environment.
   *
   * @param array<int, \Drupal\markaspot_health\SmokeCheckResult> $results
   *   Plugin results in the order returned by the plugin manager.
   * @param array<string, mixed> $context
   *   Run context (mode/category/jurisdiction) for the header line.
   *
   * @return string
   *   Rendered pretty table, ready for stdout.
   */
  protected function formatPretty(array $results, array $context): string {
    $supportsColor = $this->ttyColorEnabled();
    $supportsUtf8 = $this->ttyUtf8Enabled();

    $glyphs = $supportsUtf8
      ? ['pass' => '✓', 'fail' => '✗', 'warn' => '⚠', 'skip' => '⊘']
      : ['pass' => '+', 'fail' => 'x', 'warn' => '!', 'skip' => '-'];

    $colours = $supportsColor
      ? [
        'reset' => "\033[0m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'cyan' => "\033[36m",
        'gray' => "\033[90m",
      ]
      : array_fill_keys(['reset', 'bold', 'dim', 'green', 'red', 'yellow', 'cyan', 'gray'], '');

    // Pre-sanitise every plugin-derived string that lands in sprintf so
    // a malicious annotation cannot inject ANSI escapes via id/category/
    // label. Plugin annotations are PHP source today, but supply-chain
    // hardening is cheap.
    $sanitisedResults = [];
    foreach ($results as $r) {
      $sanitisedResults[] = [
        'result' => $r,
        'id' => $this->oneLine($r->id()),
        'category' => $this->oneLine($r->category),
        'label' => $this->oneLine($r->label),
        'message' => $this->oneLine($r->message),
        'fix_hint' => $this->oneLine((string) ($r->fixHint ?? '')),
      ];
    }

    $idWidth = 4;
    $catWidth = 10;
    foreach ($sanitisedResults as $row) {
      $idWidth = max($idWidth, mb_strlen($row['id']));
      $catWidth = max($catWidth, mb_strlen($row['category']));
    }
    // Hard cap on id column so a runaway plugin name does not push the
    // message column off-screen on 80-col terminals.
    $idWidth = min($idWidth, 38);
    $catWidth = min($catWidth, 16);

    $lines = [];
    $lines[] = '';
    $lines[] = sprintf(
      '  %s%sMark-a-Spot smoke%s',
      $colours['bold'],
      $colours['cyan'],
      $colours['reset'],
    );
    $headerMeta = ['mode=' . $this->oneLine((string) ($context['mode'] ?? SmokeCheckResult::MODE_READ_ONLY))];
    if (!empty($context['category'])) {
      $headerMeta[] = 'category=' . $this->oneLine((string) $context['category']);
    }
    if (!empty($context['jurisdiction'])) {
      $headerMeta[] = 'jurisdiction=' . (int) $context['jurisdiction'];
    }
    if (!empty($context['jurisdiction_other'])) {
      $headerMeta[] = 'jurisdiction-other=' . (int) $context['jurisdiction_other'];
    }
    $lines[] = sprintf(
      '  %s%s%s',
      $colours['dim'],
      implode('  ', $headerMeta),
      $colours['reset'],
    );
    $lines[] = '';

    $headerRow = sprintf(
      '  %s   %-4s  %-' . $idWidth . 's  %-' . $catWidth . 's  %8s  MESSAGE%s',
      $colours['bold'],
      '',
      'ID',
      'CATEGORY',
      'TIME',
      $colours['reset'],
    );
    $lines[] = $headerRow;
    $lines[] = sprintf(
      '  %s%s%s',
      $colours['dim'],
      str_repeat('─', max(80, $idWidth + $catWidth + 30)),
      $colours['reset'],
    );

    $counts = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skip' => 0];
    $firstFix = NULL;
    foreach ($sanitisedResults as $row) {
      $r = $row['result'];
      // Status comes from a hard-coded constant set on SmokeCheckResult,
      // so only the default branch's strtoupper($r->status) needs
      // sanitisation — wrap it in oneLine() defensively.
      [$glyph, $glyphColour, $statusColour, $statusLabel, $bucket] = match ($r->status) {
        SmokeCheckResult::STATUS_PASS => [$glyphs['pass'], $colours['green'], $colours['green'], 'PASS', 'pass'],
        SmokeCheckResult::STATUS_FAIL => [$glyphs['fail'], $colours['red'], $colours['red'], 'FAIL', 'fail'],
        SmokeCheckResult::STATUS_WARNING => [$glyphs['warn'], $colours['yellow'], $colours['yellow'], 'WARN', 'warn'],
        SmokeCheckResult::STATUS_SKIP => [$glyphs['skip'], $colours['gray'], $colours['gray'], 'SKIP', 'skip'],
        default => [
          $glyphs['warn'],
          $colours['yellow'],
          $colours['yellow'],
          $this->oneLine(strtoupper($r->status)),
          'warn',
        ],
      };
      $counts[$bucket]++;

      $duration = $r->lastRunDurationMs ?? 0;
      $durationColour = $duration >= 1000
        ? $colours['red']
        : ($duration >= 250 ? $colours['yellow'] : ($duration > 0 ? $colours['green'] : $colours['gray']));

      $id = $this->truncate($row['id'], $idWidth);
      $category = $this->truncate($row['category'], $catWidth);
      $message = $this->truncate($row['message'], 90);

      $lines[] = sprintf(
        '  %s%s%s  %s%s%s  %-' . $idWidth . 's  %s%-' . $catWidth . 's%s  %s%6dms%s  %s',
        $glyphColour,
        $glyph,
        $colours['reset'],
        $statusColour,
        $statusLabel,
        $colours['reset'],
        $id,
        $colours['dim'],
        $category,
        $colours['reset'],
        $durationColour,
        $duration,
        $colours['reset'],
        $message,
      );

      if ($firstFix === NULL && $r->status === SmokeCheckResult::STATUS_FAIL && $row['fix_hint'] !== '') {
        $firstFix = ['id' => $row['id'], 'hint' => $row['fix_hint']];
      }
    }

    $lines[] = '';
    $summaryParts = [];
    if ($counts['pass'] > 0) {
      $summaryParts[] = sprintf('%s%s %d pass%s', $colours['green'], $glyphs['pass'], $counts['pass'], $colours['reset']);
    }
    if ($counts['fail'] > 0) {
      $summaryParts[] = sprintf('%s%s %d fail%s', $colours['red'], $glyphs['fail'], $counts['fail'], $colours['reset']);
    }
    if ($counts['warn'] > 0) {
      $summaryParts[] = sprintf('%s%s %d warn%s', $colours['yellow'], $glyphs['warn'], $counts['warn'], $colours['reset']);
    }
    if ($counts['skip'] > 0) {
      $summaryParts[] = sprintf('%s%s %d skip%s', $colours['gray'], $glyphs['skip'], $counts['skip'], $colours['reset']);
    }
    $lines[] = sprintf(
      '  %sSummary%s  %s',
      $colours['bold'],
      $colours['reset'],
      implode('  ', $summaryParts),
    );
    if ($firstFix !== NULL) {
      $lines[] = sprintf(
        '  %sFix%s     %s%s%s — %s',
        $colours['bold'],
        $colours['reset'],
        $colours['dim'],
        $firstFix['id'],
        $colours['reset'],
        $this->truncate($firstFix['hint'], 90),
      );
    }
    $lines[] = '';

    return implode("\n", $lines);
  }

  /**
   * Truncates a string with an ellipsis when wider than $width.
   */
  protected function truncate(string $value, int $width): string {
    if (mb_strlen($value) <= $width) {
      return str_pad($value, $width);
    }
    return mb_substr($value, 0, max(1, $width - 1)) . '…';
  }

  /**
   * Returns TRUE when Drush's output stream is decorated.
   *
   * Defers to Symfony Console's OutputInterface::isDecorated(), which is
   * what Drush itself uses to honour --ansi / --no-ansi / NO_COLOR /
   * non-interactive detection in one consistent place. Querying STDOUT
   * via posix_isatty would emit ANSI even when the user passed
   * --no-ansi, since the underlying file descriptor is still a TTY.
   */
  protected function ttyColorEnabled(): bool {
    return $this->output()->isDecorated();
  }

  /**
   * Returns TRUE when the active locale advertises UTF-8.
   *
   * Falls back to ASCII glyphs in C / POSIX locales so the table does not
   * render Mojibake on legacy CI runners.
   */
  protected function ttyUtf8Enabled(): bool {
    foreach (['LC_ALL', 'LC_CTYPE', 'LANG'] as $var) {
      $value = getenv($var);
      if (is_string($value) && stripos($value, 'utf') !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Formats results as TAP v13.
   *
   * Pass = `ok`, fail = `not ok`, skip = `ok ... # SKIP`, warning =
   * `ok ... # TODO`. The plan line uses 1..N. Each failure carries a YAMLish
   * details block so prove/tap-spec consumers surface the message inline.
   *
   * @param array<int, \Drupal\markaspot_health\SmokeCheckResult> $results
   *   Plugin results in the order returned by the plugin manager.
   *
   * @return string
   *   TAP v13 document, ready for stdout.
   */
  protected function formatTap(array $results): string {
    $count = count($results);
    $lines = ['TAP version 13', '1..' . $count];
    $i = 0;
    foreach ($results as $result) {
      $i++;
      // Route every plugin-derived string through oneLine() before
      // emitting so a malicious annotation cannot inject TAP YAML
      // directives or terminal control sequences via id/category/label.
      $id = $this->oneLine($result->id());
      $label = $this->oneLine($result->label);
      $category = $this->oneLine($result->category);
      $severity = $this->oneLine($result->severity);
      $description = $id . ' - ' . $label;
      switch ($result->status) {
        case SmokeCheckResult::STATUS_PASS:
          $lines[] = sprintf('ok %d - %s', $i, $description);
          break;

        case SmokeCheckResult::STATUS_SKIP:
          $lines[] = sprintf('ok %d - %s # SKIP %s', $i, $description, $this->oneLine($result->message));
          break;

        case SmokeCheckResult::STATUS_WARNING:
          $lines[] = sprintf('ok %d - %s # TODO %s', $i, $description, $this->oneLine($result->message));
          break;

        case SmokeCheckResult::STATUS_FAIL:
        default:
          $lines[] = sprintf('not ok %d - %s', $i, $description);
          $lines[] = '  ---';
          $lines[] = '  severity: ' . $severity;
          $lines[] = '  category: ' . $category;
          $lines[] = '  message: ' . $this->yamlScalar($this->oneLine($result->message));
          if ($result->fixHint !== NULL && $result->fixHint !== '') {
            $lines[] = '  fix_hint: ' . $this->yamlScalar($this->oneLine($result->fixHint));
          }
          $lines[] = '  ...';
          break;
      }
    }
    return implode("\n", $lines);
  }

  /**
   * Formats results as a JUnit XML report.
   *
   * One <testsuite> per category to match the "category" filter dimension.
   * Failures and warnings render as <failure>; skips render as <skipped>.
   * Evidence is inlined into the <failure> body when present so dashboards
   * surface it without an artifact round-trip.
   *
   * @param array<int, \Drupal\markaspot_health\SmokeCheckResult> $results
   *   Plugin results in the order returned by the plugin manager.
   *
   * @return string
   *   JUnit XML document, ready for stdout.
   */
  protected function formatJunit(array $results): string {
    $byCategory = [];
    $totalTests = 0;
    $totalFailures = 0;
    $totalSkipped = 0;
    $totalDurationMs = 0;
    foreach ($results as $result) {
      $byCategory[$result->category][] = $result;
      $totalTests++;
      if ($result->failed()) {
        $totalFailures++;
      }
      if ($result->status === SmokeCheckResult::STATUS_SKIP) {
        $totalSkipped++;
      }
      $totalDurationMs += $result->lastRunDurationMs ?? 0;
    }

    $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
    $xml[] = sprintf(
      '<testsuites name="markaspot:smoke" tests="%d" failures="%d" skipped="%d" time="%s">',
      $totalTests,
      $totalFailures,
      $totalSkipped,
      $this->formatSeconds($totalDurationMs),
    );

    foreach ($byCategory as $category => $categoryResults) {
      $suiteFailures = 0;
      $suiteSkipped = 0;
      $suiteDurationMs = 0;
      foreach ($categoryResults as $result) {
        if ($result->failed()) {
          $suiteFailures++;
        }
        if ($result->status === SmokeCheckResult::STATUS_SKIP) {
          $suiteSkipped++;
        }
        $suiteDurationMs += $result->lastRunDurationMs ?? 0;
      }
      $xml[] = sprintf(
        '  <testsuite name="%s" tests="%d" failures="%d" skipped="%d" time="%s">',
        $this->xmlAttr((string) $category),
        count($categoryResults),
        $suiteFailures,
        $suiteSkipped,
        $this->formatSeconds($suiteDurationMs),
      );
      foreach ($categoryResults as $result) {
        $xml[] = sprintf(
          '    <testcase classname="%s" name="%s" time="%s">',
          $this->xmlAttr($result->category),
          $this->xmlAttr($result->id()),
          $this->formatSeconds($result->lastRunDurationMs ?? 0),
        );
        switch ($result->status) {
          case SmokeCheckResult::STATUS_FAIL:
            $xml[] = sprintf(
              '      <failure message="%s" type="%s">%s</failure>',
              $this->xmlAttr($result->message),
              $this->xmlAttr($result->severity),
              $this->xmlBody($this->renderFailureDetail($result)),
            );
            break;

          case SmokeCheckResult::STATUS_WARNING:
            $xml[] = sprintf(
              '      <failure message="%s" type="warning">%s</failure>',
              $this->xmlAttr($result->message),
              $this->xmlBody($result->message),
            );
            break;

          case SmokeCheckResult::STATUS_SKIP:
            $xml[] = sprintf(
              '      <skipped message="%s"/>',
              $this->xmlAttr($result->message),
            );
            break;
        }
        $xml[] = '    </testcase>';
      }
      $xml[] = '  </testsuite>';
    }

    $xml[] = '</testsuites>';
    return implode("\n", $xml);
  }

  /**
   * Renders evidence + fix hint as a plain-text body for a JUnit failure.
   */
  protected function renderFailureDetail(SmokeCheckResult $result): string {
    $parts = [$result->message];
    if ($result->fixHint !== NULL && $result->fixHint !== '') {
      $parts[] = 'Fix: ' . $result->fixHint;
    }
    if ($result->evidence !== []) {
      $parts[] = 'Evidence: ' . json_encode($result->evidence, JSON_UNESCAPED_SLASHES);
    }
    return implode("\n", $parts);
  }

  /**
   * Collapses whitespace and strips control bytes for single-line output.
   *
   * Strips C0 control bytes (0x00-0x08, 0x0B-0x1F, 0x7F) before whitespace
   * collapse so a malicious plugin label, message, or evidence string with
   * embedded ESC sequences cannot inject ANSI control codes into the
   * operator's terminal via the pretty renderer.
   */
  protected function oneLine(string $value): string {
    $stripped = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);
    return trim((string) preg_replace('/\s+/', ' ', $stripped));
  }

  /**
   * Wraps a TAP YAML scalar so colons or newlines do not break the parser.
   */
  protected function yamlScalar(string $value): string {
    return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
  }

  /**
   * Encodes an XML attribute value, escaping the five XML special characters.
   *
   * Also strips C0 control bytes that XML 1.0 forbids (everything below
   * 0x20 except TAB/LF/CR). htmlspecialchars() neutralises the markup
   * special-five but leaves raw control bytes in place, and a strict XML
   * parser would reject the resulting JUnit document outright.
   */
  protected function xmlAttr(string $value): string {
    return htmlspecialchars($this->stripXmlForbidden($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

  /**
   * Encodes an XML element body, preserving newlines but escaping markup.
   *
   * Same C0 strip as xmlAttr — JUnit consumers (CI dashboards, junit2html)
   * fail-closed on invalid XML and the threat-model comment on oneLine()
   * promised that no plugin-derived control byte reaches a renderer sink.
   */
  protected function xmlBody(string $value): string {
    return htmlspecialchars($this->stripXmlForbidden($value), ENT_NOQUOTES | ENT_XML1, 'UTF-8');
  }

  /**
   * Removes C0 bytes that XML 1.0 forbids, preserving TAB/LF/CR.
   */
  protected function stripXmlForbidden(string $value): string {
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
  }

  /**
   * Formats a millisecond duration as a JUnit-friendly seconds string.
   */
  protected function formatSeconds(int $milliseconds): string {
    return number_format($milliseconds / 1000, 3, '.', '');
  }

}
