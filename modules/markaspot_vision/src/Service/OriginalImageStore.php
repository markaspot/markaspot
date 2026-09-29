<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
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
 * so JSON:API and GeoReport never enumerate it. Only accounts that may edit
 * a report showing the photo can open it, and every view is logged. An
 * original goes 30 days after its report was closed, with its media or
 * report, or 30 days after upload when no report ever used it.
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
   * The logger channel.
   */
  protected LoggerInterface $logger;

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
      && $this->streamWrapperManager->isValidScheme('private');
  }

  /**
   * Keeps the current file of a media before the blur overwrites it.
   *
   * The first original wins: a later analysis of an already blurred photo
   * must not replace it with the blurred bytes. Failures are logged and never
   * stop the blur; privacy of the published photo comes first.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media about to be blurred.
   * @param string $uri
   *   Its current (still unblurred) file URI.
   */
  public function retain(MediaInterface $media, string $uri): void {
    if (!$this->isEnabled() || $this->find((int) $media->id())) {
      return;
    }
    try {
      $contents = @file_get_contents($uri);
      if ($contents === FALSE || $contents === '') {
        throw new \RuntimeException('The original file is unreadable.');
      }
      $directory = self::DIRECTORY;
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $target = self::DIRECTORY . '/' . $media->uuid();
      $this->fileSystem->saveData($contents, $target, FileExists::Replace);
      $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
      $this->database->merge(self::TABLE)
        ->key('mid', (int) $media->id())
        ->fields([
          'uri' => $target,
          'mime' => $mime,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      $this->logger->notice('GDPR audit: unblurred original of media @id kept for staff before blurring.', ['@id' => $media->id()]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not keep the original of media @id; the blur goes ahead without it: @message', [
        '@id' => $media->id(),
        '@message' => mb_substr($e->getMessage(), 0, 200),
      ]);
    }
  }

  /**
   * Returns the stored original of a media.
   *
   * @return array|null
   *   ['uri' => string, 'mime' => string], or NULL.
   */
  public function find(int $mid): ?array {
    $row = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['uri', 'mime'])
      ->condition('mid', $mid)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Returns the media id and MIME type an original URI belongs to.
   *
   * @return array|null
   *   ['mid' => int, 'mime' => string], or NULL for any other URI.
   */
  public function findByUri(string $uri): ?array {
    if (!str_starts_with($uri, self::DIRECTORY . '/')) {
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
   * Whether an account may see the original: it may edit a report using it.
   */
  public function canView(MediaInterface $media, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }
    foreach ($this->reportsUsing((int) $media->id()) as $node) {
      if ($node->access('update', $account)) {
        return TRUE;
      }
    }
    return FALSE;
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
   * Deletes the original of a media, file and mapping.
   */
  public function delete(int $mid): void {
    $original = $this->find($mid);
    if (!$original) {
      return;
    }
    try {
      $this->fileSystem->delete($original['uri']);
    }
    catch (\Throwable $e) {
      // A file already gone must not keep the mapping alive.
    }
    $this->database->delete(self::TABLE)->condition('mid', $mid)->execute();
    $this->logger->notice('GDPR audit: unblurred original of media @id deleted.', ['@id' => $mid]);
  }

  /**
   * Whether any report still shows a media.
   */
  public function isUsed(int $mid): bool {
    return (bool) $this->reportsUsing($mid);
  }

  /**
   * Deletes originals whose retention ended.
   *
   * @param int $limit
   *   Maximum number of originals examined per run.
   *
   * @return int
   *   Number of originals deleted.
   */
  public function purgeExpired(int $limit = 200): int {
    $cutoff = $this->time->getRequestTime() - self::RETENTION_DAYS * 86400;
    $rows = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['mid', 'created'])
      ->condition('created', $cutoff, '<')
      ->orderBy('created')
      ->range(0, $limit)
      ->execute()
      ->fetchAllKeyed();
    $deleted = 0;
    foreach ($rows as $mid => $created) {
      $reports = $this->reportsUsing((int) $mid);
      // An upload no report ever used goes after the retention period; a
      // used one only once every report showing it is closed long enough.
      $expired = !$reports || array_reduce(
        $reports,
        fn (bool $carry, NodeInterface $node): bool => $carry && $this->closedBefore($node, $cutoff),
        TRUE,
      );
      if ($expired) {
        $this->delete((int) $mid);
        $deleted++;
      }
    }
    return $deleted;
  }

  /**
   * Service requests that show a media.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The reports.
   */
  protected function reportsUsing(int $mid): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_request_media', $mid)
      ->execute();
    return $nids ? $storage->loadMultiple($nids) : [];
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
