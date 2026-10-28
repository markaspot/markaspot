<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index\Drush\Commands;

use Drupal\markaspot_request_index\RequestIndexRebuilder;
use Drupal\markaspot_request_index\RequestIndexVerifier;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the request index.
 */
final class RequestIndexCommands extends DrushCommands {

  /**
   * Seconds between progress lines while draining.
   */
  private const PROGRESS_INTERVAL = 5;

  /**
   * Constructs the commands.
   */
  public function __construct(
    private readonly RequestIndexRebuilder $rebuilder,
    private readonly RequestIndexVerifier $verifier,
  ) {
    parent::__construct();
  }

  /**
   * Instantiates the commands via Drush command discovery.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('markaspot_request_index.rebuilder'),
      $container->get('markaspot_request_index.verifier'),
    );
  }

  /**
   * Rebuilds the request index through its queue; resumes an interrupted run.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'markaspot:request-index:rebuild', aliases: ['mas:request-index:rebuild'])]
  #[CLI\Option(name: 'restart', description: 'Discard the queued batches of an interrupted run and start over.')]
  #[CLI\Option(name: 'queue-only', description: 'Only queue the batches; cron drains them.')]
  #[CLI\Option(name: 'batch-size', description: 'Service requests per queue item (default 200).')]
  #[CLI\Option(name: 'time-limit', description: 'Stop draining after this many seconds; rerun to resume (0 = no limit).')]
  #[CLI\Usage(name: 'mas:request-index:rebuild', description: 'Queue a full rebuild, or resume an interrupted one, and drain it.')]
  #[CLI\Usage(name: 'mas:request-index:rebuild --queue-only', description: 'Queue a full rebuild for cron.')]
  public function rebuild(
    array $options = [
      'restart' => FALSE,
      'queue-only' => FALSE,
      'batch-size' => RequestIndexRebuilder::BATCH_SIZE,
      'time-limit' => 0,
    ],
  ): int {
    $run = $options['restart'] ? NULL : $this->rebuilder->pendingRun();
    if ($run === NULL) {
      $run = $this->rebuilder->startFullRebuild((int) $options['batch-size']);
      $this->io()->note(sprintf('Queued %d batches for %d service requests (run %s).', $run['items'], $run['requests'], $run['id']));
    }
    else {
      $this->io()->note(sprintf('Resuming run %s started %s: %d of %d batches left.', $run['id'], date('Y-m-d H:i:s', (int) $run['started']), $this->rebuilder->status()['queued'], $run['items']));
    }
    if ($options['queue-only']) {
      return self::EXIT_SUCCESS;
    }

    $total = max(1, (int) $this->rebuilder->status()['queued']);
    $started = microtime(TRUE);
    $lastLine = 0.0;
    $result = $this->rebuilder->drain(function (int $done, int $left, int $rows) use ($total, $started, &$lastLine): void {
      $now = microtime(TRUE);
      if ($left > 0 && $now - $lastLine < self::PROGRESS_INTERVAL) {
        return;
      }
      $lastLine = $now;
      $this->io()->writeln(sprintf(
        '[%s] %d/%d batches, %d rows projected, %d left, %.0f s',
        date('H:i:s'),
        $done,
        $total,
        $rows,
        $left,
        $now - $started,
      ));
    }, (int) $options['time-limit']);

    $status = $this->rebuilder->status();
    if ($result['failed'] > 0) {
      $this->io()->warning(sprintf(
        '%d projection attempts failed and were logged; a request is retried up to %d times, then left stale. drush mas:request-index:verify lists what is left.',
        $result['failed'],
        RequestIndexRebuilder::MAX_ATTEMPTS,
      ));
    }
    if ($result['left'] > 0) {
      $this->io()->warning(sprintf(
        'Stopped with %d batches left; rerun the command to resume. Batches claimed by an interrupted run are released after %d seconds.',
        $result['left'],
        RequestIndexRebuilder::LEASE,
      ));
      return self::EXIT_SUCCESS;
    }
    $this->io()->success(sprintf('Rebuilt: %d rows for %d service requests in %.0f s.', $status['indexed'], $status['requests'], microtime(TRUE) - $started));
    return self::EXIT_SUCCESS;
  }

  /**
   * Verifies row parity, projector output and organisation drift.
   *
   * Exits non-zero when rows are missing or orphaned or a re-projection
   * differs from the stored row. Drift between field_organisation and the
   * group_node relations is reported only.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'markaspot:request-index:verify', aliases: ['mas:request-index:verify'])]
  #[CLI\Option(name: 'sample', description: 'Random service requests to re-project and compare (default 500, 0 = all).')]
  #[CLI\Option(name: 'drift-top', description: 'Most frequent field/relation organisation combinations to list (default 10).')]
  #[CLI\Usage(name: 'mas:request-index:verify', description: 'Check parity, 500 random rows and drift.')]
  #[CLI\Usage(name: 'mas:request-index:verify --sample=0', description: 'Re-project and compare every service request.')]
  public function verify(array $options = ['sample' => 500, 'drift-top' => 10]): int {
    $io = $this->io();
    $parity = $this->verifier->parity();
    $io->section('Row parity');
    $io->writeln(sprintf(
      'service requests %d, index rows %d, missing %d, orphaned %d',
      $parity['requests'],
      $parity['indexed'],
      $parity['missing'],
      $parity['orphans'],
    ));
    $queued = $this->rebuilder->status()['queued'];
    if ($queued > 0) {
      $io->writeln(sprintf('%d re-projection batches are still queued.', $queued));
    }

    $diff = $this->verifier->diff((int) $options['sample']);
    $io->section('Projector diff');
    $io->writeln(sprintf('checked %d, differing %d', $diff['checked'], count($diff['differences'])));
    $columns = [];
    $listed = 0;
    foreach ($diff['differences'] as $nid => $differing) {
      foreach ($differing as $column) {
        $columns[$column] = ($columns[$column] ?? 0) + 1;
      }
      if ($listed++ < 20) {
        $io->writeln(sprintf('  nid %d: %s', $nid, implode(', ', $differing)));
      }
    }
    if ($columns !== []) {
      arsort($columns);
      $io->writeln('  by column: ' . implode(', ', array_map(
        static fn(string $column, int $count): string => "$column $count",
        array_keys($columns),
        $columns,
      )));
    }

    $drift = $this->verifier->drift((int) $options['drift-top']);
    $io->section('Organisation drift (field_organisation vs group_node relations; source data, not index staleness)');
    if (!$drift['available']) {
      $io->writeln('not available: service_request has no field_organisation');
    }
    else {
      $io->writeln(sprintf('checked %d, drifted %d', $drift['checked'], $drift['drifted']));
      if ($drift['checked'] !== $parity['requests']) {
        $io->writeln(sprintf(
          '  drift reads the default-language rows of the node data table, parity the node base table: %d vs %d service requests.',
          $drift['checked'],
          $parity['requests'],
        ));
      }
      foreach ($drift['patterns'] as $pattern => $count) {
        $io->writeln(sprintf('  %6d  %s', $count, $pattern));
      }
      if ($drift['combinations'] !== []) {
        $io->writeln('  most frequent organisation ID combinations:');
        foreach ($drift['combinations'] as $combination => $count) {
          $io->writeln(sprintf('  %6d  %s', $count, $combination));
        }
      }
    }

    $failed = $parity['missing'] > 0 || $parity['orphans'] > 0 || $diff['differences'] !== [];
    if ($failed) {
      $io->error('Request index differs from its sources. Run drush mas:request-index:rebuild.');
      return self::EXIT_FAILURE;
    }
    $io->success('Request index matches its sources.');
    return self::EXIT_SUCCESS;
  }

}
