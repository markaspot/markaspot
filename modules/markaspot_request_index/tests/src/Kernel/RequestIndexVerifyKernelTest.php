<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_index\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\group\Entity\Group;
use Drupal\markaspot_request_index\Drush\Commands\RequestIndexCommands;
use Drupal\markaspot_request_index\Hook\RequestIndexHooks;
use Drupal\markaspot_request_index\RequestIndexRebuilder;
use Drupal\markaspot_request_index\RequestIndexVerifier;
use Drupal\markaspot_request_index\RequestIndexWriter;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests rebuild, verify and the drift report.
 *
 * @group markaspot_request_index
 */
#[RunTestsInSeparateProcesses]
final class RequestIndexVerifyKernelTest extends RequestIndexKernelTestBase {

  /**
   * Verify is clean on a consistent set, reports drift and index damage.
   */
  public function testVerifyReportsDiffAndDrift(): void {
    $first = $this->createRequest();
    $second = $this->createRequest(['title' => 'Overflowing bin']);
    $third = $this->createRequest(['title' => 'Graffiti on a wall', 'status' => 0]);
    $verifier = $this->verifier();

    $this->assertSame(['requests' => 3, 'indexed' => 3, 'missing' => 0, 'orphans' => 0], $verifier->parity());
    $this->assertSame(['checked' => 3, 'differences' => []], $verifier->diff(0));
    $drift = $verifier->drift();
    $this->assertTrue($drift['available']);
    $this->assertSame(3, $drift['checked']);
    $this->assertSame(0, $drift['drifted'], 'markaspot_group mirrors the field into relations.');
    [$exit, $output] = $this->runVerifyCommand();
    $this->assertSame(RequestIndexCommands::EXIT_SUCCESS, $exit, $output);

    // Drift 1: a relation without the field, as left by legacy writers.
    _markaspot_group_set_syncing(TRUE);
    try {
      $this->organisationB->addRelationship($first, 'group_node:service_request');
    }
    finally {
      _markaspot_group_set_syncing(FALSE);
    }
    // Drift 2: the field emptied behind the entity API (like scrub-pii.php),
    // followed by the re-projection that the runbook requires.
    $this->database()->delete('node__field_organisation')
      ->condition('entity_id', $second->id())
      ->execute();
    $this->container->get('markaspot_request_index.writer')->reproject([(int) $second->id()]);

    $this->assertSame(['checked' => 3, 'differences' => []], $verifier->diff(0), 'Drift is not an index difference.');
    $drift = $verifier->drift();
    $this->assertSame(2, $drift['drifted']);
    $this->assertSame([
      'published: relation has extra organisations' => 1,
      'published: relation only, field empty' => 1,
    ], $this->sorted($drift['patterns']));
    $orgA = (int) $this->organisationA->id();
    $orgB = (int) $this->organisationB->id();
    $this->assertSame(1, $drift['combinations'][sprintf('field=%d relation=%d,%d', $orgA, min($orgA, $orgB), max($orgA, $orgB))] ?? NULL);
    $this->assertSame(1, $drift['combinations'][sprintf('field=- relation=%d', $orgA)] ?? NULL);
    [$exit, $output] = $this->runVerifyCommand();
    $this->assertSame(RequestIndexCommands::EXIT_SUCCESS, $exit, 'Drift alone does not fail verify.');
    $this->assertStringContainsString('drifted 2', $output);

    // Index damage: a stale column, a missing row and an orphaned row.
    $this->database()->update(RequestIndexWriter::TABLE)
      ->fields(['status_tid' => 999999])
      ->condition('nid', $third->id())
      ->execute();
    $this->database()->delete(RequestIndexWriter::TABLE)
      ->condition('nid', $first->id())
      ->execute();
    $this->database()->insert(RequestIndexWriter::TABLE)
      ->fields(['nid' => 999999, 'jur_gid' => (int) $this->rootJurisdiction->id()])
      ->execute();

    $parity = $verifier->parity();
    $this->assertSame(1, $parity['missing']);
    $this->assertSame(1, $parity['orphans']);
    $diff = $verifier->diff(0);
    $this->assertSame(['row missing'], $diff['differences'][(int) $first->id()] ?? NULL);
    $this->assertSame(['status_tid'], $diff['differences'][(int) $third->id()] ?? NULL);
    $this->assertArrayNotHasKey((int) $second->id(), $diff['differences']);
    [$exit, $output] = $this->runVerifyCommand();
    $this->assertSame(RequestIndexCommands::EXIT_FAILURE, $exit, $output);
  }

  /**
   * A full rebuild resumes after an interruption and removes orphans.
   */
  public function testRebuildResumesAndRemovesOrphans(): void {
    $nodes = [
      $this->createRequest(),
      $this->createRequest(['title' => 'Overflowing bin']),
      $this->createRequest(['title' => 'Graffiti on a wall']),
    ];
    foreach (['markaspot_request_index', 'markaspot_request_index_org', 'markaspot_request_index_jur'] as $table) {
      $this->database()->delete($table)->execute();
    }
    $this->database()->insert(RequestIndexWriter::TABLE)->fields(['nid' => 999999])->execute();
    $this->database()->insert('markaspot_request_index_org')->fields(['nid' => 999999, 'gid' => 1])->execute();

    $rebuilder = $this->container->get('markaspot_request_index.rebuilder');
    $this->assertInstanceOf(RequestIndexRebuilder::class, $rebuilder);
    $run = $rebuilder->startFullRebuild(1);
    $this->assertSame(3, $run['requests']);
    $this->assertSame(3, $run['items']);

    // Interrupted after one batch, processed by the cron queue worker.
    $definition = $this->container->get('plugin.manager.queue_worker')->getDefinition(RequestIndexRebuilder::QUEUE);
    $this->assertSame(60, $definition['cron']['time']);
    $queue = $this->container->get('queue')->get(RequestIndexRebuilder::QUEUE, TRUE);
    $item = $queue->claimItem();
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance(RequestIndexRebuilder::QUEUE)
      ->processItem($item->data);
    $queue->deleteItem($item);
    $this->assertNotNull($this->row((int) $nodes[0]->id()));
    $this->assertNull($this->row((int) $nodes[2]->id()));

    $pending = $rebuilder->pendingRun();
    $this->assertSame($run['id'], $pending['id'] ?? NULL, 'The interrupted run resumes.');
    $result = $rebuilder->drain();
    $this->assertSame(['items' => 2, 'rows' => 2, 'left' => 0, 'failed' => 0], $result);
    $this->assertNull($rebuilder->pendingRun());
    $this->assertNotEmpty($this->container->get('state')->get(RequestIndexRebuilder::STATE_RUN)['finished']);

    $this->assertSame(['requests' => 3, 'indexed' => 3, 'missing' => 0, 'orphans' => 0], $this->verifier()->parity());
    $this->assertNull($this->row(999999));
    $this->assertSame([], $this->children('org', 999999));
    $this->assertSame([], $this->verifier()->diff(0)['differences']);
    $this->assertSame([(int) $this->organisationA->id()], $this->children('org', (int) $nodes[1]->id()));

    // Rows left behind by writes outside the entity API go with the next
    // full rebuild; a deleted request leaves through node_delete.
    $this->database()->insert(RequestIndexWriter::TABLE)->fields(['nid' => (int) $nodes[2]->id() + 1000])->execute();
    Node::load($nodes[2]->id())->delete();
    $rebuilder->startFullRebuild();
    $rebuilder->drain();
    $this->assertSame(['requests' => 2, 'indexed' => 2, 'missing' => 0, 'orphans' => 0], $this->verifier()->parity());
  }

  /**
   * A request that cannot be written neither blocks the rebuild nor leaks.
   *
   * A trigger makes the database refuse one row. The failing write's
   * exception message carries the title and address of the request; the
   * log must not.
   */
  public function testFailingRequestDoesNotBlockRebuild(): void {
    $poisoned = $this->createRequest(['title' => 'Fictional poisoned title']);
    $healthy = $this->createRequest(['title' => 'Overflowing bin']);
    $poisonedNid = (int) $poisoned->id();
    foreach (['markaspot_request_index', 'markaspot_request_index_org', 'markaspot_request_index_jur'] as $table) {
      $this->database()->delete($table)->execute();
    }
    $this->database()->query(
      "CREATE TRIGGER {markaspot_request_index_poison} BEFORE INSERT ON {markaspot_request_index} FOR EACH ROW IF NEW.nid = $poisonedNid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused row'; END IF",
      [],
      ['allow_delimiter_in_query' => TRUE],
    );
    $logger = new class() extends AbstractLogger {

      /**
       * Logged messages with their placeholders replaced.
       *
       * @var list<string>
       */
      public array $messages = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->messages[] = strtr((string) $message, array_map('strval', array_filter($context, 'is_scalar')));
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);

    $rebuilder = $this->container->get('markaspot_request_index.rebuilder');
    $rebuilder->startFullRebuild(1);
    $result = $rebuilder->drain();
    $this->assertSame(0, $result['left'], 'The rebuild ran to the end.');
    $this->assertSame(RequestIndexRebuilder::MAX_ATTEMPTS, $result['failed'], 'The failing request was retried, then dropped.');
    $this->assertNull($this->row($poisonedNid));
    $this->assertNotNull($this->row((int) $healthy->id()));
    // The dropped retry leaves the request marked stale: counts and queue
    // alone would not show it once a row exists.
    $staleSet = $this->container->get('markaspot_request_index.stale_set');
    $reader = $this->container->get('markaspot_request_index.reader');
    $this->assertSame([$poisonedNid], $staleSet->ids());
    $this->assertSame(1, $rebuilder->status()['stale']);
    $this->assertFalse($reader->isUsable());
    $requirement = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
    $this->assertStringContainsString('marked stale', (string) $requirement['value']);
    $this->assertStringContainsString('falls back', (string) $requirement['description']);
    [$exit, $output] = $this->runVerifyCommand();
    $this->assertSame(RequestIndexCommands::EXIT_FAILURE, $exit);
    $this->assertStringContainsString("marked stale 1: $poisonedNid", $output);

    // The save of the request itself still succeeds and queues a retry.
    $poisoned->set('field_facility', 'bench-12')->save();
    $this->assertSame(1, $rebuilder->status()['queued']);

    $logged = implode("\n", $logger->messages);
    $this->assertStringContainsString('SQLSTATE 45000', $logged);
    $this->assertStringContainsString('Gave up projecting nodes ' . $poisonedNid, $logged);
    $this->assertStringContainsString("Request index write for node $poisonedNid failed and was queued for retry", $logged);
    foreach (['Fictional poisoned title', 'Beispielweg', 'Musterstadt', '00099', 'INSERT'] as $value) {
      $this->assertStringNotContainsString($value, $logged);
    }

    $this->database()->query('DROP TRIGGER {markaspot_request_index_poison}');
    $rebuilder->drain();
    $this->assertSame('bench-12', $this->row($poisonedNid)['facility_id'] ?? NULL);
    $this->assertSame([], $staleSet->ids(), 'The successful re-projection cleared the mark.');
    $reader->resetParity();
    $this->assertTrue($reader->isUsable());
    $this->assertSame(RequirementSeverity::OK, $this->requirement()['severity']);

    // A mark whose row matches its source again is cleared by verify, and a
    // mark of a request that no longer exists by the next rebuild.
    $staleSet->add([(int) $healthy->id()]);
    [$exit, $output] = $this->runVerifyCommand();
    $this->assertSame(RequestIndexCommands::EXIT_SUCCESS, $exit, $output);
    $this->assertSame([], $staleSet->ids());
    $staleSet->add([$poisonedNid + 5000]);
    $rebuilder->startFullRebuild();
    $rebuilder->drain();
    $this->assertSame([], $staleSet->ids());
  }

  /**
   * An organisation move queues a re-projection; the status report warns.
   *
   * The org-move path of markaspot_group (re-stamp, membership resync) is
   * not part of this fixture, so the hook method runs on an unsaved group.
   */
  public function testOrganisationMoveQueueAndStatusReport(): void {
    $node = $this->createRequest();
    $nid = (int) $node->id();
    $rebuilder = $this->container->get('markaspot_request_index.rebuilder');
    $requirement = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $requirement['severity']);
    $this->assertStringContainsString('dashboard list takes its candidates from this index', (string) $requirement['description']);

    $this->database()->delete(RequestIndexWriter::TABLE)->execute();
    $requirement = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
    $this->assertStringContainsString('empty', (string) $requirement['value']);

    $organisation = Group::load($this->organisationA->id());
    $organisation->setOriginal(clone $organisation);
    $organisation->set('field_jurisdiction', $this->childJurisdiction->id());
    $this->container->get(RequestIndexHooks::class)->groupUpdate($organisation);
    $this->assertSame(1, $rebuilder->status()['queued']);
    $this->assertSame(RequirementSeverity::Warning, $this->requirement()['severity']);

    // The organisation item expands to its requests, which are re-projected.
    $result = $rebuilder->drain();
    $this->assertSame(['items' => 2, 'rows' => 1, 'left' => 0, 'failed' => 0], $result);
    $this->assertSame((string) $this->organisationA->id(), $this->row($nid)['org_gid'] ?? NULL);
    $this->assertSame(RequirementSeverity::OK, $this->requirement()['severity']);
  }

  /**
   * Returns the status report entry of the module.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function requirement(): array {
    $requirements = $this->container->get('module_handler')->invoke('markaspot_request_index', 'runtime_requirements');
    $this->assertIsArray($requirements);
    return $requirements['markaspot_request_index'];
  }

  /**
   * Runs the verify drush command and returns its exit code and output.
   *
   * @return array{0: int, 1: string}
   *   Exit code and output.
   */
  private function runVerifyCommand(): array {
    $command = RequestIndexCommands::create($this->container);
    $output = new BufferedOutput();
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output);
    $exit = $command->verify(['sample' => 0, 'drift-top' => 10]);
    return [$exit, $output->fetch()];
  }

  /**
   * Returns the verifier.
   */
  private function verifier(): RequestIndexVerifier {
    $verifier = $this->container->get('markaspot_request_index.verifier');
    $this->assertInstanceOf(RequestIndexVerifier::class, $verifier);
    return $verifier;
  }

  /**
   * Sorts a count map by key.
   *
   * @param array<string, int> $counts
   *   Counts.
   *
   * @return array<string, int>
   *   Counts sorted by key.
   */
  private function sorted(array $counts): array {
    ksort($counts);
    return $counts;
  }

}
