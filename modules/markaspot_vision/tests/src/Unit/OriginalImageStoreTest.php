<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests keeping, binding, showing and purging unblurred originals.
 */
#[CoversClass(OriginalImageStore::class)]
#[Group('markaspot_vision')]
class OriginalImageStoreTest extends UnitTestCase {

  /**
   * Request time used by the tests.
   */
  private const NOW = 1_800_000_000;

  /**
   * Rows of the originals table, keyed by media id.
   *
   * @var array
   */
  private array $rows = [];

  /**
   * Reports by nid: ['media' => int[], 'node' => NodeInterface].
   *
   * @var array
   */
  private array $reports = [];

  /**
   * Whether the site keeps originals.
   */
  private bool $enabled = TRUE;

  /**
   * Whether the private file system works.
   */
  private bool $privateOk = TRUE;

  /**
   * Whether a database transaction is open.
   */
  private bool $inTransaction = FALSE;

  /**
   * Post-transaction callbacks registered by the store.
   *
   * @var callable[]
   */
  private array $afterCommit = [];

  /**
   * State values.
   *
   * @var array
   */
  private array $state = [];

  /**
   * The file system mock.
   *
   * @var \Drupal\Core\File\FileSystemInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private FileSystemInterface $fileSystem;

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private LoggerInterface $logger;

  /**
   * Builds the store over in-memory rows, reports and state.
   */
  private function store(bool $contractor = FALSE): OriginalImageStore {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn ($key) => $key === 'retain_originals' ? $this->enabled : NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);
    $transactions = $this->createMock(TransactionManagerInterface::class);
    $transactions->method('inTransaction')->willReturnCallback(fn () => $this->inTransaction);
    $transactions->method('addPostTransactionCallback')->willReturnCallback(function (callable $callback) {
      $this->afterCommit[] = $callback;
    });
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->method('transactionManager')->willReturn($transactions);
    $database->method('select')->willReturnCallback(fn () => $this->selectQuery());
    $database->method('insert')->willReturnCallback(fn () => $this->insertQuery());
    $database->method('update')->willReturnCallback(fn () => $this->updateQuery());
    $database->method('delete')->willReturnCallback(function () {
      $delete = $this->createMock(Delete::class);
      $delete->method('condition')->willReturnCallback(function ($field, $value) use ($delete) {
        unset($this->rows[$value]);
        return $delete;
      });
      return $delete;
    });

    $nodeQuery = $this->createMock(QueryInterface::class);
    $mid = NULL;
    foreach (['accessCheck', 'sort', 'range'] as $method) {
      $nodeQuery->method($method)->willReturnSelf();
    }
    $nodeQuery->method('condition')->willReturnCallback(function ($field, $value) use (&$mid, $nodeQuery) {
      if ($field === 'field_request_media') {
        $mid = $value;
      }
      return $nodeQuery;
    });
    $nodeQuery->method('execute')->willReturnCallback(function () use (&$mid) {
      $nids = array_keys(array_filter($this->reports, fn (array $report) => in_array($mid, $report['media'], TRUE)));
      sort($nids);
      return array_slice($nids, 0, 1);
    });
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($nodeQuery);
    $nodeStorage->method('load')->willReturnCallback(fn ($nid) => $this->reports[$nid]['node'] ?? NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $streamWrappers = $this->createMock(StreamWrapperManagerInterface::class);
    $streamWrappers->method('isValidScheme')->with('private')->willReturnCallback(fn () => $this->privateOk);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn ($key, $default = NULL) => $this->state[$key] ?? $default);
    $state->method('set')->willReturnCallback(function ($key, $value) {
      $this->state[$key] = $value;
    });
    $this->logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);
    $statusClassifier = $this->createMock(StatusClassifier::class);
    $statusClassifier->method('closedTids')->willReturn([9]);

    return new class($configFactory, $database, $entityTypeManager, $this->fileSystem, $streamWrappers, $time, $state, $loggerFactory, $statusClassifier, $contractor) extends OriginalImageStore {

      public function __construct($configFactory, $database, $entityTypeManager, $fileSystem, $streamWrappers, $time, $state, $loggerFactory, $statusClassifier, private bool $contractor) {
        parent::__construct($configFactory, $database, $entityTypeManager, $fileSystem, $streamWrappers, $time, $state, $loggerFactory, $statusClassifier);
      }

      /**
       * Answers as configured by the test.
       */
      protected function isRestrictedContractor(AccountInterface $account): bool {
        return $this->contractor;
      }

    };
  }

  /**
   * A select over the in-memory rows, honouring the conditions used.
   */
  private function selectQuery(): SelectInterface {
    $select = $this->createMock(SelectInterface::class);
    $conditions = [];
    $limit = NULL;
    foreach (['fields', 'orderBy'] as $method) {
      $select->method($method)->willReturnSelf();
    }
    $select->method('range')->willReturnCallback(function ($start, $length) use (&$limit, $select) {
      $limit = $length;
      return $select;
    });
    $select->method('condition')->willReturnCallback(function ($field, $value, $op = '=') use (&$conditions, $select) {
      $conditions[] = [$field, $value, $op];
      return $select;
    });
    $select->method('execute')->willReturnCallback(function () use (&$conditions, &$limit) {
      $rows = array_filter($this->rows, function (array $row) use ($conditions) {
        foreach ($conditions as [$field, $value, $op]) {
          $keep = match ($op) {
            '<' => $row[$field] < $value,
            '>' => $row[$field] > $value,
            default => $row[$field] == $value,
          };
          if (!$keep) {
            return FALSE;
          }
        }
        return TRUE;
      });
      ksort($rows);
      if ($limit !== NULL) {
        $rows = array_slice($rows, 0, $limit, TRUE);
      }
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchAssoc')->willReturn($rows ? reset($rows) : FALSE);
      $statement->method('fetchAllKeyed')->willReturn(array_column($rows, 'nid', 'mid'));
      $statement->method('fetchCol')->willReturn(array_column($rows, 'mid'));
      return $statement;
    });
    return $select;
  }

  /**
   * An insert that fails like the primary key on an existing media id.
   */
  private function insertQuery(): Insert {
    $insert = $this->createMock(Insert::class);
    $fields = [];
    $insert->method('fields')->willReturnCallback(function (array $values) use (&$fields, $insert) {
      $fields = $values;
      return $insert;
    });
    $insert->method('execute')->willReturnCallback(function () use (&$fields) {
      if (isset($this->rows[$fields['mid']])) {
        throw new IntegrityConstraintViolationException('Duplicate entry');
      }
      $this->rows[$fields['mid']] = $fields;
      return $fields['mid'];
    });
    return $insert;
  }

  /**
   * An update binding unbound rows to a report.
   */
  private function updateQuery(): Update {
    $update = $this->createMock(Update::class);
    $values = [];
    $mids = [];
    $onlyUnbound = FALSE;
    $update->method('fields')->willReturnCallback(function (array $fields) use (&$values, $update) {
      $values = $fields;
      return $update;
    });
    $createdAfter = NULL;
    $update->method('condition')->willReturnCallback(function ($field, $value) use (&$mids, &$createdAfter, $update) {
      if ($field === 'created') {
        $createdAfter = $value;
      }
      else {
        $mids = (array) $value;
      }
      return $update;
    });
    $update->method('isNull')->willReturnCallback(function ($field) use (&$onlyUnbound, $update) {
      $onlyUnbound = $field === 'nid';
      return $update;
    });
    $update->method('execute')->willReturnCallback(function () use (&$values, &$mids, &$onlyUnbound, &$createdAfter) {
      foreach ($mids as $mid) {
        if (isset($this->rows[$mid])
          && (!$onlyUnbound || $this->rows[$mid]['nid'] === NULL)
          && ($createdAfter === NULL || $this->rows[$mid]['created'] > $createdAfter)) {
          $this->rows[$mid] = $values + $this->rows[$mid];
        }
      }
      return 1;
    });
    return $update;
  }

  /**
   * A row of the originals table.
   */
  private function row(int $mid, int $created, ?int $nid): array {
    return [
      'mid' => $mid,
      'uri' => OriginalImageStore::DIRECTORY . '/uuid-' . $mid,
      'mime' => 'image/jpeg',
      'created' => $created,
      'nid' => $nid,
    ];
  }

  /**
   * A media mock.
   */
  private function media(int $id): MediaInterface {
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn($id);
    $media->method('uuid')->willReturn('uuid-' . $id);
    return $media;
  }

  /**
   * A logged-in account.
   */
  private function staff(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    return $account;
  }

  /**
   * Registers a report showing media, with its edit access and status.
   */
  private function report(int $nid, array $media, bool $editable = FALSE, ?int $status = NULL, ?int $closedAt = NULL): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('bundle')->willReturn('service_request');
    $node->method('access')->with('update')->willReturn($editable);
    $node->method('hasField')->willReturn(TRUE);
    $statusField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $statusField->method('isEmpty')->willReturn($status === NULL);
    $statusField->method('__get')->with('target_id')->willReturn($status);
    $notes = [];
    if ($closedAt !== NULL) {
      $note = $this->createMock(Paragraph::class);
      $note->method('hasField')->willReturn(TRUE);
      $term = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $term->method('__get')->with('target_id')->willReturn(9);
      $note->method('get')->with('field_status_term')->willReturn($term);
      $note->method('getCreatedTime')->willReturn($closedAt);
      $notes[] = $note;
    }
    $notesField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $notesField->method('referencedEntities')->willReturn($notes);
    $mediaField = new \ArrayIterator(array_map(fn ($mid) => (object) ['target_id' => $mid], $media));
    $node->method('get')->willReturnCallback(fn ($field) => match ($field) {
      'field_status' => $statusField,
      'field_request_media' => $mediaField,
      default => $notesField,
    });
    $node->method('getChangedTime')->willReturn(self::NOW);
    $this->reports[$nid] = ['media' => $media, 'node' => $node];
    return $node;
  }

  /**
   * Writes a readable image and returns its path.
   */
  private function image(): string {
    $uri = tempnam(sys_get_temp_dir(), 'original-');
    file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII='));
    return $uri;
  }

  /**
   * The first analysis keeps the original; a later one never replaces it.
   */
  public function testRetainKeepsTheFirstOriginalOnly(): void {
    $store = $this->store();
    $uri = $this->image();
    try {
      $this->fileSystem->expects($this->once())->method('saveData')
        ->with($this->anything(), OriginalImageStore::DIRECTORY . '/uuid-7');

      $store->retain($this->media(7), $uri);
      $store->retain($this->media(7), $uri);
    }
    finally {
      unlink($uri);
    }

    $this->assertSame('image/png', $this->rows[7]['mime']);
    $this->assertNull($this->rows[7]['nid'], 'Not yet submitted with a report.');
  }

  /**
   * A photo analysed after its report exists is bound to it right away.
   */
  public function testRetainBindsToTheReportAlreadyShowingThePhoto(): void {
    $this->report(21, [7]);
    $store = $this->store();
    $uri = $this->image();
    try {
      $store->retain($this->media(7), $uri);
    }
    finally {
      unlink($uri);
    }

    $this->assertSame(21, $this->rows[7]['nid']);
  }

  /**
   * A failed write leaves no row that would claim an original.
   */
  public function testFailedWriteRemovesTheRow(): void {
    $store = $this->store();
    $this->fileSystem->method('saveData')->willThrowException(new \RuntimeException('Disk full'));
    $this->logger->expects($this->once())->method('error');
    $uri = $this->image();
    try {
      $store->retain($this->media(7), $uri);
    }
    finally {
      unlink($uri);
    }

    $this->assertSame([], $this->rows);
  }

  /**
   * Without the site switch nothing is kept.
   */
  public function testRetainDoesNothingWhenSwitchedOff(): void {
    $this->enabled = FALSE;
    $store = $this->store();
    $this->fileSystem->expects($this->never())->method('saveData');

    $store->retain($this->media(7), 'public://photo.jpg');

    $this->assertSame([], $this->rows);
  }

  /**
   * The first report binds the original; later reports gain nothing.
   */
  public function testTheFirstReportOwnsTheOriginal(): void {
    $this->rows[7] = $this->row(7, self::NOW, NULL);
    $own = $this->report(1, [7], TRUE);
    // Another editor attaches the same photo to a report of their own.
    $foreign = $this->report(2, [7], TRUE);
    $store = $this->store();

    $store->bind($own);
    $store->bind($foreign);

    $this->assertSame(1, $this->rows[7]['nid']);
  }

  /**
   * Access follows edit access on the owning report, nothing else.
   */
  public function testCanViewFollowsTheOwningReportOnly(): void {
    $this->rows[7] = $this->row(7, self::NOW, 1);
    $this->rows[8] = $this->row(8, self::NOW, 2);
    $this->rows[9] = $this->row(9, self::NOW, NULL);
    $this->report(1, [7], TRUE);
    $this->report(2, [8], FALSE);
    // Report 3 is editable and shows photo 8, but does not own its original.
    $this->report(3, [8], TRUE);
    $store = $this->store();
    $anonymous = $this->createMock(AccountInterface::class);
    $anonymous->method('isAnonymous')->willReturn(TRUE);

    $this->assertTrue($store->canView($this->media(7), $this->staff()));
    $this->assertFalse($store->canView($this->media(8), $this->staff()), 'Editing another report showing the photo grants nothing.');
    $this->assertFalse($store->canView($this->media(9), $this->staff()), 'Not bound to any report yet.');
    $this->assertFalse($store->canView($this->media(7), $anonymous));
    $this->assertFalse($this->store(TRUE)->canView($this->media(7), $this->staff()), 'External contractors never.');
  }

  /**
   * Only URIs in the originals directory resolve to a media.
   */
  public function testFindByUriIgnoresOtherPrivateFiles(): void {
    $this->rows[7] = $this->row(7, self::NOW, 1);
    $store = $this->store();

    $this->assertSame(['mid' => 7, 'mime' => 'image/jpeg'], $store->findByUri(OriginalImageStore::DIRECTORY . '/uuid-7'));
    $this->assertNull($store->findByUri('private://invoices/uuid-7'));
  }

  /**
   * Originals go 30 days after closing, or after an upload no report used.
   */
  public function testPurgeFollowsTheRetentionRules(): void {
    $old = self::NOW - 40 * 86400;
    $this->rows[1] = $this->row(1, $old, 11);
    $this->rows[2] = $this->row(2, $old, 12);
    $this->rows[3] = $this->row(3, $old, 13);
    $this->rows[4] = $this->row(4, $old, NULL);
    $this->rows[5] = $this->row(5, self::NOW, NULL);
    // 1: closed 35 days ago. 2: closed 10 days ago. 3: still open.
    // 4: no report ever used it. 5: kept today.
    $this->report(11, [1], FALSE, 9, self::NOW - 35 * 86400);
    $this->report(12, [2], FALSE, 9, self::NOW - 10 * 86400);
    $this->report(13, [3], FALSE, 4);
    $store = $this->store();

    $this->assertSame(2, $store->purgeExpired());
    $this->assertSame([2, 3, 5], array_keys($this->rows));
  }

  /**
   * Open reports never block the originals behind them.
   */
  public function testPurgeWalksOnPastOriginalsItKeeps(): void {
    $old = self::NOW - 40 * 86400;
    foreach ([1, 2, 3] as $mid) {
      $this->rows[$mid] = $this->row($mid, $old, 10 + $mid);
      $this->report(10 + $mid, [$mid], FALSE, 4);
    }
    $this->rows[4] = $this->row(4, $old, NULL);
    $store = $this->store();

    $this->assertSame(0, $store->purgeExpired(3));
    $this->assertSame(1, $store->purgeExpired(3), 'The second run starts behind the kept ones.');
    $this->assertArrayNotHasKey(4, $this->rows);
    $this->assertSame(0, $this->state[OriginalImageStore::PURGE_CURSOR], 'A short page starts the next cycle over.');
  }

  /**
   * Switching the site off deletes every kept original.
   */
  public function testPurgeDeletesEverythingOnceSwitchedOff(): void {
    $this->rows[1] = $this->row(1, self::NOW, 11);
    $this->report(11, [1], TRUE, 4);
    $this->enabled = FALSE;
    $store = $this->store();

    $this->assertSame(1, $store->purgeExpired());
    $this->assertSame([], $this->rows);
  }

  /**
   * Inside an entity delete, the file goes only once the delete committed.
   */
  public function testFileIsDeletedOnlyAfterCommit(): void {
    $this->rows[1] = $this->row(1, self::NOW, 11);
    $this->rows[2] = $this->row(2, self::NOW, 12);
    $this->inTransaction = TRUE;
    $store = $this->store();
    $this->fileSystem->expects($this->once())->method('delete')->with(OriginalImageStore::DIRECTORY . '/uuid-1');

    $store->delete(1);
    $store->delete(2);
    ($this->afterCommit[0])(TRUE);
    ($this->afterCommit[1])(FALSE);
  }

  /**
   * An upload nobody submitted within a day can no longer be claimed.
   */
  public function testAbandonedUploadCannotBeClaimedLater(): void {
    $this->rows[7] = $this->row(7, self::NOW - 2 * 86400, NULL);
    $late = $this->report(5, [7], TRUE);
    $store = $this->store();

    $store->bind($late);

    $this->assertNull($this->rows[7]['nid']);
  }

  /**
   * With the feature off, saving a report binds nothing.
   */
  public function testBindDoesNothingWhenSwitchedOff(): void {
    $this->rows[7] = $this->row(7, self::NOW, NULL);
    $report = $this->report(1, [7], TRUE);
    $this->enabled = FALSE;
    $store = $this->store();

    $store->bind($report);

    $this->assertNull($this->rows[7]['nid']);
  }

  /**
   * A split-off report still showing the photo takes the original over.
   */
  public function testDeletedReportHandsTheOriginalToReportStillShowingIt(): void {
    $this->rows[7] = $this->row(7, self::NOW, 1);
    $this->rows[8] = $this->row(8, self::NOW, 1);
    // Report 1 was deleted; report 2, split off it, still shows photo 7.
    $this->report(2, [7], TRUE);
    $store = $this->store();
    $this->fileSystem->expects($this->once())->method('delete')->with(OriginalImageStore::DIRECTORY . '/uuid-8');

    $store->releaseOwnedBy(1);

    $this->assertSame(2, $this->rows[7]['nid']);
    $this->assertArrayNotHasKey(8, $this->rows);
  }

  /**
   * A broken private file system never makes the purge strand files.
   */
  public function testPurgeLeavesEverythingWithoutPrivateFileSystem(): void {
    $this->rows[1] = $this->row(1, self::NOW - 40 * 86400, NULL);
    $this->privateOk = FALSE;
    $store = $this->store();

    $this->assertSame(0, $store->purgeExpired());
    $this->assertArrayHasKey(1, $this->rows);
  }

}
