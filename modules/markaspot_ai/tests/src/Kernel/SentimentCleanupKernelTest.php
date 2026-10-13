<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\markaspot_privacy\Form\GDPRForm;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Sentiment records and other derived AI data leave together with a report.
 *
 * Runs the real node delete/update lifecycle with markaspot_ai enabled.
 */
#[TestGroup('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class SentimentCleanupKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'file', 'options',
    'entity', 'flexible_permissions', 'group',
    'entity_reference_revisions', 'paragraphs',
    'markaspot_ai', 'markaspot_ai_pii_test',
    'markaspot_archive', 'markaspot_privacy',
  ];

  /**
   * Reasoning text that must never reach a log line.
   */
  private const REASONING = 'Citizen Erika Mustermann is upset about the blocked lane.';

  /**
   * Captured log lines.
   *
   * @var string[]
   */
  private array $logLines = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper', 'paragraph'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('markaspot_ai', [
      'markaspot_ai_embeddings',
      'markaspot_ai_duplicate_matches',
      'markaspot_ai_sentiment',
    ]);
    $this->installConfig(['system', 'user', 'field', 'node', 'filter']);

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->addField('body', 'text_with_summary');
    $this->addField('field_feedback', 'text_long');
    $this->addField('field_request_attributes', 'string_long');
    $this->addField('field_e_mail', 'email');
    $this->addField('field_first_name', 'string');

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    $lines = &$this->logLines;
    $this->container->get('logger.factory')->addLogger(new class($lines) implements LoggerInterface {

      use RfcLoggerTrait;

      /**
       * Collected lines.
       *
       * @var string[]
       */
      private array $lines;

      public function __construct(array &$lines) {
        $this->lines = &$lines;
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = strtr((string) $message, array_map('strval', array_filter($context, 'is_scalar')));
      }

    });
  }

  /**
   * Deleting a report removes all of its AI records and nothing else.
   */
  public function testDeletingReportRemovesItsAiRecords(): void {
    $doomed = $this->createReport('Blocked lane');
    $kept = $this->createReport('Broken lamp');
    $other = $this->createReport('Pothole');
    foreach ([$doomed, $kept, $other] as $node) {
      $this->addSentiment((int) $node->id());
      $this->addEmbedding((int) $node->id());
    }
    $this->addMatch((int) $doomed->id(), (int) $kept->id());
    $this->addMatch((int) $kept->id(), (int) $other->id());

    $doomed->delete();

    $this->assertSame(0, $this->rowCount('markaspot_ai_sentiment', (int) $doomed->id()));
    $this->assertSame(0, $this->rowCount('markaspot_ai_embeddings', (int) $doomed->id()));
    $this->assertSame(0, $this->matchCount((int) $doomed->id()));

    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $kept->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $other->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_embeddings', (int) $kept->id()));
    $this->assertSame(1, $this->matchCount((int) $other->id()));
    $this->assertReasoningNotLogged();
  }

  /**
   * A non-report node and a sentiment row of another entity type survive.
   */
  public function testOtherBundlesAndEntityTypesAreUntouched(): void {
    $page = Node::create(['type' => 'page', 'title' => 'About', 'uid' => 1]);
    $page->save();
    $this->addSentiment((int) $page->id());
    $this->addSentiment((int) $page->id(), 'user');

    $page->delete();

    // Pages never get sentiment cleanup; the hook only handles reports.
    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $page->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $page->id(), 'user'));
  }

  /**
   * A failed report deletion rolls the AI cleanup back with it.
   */
  public function testFailedDeletionKeepsAiRecords(): void {
    $report = $this->createReport('Blocked lane');
    $this->addSentiment((int) $report->id());
    $this->addEmbedding((int) $report->id());
    \Drupal::state()->set('markaspot_ai_pii_test.fail_report_delete', TRUE);

    $thrown = FALSE;
    try {
      $report->delete();
    }
    catch (\Throwable) {
      $thrown = TRUE;
    }
    $this->assertTrue($thrown, 'The forced delete failure surfaces.');
    \Drupal::state()->delete('markaspot_ai_pii_test.fail_report_delete');

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertNotNull(Node::load($report->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $report->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_embeddings', (int) $report->id()));
  }

  /**
   * A failing cleanup aborts the deletion instead of leaving data behind.
   */
  public function testCleanupFailureRollsBackTheDeletion(): void {
    $report = $this->createReport('Blocked lane');
    $this->addSentiment((int) $report->id());
    $this->addEmbedding((int) $report->id());

    $failing = $this->createMock(SentimentService::class);
    $failing->method('deleteForEntity')->willThrowException(new \RuntimeException('Simulated database failure.'));
    $this->container->set('markaspot_ai.sentiment', $failing);

    $thrown = FALSE;
    try {
      $report->delete();
    }
    catch (\Throwable) {
      $thrown = TRUE;
    }
    $this->assertTrue($thrown, 'The cleanup failure aborts the deletion.');

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertNotNull(Node::load($report->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $report->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_embeddings', (int) $report->id()), 'Embeddings deleted before the failure are rolled back.');
    $this->assertReasoningNotLogged();
  }

  /**
   * Changing the analysed text clears the reasoning; other edits keep it.
   */
  public function testTextChangeClearsReasoningButKeepsClassification(): void {
    $report = $this->createReport('Blocked lane');
    $this->addSentiment((int) $report->id());

    // An edit that does not touch the analysed text keeps the reasoning.
    $report->setSticky(TRUE)->save();
    $this->assertSame(self::REASONING, $this->sentimentRow((int) $report->id())['reasoning']);

    // The archive anonymises by overwriting fields and saving the node.
    $report->set('body', '********')->setUnpublished()->save();
    $row = $this->sentimentRow((int) $report->id());
    $this->assertNull($row['reasoning']);
    $this->assertSame('frustrated', $row['sentiment']);

    // Feedback and title are analysed too.
    $second = $this->createReport('Broken lamp');
    $this->addSentiment((int) $second->id());
    $second->set('field_feedback', 'Thanks, fixed.')->save();
    $this->assertNull($this->sentimentRow((int) $second->id())['reasoning']);

    $third = $this->createReport('Pothole');
    $this->addSentiment((int) $third->id());
    $third->setTitle('Pothole on Main Street')->save();
    $this->assertNull($this->sentimentRow((int) $third->id())['reasoning']);
  }

  /**
   * A failing embedding or match cleanup rolls the whole deletion back.
   */
  public function testRealCleanupFailuresRollBackTheDeletion(): void {
    foreach (['markaspot_ai_duplicate_matches', 'markaspot_ai_embeddings'] as $broken) {
      $report = $this->createReport('Blocked lane ' . $broken);
      $this->addSentiment((int) $report->id());
      $this->addEmbedding((int) $report->id());
      // Make the real service fail on its table; the DDL happens before the
      // delete transaction starts.
      $schema = \Drupal::database()->schema();
      $schema->renameTable($broken, $broken . '_off');

      $thrown = FALSE;
      try {
        $report->delete();
      }
      catch (\Throwable) {
        $thrown = TRUE;
      }
      $schema->renameTable($broken . '_off', $broken);

      $this->assertTrue($thrown, "A failing $broken cleanup aborts the deletion.");
      $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
      $this->assertNotNull(Node::load($report->id()));
      $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $report->id()));
      $this->assertSame(1, $this->rowCount('markaspot_ai_embeddings', (int) $report->id()), 'Embeddings deleted before the failure are rolled back.');
    }
    $this->assertReasoningNotLogged();
  }

  /**
   * Changing only the request attributes clears the reasoning.
   */
  public function testAttributeOnlyChangeClearsReasoning(): void {
    $report = $this->createReport('Blocked lane');
    $report->set('field_request_attributes', '{"vehicle":"Erika\'s car"}')->save();
    $this->addSentiment((int) $report->id());

    $report->set('field_request_attributes', '{"vehicle":"[REDACTED]"}')->save();

    $this->assertNull($this->sentimentRow((int) $report->id())['reasoning']);
  }

  /**
   * The real archive anonymisation clears the reasoning of a kept report.
   */
  public function testArchiveAnonymisationClearsReasoning(): void {
    $report = $this->createReport('Blocked lane');
    $report->set('field_e_mail', 'erika@example.test')->set('field_first_name', 'Erika')->save();
    $this->addSentiment((int) $report->id());
    $kept = $this->createReport('Broken lamp');
    $this->addSentiment((int) $kept->id());
    $body = $report->get('body')->value;

    // The same sequence as ArchiveQueueWorker and the drush command, with the
    // shipped contact-field configuration.
    $archive = $this->container->get('markaspot_archive.archive');
    $values = $archive->anonymize($report, [
      'field_e_mail' => 'field_e_mail',
      'field_first_name' => 'field_first_name',
    ]);
    $this->assertNotSame([], $values);
    $report->save();
    $archive->anonymizeRevisions($report, $values);

    $report = Node::load($report->id());
    $this->assertSame($body, $report->get('body')->value, 'Anonymisation does not touch the analysed text.');
    $this->assertNotSame('erika@example.test', $report->get('field_e_mail')->value);
    $row = $this->sentimentRow((int) $report->id());
    $this->assertNull($row['reasoning']);
    $this->assertSame('frustrated', $row['sentiment']);
    $this->assertSame(self::REASONING, $this->sentimentRow((int) $kept->id())['reasoning']);

    // Nothing anonymized: no purge.
    $third = $this->createReport('Pothole');
    $this->addSentiment((int) $third->id());
    $archive->anonymizeRevisions($third, []);
    $this->assertSame(self::REASONING, $this->sentimentRow((int) $third->id())['reasoning']);
  }

  /**
   * The GDPR erasure form clears the reasoning as well.
   */
  public function testGdprErasureClearsReasoning(): void {
    $report = $this->createReport('Blocked lane');
    $report->set('field_e_mail', 'erika@example.test')->save();
    $this->addSentiment((int) $report->id());

    $form_object = GDPRForm::create($this->container);
    $form_state = (new FormState())->setValue('uuid', $report->uuid());
    $form = [];
    $form_object->submitForm($form, $form_state);

    $this->assertNull($this->sentimentRow((int) $report->id())['reasoning']);
  }

  /**
   * Account cancellation that keeps the reports clears their reasoning.
   */
  public function testUserCancelReassignAndUnpublishClearReasoning(): void {
    foreach (['user_cancel_reassign', 'user_cancel_block_unpublish'] as $i => $method) {
      $author = User::create(['name' => 'reporter' . $i, 'mail' => "r$i@example.test", 'status' => 1]);
      $author->save();
      $other = User::create(['name' => 'other' . $i, 'mail' => "o$i@example.test", 'status' => 1]);
      $other->save();
      $own = [
        $this->createReport('Lane ' . $i, (int) $author->id()),
        $this->createReport('Lamp ' . $i, (int) $author->id()),
      ];
      $foreign = $this->createReport('Pothole ' . $i, (int) $other->id());
      foreach ([...$own, $foreign] as $node) {
        $this->addSentiment((int) $node->id());
      }

      $this->cancelAccount((int) $author->id(), $method);

      foreach ($own as $node) {
        $row = $this->sentimentRow((int) $node->id());
        $this->assertNull($row['reasoning'], "$method clears the reasoning of the user's reports.");
        $this->assertSame('frustrated', $row['sentiment']);
      }
      $this->assertSame(self::REASONING, $this->sentimentRow((int) $foreign->id())['reasoning']);

      $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
      $reloaded = Node::load($own[0]->id());
      if ($method === 'user_cancel_reassign') {
        $this->assertSame(0, (int) $reloaded->getOwnerId(), 'Core reassigned the report.');
      }
      else {
        $this->assertFalse($reloaded->isPublished(), 'Core unpublished the report.');
      }
    }
  }

  /**
   * Blocking an account without touching content keeps the reasoning.
   */
  public function testUserCancelBlockKeepsReasoning(): void {
    $author = User::create(['name' => 'reporter', 'mail' => 'r@example.test', 'status' => 1]);
    $author->save();
    $report = $this->createReport('Lane', (int) $author->id());
    $this->addSentiment((int) $report->id());

    $this->cancelAccount((int) $author->id(), 'user_cancel_block');

    $this->assertSame(self::REASONING, $this->sentimentRow((int) $report->id())['reasoning']);
  }

  /**
   * The dump scrub detects and clears derived reasoning.
   *
   * --verify ends the process with exit(1) on any residue, so the gate is
   * covered through the counter it uses rather than by running it here.
   */
  public function testScrubScriptClearsDerivedText(): void {
    $report = $this->createReport('Lane');
    $second = $this->createReport('Lamp');
    $this->addSentiment((int) $report->id());
    $this->addSentiment((int) $second->id());
    \Drupal::database()->update('markaspot_ai_sentiment')
      ->fields(['reasoning' => ''])
      ->condition('entity_id', $second->id())
      ->execute();

    putenv('IS_DDEV_PROJECT=true');
    // The script reads its drush arguments from $extra: none means dry run.
    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    $extra = [];
    ob_start();
    include DRUPAL_ROOT . '/profiles/contrib/markaspot/scripts/scrub-pii.php';
    $output = (string) ob_get_clean();

    // The dry run reports the one non-empty reasoning and writes nothing.
    $this->assertStringContainsString('Derived text markaspot_ai_sentiment (reasoning): 1 rows would be cleared', $output);
    $this->assertSame(self::REASONING, $this->sentimentRow((int) $report->id())['reasoning']);

    // The counter --verify uses sees the residue, the apply step removes it.
    $this->assertSame(1, scrub_count_derived_text('markaspot_ai_sentiment', ['reasoning']));
    scrub_clear_derived_text('markaspot_ai_sentiment', ['reasoning']);
    $this->assertSame(0, scrub_count_derived_text('markaspot_ai_sentiment', ['reasoning']));
    $this->assertNull($this->sentimentRow((int) $report->id())['reasoning']);
    $this->assertSame('frustrated', $this->sentimentRow((int) $report->id())['sentiment']);
    $this->assertNull(scrub_count_derived_text('markaspot_ai_missing_table', ['reasoning']));
  }

  /**
   * The update hook removes orphans only, per entity type, idempotently.
   */
  public function testOrphanCleanupUpdate(): void {
    $live = $this->createReport('Blocked lane');
    $gone = $this->createReport('Old report');
    $this->addSentiment((int) $live->id());
    $this->addSentiment((int) $gone->id());
    // Simulate a report deleted before sentiment cleanup existed.
    \Drupal::database()->delete('node_field_data')->condition('nid', $gone->id())->execute();
    \Drupal::database()->delete('node')->condition('nid', $gone->id())->execute();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();

    $this->addSentiment(1, 'user');
    $this->addSentiment(999, 'user');
    $this->addSentiment(5, 'legacy_thing');

    // Small batches walk the whole table by primary key.
    $service = $this->container->get('markaspot_ai.sentiment');
    $first = $service->purgeOrphanedRecords(0, 2);
    $this->assertSame(2, $first['scanned']);
    $second = $service->purgeOrphanedRecords($first['last_id'], 2);
    $third = $service->purgeOrphanedRecords($second['last_id'], 2);
    $this->assertSame(2, $first['deleted'] + $second['deleted'] + $third['deleted']);

    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', (int) $live->id()));
    $this->assertSame(0, $this->rowCount('markaspot_ai_sentiment', (int) $gone->id()));
    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', 1, 'user'));
    $this->assertSame(0, $this->rowCount('markaspot_ai_sentiment', 999, 'user'));
    $this->assertSame(1, $this->rowCount('markaspot_ai_sentiment', 5, 'legacy_thing'), 'Rows of an unknown entity type are kept.');

    // The update hook itself finishes and is idempotent.
    \Drupal::moduleHandler()->loadInclude('markaspot_ai', 'install');
    $sandbox = [];
    do {
      $message = markaspot_ai_update_11903($sandbox);
    } while (($sandbox['#finished'] ?? 1) < 1);
    $this->assertStringContainsString('0 orphaned', $message);
    $this->assertSame(3, $this->countAll());
  }

  /**
   * Creates a published report.
   */
  private function createReport(string $title, int $uid = 1): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $title,
      'uid' => $uid,
      'status' => 1,
      'body' => ['value' => 'Description of ' . $title, 'format' => 'plain_text'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Cancels an account through core's user_cancel() and runs its batch.
   */
  private function cancelAccount(int $uid, string $method): void {
    user_cancel([], $uid, $method);
    $batch = &batch_get();
    $batch['progressive'] = FALSE;
    batch_process();
  }

  /**
   * Inserts a sentiment row.
   */
  private function addSentiment(int $entityId, string $entityType = 'node'): void {
    \Drupal::database()->insert('markaspot_ai_sentiment')->fields([
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'sentiment' => 'frustrated',
      'score' => -0.6,
      'confidence' => 0.9,
      'reasoning' => self::REASONING,
      'analyzed_at' => 1000,
    ])->execute();
  }

  /**
   * Inserts an embedding row.
   */
  private function addEmbedding(int $nid): void {
    \Drupal::database()->insert('markaspot_ai_embeddings')->fields([
      'entity_type' => 'node',
      'entity_id' => $nid,
      'embedding_type' => 'content',
      'embedding' => '[0.1,0.2]',
      'model' => 'test',
      'dimensions' => 2,
      'text_hash' => md5((string) $nid),
      'created' => 1000,
    ])->execute();
  }

  /**
   * Inserts a duplicate match row.
   */
  private function addMatch(int $source, int $match): void {
    \Drupal::database()->insert('markaspot_ai_duplicate_matches')->fields([
      'source_nid' => $source,
      'match_nid' => $match,
      'similarity_score' => 0.9,
      'status' => 'pending',
      'created' => 1000,
    ])->execute();
  }

  /**
   * Counts rows of an AI table for an entity.
   */
  private function rowCount(string $table, int $entityId, string $entityType = 'node'): int {
    return (int) \Drupal::database()->select($table, 't')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts all sentiment rows.
   */
  private function countAll(): int {
    return (int) \Drupal::database()->select('markaspot_ai_sentiment', 's')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts duplicate matches a node takes part in.
   */
  private function matchCount(int $nid): int {
    $or = \Drupal::database()->condition('OR')
      ->condition('source_nid', $nid)
      ->condition('match_nid', $nid);
    return (int) \Drupal::database()->select('markaspot_ai_duplicate_matches', 'm')
      ->condition($or)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Loads a report's sentiment row.
   */
  private function sentimentRow(int $nid): array {
    return (array) \Drupal::database()->select('markaspot_ai_sentiment', 's')
      ->fields('s', ['sentiment', 'reasoning'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nid)
      ->execute()
      ->fetchAssoc();
  }

  /**
   * Asserts that no log line repeats the stored reasoning.
   */
  private function assertReasoningNotLogged(): void {
    foreach ($this->logLines as $line) {
      $this->assertStringNotContainsString('Erika', $line);
    }
  }

  /**
   * Adds a field to service requests.
   */
  private function addField(string $name, string $type): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => $name,
    ])->save();
  }

}
