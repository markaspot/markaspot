<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\markaspot_group\Service\OrganisationRelationDiff;
use Drupal\markaspot_group\Service\OrganisationRelationSync;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command repairing organisation relations from field_organisation.
 */
final class OrganisationRelationSyncCommands extends DrushCommands {

  /**
   * CSV columns of the per-request log.
   */
  private const LOG_COLUMNS = [
    'nid',
    'published',
    'jurisdiction',
    'field_organisations',
    'relation_organisations',
    'add',
    'remove',
    'skipped',
    'blocked',
    'result',
  ];

  /**
   * Constructs the command.
   */
  public function __construct(
    private readonly OrganisationRelationSync $sync,
    private readonly FileSystemInterface $fileSystem,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly TimeInterface $time,
    private readonly string $appRoot,
  ) {
    parent::__construct();
  }

  /**
   * Drush 13 auto-discovery factory.
   *
   * The sync is built through the class resolver instead of a service
   * definition, so a stale container after a deploy cannot break the command.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('class_resolver')->getInstanceFromDefinition(OrganisationRelationSync::class),
      $container->get('file_system'),
      $container->get('stream_wrapper_manager'),
      $container->get('datetime.time'),
      (string) $container->getParameter('app.root'),
    );
  }

  /**
   * Makes organisation relations match field_organisation.
   *
   * Field_organisation is the authority. Missing organisation relations are
   * added, relations to organisations the field does not list (and duplicate
   * relations) are removed. Jurisdiction relations are never touched, the
   * node is never saved, and no e-mail, ECA event or notification is
   * triggered. The default is a dry run.
   *
   * Run --apply off-peak and on cp2 first: each transaction holds the sync
   * lock of its requests, and a staff save of one of them waits for it. Run
   * a dry run again afterwards; requests reported as locked need a rerun.
   *
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return int
   *   Exit code.
   */
  #[CLI\Command(name: 'markaspot:org-relations:sync', aliases: ['mas:org-relations:sync'])]
  #[CLI\Help(
    description: 'Make organisation group relations of service requests match field_organisation.',
    synopsis: 'Dry run by default: counts by pattern plus a per-request CSV log. --apply writes in short batches, one transaction per batch; run it off-peak and dry-run again afterwards.',
  )]
  #[CLI\Option(name: 'jurisdiction', description: 'Only requests in this jurisdiction group or its descendants.')]
  #[CLI\Option(name: 'nid', description: 'Only this service request.')]
  #[CLI\Option(name: 'organisation', description: 'Only drift involving these organisation group IDs (comma list), on the field or the relation side.')]
  #[CLI\Option(name: 'limit', description: 'Stop after this many drifted requests.')]
  #[CLI\Option(name: 'apply', description: 'Write the changes. Without this option the command is a dry run.')]
  #[CLI\Option(name: 'log', description: 'CSV log: a private:// or temporary:// URI, a file name under private://markaspot_group/, or an absolute path outside the Drupal root (default private://markaspot_group/org-relation-sync-<timestamp>.csv).')]
  #[CLI\Option(name: 'batch-size', description: 'Requests locked and written per transaction with --apply (default 25, at most 50).')]
  #[CLI\Option(name: 'top', description: 'Most frequent patterns to print (default 20, 0 = all).')]
  #[CLI\Usage(name: 'drush mas:org-relations:sync --jurisdiction=12', description: 'Dry run for jurisdiction 12 and its descendants.')]
  #[CLI\Usage(name: 'drush mas:org-relations:sync --jurisdiction=12 --apply', description: 'Repair the drift in jurisdiction 12.')]
  #[CLI\Usage(name: 'drush mas:org-relations:sync --organisation=34,35 --apply', description: 'Repair only drift that involves organisations 34 or 35.')]
  public function sync(
    array $options = [
      'jurisdiction' => NULL,
      'nid' => NULL,
      'organisation' => NULL,
      'limit' => NULL,
      'apply' => FALSE,
      'log' => NULL,
      'batch-size' => OrganisationRelationSync::BATCH_SIZE,
      'top' => 20,
    ],
  ): int {
    $apply = !empty($options['apply']);
    $filter = [
      'jurisdiction' => $this->positiveInt($options, 'jurisdiction'),
      'nid' => $this->positiveInt($options, 'nid'),
      'limit' => $this->positiveInt($options, 'limit'),
      'organisations' => $this->idList($options['organisation'] ?? NULL),
    ];
    $batchSize = min($this->positiveInt($options, 'batch-size') ?? OrganisationRelationSync::BATCH_SIZE, OrganisationRelationSync::MAX_BATCH_SIZE);
    $top = max(0, (int) ($options['top'] ?? 20));

    $problem = $this->sync->writeProblem();
    if ($apply && $problem !== NULL) {
      throw new \RuntimeException('Refusing to write organisation relations: ' . $problem);
    }

    [$uri, $handle] = $this->openLog($options['log'] ?? NULL);
    $summary = [
      'drift' => 0,
      'published' => 0,
      'add' => 0,
      'remove' => 0,
      'duplicates' => 0,
      'skipped' => [],
      'blocked' => [],
      'results' => [],
      'patterns' => [],
    ];
    try {
      $batch = [];
      foreach ($this->sync->scan($filter) as $diff) {
        if (!$apply) {
          $this->record($summary, $handle, $diff, $diff->blocked !== NULL ? 'blocked' : ($diff->hasChanges() ? 'planned' : 'skipped'));
          continue;
        }
        $batch[$diff->nid] = $diff;
        if (count($batch) >= $batchSize) {
          $this->applyBatch($batch, $summary, $handle);
          $batch = [];
        }
      }
      if ($batch !== []) {
        $this->applyBatch($batch, $summary, $handle);
      }
    }
    finally {
      fclose($handle);
    }

    $this->report($summary, $apply, $top, $uri, $problem);
    return self::EXIT_SUCCESS;
  }

  /**
   * Applies one batch and records the re-read diffs.
   *
   * @param array<int, \Drupal\markaspot_group\Service\OrganisationRelationDiff> $batch
   *   Scanned diffs keyed by node ID.
   * @param array<string, mixed> $summary
   *   Running summary.
   * @param resource $handle
   *   CSV log handle.
   */
  private function applyBatch(array $batch, array &$summary, $handle): void {
    foreach ($this->sync->apply(array_keys($batch), count($batch)) as $nid => $result) {
      $this->record($summary, $handle, $result['diff'] ?? $batch[$nid], $result['result']);
    }
  }

  /**
   * Adds one request to the summary and the CSV log.
   *
   * @param array<string, mixed> $summary
   *   Running summary.
   * @param resource $handle
   *   CSV log handle.
   * @param \Drupal\markaspot_group\Service\OrganisationRelationDiff $diff
   *   The request's diff.
   * @param string $result
   *   The dry-run result ("planned", "skipped" or "blocked") or the apply
   *   result.
   */
  private function record(array &$summary, $handle, OrganisationRelationDiff $diff, string $result): void {
    $summary['results'][$result] = ($summary['results'][$result] ?? 0) + 1;
    $writes = in_array($result, ['planned', 'applied'], TRUE);
    if ($diff->isDrift() || $writes) {
      $summary['drift']++;
      $summary['published'] += $diff->published ? 1 : 0;
      $pattern = $diff->pattern();
      $summary['patterns'][$pattern] = ($summary['patterns'][$pattern] ?? 0) + 1;
    }
    if ($writes) {
      $summary['add'] += count($diff->add);
      $summary['remove'] += count($diff->remove);
      $summary['duplicates'] += $diff->duplicateCount();
    }
    foreach ($diff->skipped as $reason) {
      $summary['skipped'][$reason] = ($summary['skipped'][$reason] ?? 0) + 1;
    }
    if ($diff->blocked !== NULL) {
      $summary['blocked'][$diff->blocked] = ($summary['blocked'][$diff->blocked] ?? 0) + 1;
    }

    $relations = array_values($diff->relations);
    sort($relations);
    $skipped = [];
    foreach ($diff->skipped as $gid => $reason) {
      $skipped[] = $gid . ':' . $reason;
    }
    fputcsv($handle, [
      $diff->nid,
      $diff->published ? 1 : 0,
      $diff->jurisdictionId ?? '',
      implode(';', $diff->fieldIds),
      implode(';', $relations),
      implode(';', $diff->add),
      implode(';', array_values($diff->remove)),
      implode(';', $skipped),
      $diff->blocked ?? '',
      $result,
    ], ',', '"', '');
  }

  /**
   * Prints the summary table and the most frequent patterns.
   *
   * @param array<string, mixed> $summary
   *   Final summary.
   * @param bool $apply
   *   Whether changes were written.
   * @param int $top
   *   Patterns to print, 0 for all.
   * @param string $uri
   *   CSV log URI.
   * @param string|null $problem
   *   Why --apply would be refused, NULL when it would not.
   */
  private function report(array $summary, bool $apply, int $top, string $uri, ?string $problem): void {
    $verb = $apply ? '' : ' (planned)';
    $rows = [
      ['Requests with drift', $summary['drift']],
      ['  published', $summary['published']],
      ['  unpublished', $summary['drift'] - $summary['published']],
      ['Relations added' . $verb, $summary['add']],
      ['Relations removed' . $verb, $summary['remove']],
      ['  of which duplicates', $summary['duplicates']],
    ];
    foreach ($summary['skipped'] as $reason => $count) {
      $rows[] = ['Field organisations skipped: ' . $reason, $count];
    }
    foreach ($summary['blocked'] as $reason => $count) {
      $rows[] = ['Requests left untouched: ' . $reason, $count];
    }
    if ($apply) {
      foreach ($summary['results'] as $result => $count) {
        $rows[] = ['Result: ' . $result, $count];
      }
    }
    $this->io()->table(['Metric', 'Count'], $rows);

    $patterns = $summary['patterns'];
    arsort($patterns);
    if ($top > 0) {
      $patterns = array_slice($patterns, 0, $top, TRUE);
    }
    $patternRows = [];
    foreach ($patterns as $pattern => $count) {
      $patternRows[] = [$count, $pattern];
    }
    if ($patternRows !== []) {
      $this->io()->table(['Requests', 'Pattern'], $patternRows);
    }

    $path = $this->fileSystem->realpath($uri) ?: $uri;
    $this->io()->note('Per-request log: ' . $path);
    if ($apply) {
      $locked = (int) ($summary['results']['locked'] ?? 0);
      if ($locked > 0) {
        $this->io()->warning(sprintf('Requests left unchanged because another process held their lock: %d. Run the command again (dry run first) to repair them.', $locked));
      }
      $this->io()->success(sprintf('Applied: %d relations added, %d removed.', $summary['add'], $summary['remove']));
      return;
    }
    if ($problem !== NULL) {
      $this->io()->warning('--apply would be refused: ' . $problem);
    }
    $this->io()->note('Dry run: nothing was changed. Re-run with --apply to write.');
  }

  /**
   * Opens the CSV log and writes the header.
   *
   * @return array{0: string, 1: resource}
   *   The log URI and its open handle.
   */
  private function openLog(mixed $log): array {
    $uri = $this->logUri(is_string($log) ? trim($log) : '');
    $scheme = $this->streamWrapperManager->getScheme($uri);
    if ($scheme !== FALSE && !$this->streamWrapperManager->isValidScheme($scheme)) {
      throw new \RuntimeException(sprintf('Stream wrapper %s:// is not available; pass --log=<absolute path outside the Drupal root>.', $scheme));
    }
    $directory = $this->fileSystem->dirname($uri);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException(sprintf('Cannot create the log directory %s.', $directory));
    }
    $handle = fopen($uri, 'wb');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Cannot open the log %s for writing.', $uri));
    }
    fputcsv($handle, self::LOG_COLUMNS, ',', '"', '');
    return [$uri, $handle];
  }

  /**
   * Resolves the --log option to a location the web server never serves.
   *
   * The log lists unpublished requests with their organisations. Stream
   * wrappers other than private:// and temporary:// are refused, a relative
   * name is placed under private://markaspot_group/, and an absolute path
   * must lie outside the Drupal root.
   */
  private function logUri(string $log): string {
    if ($log === '') {
      return sprintf('private://markaspot_group/org-relation-sync-%s.csv', date('Ymd-His', $this->time->getCurrentTime()));
    }
    $scheme = $this->streamWrapperManager->getScheme($log);
    if ($scheme !== FALSE) {
      if (!in_array($scheme, ['private', 'temporary'], TRUE)) {
        throw new \RuntimeException(sprintf('Refusing to write the log to %s://; use private://, temporary:// or an absolute path outside the Drupal root.', $scheme));
      }
      return $log;
    }
    if (str_contains('/' . $log . '/', '/../')) {
      throw new \RuntimeException('Refusing a log path that contains "..".');
    }
    if (!str_starts_with($log, '/')) {
      return 'private://markaspot_group/' . $log;
    }
    $directory = realpath(dirname($log)) ?: dirname($log);
    if (str_starts_with(rtrim($directory, '/') . '/', rtrim($this->appRoot, '/') . '/')) {
      throw new \RuntimeException('Refusing to write the log inside the Drupal root, which the web server serves.');
    }
    return $log;
  }

  /**
   * Reads a positive integer option, NULL when absent.
   *
   * @param array<string, mixed> $options
   *   Command options.
   * @param string $name
   *   Option name.
   */
  private function positiveInt(array $options, string $name): ?int {
    $value = $options[$name] ?? NULL;
    if ($value === NULL || $value === '' || $value === TRUE) {
      return NULL;
    }
    if (!is_numeric($value) || (int) $value <= 0 || (string) (int) $value !== (string) $value) {
      throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
    }
    return (int) $value;
  }

  /**
   * Parses a comma list of positive group IDs.
   *
   * @return list<int>
   *   Group IDs.
   */
  private function idList(mixed $value): array {
    if ($value === NULL || $value === '' || $value === TRUE) {
      return [];
    }
    $ids = [];
    foreach (explode(',', (string) $value) as $part) {
      $part = trim($part);
      if (!ctype_digit($part) || (int) $part <= 0) {
        throw new \InvalidArgumentException('--organisation must be a comma list of group IDs.');
      }
      $ids[] = (int) $part;
    }
    return array_values(array_unique($ids));
  }

}
