<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the unblurred original of a blurred photo for the staff handling it.
 *
 * Opt-in per site (retain_originals). The original lives in the private file
 * system, one file per media named by its UUID, mapped in a table of its own
 * so JSON:API and GeoReport never enumerate it. Each original belongs to one
 * report, the first that showed the photo; only accounts that may edit that
 * report can open it (external contractors never), and every view is logged.
 * Attaching someone else's photo to another report grants nothing. An
 * original goes 30 days after its report was closed, with its media or
 * report, 30 days after upload when no report ever used it, and entirely
 * once the site switches the feature off.
 */
class OriginalImageStore {

  /**
   * Private directory of the originals.
   */
  public const DIRECTORY = 'private://markaspot_vision/originals';

  /**
   * Table mapping media to their original.
   */
  public const TABLE = 'markaspot_vision_original';

  /**
   * Days an original outlives its closed report or its unused upload.
   */
  public const RETENTION_DAYS = 30;

  /**
   * State key of the purge cursor (last media id examined).
   */
  public const PURGE_CURSOR = 'markaspot_vision.original_purge_cursor';

  /**
   * Seconds after upload within which a report can take on an original.
   */
  public const CLAIM_WINDOW = 86400;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Whether the table exists, once asked.
   */
  protected ?bool $tableExists = NULL;

  /**
   * Constructs the store.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected TimeInterface $time,
    protected StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
    protected ?StatusClassifier $statusClassifier = NULL,
  ) {
    $this->logger = $loggerFactory->get('markaspot_vision');
  }

  /**
   * Whether the site keeps originals and has a private file system for them.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('markaspot_vision.settings')->get('retain_originals')
      && $this->streamWrapperManager->isValidScheme('private')
      && $this->tableExists();
  }

  /**
   * Whether the table exists; false between deploy and the database update.
   */
  public function tableExists(): bool {
    return $this->tableExists ??= $this->database->schema()->tableExists(self::TABLE);
  }

  /**
   * Keeps the current file of a media before the blur overwrites it.
   *
   * The table row is the lock: only the first analysis keeps an original,
   * and it always reads the file before its own blur overwrites it. Failures
   * are logged and never stop the blur; privacy of the published photo
   * comes first.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media about to be blurred.
   * @param string $uri
   *   Its current (still unblurred) file URI.
   */
  public function retain(MediaInterface $media, string $uri): void {
    if (!$this->isEnabled()) {
      return;
    }
    $mid = (int) $media->id();
    try {
      $contents = @file_get_contents($uri);
      if ($contents === FALSE || $contents === '') {
        throw new \RuntimeException('The original file is unreadable.');
      }
      $target = self::DIRECTORY . '/' . $media->uuid();
      try {
        $this->database->insert(self::TABLE)
          ->fields([
            'mid' => $mid,
            'uri' => $target,
            'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream',
            'created' => $this->time->getRequestTime(),
            'nid' => $this->firstReportUsing($mid),
          ])
          ->execute();
      }
      catch (IntegrityConstraintViolationException $e) {
        // Kept by an earlier analysis; that copy is the unblurred one.
        return;
      }
      try {
        $directory = self::DIRECTORY;
        $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        $this->fileSystem->saveData($contents, $target, FileExists::Replace);
      }
      catch (\Throwable $e) {
        $this->database->delete(self::TABLE)->condition('mid', $mid)->execute();
        throw $e;
      }
      $this->logger->notice('GDPR audit: unblurred original of media @id kept for staff before blurring.', ['@id' => $mid]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not keep the original of media @id; the blur goes ahead without it: @message', [
        '@id' => $mid,
        '@message' => mb_substr($e->getMessage(), 0, 200),
      ]);
    }
  }

  /**
   * Binds the originals of a report's photos to it, if not bound yet.
   *
   * Called when a report is saved: the first report that shows a photo owns
   * its original. Later reports showing the same photo gain nothing, and an
   * upload nobody submitted within a day can no longer be claimed at all.
   */
  public function bind(NodeInterface $node): void {
    if ($node->bundle() !== 'service_request' || !$node->hasField('field_request_media') || !$this->isEnabled()) {
      return;
    }
    $mids = array_filter(array_map(
      static fn ($item): int => (int) $item->target_id,
      iterator_to_array($node->get('field_request_media')),
    ));
    if (!$mids) {
      return;
    }
    $this->database->update(self::TABLE)
      ->fields(['nid' => (int) $node->id()])
      ->condition('mid', $mids, 'IN')
      ->isNull('nid')
      ->condition('created', $this->time->getRequestTime() - self::CLAIM_WINDOW, '>')
      ->execute();
  }

  /**
   * Returns the stored original of a media.
   *
   * @return array|null
   *   ['uri' => string, 'mime' => string, 'nid' => int|null], or NULL.
   */
  public function find(int $mid): ?array {
    if (!$this->tableExists()) {
      return NULL;
    }
    $row = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['uri', 'mime', 'nid'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['nid'] = $row['nid'] === NULL ? NULL : (int) $row['nid'];
    return $row;
  }

  /**
   * Returns the media id and MIME type an original URI belongs to.
   *
   * @return array|null
   *   ['mid' => int, 'mime' => string], or NULL for any other URI.
   */
  public function findByUri(string $uri): ?array {
    if (!str_starts_with($uri, self::DIRECTORY . '/') || !$this->tableExists()) {
      return NULL;
    }
    $row = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['mid', 'mime'])
      ->condition('uri', $uri)
      ->execute()
      ->fetchAssoc();
    return $row ? ['mid' => (int) $row['mid'], 'mime' => $row['mime']] : NULL;
  }

  /**
   * Whether an account may see the original of a media.
   *
   * Only accounts that may edit the report owning the original, and never
   * external contractors: the original shows third parties unblurred.
   */
  public function canView(MediaInterface $media, AccountInterface $account): bool {
    if ($account->isAnonymous() || $this->isRestrictedContractor($account)) {
      return FALSE;
    }
    $original = $this->find((int) $media->id());
    if (!$original || $original['nid'] === NULL) {
      return FALSE;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($original['nid']);
    return $node instanceof NodeInterface && $node->access('update', $account);
  }

  /**
   * Logs that an account opened an original.
   */
  public function logView(int $mid, AccountInterface $account): void {
    $this->logger->notice('GDPR audit: unblurred original of media @id viewed by user @uid.', [
      '@id' => $mid,
      '@uid' => $account->id(),
    ]);
  }

  /**
   * Deletes the original of a media, mapping now and file once committed.
   */
  public function delete(int $mid): void {
    $original = $this->find($mid);
    if (!$original) {
      return;
    }
    $this->database->delete(self::TABLE)->condition('mid', $mid)->execute();
    $unlink = function (bool $committed = TRUE) use ($original): void {
      if (!$committed) {
        return;
      }
      try {
        $this->fileSystem->delete($original['uri']);
      }
      catch (\Throwable $e) {
        // Nothing is left to serve it once the mapping is gone.
      }
    };
    // Inside an entity delete, the file goes only if the delete commits.
    $transactions = $this->database->transactionManager();
    if ($transactions->inTransaction()) {
      $transactions->addPostTransactionCallback($unlink);
    }
    else {
      $unlink();
    }
    $this->logger->notice('GDPR audit: unblurred original of media @id deleted.', ['@id' => $mid]);
  }

  /**
   * Hands the originals of a deleted report on, or deletes them.
   *
   * A report split off the deleted one may still show the photo; it takes
   * the original over. Without such a report the original goes.
   */
  public function releaseOwnedBy(int $nid): void {
    if (!$this->tableExists()) {
      return;
    }
    $mids = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['mid'])
      ->condition('nid', $nid)
      ->execute()
      ->fetchCol();
    foreach ($mids as $mid) {
      $next = $this->firstReportUsing((int) $mid);
      if ($next !== NULL && $next !== $nid) {
        $this->database->update(self::TABLE)
          ->fields(['nid' => $next])
          ->condition('mid', (int) $mid)
          ->execute();
        continue;
      }
      $this->delete((int) $mid);
    }
  }

  /**
   * Deletes originals whose retention ended.
   *
   * Walks the table in media id order with a cursor kept in state, so
   * originals of reports still open never block the ones behind them.
   * With the site switch off, nothing may be kept and all go.
   *
   * @param int $limit
   *   Maximum number of originals examined per run.
   *
   * @return int
   *   Number of originals deleted.
   */
  public function purgeExpired(int $limit = 200): int {
    // Without a working private file system nothing can be deleted cleanly;
    // wiping the mapping alone would strand the files.
    if (!$this->tableExists() || !$this->streamWrapperManager->isValidScheme('private')) {
      return 0;
    }
    $enabled = (bool) $this->configFactory->get('markaspot_vision.settings')->get('retain_originals');
    $cutoff = $this->time->getRequestTime() - self::RETENTION_DAYS * 86400;
    $cursor = (int) $this->state->get(self::PURGE_CURSOR, 0);
    $query = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['mid', 'nid'])
      ->condition('mid', $cursor, '>')
      ->orderBy('mid')
      ->range(0, $limit);
    if ($enabled) {
      $query->condition('created', $cutoff, '<');
    }
    $rows = $query->execute()->fetchAllKeyed();

    $deleted = 0;
    $node_storage = $this->entityTypeManager->getStorage('node');
    foreach ($rows as $mid => $nid) {
      if ($enabled && $nid !== NULL) {
        $node = $node_storage->load((int) $nid);
        if ($node instanceof NodeInterface && !$this->closedBefore($node, $cutoff)) {
          continue;
        }
      }
      // Switched off, never used by a report, report gone or closed long
      // enough: the original goes.
      $this->delete((int) $mid);
      $deleted++;
    }
    $this->state->set(self::PURGE_CURSOR, count($rows) < $limit ? 0 : (int) array_key_last($rows));
    return $deleted;
  }

  /**
   * Whether an account is an external contractor without an editorial role.
   */
  protected function isRestrictedContractor(AccountInterface $account): bool {
    return function_exists('_markaspot_group_account_has_restricted_contractor_access')
      && _markaspot_group_account_has_restricted_contractor_access($account);
  }

  /**
   * The first service request showing a media, if any.
   */
  protected function firstReportUsing(int $mid): ?int {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_request_media', $mid)
      ->sort('nid')
      ->range(0, 1)
      ->execute();
    return $nids ? (int) reset($nids) : NULL;
  }

  /**
   * Whether a report is closed and was closed before a timestamp.
   *
   * The closing time is the newest status note carrying a closed status;
   * without such a note the report's last change stands in for it.
   */
  protected function closedBefore(NodeInterface $node, int $cutoff): bool {
    if (!$this->statusClassifier || !$node->hasField('field_status') || $node->get('field_status')->isEmpty()) {
      return FALSE;
    }
    $closed = $this->statusClassifier->closedTids();
    if (!in_array((int) $node->get('field_status')->target_id, $closed, TRUE)) {
      return FALSE;
    }
    $closed_at = 0;
    if ($node->hasField('field_status_notes')) {
      foreach ($node->get('field_status_notes')->referencedEntities() as $note) {
        if ($note->hasField('field_status_term')
          && in_array((int) $note->get('field_status_term')->target_id, $closed, TRUE)
          && method_exists($note, 'getCreatedTime')) {
          $closed_at = max($closed_at, (int) $note->getCreatedTime());
        }
      }
    }
    if ($closed_at === 0 && method_exists($node, 'getChangedTime')) {
      $closed_at = (int) $node->getChangedTime();
    }
    return $closed_at > 0 && $closed_at < $cutoff;
  }

}
