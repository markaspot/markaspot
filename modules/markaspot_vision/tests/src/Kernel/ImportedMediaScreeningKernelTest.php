<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_health\Plugin\HealthCheck\MediaIntakeScreeningCheck;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_vision\Plugin\QueueWorker\MediaIntakeScreeningWorker;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\markaspot_vision\Service\MediaTenantScope;
use Drupal\markaspot_vision\Service\MediaTenantScopeResolver;
use Drupal\markaspot_vision\Service\OriginalImageStore;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises imported media privacy, queue resumption and publication in Drupal.
 */
#[Group('markaspot_vision')]
#[RunTestsInSeparateProcesses]
final class ImportedMediaScreeningKernelTest extends KernelTestBase {

  use ImageFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'filter', 'text', 'file', 'image', 'node', 'media'];

  /**
   * The real persistence path with a synthetic blur/AI boundary.
   */
  private ImageProcessingService $images;

  /**
   * The queue's orchestration service.
   */
  private MediaIntakeScreening $screening;

  /**
   * Tenant feature policy double.
   */
  private FeatureScopeResolver $features;

  /**
   * SaaS analysis ledger double.
   */
  private TierConfigService $budget;

  /**
   * Token budget double.
   */
  private TokenTrackingService $tokens;

  /**
   * Whether the fictional installation has token budget remaining.
   */
  private bool $tokenBudgetAvailable = TRUE;

  /**
   * Clock advanced explicitly to test backoff.
   */
  private int $now = 10000;

  /**
   * Fictional transformed image bytes.
   */
  private string $screenedBytes;

  /**
   * Observed blur requests.
   */
  private int $blurCalls = 0;

  /**
   * Whether the synthetic blur boundary throws.
   */
  private bool $blurFails = FALSE;

  /**
   * Whether the fictional detector found a sensitive region.
   */
  private bool $blurDetected = TRUE;

  /**
   * Whether preprocessing actually ran.
   */
  private bool $processed = TRUE;

  /**
   * Simulates an editorial write during the external blur request.
   */
  private ?\Closure $duringBlur = NULL;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Keep real schema validation without enabling the entire Nuxt stack.
    $container->getDefinition('config.storage.schema')->setClass(IntakeSchemaStorage::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_vision.module';
    foreach (['user', 'file', 'node', 'media'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'file', 'image']);
    NodeType::create(['type' => 'service_request', 'name' => 'Fictional report'])->save();
    MediaType::create([
      'id' => 'request_image', 'label' => 'Fictional image', 'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    $this->createImageField('field_media_image', 'media', 'request_image');
    $this->field('node', 'service_request', 'field_request_media', 'entity_reference', ['target_type' => 'media']);
    $this->field('node', 'service_request', 'body', 'text_long');
    $this->field('media', 'request_image', 'field_ai_metadata', 'string_long');
    $this->field('media', 'request_image', 'field_ai_privacy_flag', 'boolean');
    $this->field('media', 'request_image', 'field_ai_privacy_issues', 'string_long');
    $this->config('markaspot_vision.settings')->set('require_ai_screening', TRUE)->save();

    $image = imagecreatetruecolor(2, 2);
    imagefill($image, 0, 0, imagecolorallocate($image, 42, 80, 120));
    ob_start();
    imagepng($image);
    $this->screenedBytes = (string) ob_get_clean();
    imagedestroy($image);

    $c = $this->container;
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturnCallback(fn() => $this->now);
    $this->images = $this->getMockBuilder(ImageProcessingService::class)
      ->setConstructorArgs([
        $c->get('http_client'), $c->get('config.factory'), $c->get('entity_type.manager'),
        $c->get('file_system'), $c->get('logger.factory'), $c->get('state'), $clock, $c->get('file.usage'),
      ])->onlyMethods(['blurSensitiveAreas', 'processScreenedImages'])->getMock();
    $this->images->method('blurSensitiveAreas')->willReturnCallback(function () {
      $this->blurCalls++;
      if ($this->duringBlur) {
        ($this->duringBlur)();
      }
      if ($this->blurFails) {
        throw new \RuntimeException('Fictional blur outage');
      }
      return ['contents' => $this->screenedBytes, 'blurred' => $this->blurDetected, 'processed' => $this->processed];
    });
    $scope = $this->createMock(MediaTenantScopeResolver::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(41);
    $group->method('hasField')->with('field_tier')->willReturn(TRUE);
    $tier = $this->createMock(FieldItemListInterface::class);
    $tier->method('__get')->with('value')->willReturn('professional');
    $group->method('get')->with('field_tier')->willReturn($tier);
    $scope->method('resolve')->willReturn(MediaTenantScope::resolved($group));
    $this->features = $this->createMock(FeatureScopeResolver::class);
    $this->budget = $this->createMock(TierConfigService::class);
    $this->tokens = $this->createMock(TokenTrackingService::class);
    $this->tokens->method('checkLimit')->willReturnCallback(fn() => $this->tokenBudgetAvailable);
    $this->screening = new MediaIntakeScreening(
      $c->get('queue'), $c->get('keyvalue'), $c->get('lock'), $c->get('entity_type.manager'),
      $c->get('config.factory'), $clock, $this->images, $this->createMock(OriginalImageStore::class),
      $scope, $this->features, $c->get('logger.factory'), $this->budget, $this->tokens,
    );
    $c->set('markaspot_vision.media_intake', $this->screening);
  }

  /**
   * Defines a small field for the fictional fixtures.
   */
  private function field(string $entity, string $bundle, string $name, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'entity_type' => $entity, 'field_name' => $name,
      'type' => $type, 'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entity, 'bundle' => $bundle,
      'field_name' => $name, 'label' => $name,
    ])->save();
  }

  /**
   * Creates an unscreened image and its report, then enqueues it.
   */
  private function fixture(bool $published = TRUE, string $description = ''): array {
    $uri = 'public://fictional-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'request_image', 'name' => 'Fictional photo', 'status' => 0,
      'field_media_image' => [
        'target_id' => $file->id(), 'alt' => 'Fictional scene',
      ],
    ]);
    $media->save();
    $this->screening->register($media);
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Fictional report',
      'status' => $published, 'body' => $description,
      'field_request_media' => $media->id(),
    ]);
    $node->save();
    $this->screening->enqueueReport($node);
    return [$media, $node, $uri];
  }

  /**
   * Real overwrite precedes publication; duplicate jobs do nothing.
   */
  public function testQueueOverwritesAndPublishesOnceWithoutTenantAi(): void {
    $this->features->method('isEnabledEffective')->with('aiAnalysis')->willReturn(FALSE);
    $this->images->expects($this->never())->method('processScreenedImages');
    $this->budget->expects($this->never())->method('recordAIAnalysis');
    [$media, $node, $uri] = $this->fixture();
    $media->set('field_ai_metadata', '{"description":"Old metadata without a source fingerprint"}')->save();
    _markaspot_vision_publish_safe_media($node);
    $this->assertFalse(Media::load($media->id())->isPublished());
    $this->screening->enqueue($media);
    $this->assertSame(1, $this->container->get('queue')->get(MediaIntakeScreening::QUEUE)->numberOfItems());
    $worker = MediaIntakeScreeningWorker::create($this->container, [], MediaIntakeScreening::QUEUE, []);
    $worker->processItem(['media_id' => (int) $media->id()]);
    $worker->processItem(['media_id' => (int) $media->id()]);
    $fresh = $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($media->id());
    $this->assertSame($this->screenedBytes, file_get_contents($uri));
    $this->assertSame(1, $this->blurCalls);
    $this->assertTrue($fresh->isPublished());
    $metadata = json_decode($fresh->get('field_ai_metadata')->value, TRUE);
    $this->assertTrue($metadata['intake_screening']['privacy_screened']);
    $this->assertSame('tenant_disabled', $metadata['intake_screening']['analysis']);
  }

  /**
   * Privacy-screened mail photos wait for report publication.
   */
  public function testUnpublishedReportAndManualPublication(): void {
    [$media, $node] = $this->fixture(FALSE);
    $this->screening->process((int) $media->id());
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $this->assertFalse($storage->loadUnchanged($media->id())->isPublished());
    $node = Node::load($node->id());
    $node->setPublished();
    $node->save();
    _markaspot_vision_publish_safe_media($node);
    $this->assertTrue($storage->loadUnchanged($media->id())->isPublished());
    $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual')->set($media->id(), TRUE);
    $node->setUnpublished();
    _markaspot_vision_publish_safe_media($node);
    $this->assertTrue($storage->loadUnchanged($media->id())->isPublished());
  }

  /**
   * Outages back off, stop after five attempts, and never release media.
   */
  public function testBlurOutageFailsClosedEvenInLegacyMode(): void {
    $this->blurFails = TRUE;
    $this->config('markaspot_vision.settings')->set('require_ai_screening', FALSE)->save();
    $this->images->expects($this->never())->method('processScreenedImages');
    [$media, $node] = $this->fixture();
    $mid = (int) $media->id();
    $store = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE);
    foreach ([60, 120, 240, 480, 960] as $attempt => $delay) {
      try {
        $this->screening->process($mid);
        $this->assertSame(4, $attempt, 'Only the terminal attempt drops the job.');
      }
      catch (DelayedRequeueException $e) {
        $this->assertSame($delay, $e->getDelay());
      }
      _markaspot_vision_publish_safe_media(Node::load($node->id()));
      $fresh = $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($mid);
      $this->assertFalse($fresh->isPublished());
      $this->assertEmpty($fresh->get('field_ai_metadata')->value);
      $this->assertNotEmpty($fresh->get('field_ai_privacy_issues')->value);
      $this->now += $delay;
    }
    $this->assertSame('failed', $store->get($mid)['status']);
    $this->screening->process($mid);
    $this->assertSame(5, $this->blurCalls);
    $health = new MediaIntakeScreeningCheck([], 'media_intake_screening', ['label' => 'Intake'], $this->container->get('keyvalue'), $this->container->get('datetime.time'));
    $this->assertFalse($health->run()->passed);
    $this->assertSame(1, $health->run()->count);
  }

  /**
   * A failed attempt can recover without bypassing its retry timestamp.
   */
  public function testRetryAndInterruptedOverwriteDoNotBlurTwice(): void {
    [$media, , $uri] = $this->fixture();
    $mid = (int) $media->id();
    $store = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE);
    // Simulate termination between the shared overwrite and its checkpoint.
    file_put_contents($uri, $this->screenedBytes);
    $style = ImageStyle::create(['name' => 'fictional_preview', 'label' => 'Fictional preview']);
    $style->save();
    $derivative = $style->buildUri($uri);
    $directory = dirname($derivative);
    $this->container->get('file_system')->prepareDirectory($directory, 1);
    file_put_contents($derivative, 'Fictional stale unblurred derivative');
    $entry = $store->get($mid);
    $entry['prepared_hash'] = hash('sha256', $this->screenedBytes);
    $entry['blurred'] = TRUE;
    $entry['processed'] = TRUE;
    $store->set($mid, $entry);
    $this->screening->process($mid);
    $this->assertSame(0, $this->blurCalls);
    $this->assertFileDoesNotExist($derivative, 'Recovery flushes old derivatives before releasing the image.');
    $this->assertTrue(Media::load($mid)->isPublished());

    [$retry] = $this->fixture();
    $this->blurFails = TRUE;
    try {
      $this->screening->process((int) $retry->id());
      $this->fail('A blur outage must request backoff.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(60, $e->getDelay());
    }
    try {
      $this->screening->process((int) $retry->id());
      $this->fail('An immediate retry must respect backoff.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(60, $e->getDelay());
      $this->assertSame(1, $this->blurCalls);
    }
    $this->now += 60;
    $this->blurFails = FALSE;
    $this->screening->process((int) $retry->id());
    $fresh = Media::load($retry->id());
    $this->assertTrue($fresh->isPublished());
    $this->assertEmpty($fresh->get('field_ai_privacy_issues')->value);
  }

  /**
   * Default analysis skips citizen descriptions; always mode only suggests.
   */
  public function testDescriptionPolicyAndAtMostOnceBudget(): void {
    $this->features->method('isEnabledEffective')->willReturn(TRUE);
    $this->budget->method('isAIBudgetExhausted')->willReturn(FALSE);
    $this->budget->expects($this->once())->method('recordAIAnalysis')->with(41);
    $this->tokens->expects($this->once())->method('logUsage')
      ->with('vision', 'fictional-model', 'vision', 17, 9, 'media', $this->anything());
    $this->images->expects($this->once())->method('processScreenedImages')->willReturn([
      'model' => 'fictional-model',
      'usage' => ['prompt_tokens' => 17, 'completion_tokens' => 9],
      'ai_result' => json_encode([
        'privacy_flag' => FALSE, 'privacy_issues' => [],
        'description' => 'Fictional suggestion', 'category' => 123,
      ]),
    ]);
    [$media] = $this->fixture(TRUE, 'Fictional citizen description');
    $this->screening->process((int) $media->id());
    $metadata = json_decode(Media::load($media->id())->get('field_ai_metadata')->value, TRUE);
    $this->assertSame('description_present', $metadata['intake_screening']['analysis']);
    $this->config('markaspot_vision.settings')->set('imported_image_analysis', 'always')->save();
    [$second, $report] = $this->fixture(TRUE, 'Keep this fictional citizen description');
    $this->screening->process((int) $second->id());
    $this->screening->process((int) $second->id());
    $metadata = json_decode(Media::load($second->id())->get('field_ai_metadata')->value, TRUE);
    $this->assertSame('Fictional suggestion', $metadata['intake_suggestions']['description']);
    $this->assertSame('Keep this fictional citizen description', Node::load($report->id())->get('body')->value);
  }

  /**
   * An exhausted budget still allows privacy screening and publication.
   */
  public function testBudgetAndAiFailureDoNotHoldScreenedMedia(): void {
    $this->features->method('isEnabledEffective')->willReturn(TRUE);
    $this->budget->method('isAIBudgetExhausted')->willReturn(TRUE);
    $this->budget->expects($this->never())->method('recordAIAnalysis');
    $this->images->expects($this->never())->method('processScreenedImages');
    [$media] = $this->fixture();
    $this->screening->process((int) $media->id());
    $fresh = Media::load($media->id());
    $this->assertTrue($fresh->isPublished());
    $this->assertSame('budget_exhausted', json_decode($fresh->get('field_ai_metadata')->value, TRUE)['intake_screening']['analysis']);
  }

  /**
   * Token limits and an explicit import opt-out block all model calls.
   */
  public function testTokenLimitAndImportOptOut(): void {
    $this->features->method('isEnabledEffective')->willReturn(TRUE);
    $this->budget->expects($this->never())->method('recordAIAnalysis');
    $this->tokens->expects($this->never())->method('logUsage');
    $this->images->expects($this->never())->method('processScreenedImages');
    $this->tokenBudgetAvailable = FALSE;
    [$limited] = $this->fixture();
    $this->screening->process((int) $limited->id());
    $fresh = Media::load($limited->id());
    $this->assertTrue($fresh->isPublished());
    $this->assertSame('budget_exhausted', json_decode($fresh->get('field_ai_metadata')->value, TRUE)['intake_screening']['analysis']);

    $this->tokenBudgetAvailable = TRUE;
    $this->config('markaspot_vision.settings')->set('imported_image_analysis', 'off')->save();
    [$disabled] = $this->fixture();
    $this->screening->process((int) $disabled->id());
    $fresh = Media::load($disabled->id());
    $this->assertTrue($fresh->isPublished());
    $this->assertSame('disabled', json_decode($fresh->get('field_ai_metadata')->value, TRUE)['intake_screening']['analysis']);
  }

  /**
   * A manual decision made while blur is in flight survives every worker save.
   */
  public function testManualPublicationDuringBlurIsPreserved(): void {
    foreach ([TRUE, FALSE] as $published) {
      [$media] = $this->fixture();
      $mid = (int) $media->id();
      $this->duringBlur = function () use ($mid, $published): void {
        $fresh = $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($mid);
        ($published ? $fresh->setPublished() : $fresh->setUnpublished())->save();
        $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual')->set($mid, TRUE);
      };
      $this->screening->process($mid);
      $this->assertSame($published, Media::load($mid)->isPublished());
    }
  }

  /**
   * The checkpoint describes stored bytes, and a replacement is queued anew.
   */
  public function testSourceProofAndReplacement(): void {
    $this->blurDetected = FALSE;
    [$media, , $uri] = $this->fixture();
    $original = file_get_contents($uri);
    $mid = (int) $media->id();
    $this->screening->process($mid);
    $this->assertSame($original, file_get_contents($uri), 'A no-detection response does not replace the source.');
    $store = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE);
    $this->assertSame(hash('sha256', $original), $store->get($mid)['prepared_hash']);
    $this->assertTrue(Media::load($mid)->isPublished());

    $replacementUri = 'public://fictional-replacement.png';
    file_put_contents($replacementUri, $this->screenedBytes);
    $replacement = File::create(['uri' => $replacementUri, 'status' => 1]);
    $replacement->save();
    $fresh = $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($mid);
    $fresh->setOriginal(clone $fresh);
    $fresh->set('field_media_image', ['target_id' => $replacement->id(), 'alt' => 'Fictional replacement']);
    markaspot_vision_media_presave($fresh);
    $fresh->save();
    markaspot_vision_media_update($fresh);
    $this->assertFalse(Media::load($mid)->isPublished());
    $this->assertSame(0, $store->get($mid)['attempts']);
    $this->assertTrue($store->get($mid)['queued']);
    $this->blurDetected = TRUE;
    $this->screening->process($mid);
    $this->assertTrue(Media::load($mid)->isPublished());

    // A later controller reblur advances a completed intake fingerprint.
    file_put_contents($replacementUri, $original);
    $fresh = $this->container->get('entity_type.manager')->getStorage('media')->loadUnchanged($mid);
    $fresh->set('field_ai_metadata', json_encode(['intake_screening' => [
      'origin' => 'vision', 'privacy_screened' => TRUE, 'processed' => TRUE,
      'source_sha256' => hash('sha256', $original),
    ]]));
    $fresh->save();
    markaspot_vision_media_update($fresh);
    $this->assertSame(hash('sha256', $original), $store->get($mid)['prepared_hash']);
  }

  /**
   * Failed or interrupted AI is visible and never rebooked on retry.
   */
  public function testOptionalAiFailureAndInterruptionAreVisible(): void {
    $this->features->method('isEnabledEffective')->willReturn(TRUE);
    $this->budget->method('isAIBudgetExhausted')->willReturn(FALSE);
    $this->budget->expects($this->once())->method('recordAIAnalysis');
    $this->images->expects($this->once())->method('processScreenedImages')->willThrowException(new \RuntimeException('Fictional provider outage'));
    [$failed] = $this->fixture();
    $this->screening->process((int) $failed->id());
    $this->screening->process((int) $failed->id());
    $fresh = Media::load($failed->id());
    $this->assertTrue($fresh->isPublished());
    $this->assertSame('failed', json_decode($fresh->get('field_ai_metadata')->value, TRUE)['intake_screening']['analysis']);
    [$interrupted] = $this->fixture();
    $store = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE);
    $entry = $store->get($interrupted->id());
    $entry['analysis'] = 'started';
    $store->set($interrupted->id(), $entry);
    $this->screening->process((int) $interrupted->id());
    $this->assertTrue(Media::load($interrupted->id())->isPublished());
    $this->assertSame('interrupted', $store->get($interrupted->id())['analysis']);
    $health = new MediaIntakeScreeningCheck([], 'media_intake_screening', ['label' => 'Intake'], $this->container->get('keyvalue'), $this->container->get('datetime.time'));
    $this->assertFalse($health->run()->passed);
    $this->assertSame('warning', $health->run()->severity);
  }

  /**
   * A disabled blur is honest and follows citizen publication rules.
   */
  public function testFallbackAndRequiredPolicyTransition(): void {
    $oldRequired = getenv('MARKASPOT_BLUR_REQUIRED');
    $oldUrl = getenv('MARKASPOT_BLUR_URL');
    putenv('MARKASPOT_BLUR_REQUIRED=0');
    putenv('MARKASPOT_BLUR_URL');
    try {
      $this->processed = FALSE;
      $this->blurDetected = FALSE;
      $this->images->expects($this->never())->method('processScreenedImages');
      [$media, $node] = $this->fixture(TRUE, 'Fictional citizen description');
      $mid = (int) $media->id();
      $this->screening->process($mid);
      $storage = $this->container->get('entity_type.manager')->getStorage('media');
      $fresh = $storage->loadUnchanged($mid);
      $marker = json_decode($fresh->get('field_ai_metadata')->value, TRUE)['intake_screening'];
      $this->assertFalse($marker['privacy_screened']);
      $this->assertFalse($marker['processed']);
      $this->assertFalse($fresh->isPublished(), 'Strict mode holds an unanalysed, unprocessed image.');
      $this->config('markaspot_vision.settings')->set('require_ai_screening', FALSE)->save();
      _markaspot_vision_publish_safe_media($node);
      $this->assertTrue($storage->loadUnchanged($mid)->isPublished());
      putenv('MARKASPOT_BLUR_REQUIRED=1');
      _markaspot_vision_publish_safe_media($node);
      $this->assertFalse($storage->loadUnchanged($mid)->isPublished());
      $this->processed = TRUE;
      $this->screening->process($mid);
      $this->assertSame(2, $this->blurCalls, 'Required policy reruns the fallback checkpoint.');
      $this->assertTrue($storage->loadUnchanged($mid)->isPublished());
    }
    finally {
      putenv($oldRequired === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $oldRequired);
      putenv($oldUrl === FALSE ? 'MARKASPOT_BLUR_URL' : 'MARKASPOT_BLUR_URL=' . $oldUrl);
    }
  }

  /**
   * Removing association does not leave a stalled job; recovery can requeue.
   */
  public function testOrphanIsExcludedFromHealthAndReassociated(): void {
    [$media, $node] = $this->fixture();
    $node->set('field_request_media', [])->save();
    $this->screening->process((int) $media->id());
    $store = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE);
    $this->assertSame('orphaned', $store->get($media->id())['status']);
    $this->assertFalse($store->get($media->id())['queued']);
    $health = new MediaIntakeScreeningCheck([], 'media_intake_screening', ['label' => 'Intake'], $this->container->get('keyvalue'), $this->container->get('datetime.time'));
    $this->assertTrue($health->run()->passed);
    $node->set('field_request_media', $media->id())->save();
    $this->screening->recoverPending();
    $this->assertSame('pending', $store->get($media->id())['status']);
    $this->assertTrue($store->get($media->id())['queued']);
  }

  /**
   * Historical media returns after report moderation; privacy holds survive.
   */
  public function testHistoricalMediaRestoresUnlessPrivacyHeld(): void {
    [$media, $node] = $this->fixture();
    $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE)->delete($media->id());
    $media->setPublished()->save();
    $node->setUnpublished();
    _markaspot_vision_publish_safe_media($node);
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $this->assertFalse($storage->loadUnchanged($media->id())->isPublished());
    $node->setPublished();
    _markaspot_vision_publish_safe_media($node);
    $this->assertTrue($storage->loadUnchanged($media->id())->isPublished());
    $node->setUnpublished();
    _markaspot_vision_publish_safe_media($node);
    $fresh = $storage->loadUnchanged($media->id());
    $fresh->set('field_ai_privacy_flag', TRUE)->save();
    $node->setPublished();
    _markaspot_vision_publish_safe_media($node);
    $this->assertFalse($storage->loadUnchanged($media->id())->isPublished());
  }

  /**
   * A node save during AI cannot release an image before its verdict.
   */
  public function testAnalysisInFlightAndWhitespaceIssues(): void {
    $this->features->method('isEnabledEffective')->willReturn(TRUE);
    $this->budget->method('isAIBudgetExhausted')->willReturn(FALSE);
    [$media, $node] = $this->fixture();
    $this->images->expects($this->once())->method('processScreenedImages')->willReturnCallback(function () use ($media, $node) {
      _markaspot_vision_publish_safe_media($node);
      $this->assertFalse(Media::load($media->id())->isPublished());
      return ['ai_result' => json_encode(['privacy_flag' => FALSE, 'privacy_issues' => [' ', '']])];
    });
    $this->screening->process((int) $media->id());
    $this->assertTrue(Media::load($media->id())->isPublished());
  }

  /**
   * Lock contention after AI retries persistence, never the provider or charge.
   */
  public function testPublicationLockRetryKeepsAnalysisVerdict(): void {
    $this->features->method('isEnabledEffective')->willReturn(TRUE);
    $this->budget->method('isAIBudgetExhausted')->willReturn(FALSE);
    $this->budget->expects($this->once())->method('recordAIAnalysis');
    $blocked = FALSE;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(static function ($name) use (&$blocked) {
      return !$blocked || !str_starts_with($name, 'markaspot_open311.media_publication.');
    });
    $lock->expects($this->once())->method('wait');
    (new \ReflectionProperty($this->screening, 'lock'))->setValue($this->screening, $lock);
    $this->images->expects($this->once())->method('processScreenedImages')->willReturnCallback(static function () use (&$blocked) {
      $blocked = TRUE;
      return ['ai_result' => json_encode(['privacy_flag' => TRUE, 'privacy_issues' => ['Fictional private detail']])];
    });
    [$media] = $this->fixture();
    $mid = (int) $media->id();
    try {
      $this->screening->process($mid);
      $this->fail('A busy publication lock must retry.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(30, $e->getDelay());
    }
    $entry = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE)->get($mid);
    $this->assertSame(0, $entry['attempts']);
    $this->assertSame('done', $entry['analysis']);
    $this->assertNotEmpty($entry['analysis_result']);
    $this->assertEmpty(Media::load($mid)->get('field_ai_privacy_issues')->value);
    $blocked = FALSE;
    $this->screening->process($mid);
    $fresh = Media::load($mid);
    $this->assertFalse($fresh->isPublished());
    $this->assertSame('Fictional private detail', $fresh->get('field_ai_privacy_issues')->value);
    $this->assertSame(1, $this->blurCalls);
  }

  /**
   * A transient fingerprint read error remains recoverable.
   */
  public function testUnreadableCheckpointReturnsToPending(): void {
    [$media, $node, $uri] = $this->fixture();
    $this->screening->process((int) $media->id());
    $bytes = file_get_contents($uri);
    unlink($uri);
    _markaspot_vision_publish_safe_media($node);
    $entry = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE)->get($media->id());
    $this->assertSame('pending', $entry['status']);
    $this->assertFalse($entry['queued']);
    $this->assertFalse(Media::load($media->id())->isPublished());
    file_put_contents($uri, $bytes);
    $this->screening->process((int) $media->id());
    $this->assertTrue(Media::load($media->id())->isPublished());
  }

  /**
   * Budget contention is retryable and consumes no privacy attempt or AI slot.
   */
  public function testBudgetBusyRetriesWithoutFailureOrCharge(): void {
    $this->features->method('isEnabledEffective')->willReturn(TRUE);
    $this->budget->method('isAIBudgetExhausted')->willReturn(FALSE);
    $this->budget->expects($this->once())->method('recordAIAnalysis');
    $blocked = TRUE;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(static function ($name) use (&$blocked) {
      return !$blocked || $name !== 'markaspot_media_intake:budget:41';
    });
    $lock->expects($this->once())->method('wait')->with('markaspot_media_intake:budget:41', 1);
    (new \ReflectionProperty($this->screening, 'lock'))->setValue($this->screening, $lock);
    $this->images->expects($this->once())->method('processScreenedImages')->willReturn([
      'ai_result' => json_encode(['privacy_flag' => FALSE, 'privacy_issues' => []]),
    ]);
    [$media] = $this->fixture();
    $mid = (int) $media->id();
    try {
      $this->screening->process($mid);
      $this->fail('Budget contention must retry.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(30, $e->getDelay());
    }
    $entry = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE)->get($mid);
    $this->assertSame(0, $entry['attempts']);
    $this->assertArrayNotHasKey('analysis', $entry);
    $this->assertEmpty(Media::load($mid)->get('field_ai_privacy_issues')->value);
    $blocked = FALSE;
    $this->screening->process($mid);
    $this->assertTrue(Media::load($mid)->isPublished());
    $this->assertSame(1, $this->blurCalls);
  }

  /**
   * A completed job can finish publication after the report gate was busy.
   */
  public function testCompletedJobResumesPublication(): void {
    [$media] = $this->fixture();
    $realLock = $this->container->get('lock');
    $busy = $this->createMock(LockBackendInterface::class);
    $busy->method('acquire')->willReturn(FALSE);
    $busy->expects($this->once())->method('wait');
    $this->container->set('lock', $busy);
    try {
      $this->screening->process((int) $media->id());
      $this->fail('The busy report publication gate must be retried.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Media publication is being edited', $e->getMessage());
    }
    $this->assertFalse(Media::load($media->id())->isPublished());
    $this->container->set('lock', $realLock);
    $this->screening->process((int) $media->id());
    $this->assertTrue(Media::load($media->id())->isPublished());
    $this->assertSame(1, $this->blurCalls);
  }

  /**
   * Editorial resolution survives even a concurrent blur failure.
   */
  public function testEditorialDecisionDuringBlurFailureResolvesWork(): void {
    [$media] = $this->fixture();
    $mid = (int) $media->id();
    $store = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE);
    $this->duringBlur = function () use ($mid, $store): void {
      $this->container->get('keyvalue')->get('markaspot_open311.media_publication_manual')->set($mid, TRUE);
      $store->delete($mid);
    };
    $this->blurFails = TRUE;
    $this->screening->process($mid);
    $this->assertFalse($store->has($mid));
    $this->assertEmpty(Media::load($mid)->get('field_ai_privacy_issues')->value);
  }

  /**
   * Required blur also invalidates controller markers without an intake row.
   */
  public function testControllerFallbackWithoutIntakeRequiresFreshBlur(): void {
    $oldRequired = getenv('MARKASPOT_BLUR_REQUIRED');
    putenv('MARKASPOT_BLUR_REQUIRED=1');
    try {
      $this->images->expects($this->never())->method('processScreenedImages');
      [$media, $node, $uri] = $this->fixture();
      $mid = (int) $media->id();
      $store = $this->container->get('keyvalue')->get(MediaIntakeScreening::STORE);
      $store->delete($mid);
      $marker = [
        'origin' => 'vision', 'privacy_screened' => FALSE, 'processed' => FALSE,
        'blur_required' => FALSE, 'source_sha256' => hash_file('sha256', $uri),
      ];
      $media->set('field_ai_metadata', json_encode(['intake_screening' => $marker]));
      $media->setPublished()->save();
      _markaspot_vision_publish_safe_media($node);
      $this->assertFalse(Media::load($mid)->isPublished());
      $this->assertSame('pending', $store->get($mid)['status']);
      $this->assertSame('existing', $store->get($mid)['analysis']);
      $this->screening->process($mid);
      $this->assertSame(1, $this->blurCalls);
      $this->assertTrue(Media::load($mid)->isPublished());

      // A current, real checkpoint without intake remains eligible.
      $store->delete($mid);
      _markaspot_vision_publish_safe_media($node);
      $this->assertFalse($store->has($mid));
      $this->assertTrue(Media::load($mid)->isPublished());
    }
    finally {
      putenv($oldRequired === FALSE ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $oldRequired);
    }
  }

}

/**
 * Adds the worktree's real Vision schema to this isolated kernel.
 */
final class IntakeSchemaStorage extends ExtensionInstallStorage {

  /**
   * {@inheritdoc}
   */
  protected function getAllFolders() {
    return ['markaspot_vision.schema' => dirname(__DIR__, 3) . '/config/schema'] + parent::getAllFolders();
  }

}
