<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
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
 * Tests keeping, finding, showing and purging unblurred originals.
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
   * Media ids referenced per report: [nid => node mock].
   *
   * @var array
   */
  private array $reports = [];

  /**
   * Whether the site keeps originals.
   */
  private bool $enabled = TRUE;

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
   * Builds the store over in-memory rows and reports.
   */
  private function store(): OriginalImageStore {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn ($key) => $key === 'retain_originals' ? $this->enabled : NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturnCallback(fn () => $this->selectQuery());
    $database->method('merge')->willReturnCallback(function () {
      $merge = $this->createMock(Merge::class);
      $key = NULL;
      $merge->method('key')->willReturnCallback(function ($field, $value) use (&$key, $merge) {
        $key = $value;
        return $merge;
      });
      $merge->method('fields')->willReturnCallback(function (array $fields) use (&$key, $merge) {
        $this->rows[$key] = $fields + ['mid' => $key];
        return $merge;
      });
      return $merge;
    });
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
    $nodeQuery->method('accessCheck')->willReturnSelf();
    $nodeQuery->method('condition')->willReturnCallback(function ($field, $value) use (&$mid, $nodeQuery) {
      if ($field === 'field_request_media') {
        $mid = $value;
      }
      return $nodeQuery;
    });
    $nodeQuery->method('execute')->willReturnCallback(function () use (&$mid) {
      return array_keys(array_filter(
        $this->reports,
        fn (array $report) => in_array($mid, $report['media'], TRUE),
      ));
    });
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($nodeQuery);
    $nodeStorage->method('loadMultiple')->willReturnCallback(fn (array $nids) => array_map(
      fn ($nid) => $this->reports[$nid]['node'],
      array_combine($nids, $nids),
    ));
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $streamWrappers = $this->createMock(StreamWrapperManagerInterface::class);
    $streamWrappers->method('isValidScheme')->with('private')->willReturn(TRUE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    $this->logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);
    $statusClassifier = $this->createMock(StatusClassifier::class);
    $statusClassifier->method('closedTids')->willReturn([9]);

    return new OriginalImageStore($configFactory, $database, $entityTypeManager, $this->fileSystem, $streamWrappers, $time, $loggerFactory, $statusClassifier);
  }

  /**
   * A select over the in-memory rows, honouring the conditions used.
   */
  private function selectQuery(): SelectInterface {
    $select = $this->createMock(SelectInterface::class);
    $conditions = [];
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('condition')->willReturnCallback(function ($field, $value, $op = '=') use (&$conditions, $select) {
      $conditions[] = [$field, $value, $op];
      return $select;
    });
    $select->method('execute')->willReturnCallback(function () use (&$conditions) {
      $rows = array_filter($this->rows, function (array $row) use ($conditions) {
        foreach ($conditions as [$field, $value, $op]) {
          if ($op === '<' ? !($row[$field] < $value) : $row[$field] != $value) {
            return FALSE;
          }
        }
        return TRUE;
      });
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchAssoc')->willReturn($rows ? reset($rows) : FALSE);
      $statement->method('fetchAllKeyed')->willReturn(array_column($rows, 'created', 'mid'));
      return $statement;
    });
    return $select;
  }

  /**
   * A row of the originals table.
   */
  private function row(int $mid, int $created): array {
    return [
      'mid' => $mid,
      'uri' => OriginalImageStore::DIRECTORY . '/uuid-' . $mid,
      'mime' => 'image/jpeg',
      'created' => $created,
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
   * Registers a report showing media, with its edit access and status.
   */
  private function report(int $nid, array $media, bool $editable = FALSE, ?int $status = NULL, ?int $closedAt = NULL): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('access')->with('update')->willReturn($editable);
    $node->method('hasField')->willReturnCallback(fn ($field) => in_array($field, ['field_status', 'field_status_notes'], TRUE));
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
    $node->method('get')->willReturnCallback(fn ($field) => $field === 'field_status' ? $statusField : $notesField);
    $node->method('getChangedTime')->willReturn(self::NOW);
    $this->reports[$nid] = ['media' => $media, 'node' => $node];
  }

  /**
   * The unblurred file is kept once, before the blur overwrites it.
   */
  public function testRetainKeepsTheFirstOriginalOnly(): void {
    $store = $this->store();
    $uri = tempnam(sys_get_temp_dir(), 'original-');
    file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII='));
    try {
      $this->fileSystem->expects($this->once())->method('saveData')
        ->with($this->anything(), OriginalImageStore::DIRECTORY . '/uuid-7');

      $store->retain($this->media(7), $uri);
      // A later analysis of the already blurred file must not replace it.
      $store->retain($this->media(7), $uri);
    }
    finally {
      unlink($uri);
    }

    $this->assertSame(OriginalImageStore::DIRECTORY . '/uuid-7', $this->rows[7]['uri']);
    $this->assertSame('image/png', $this->rows[7]['mime']);
    $this->assertSame(self::NOW, $this->rows[7]['created']);
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
   * An unreadable original is logged and never stops the blur.
   */
  public function testRetainFailureIsLoggedNotThrown(): void {
    $store = $this->store();
    $this->logger->expects($this->once())->method('error');

    $store->retain($this->media(7), '/nonexistent/photo.jpg');

    $this->assertSame([], $this->rows);
  }

  /**
   * Only accounts that may edit a report showing the photo see the original.
   */
  public function testCanViewFollowsEditAccessOnTheReport(): void {
    $this->report(1, [7], TRUE);
    $this->report(2, [8], FALSE);
    $store = $this->store();
    $staff = $this->createMock(AccountInterface::class);
    $staff->method('isAnonymous')->willReturn(FALSE);
    $anonymous = $this->createMock(AccountInterface::class);
    $anonymous->method('isAnonymous')->willReturn(TRUE);

    $this->assertTrue($store->canView($this->media(7), $staff));
    $this->assertFalse($store->canView($this->media(8), $staff), 'No edit access on the report.');
    $this->assertFalse($store->canView($this->media(9), $staff), 'No report shows the photo.');
    $this->assertFalse($store->canView($this->media(7), $anonymous));
  }

  /**
   * Only URIs in the originals directory resolve to a media.
   */
  public function testFindByUriIgnoresOtherPrivateFiles(): void {
    $this->rows[7] = $this->row(7, self::NOW);
    $store = $this->store();

    $this->assertSame(['mid' => 7, 'mime' => 'image/jpeg'], $store->findByUri(OriginalImageStore::DIRECTORY . '/uuid-7'));
    $this->assertNull($store->findByUri('private://invoices/uuid-7'));
  }

  /**
   * Originals go 30 days after closing, or after an upload no report used.
   */
  public function testPurgeFollowsTheRetentionRules(): void {
    $old = self::NOW - 40 * 86400;
    foreach ([1, 2, 3, 4] as $mid) {
      $this->rows[$mid] = $this->row($mid, $old);
    }
    $this->rows[5] = $this->row(5, self::NOW);
    // 1: closed 35 days ago. 2: closed 10 days ago. 3: still open.
    // 4: no report ever used it. 5: kept today.
    $this->report(11, [1], FALSE, 9, self::NOW - 35 * 86400);
    $this->report(12, [2], FALSE, 9, self::NOW - 10 * 86400);
    $this->report(13, [3], FALSE, 4);
    $store = $this->store();

    $this->assertSame(2, $store->purgeExpired());
    $this->assertSame([2, 3, 5], array_keys($this->rows));
  }

}
