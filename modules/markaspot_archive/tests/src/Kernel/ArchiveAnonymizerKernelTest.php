<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_archive\Kernel;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_archive\Drush\Commands\ArchiveCommands;
use Drupal\markaspot_archive\ArchiveService;
use Drupal\markaspot_archive\ArchiveServiceInterface;
use Drupal\markaspot_archive\Plugin\QueueWorker\ArchiveQueueWorker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Psr\Log\LogLevel;

/**
 * Covers archive anonymization for current nodes and historic revisions.
 *
 * @group markaspot_archive
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class ArchiveAnonymizerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'taxonomy',
    'telephone',
    'markaspot_archive',
  ];

  /**
   * The test archive service.
   *
   * @var \Drupal\markaspot_archive\ArchiveService
   */
  private ArchiveService $archiveService;

  /**
   * Logger collecting records for assertions.
   *
   * @var \Drupal\Tests\markaspot_archive\Kernel\ArchiveAnonymizerTestLogger
   */
  private ArchiveAnonymizerTestLogger $loggerSink;

  /**
   * Category term id.
   */
  private int $categoryTid;

  /**
   * Warning message for fields without dedicated revision tables.
   */
  private const SHARED_TABLE_WARNING = 'Revisions of field @field use a ' .
    'shared table and were not anonymized; previous revisions may retain data.';

  /**
   * Critical message when archive queue retries are exhausted.
   */
  private const GIVE_UP_MESSAGE = 'Queue processing failed for node @nid; ' .
    'giving up after 3 attempts: @error';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);

    // The module ships optional field config targeting the service_category
    // vocabulary, so the bundle entities must exist before config install.
    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
      'new_revision' => TRUE,
    ])->save();
    Vocabulary::create([
      'vid' => 'service_category',
      'name' => 'Service category',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service status',
    ])->save();

    $this->installConfig(['system', 'user', 'field', 'node']);
    $this->installConfig(['markaspot_archive']);

    $category = Term::create([
      'vid' => 'service_category',
      'name' => 'Streetlight',
    ]);
    $category->save();
    $this->categoryTid = (int) $category->id();

    $this->createNodeField('field_e_mail', 'email');
    $this->createNodeField('field_phone', 'telephone');
    $this->createNodeField('field_first_name', 'string');
    $this->createNodeField('field_notification', 'boolean');
    $this->createNodeField('field_category', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);
    $this->createNodeField('field_jurisdiction', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);
    $this->createNodeField('field_status', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);

    $logger = new LoggerChannel('markaspot_archive');
    $this->loggerSink = new ArchiveAnonymizerTestLogger();
    $logger->addLogger($this->loggerSink);
    $this->archiveService = new ArchiveService(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $logger,
      $this->container->get('database'),
    );
  }

  /**
   * The module installs the service and safe contact-data defaults.
   */
  public function testServiceAndSafeDefaultConfigAreInstalled(): void {
    self::assertInstanceOf(
      ArchiveService::class,
      $this->container->get('markaspot_archive.archive')
    );
    self::assertSame([
      'field_e_mail' => 'field_e_mail',
      'field_first_name' => 'field_first_name',
      'field_last_name' => 'field_last_name',
      'field_phone' => 'field_phone',
      'field_notification' => 'field_notification',
    ], $this->config('markaspot_archive.settings')->get('anonymize_fields'));
    self::assertArrayNotHasKey(
      'field_address',
      $this->config('markaspot_archive.settings')->get('anonymize_fields')
    );
  }

  /**
   * Supported field types are anonymized with type-aware values.
   */
  public function testSupportedFieldsAreAnonymized(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_phone' => '+49 221 123456',
      'field_first_name' => 'Jane',
      'field_notification' => 1,
    ]);

    $values = $this->archiveService->anonymize($node, [
      'field_e_mail' => 'field_e_mail',
      'field_phone' => 'field_phone',
      'field_first_name' => 'field_first_name',
      'field_notification' => 'field_notification',
    ]);

    self::assertArrayHasKey('field_e_mail', $values);
    self::assertNotSame(
      'citizen@example.org',
      $node->get('field_e_mail')->value
    );
    self::assertStringEndsWith(
      '@anonymized.off',
      $node->get('field_e_mail')->value
    );
    self::assertSame('+49-0123459995555', $node->get('field_phone')->value);
    self::assertNotSame('Jane', $node->get('field_first_name')->value);
    self::assertSame('0', (string) $node->get('field_notification')->value);
  }

  /**
   * Complex fields are skipped and logged instead of being blindly overwritten.
   */
  public function testEntityReferenceFieldIsSkippedAndLogged(): void {
    $node = $this->createRequest([
      'field_category' => $this->categoryTid,
    ]);

    $values = $this->archiveService->anonymize($node, [
      'field_category' => 'field_category',
    ]);

    self::assertSame([], $values);
    self::assertSame(
      $this->categoryTid,
      (int) $node->get('field_category')->target_id
    );
    self::assertCount(1, $this->loggerSink->records);
    self::assertSame(
      'field @field of type @type skipped, not anonymizable',
      (string) $this->loggerSink->records[0]['message']
    );
    self::assertSame(
      'field_category',
      $this->loggerSink->records[0]['context']['@field']
    );
    self::assertSame(
      'entity_reference',
      $this->loggerSink->records[0]['context']['@type']
    );
  }

  /**
   * Empty fields remain empty on the current node.
   */
  public function testEmptyFieldsRemainEmpty(): void {
    $node = $this->createRequest();

    $this->archiveService->anonymize($node, [
      'field_e_mail' => 'field_e_mail',
    ]);

    self::assertTrue($node->get('field_e_mail')->isEmpty());
  }

  /**
   * An empty field list is a no-op.
   */
  public function testEmptyAnonymizeFieldsListDoesNothing(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_notification' => 1,
    ]);

    $values = $this->archiveService->anonymize($node, []);

    self::assertSame([], $values);
    self::assertSame('citizen@example.org', $node->get('field_e_mail')->value);
    self::assertSame('1', (string) $node->get('field_notification')->value);
  }

  /**
   * List-form config fields are accepted while legacy numeric ids are ignored.
   */
  public function testListConfiguredFieldsAreAccepted(): void {
    $fields = $this->archiveService->normalizeConfiguredFields([
      0 => 'field_e_mail',
      1 => 123,
      2 => '123',
      'field_first_name' => 'field_first_name',
      'field_last_name' => 0,
      'field_middle_name' => '123',
    ]);

    self::assertSame([
      'field_e_mail' => 'field_e_mail',
      'field_first_name' => 'field_first_name',
    ], $fields);
  }

  /**
   * Previous node revisions are overwritten with the current anonymized value.
   */
  public function testPreviousRevisionFieldRowsAreAnonymized(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'first@example.org',
    ]);
    $first_revision = (int) $node->getRevisionId();

    $node->setNewRevision(TRUE);
    $node->set('field_e_mail', 'second@example.org');
    $node->save();
    $second_revision = (int) $node->getRevisionId();

    $node->setNewRevision(TRUE);
    $node->set('field_e_mail', 'current@example.org');
    $values = $this->archiveService->anonymize($node, [
      'field_e_mail' => 'field_e_mail',
    ]);
    $current_value = $node->get('field_e_mail')->value;
    $node->save();

    $updated = $this->archiveService->anonymizeRevisions($node, $values);

    self::assertGreaterThanOrEqual(2, $updated);
    self::assertStringEndsWith('@anonymized.off', $current_value);
    self::assertSame(
      $current_value,
      $this->revisionFieldValue($first_revision, 'field_e_mail')
    );
    self::assertSame(
      $current_value,
      $this->revisionFieldValue($second_revision, 'field_e_mail')
    );
    self::assertSame($current_value, $node->get('field_e_mail')->value);
  }

  /**
   * Staff anonymization keeps the request public and rewrites revisions.
   */
  public function testStaffCommandAnonymizesNodeAndPreviousRevisions(): void {
    $status = $this->createStatusTerm('Open');
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
        'field_first_name' => 'field_first_name',
        'field_notification' => 'field_notification',
      ])
      ->save();

    $node = $this->createRequest([
      'status' => TRUE,
      'field_status' => (int) $status->id(),
      'field_e_mail' => 'first@example.org',
      'field_phone' => '+49 221 111111',
      'field_first_name' => 'Jane',
      'field_notification' => 1,
    ]);
    $first_revision = (int) $node->getRevisionId();

    $node->setNewRevision(TRUE);
    $node->set('field_e_mail', 'second@example.org');
    $node->set('field_phone', '+49 221 222222');
    $node->set('field_first_name', 'Janet');
    $node->save();
    $second_revision = (int) $node->getRevisionId();

    $this->archiveCommand()->anonymize(
      (int) $node->id(),
      $this->commandOptions(['no-cascade' => TRUE])
    );

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('node');
    $storage->resetCache([(int) $node->id()]);
    $anonymized = $storage->load((int) $node->id());
    self::assertInstanceOf(Node::class, $anonymized);

    self::assertTrue($anonymized->isPublished());
    self::assertSame(
      (int) $status->id(),
      (int) $anonymized->get('field_status')->target_id
    );
    self::assertNotSame(
      'second@example.org',
      $anonymized->get('field_e_mail')->value
    );
    self::assertStringEndsWith(
      '@anonymized.off',
      $anonymized->get('field_e_mail')->value
    );
    self::assertSame(
      '+49-0123459995555',
      $anonymized->get('field_phone')->value
    );
    self::assertNotSame(
      'Janet',
      $anonymized->get('field_first_name')->value
    );
    self::assertSame(
      '0',
      (string) $anonymized->get('field_notification')->value
    );

    self::assertSame(
      $anonymized->get('field_e_mail')->value,
      $this->revisionFieldValue($first_revision, 'field_e_mail')
    );
    self::assertSame(
      $anonymized->get('field_e_mail')->value,
      $this->revisionFieldValue($second_revision, 'field_e_mail')
    );
    self::assertSame(
      $anonymized->get('field_phone')->value,
      $this->revisionFieldValue($first_revision, 'field_phone')
    );
    self::assertSame(
      (string) $anonymized->get('field_notification')->value,
      $this->revisionFieldValue($first_revision, 'field_notification')
    );

    $revision_ids = $this->nodeRevisionIds((int) $anonymized->id());
    self::assertCount(3, $revision_ids);
    $latest_revision = $storage->loadRevision(max($revision_ids));
    self::assertInstanceOf(Node::class, $latest_revision);
    self::assertSame(
      'Reporter contact data anonymized (GDPR request via drush).',
      $latest_revision->getRevisionLogMessage()
    );
  }

  /**
   * By-mail anonymization targets exact matches and honors jurisdiction scope.
   */
  public function testStaffCommandByMailQueryAndJurisdictionFilter(): void {
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
      ])
      ->save();
    $jurisdiction_a = $this->createStatusTerm('Jurisdiction A');
    $jurisdiction_b = $this->createStatusTerm('Jurisdiction B');

    $match_a = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_jurisdiction' => (int) $jurisdiction_a->id(),
    ]);
    $match_b = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_jurisdiction' => (int) $jurisdiction_b->id(),
    ]);
    $this->createRequest([
      'field_e_mail' => 'citizen@example.org.invalid',
      'field_jurisdiction' => (int) $jurisdiction_a->id(),
    ]);

    $all_rows = $this->rowsToArray($this->archiveCommand()->anonymize(
      NULL,
      $this->commandOptions([
        'by-mail' => 'citizen@example.org',
        'dry-run' => TRUE,
        'no-cascade' => TRUE,
      ])
    ));
    $all_nids = array_column($all_rows, 'nid');
    sort($all_nids);
    self::assertSame([
      (int) $match_a->id(),
      (int) $match_b->id(),
    ], $all_nids);

    $jurisdiction_rows = $this->rowsToArray($this->archiveCommand()
      ->anonymize(
        NULL,
        $this->commandOptions([
          'by-mail' => 'citizen@example.org',
          'jurisdiction' => (int) $jurisdiction_a->id(),
          'dry-run' => TRUE,
          'no-cascade' => TRUE,
        ])
      ));
    self::assertSame([
      (int) $match_a->id(),
    ], array_column($jurisdiction_rows, 'nid'));
  }

  /**
   * Dry-run previews configured fields without saving anything.
   */
  public function testStaffCommandDryRunDoesNotWrite(): void {
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
        'field_phone' => 'field_phone',
      ])
      ->save();
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
      'field_phone' => '+49 221 123456',
    ]);
    $revision_id = (int) $node->getRevisionId();

    $rows = $this->rowsToArray($this->archiveCommand()->anonymize(
      (int) $node->id(),
      $this->commandOptions([
        'dry-run' => TRUE,
        'no-cascade' => TRUE,
      ])
    ));

    self::assertSame([
      [
        'nid' => (int) $node->id(),
        'request_id' => '',
        'fields' => 'field_e_mail, field_phone',
      ],
    ], $rows);

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('node');
    $storage->resetCache([(int) $node->id()]);
    $unchanged = $storage->load((int) $node->id());
    self::assertInstanceOf(Node::class, $unchanged);
    self::assertSame('citizen@example.org', $unchanged->get(
      'field_e_mail'
    )->value);
    self::assertSame('+49 221 123456', $unchanged->get('field_phone')->value);
    self::assertSame($revision_id, (int) $unchanged->getRevisionId());
  }

  /**
   * Empty anonymize field config fails before the command can no-op.
   */
  public function testStaffCommandFailsWhenAnonymizeFieldsAreEmpty(): void {
    $node = $this->createRequest([
      'field_e_mail' => 'citizen@example.org',
    ]);
    $this->config('markaspot_archive.settings')
      ->set('anonymize_fields', [])
      ->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No anonymize fields are configured.');
    $this->archiveCommand()->anonymize(
      (int) $node->id(),
      $this->commandOptions(['no-cascade' => TRUE])
    );
  }

  /**
   * Split cascade traversal is transitive and cycle-safe.
   */
  public function testCascadeWalkerIsTransitiveAndCycleSafe(): void {
    $links = [
      1 => [
        ['source_nid' => 1, 'target_nid' => 2],
        ['source_nid' => 4, 'target_nid' => 1],
      ],
      2 => [
        ['source_nid' => 2, 'target_nid' => 3],
        ['source_nid' => 2, 'target_nid' => 1],
      ],
      3 => [
        ['source_nid' => 3, 'target_nid' => 4],
      ],
      4 => [
        ['source_nid' => 4, 'target_nid' => 2],
      ],
    ];

    $method = new \ReflectionMethod(
      ArchiveCommands::class,
      'resolveLinkedNodeIds'
    );
    $resolved = $method->invoke(
      NULL,
      1,
      static fn(int $nid): array => $links[$nid] ?? []
    );
    sort($resolved);

    self::assertSame([1, 2, 3, 4], $resolved);
  }

  /**
   * Shared-table previous revisions are skipped with an operator warning.
   */
  public function testSharedTableRevisionFieldsAreLogged(): void {
    $node = $this->createRequest([
      'title' => 'Original title',
    ]);

    $node->setNewRevision(TRUE);
    $node->setTitle('Second title');
    $node->save();

    $node->setNewRevision(TRUE);
    $node->setTitle('Current title');
    $values = $this->archiveService->anonymize($node, [
      'title' => 'title',
    ]);
    $node->save();

    $updated = $this->archiveService->anonymizeRevisions($node, $values);

    self::assertSame(0, $updated);
    self::assertTrue($this->loggerHasRecord(
      LogLevel::WARNING,
      self::SHARED_TABLE_WARNING
    ));
  }

  /**
   * Queue failures stop after the third attempt and mark the node in state.
   */
  public function testArchiveQueueWorkerGivesUpAfterThreeAttempts(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(new \RuntimeException(
      'Anonymizer failed'
    ));
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::never())->method('createItem');

    $worker = $this->archiveQueueWorker($archive_service, $queue);
    $worker->processItem([
      'nid' => (int) $node->id(),
      'attempts' => ArchiveQueueWorker::MAX_ATTEMPTS,
    ]);

    $failed_nids = (array) $this->container
      ->get('state')
      ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);

    self::assertArrayHasKey((int) $node->id(), $failed_nids);
    self::assertGreaterThan(0, $failed_nids[(int) $node->id()]);
    self::assertTrue($this->loggerHasRecord(
      LogLevel::CRITICAL,
      self::GIVE_UP_MESSAGE
    ));
  }

  /**
   * Failed items below the cap are requeued with an incremented attempt count.
   */
  public function testArchiveQueueWorkerRequeuesWithNextAttempt(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(new \RuntimeException(
      'Anonymizer failed'
    ));
    $queue = $this->createMock(QueueInterface::class);
    $queue
      ->expects(self::once())
      ->method('createItem')
      ->with([
        'nid' => (int) $node->id(),
        'attempts' => 2,
      ]);

    $worker = $this->archiveQueueWorker($archive_service, $queue);
    $worker->processItem([
      'nid' => (int) $node->id(),
      'attempts' => 1,
    ]);

    $failed_nids = (array) $this->container
      ->get('state')
      ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);
    self::assertSame([], $failed_nids);
  }

  /**
   * Database failures suspend the whole queue without consuming an attempt.
   */
  public function testArchiveQueueWorkerSuspendsOnDatabaseException(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(
      new DatabaseExceptionWrapper('Database unavailable')
    );
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::never())->method('createItem');

    $worker = $this->archiveQueueWorker($archive_service, $queue);

    try {
      $worker->processItem([
        'nid' => (int) $node->id(),
        'attempts' => 1,
      ]);
      self::fail('Expected a SuspendQueueException.');
    }
    catch (SuspendQueueException) {
      $failed_nids = (array) $this->container
        ->get('state')
        ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);
      self::assertSame([], $failed_nids);
    }
  }

  /**
   * Queue-control exceptions from dependencies stay queue-level failures.
   */
  public function testArchiveQueueWorkerRethrowsSuspendQueueException(): void {
    $node = $this->createQueueRequest();
    $archive_service = $this->failingArchiveService(
      new SuspendQueueException('Queue paused')
    );
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::never())->method('createItem');

    $worker = $this->archiveQueueWorker($archive_service, $queue);

    try {
      $worker->processItem([
        'nid' => (int) $node->id(),
        'attempts' => 1,
      ]);
      self::fail('Expected a SuspendQueueException.');
    }
    catch (SuspendQueueException) {
      $failed_nids = (array) $this->container
        ->get('state')
        ->get(ArchiveQueueWorker::FAILED_NIDS_STATE, []);
      self::assertSame([], $failed_nids);
    }
  }

  /**
   * Creates a field on the service_request node type.
   */
  private function createNodeField(
    string $name,
    string $type,
    array $settings = [],
    int $cardinality = 1,
  ): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'service_request', $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $name,
        'required' => FALSE,
      ])->save();
    }
  }

  /**
   * Creates a service request node.
   */
  private function createRequest(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'Broken streetlight',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a service request configured for queue processing.
   */
  private function createQueueRequest(): Node {
    $open_status = $this->createStatusTerm('Open');
    $archived_status = $this->createStatusTerm('Archived');
    $this->config('markaspot_archive.settings')
      ->set('status_archivable', [
        (int) $open_status->id() => (string) $open_status->id(),
      ])
      ->set('status_archived', (string) $archived_status->id())
      ->set('unpublish', 0)
      ->set('anonymize', 1)
      ->set('anonymize_fields', [
        'field_e_mail' => 'field_e_mail',
      ])
      ->save();

    return $this->createRequest([
      'field_status' => (int) $open_status->id(),
      'field_e_mail' => 'citizen@example.org',
    ]);
  }

  /**
   * Creates a service status term.
   */
  private function createStatusTerm(string $name): Term {
    $term = Term::create([
      'vid' => 'service_status',
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Builds an archive service mock that fails during anonymization.
   */
  private function failingArchiveService(
    \Throwable $exception,
  ): ArchiveServiceInterface {
    $archive_service = $this->createMock(ArchiveServiceInterface::class);
    $archive_service
      ->method('normalizeConfiguredFields')
      ->willReturn([
        'field_e_mail' => 'field_e_mail',
      ]);
    $archive_service
      ->method('anonymize')
      ->willThrowException($exception);
    return $archive_service;
  }

  /**
   * Builds a queue worker for targeted failure handling assertions.
   */
  private function archiveQueueWorker(
    ArchiveServiceInterface $archive_service,
    QueueInterface $queue,
  ): ArchiveQueueWorker {
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory
      ->method('get')
      ->with('markaspot_archive_queue_worker')
      ->willReturn($queue);

    return new ArchiveQueueWorker(
      [],
      'markaspot_archive_queue_worker',
      [],
      $archive_service,
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->testLogger(),
      $queue_factory,
      $this->container->get('state'),
      $this->container->get('datetime.time')
    );
  }

  /**
   * Builds the staff anonymization command under test.
   */
  private function archiveCommand(): ArchiveCommands {
    return new ArchiveCommands(
      $this->archiveService,
      $this->container->get('config.factory'),
      $this->container->get('date.formatter'),
      $this->container->get('entity_type.manager'),
      $this->testLogger(),
      $this->container->get('database'),
      NULL
    );
  }

  /**
   * Returns command options with Drush-like defaults.
   *
   * @param array<string, mixed> $overrides
   *   Option overrides.
   *
   * @return array<string, mixed>
   *   Command options.
   */
  private function commandOptions(array $overrides = []): array {
    return $overrides + [
      'by-mail' => NULL,
      'jurisdiction' => NULL,
      'dry-run' => FALSE,
      'no-cascade' => FALSE,
    ];
  }

  /**
   * Converts Drush rows into a plain assertion array.
   *
   * @return array<int, array<string, mixed>>
   *   Row arrays.
   */
  private function rowsToArray(?RowsOfFields $rows): array {
    self::assertInstanceOf(RowsOfFields::class, $rows);
    $result = [];
    foreach ($rows as $row) {
      $result[] = $row;
    }
    return $result;
  }

  /**
   * Returns node revision IDs without using deprecated storage APIs.
   *
   * @return int[]
   *   Revision IDs in ascending order.
   */
  private function nodeRevisionIds(int $nid): array {
    $values = $this->container
      ->get('database')
      ->select('node_revision', 'nr')
      ->fields('nr', ['vid'])
      ->condition('nid', $nid)
      ->orderBy('vid')
      ->execute()
      ->fetchCol();
    return array_map('intval', $values);
  }

  /**
   * Returns a test logger backed by the assertion sink.
   */
  private function testLogger(): LoggerChannel {
    $logger = new LoggerChannel('markaspot_archive');
    $logger->addLogger($this->loggerSink);
    return $logger;
  }

  /**
   * Returns TRUE when a matching log record exists.
   *
   * LoggerChannel translates PSR string levels to RFC 5424 integers before
   * delegating to registered loggers, so both representations must match.
   */
  private function loggerHasRecord(string $level, string $message): bool {
    $rfc_levels = [
      LogLevel::CRITICAL => RfcLogLevel::CRITICAL,
      LogLevel::WARNING => RfcLogLevel::WARNING,
      LogLevel::NOTICE => RfcLogLevel::NOTICE,
    ];
    $rfc_level = $rfc_levels[$level] ?? NULL;
    foreach ($this->loggerSink->records as $record) {
      if (
        ($record['level'] === $level || $record['level'] === $rfc_level)
        && (string) $record['message'] === $message
      ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads a revision field value directly from the revision field table.
   */
  private function revisionFieldValue(
    int $revision_id,
    string $field_name,
  ): ?string {
    $column = $field_name . '_value';
    $value = $this->container
      ->get('database')
      ->select('node_revision__' . $field_name, 'f')
      ->fields('f', [$column])
      ->condition('revision_id', $revision_id)
      ->execute()
      ->fetchField();
    return $value === FALSE ? NULL : (string) $value;
  }

}
