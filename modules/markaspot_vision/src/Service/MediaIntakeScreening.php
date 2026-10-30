<?php

declare(strict_types=1);

namespace Drupal\markaspot_vision\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_ai\Utility\BlurPolicy;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Durable privacy screening and optional enrichment of imported images.
 */
class MediaIntakeScreening {

  public const QUEUE = 'markaspot_media_intake';
  public const STORE = 'markaspot_vision.media_intake';
  public const MAX_ATTEMPTS = 5;
  private const FAILURE_MESSAGE = 'Imported image privacy screening failed; awaiting retry or staff review.';

  /**
   * Constructs the intake screening service.
   */
  public function __construct(
    protected QueueFactory $queueFactory,
    protected KeyValueFactoryInterface $keyValue,
    protected LockBackendInterface $lock,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected ImageProcessingService $images,
    protected OriginalImageStore $originals,
    protected MediaTenantScopeResolver $tenantScope,
    protected FeatureScopeResolver $features,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ?TierConfigService $tierConfig = NULL,
    protected ?TokenTrackingService $tokenTracking = NULL,
  ) {}

  /**
   * Marks an imported image pending before its report is saved.
   */
  public function register(MediaInterface $media): void {
    $this->keyValue->get(self::STORE)->setIfNotExists($media->id(), [
      'status' => 'pending', 'attempts' => 0, 'queued' => FALSE,
      'created' => $this->time->getCurrentTime(),
    ]);
  }

  /**
   * Enqueues only after association. Failed queue inserts remain recoverable.
   */
  public function enqueue(MediaInterface $media): void {
    $this->register($media);
    $store = $this->keyValue->get(self::STORE);
    $lock = self::QUEUE . ':dispatch:' . $media->id();
    if (!$this->lock->acquire($lock, 900)) {
      return;
    }
    try {
      $entry = $store->get($media->id());
      if (!empty($entry['queued']) || !in_array($entry['status'], ['pending', 'orphaned'], TRUE)) {
        return;
      }
      if (!$this->reports((int) $media->id())) {
        $entry['status'] = 'orphaned';
        $store->set($media->id(), $entry);
        return;
      }
      if (!$this->queueFactory->get(self::QUEUE)->createItem(['media_id' => (int) $media->id()])) {
        throw new \RuntimeException('Queue backend refused the imported image.');
      }
      // A crash between insertion and this flag can create a duplicate during
      // recovery, but processing is idempotent and no image is lost.
      $entry['status'] = 'pending';
      $entry['queued'] = TRUE;
      $store->set($media->id(), $entry);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('markaspot_vision')->error('Imported media @mid queue insertion failed (@type); cron will recover it.', [
        '@mid' => $media->id(),
        '@type' => get_class($e),
      ]);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Queues registered attachments after the report has been saved.
   */
  public function enqueueReport(NodeInterface $node, bool $register = FALSE): void {
    if ($node->hasField('field_request_media')) {
      foreach ($node->get('field_request_media')->referencedEntities() as $media) {
        if ($media instanceof MediaInterface && ($register || $this->keyValue->get(self::STORE)->has($media->id()))) {
          $this->enqueue($media);
        }
      }
    }
  }

  /**
   * Recovers queue insert failures without depending on the original POST.
   */
  public function recoverPending(): void {
    foreach ($this->keyValue->get(self::STORE)->getAll() as $mid => $entry) {
      if (in_array($entry['status'], ['pending', 'orphaned'], TRUE) && empty($entry['queued'])) {
        $media = $this->entityTypeManager->getStorage('media')->load($mid);
        if ($media instanceof MediaInterface) {
          $this->enqueue($media);
        }
      }
    }
  }

  /**
   * Processes one media item under a lock, checkpointing before side effects.
   */
  public function process(int $mid): void {
    $lock = self::QUEUE . ':' . $mid;
    if (!$this->lock->acquire($lock, 900)) {
      throw new DelayedRequeueException(60, 'Media intake screening is already running.');
    }
    try {
      $this->processLocked($mid);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Resumes privacy work, then attempts optional AI at most once.
   */
  private function processLocked(int $mid): void {
    $store = $this->keyValue->get(self::STORE);
    $entry = $store->get($mid);
    if (!$entry || in_array($entry['status'], ['failed', 'resolved'], TRUE)) {
      return;
    }
    if ($this->keyValue->get('markaspot_open311.media_publication_manual')->get($mid)) {
      $store->delete($mid);
      return;
    }
    if ($entry['status'] === 'done') {
      foreach ($this->reports($mid) as $node) {
        _markaspot_vision_publish_safe_media($node);
      }
      return;
    }
    $remaining = ($entry['next_attempt'] ?? 0) - $this->time->getCurrentTime();
    if ($remaining > 0) {
      throw new DelayedRequeueException($remaining);
    }
    $media = $this->entityTypeManager->getStorage('media')->loadUnchanged($mid);
    if (!$media instanceof MediaInterface) {
      $store->delete($mid);
      return;
    }
    $nodes = $this->reports($mid);
    if (!$nodes) {
      $entry['status'] = 'orphaned';
      $entry['queued'] = FALSE;
      $store->set($mid, $entry);
      return;
    }
    $required = BlurPolicy::isRequired($this->loggerFactory->get('markaspot_vision'));
    $entry['attempts']++;
    $store->set($mid, $entry);
    try {
      $file = $media->get('field_media_image')->entity;
      $uri = $file?->getFileUri();
      if (!$uri) {
        throw new \RuntimeException('Imported image has no source file.');
      }
      $hash = hash_file('sha256', $uri);
      if ($hash === FALSE) {
        throw new \RuntimeException('Imported image is unreadable.');
      }
      // Only an explicit marker for these exact bytes can replace our blur.
      $metadata = json_decode((string) $media->get('field_ai_metadata')->value, TRUE) ?: [];
      $screening = $metadata['intake_screening'] ?? [];
      if (ScreeningMarker::accepts($screening, $hash, $required)) {
        $entry['privacy_screened'] = TRUE;
        $entry['processed'] = TRUE;
        $entry['blur_required'] = $screening['blur_required'] ?? FALSE;
        if (($screening['origin'] ?? '') === 'vision') {
          $entry['analysis'] ??= 'existing';
        }
        $entry['prepared_hash'] = $hash;
        $entry['blurred'] = !empty($screening['blurred']);
        $store->set($mid, $entry);
      }
      $needs_required_blur = $required && (empty($entry['processed']) || empty($entry['blur_required']));
      if (empty($entry['privacy_screened']) || ($entry['prepared_hash'] ?? '') !== $hash || $needs_required_blur) {
        // Journal the output hash BEFORE overwrite. If PHP stops after the
        // write, retry recognises those bytes without applying blur twice.
        if (($entry['prepared_hash'] ?? '') !== $hash || $needs_required_blur) {
          $entry['privacy_screened'] = FALSE;
          $store->set($mid, $entry);
          $contents = file_get_contents($uri);
          if ($contents === FALSE) {
            throw new \RuntimeException('Imported image is unreadable.');
          }
          $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'image/jpeg';
          $blur = $this->images->blurSensitiveAreas($contents, $mime);
          $entry['prepared_hash'] = hash('sha256', !empty($blur['blurred']) ? $blur['contents'] : $contents);
          $entry['blurred'] = !empty($blur['blurred']);
          $entry['processed'] = ($blur['processed'] ?? FALSE) === TRUE;
          $entry['blur_required'] = $required;
          if ($required && !$entry['processed']) {
            throw new \RuntimeException('Required blur did not complete.');
          }
          $store->set($mid, $entry);
          $this->images->persistBlurResult($media, $blur, $uri, $this->originals);
        }
        elseif (!empty($entry['blurred'])) {
          // A stopped process may have written the file but not invalidated
          // its old, unblurred derivatives. Finish without retaining or
          // transforming the already blurred bytes again.
          $this->images->finalizeBlurOverwrite($media, $uri);
        }
        $entry['privacy_screened'] = ($entry['processed'] ?? FALSE) === TRUE;
        $store->set($mid, $entry);
      }
    }
    catch (DelayedRequeueException $e) {
      $entry['attempts']--;
      $store->set($mid, $entry);
      throw $e;
    }
    catch (\Throwable $e) {
      if ($this->keyValue->get('markaspot_open311.media_publication_manual')->get($mid)) {
        $store->delete($mid);
        return;
      }
      $entry['status'] = $entry['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
      $delay = min(3600, 60 * (2 ** ($entry['attempts'] - 1)));
      $entry['next_attempt'] = $this->time->getCurrentTime() + $delay;
      $store->set($mid, $entry);
      // Staff see the hold through the existing privacy-issues field. Avoid
      // storing provider errors, file paths or citizen data in that field.
      $this->updateMedia($mid, function (MediaInterface $fresh) use ($store, $mid): void {
        if ($this->keyValue->get('markaspot_open311.media_publication_manual')->get($mid)) {
          $store->delete($mid);
          return;
        }
        if (!$fresh->get('field_ai_privacy_issues')->value) {
          $fresh->set('field_ai_privacy_issues', self::FAILURE_MESSAGE);
        }
        if (!$this->keyValue->get('markaspot_open311.media_publication_manual')->get($fresh->id())) {
          $fresh->setUnpublished();
        }
      });
      $this->loggerFactory->get('markaspot_vision')->error('Imported media @mid screening @status (attempt @attempt, @type).', [
        '@mid' => $mid, '@status' => $entry['status'], '@attempt' => $entry['attempts'], '@type' => get_class($e),
      ]);
      if ($entry['status'] !== 'failed') {
        throw new DelayedRequeueException($delay, 'Imported image privacy screening will retry.');
      }
      return;
    }

    if ($this->keyValue->get('markaspot_open311.media_publication_manual')->get($mid)) {
      $store->delete($mid);
      return;
    }
    if (($entry['analysis'] ?? '') === 'started') {
      $entry['analysis'] = 'interrupted';
      $this->loggerFactory->get('markaspot_vision')->warning('Imported media @mid AI enrichment was interrupted; not repeating an uncertain provider call.', ['@mid' => $mid]);
    }
    if (!isset($entry['analysis'])) {
      // Persist BEFORE calling the model or booking usage. An interrupted
      // analysis is never called or charged again by a retried queue item.
      $entry['analysis'] = 'started';
      $store->set($mid, $entry);
      try {
        $entry['analysis'] = $this->analyse($media, $nodes, $uri, $entry);
      }
      catch (DelayedRequeueException $e) {
        unset($entry['analysis']);
        $entry['attempts']--;
        $store->set($mid, $entry);
        throw $e;
      }
      catch (\Throwable $e) {
        $entry['analysis'] = 'failed';
        $this->loggerFactory->get('markaspot_vision')->warning('Imported media @mid AI enrichment failed (@type); privacy screening remains valid.', [
          '@mid' => $mid,
          '@type' => get_class($e),
        ]);
      }
      $store->set($mid, $entry);
    }
    try {
      $this->updateMedia($mid, function (MediaInterface $fresh) use (&$entry, $store, $mid): void {
        if ($this->keyValue->get('markaspot_open311.media_publication_manual')->get($mid)) {
          $store->delete($mid);
          return;
        }
        $metadata = json_decode((string) $fresh->get('field_ai_metadata')->value, TRUE) ?: [];
        $metadata['intake_screening'] = [
          'privacy_screened' => $entry['privacy_screened'],
          'processed' => $entry['processed'] ?? FALSE,
          'blur_required' => $entry['blur_required'] ?? FALSE,
          'origin' => 'intake',
          'blurred' => $entry['blurred'] ?? FALSE,
          'source_sha256' => $entry['prepared_hash'],
          'analysis' => $entry['analysis'],
          'status' => 'done',
        ];
        if ($fresh->get('field_ai_privacy_issues')->value === self::FAILURE_MESSAGE) {
          $fresh->set('field_ai_privacy_issues', '');
        }
        if (isset($entry['analysis_result'])) {
          $decoded = $entry['analysis_result'];
          $metadata['intake_suggestions'] = $decoded;
          $issues = $decoded['privacy_issues'] ?? [];
          $issues = is_array($issues) ? $issues : [$issues];
          $issues = array_filter($issues, static fn($issue) => is_string($issue) ? trim($issue) !== '' : !empty($issue));
          if ($decoded['privacy_flag'] || $issues) {
            $fresh->set('field_ai_privacy_flag', TRUE);
            $existing = trim((string) $fresh->get('field_ai_privacy_issues')->value);
            $fresh->set('field_ai_privacy_issues', implode(', ', array_filter([$existing, ...$issues])));
          }
        }
        $fresh->set('field_ai_metadata', json_encode($metadata, JSON_THROW_ON_ERROR));
      }, function () use (&$entry, $store, $mid): void {
        if ($this->keyValue->get('markaspot_open311.media_publication_manual')->get($mid)) {
          $store->delete($mid);
          return;
        }
        $entry['status'] = 'done';
        unset($entry['analysis_result']);
        $store->set($mid, $entry);
      });
    }
    catch (DelayedRequeueException $e) {
      $entry['attempts']--;
      $store->set($mid, $entry);
      throw $e;
    }
    foreach ($this->reports($mid) as $node) {
      _markaspot_vision_publish_safe_media($node);
    }

  }

  /**
   * Loads the current reports referencing a media item, without access filters.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Reports, including unpublished reports.
   */
  private function reports(int $mid): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'service_request')->condition('field_request_media', $mid)->execute();
    $storage->resetCache($ids);
    return $storage->loadMultiple($ids);
  }

  /**
   * Enriches media, keeping all model output away from citizen text fields.
   */
  private function analyse(MediaInterface $media, array $nodes, string $uri, array &$entry): string {
    $mode = $this->configFactory->get('markaspot_vision.settings')->get('imported_image_analysis') ?? 'without_description';
    if ($mode === 'off') {
      return 'disabled';
    }
    foreach ($nodes as $node) {
      if ($mode !== 'always' && $node->hasField('body') && trim((string) $node->get('body')->value) !== '') {
        return 'description_present';
      }
    }
    $scope = $this->tenantScope->resolve([$media], NULL, $this->tierConfig !== NULL);
    if (!$scope->isAllowed()) {
      return 'scope_refused';
    }
    $group = $scope->jurisdiction;
    if (!$group instanceof GroupInterface) {
      return 'scope_refused';
    }
    if (!$this->features->isEnabledEffective('aiAnalysis', $group, TRUE)) {
      return 'tenant_disabled';
    }
    $jid = $group instanceof GroupInterface ? (int) $group->id() : NULL;
    if ($this->tokenTracking && !$this->tokenTracking->checkLimit()) {
      return 'budget_exhausted';
    }
    if ($this->tierConfig) {
      if (!$group instanceof GroupInterface || !$group->hasField('field_tier')) {
        return 'scope_refused';
      }
      $tier = $group->get('field_tier')->value ?: 'free';
      $budgetLock = self::QUEUE . ':budget:' . $jid;
      if (!$this->lock->acquire($budgetLock, 30)) {
        $this->lock->wait($budgetLock, 1);
        if (!$this->lock->acquire($budgetLock, 30)) {
          throw new DelayedRequeueException(30, 'AI budget reservation is busy.');
        }
      }
      try {
        if ($this->tierConfig->isAIBudgetExhausted($jid, $tier)) {
          return 'budget_exhausted';
        }
        // Reserve atomically across this tenant's concurrent media jobs.
        $this->tierConfig->recordAIAnalysis($jid);
      }
      finally {
        $this->lock->release($budgetLock);
      }
    }
    $node = reset($nodes);
    $result = $this->images->processScreenedImages([$uri => [
      'privacy_screened' => $entry['privacy_screened'],
      'processed' => $entry['processed'] ?? FALSE,
      'blur_required' => $entry['blur_required'] ?? FALSE,
      'source_sha256' => $entry['prepared_hash'],
      'blurred' => $entry['blurred'] ?? FALSE,
    ]], $node->language()->getId(), $jid, NULL, (int) $media->id());
    $decoded = json_decode((string) ($result['ai_result'] ?? ''), TRUE);
    if (!is_array($decoded) || !is_bool($decoded['privacy_flag'] ?? NULL)) {
      return 'failed';
    }
    unset($decoded['observation']);
    $entry['analysis_result'] = $decoded;
    return 'done';
  }

  /**
   * Merges changes into current media under the editorial publication lock.
   */
  private function updateMedia(int $mid, callable $update, ?callable $afterSave = NULL): void {
    $lock = 'markaspot_open311.media_publication.' . $mid;
    if (!$this->lock->acquire($lock, 30)) {
      $this->lock->wait($lock, 1);
      if (!$this->lock->acquire($lock, 30)) {
        throw new DelayedRequeueException(30, 'Media publication is being edited.');
      }
    }
    try {
      $fresh = $this->entityTypeManager->getStorage('media')->loadUnchanged($mid);
      if ($fresh instanceof MediaInterface) {
        $update($fresh);
        $fresh->save();
        if ($afterSave) {
          $afterSave();
        }
      }
    }
    finally {
      $this->lock->release($lock);
    }
  }

}
