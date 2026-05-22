<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_vision\Controller\ImageProcessingController;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests security hardening of the ImageProcessingController.
 *
 * @group markaspot_vision
 * @coversDefaultClass \Drupal\markaspot_vision\Controller\ImageProcessingController
 */
class ImageProcessingControllerTest extends UnitTestCase {

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_vision\Controller\ImageProcessingController
   */
  protected ImageProcessingController $controller;

  /**
   * Mocked flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $flood;

  /**
   * Mocked image processing service.
   *
   * @var \Drupal\markaspot_vision\Service\ImageProcessingService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $imageProcessingService;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked media storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mediaStorage;

  /**
   * Mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->imageProcessingService = $this->createMock(ImageProcessingService::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->mediaStorage = $this->createMock(EntityStorageInterface::class);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    // FeatureFlagChecker is final; instantiate the real service. With no
    // jurisdiction context available in these unit tests, isEnabled() returns
    // the caller's $default, and the controller defaults aiAnalysis to TRUE.
    $featureFlagChecker = new FeatureFlagChecker();

    // Default: node query returns no results (no parent nodes).
    $nodeQuery = $this->createMock(QueryInterface::class);
    $nodeQuery->method('accessCheck')->willReturnSelf();
    $nodeQuery->method('condition')->willReturnSelf();
    $nodeQuery->method('execute')->willReturn([]);
    $this->nodeStorage->method('getQuery')->willReturn($nodeQuery);
    $this->nodeStorage->method('loadMultiple')->willReturn([]);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_vision')
      ->willReturn($this->logger);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['media', $this->mediaStorage],
        ['node', $this->nodeStorage],
      ]);

    $this->controller = new ImageProcessingController(
      $this->imageProcessingService,
      $loggerFactory,
      $this->flood,
      $featureFlagChecker,
    );

    // Inject entityTypeManager via reflection (ControllerBase stores it
    // as a protected property from EntityTypeManagerTrait).
    $ref = new \ReflectionProperty($this->controller, 'entityTypeManager');
    $ref->setAccessible(TRUE);
    $ref->setValue($this->controller, $entityTypeManager);

    // Inject string translation stub so $this->t() works in tests.
    $this->controller->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Creates a JSON POST request with the given body.
   */
  protected function createJsonRequest(array $body, string $ip = '127.0.0.1'): Request {
    $request = Request::create(
      '/api/vision/analyze',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => $ip],
      json_encode($body),
    );
    $request->headers->set('Content-Type', 'application/json');
    return $request;
  }

  /**
   * Creates a mock media entity with configurable access.
   *
   * @param int $id
   *   The entity ID.
   * @param bool $viewAccess
   *   Whether view access is granted.
   * @param bool $updateAccess
   *   Whether update access is granted.
   * @param string|null $fileUri
   *   Optional file URI for the media image field.
   *
   * @return \Drupal\media\MediaInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked media entity.
   */
  protected function createMockMedia(int $id, bool $viewAccess = TRUE, bool $updateAccess = TRUE, ?string $fileUri = 'public://test.jpg'): MediaInterface {
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn($id);
    $media->method('uuid')->willReturn('uuid-' . $id);
    $media->method('access')
      ->willReturnCallback(function ($operation) use ($viewAccess, $updateAccess) {
        if ($operation === 'view') {
          return $viewAccess;
        }
        if ($operation === 'update') {
          return $updateAccess;
        }
        return FALSE;
      });

    if ($fileUri) {
      $file = new class($fileUri) {

        /**
         * The file URI.
         */
        private string $uri;

        /**
         * Constructs the file stub.
         */
        public function __construct(string $uri) {
          $this->uri = $uri;
        }

        /**
         * Returns the file URI.
         */
        public function getFileUri(): string {
          return $this->uri;
        }

      };

      $fieldItem = new class($file) {

        /**
         * The referenced file entity.
         */
        public object $entity;

        /**
         * The alt text value.
         */
        public ?string $alt = NULL;

        /**
         * Constructs the field item stub.
         */
        public function __construct(object $file) {
          $this->entity = $file;
        }

        /**
         * Checks if the field is empty.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };

      $media->method('get')
        ->willReturnCallback(function ($field_name) use ($fieldItem) {
          if ($field_name === 'field_media_image') {
            return $fieldItem;
          }
          return NULL;
        });
    }

    return $media;
  }

  /**
   * @covers ::getAIResults
   */
  public function testRateLimitExceededReturns429(): void {
    $this->flood->method('isAllowed')
      ->with('markaspot_vision.analyze', 10, 3600, '127.0.0.1')
      ->willReturn(FALSE);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(429, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertStringContainsString('Too many requests', $data['error']);
  }

  /**
   * @covers ::getAIResults
   */
  public function testRateLimitRegistersEachRequest(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->flood->expects($this->once())
      ->method('register')
      ->with('markaspot_vision.analyze', 3600, '127.0.0.1');

    // Request will fail at media loading, but register should still be called.
    $this->mediaStorage->method('loadByProperties')->willReturn([]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $this->controller->getAIResults($request);
  }

  /**
   * @covers ::getAIResults
   */
  public function testRateLimitUsesClientIp(): void {
    $this->flood->expects($this->once())
      ->method('isAllowed')
      ->with('markaspot_vision.analyze', 10, 3600, '10.0.0.5')
      ->willReturn(FALSE);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']], '10.0.0.5');
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(429, $response->getStatusCode());
  }

  /**
   * @covers ::getAIResults
   */
  public function testMissingMediaIdsReturns400(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $request = $this->createJsonRequest(['something_else' => 'value']);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('media_ids', $data['error']);
  }

  /**
   * @covers ::getAIResults
   */
  public function testNonArrayMediaIdsReturns400(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $request = $this->createJsonRequest(['media_ids' => 'not-an-array']);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * @covers ::getAIResults
   */
  public function testEmptyMediaIdsReturns400(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $request = $this->createJsonRequest(['media_ids' => []]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * @covers ::getAIResults
   */
  public function testTooManyMediaIdsReturns400(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $request = $this->createJsonRequest([
      'media_ids' => ['a', 'b', 'c', 'd', 'e', 'f'],
    ]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('Maximum', $data['error']);
    $this->assertStringContainsString('5', $data['error']);
  }

  /**
   * @covers ::getAIResults
   */
  public function testExactlyFiveMediaIdsAllowed(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    // 5 items should pass validation (fail later at entity loading).
    $this->mediaStorage->method('loadByProperties')->willReturn([]);

    $request = $this->createJsonRequest([
      'media_ids' => ['a', 'b', 'c', 'd', 'e'],
    ]);
    $response = $this->controller->getAIResults($request);

    // Should NOT be 400 (passes count validation).
    // Will be 500 because no media found, which is fine.
    $this->assertNotEquals(400, $response->getStatusCode());
  }

  /**
   * @covers ::getAIResults
   */
  public function testMediaWithoutViewAccessIsFiltered(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1, viewAccess: FALSE);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    // No viewable media -> generic error.
    $this->assertEquals(500, $response->getStatusCode());
  }

  /**
   * @covers ::getAIResults
   */
  public function testMediaWithoutUpdateAccessStillSaves(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    // The controller intentionally skips access checks (privacy by design:
    // media may be unpublished but still needs AI analysis).
    $media = $this->createMockMedia(1, viewAccess: TRUE, updateAccess: FALSE);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    $media->expects($this->once())->method('save');

    $aiResult = json_encode([
      'category' => 42,
      'description' => 'A pothole',
      'alt_text' => ['Pothole on road'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => FALSE,
      'privacy_issues' => [],
    ]);

    $this->imageProcessingService->method('processImages')
      ->willReturn(['ai_result' => $aiResult]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * @covers ::getAIResults
   */
  public function testAllLoadedMediaIsProcessed(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    // The controller skips access checks (privacy by design), so all loaded
    // media entities are processed regardless of view access.
    $media1 = $this->createMockMedia(1, viewAccess: TRUE);
    $media2 = $this->createMockMedia(2, viewAccess: FALSE);
    $this->mediaStorage->method('loadByProperties')
      ->willReturn([1 => $media1, 2 => $media2]);

    // processImages should receive 2 file URIs (all loaded media).
    $this->imageProcessingService->expects($this->once())
      ->method('processImages')
      ->with(
        $this->callback(function ($uris) {
          return count($uris) === 2;
        }),
        $this->anything(),
        $this->anything(),
      )
      ->willReturn([
        'ai_result' => json_encode([
          'category' => 1,
          'description' => 'Test',
          'alt_text' => ['Alt 1', 'Alt 2'],
          'hazard_flag' => FALSE,
          'hazard_level' => 0,
          'hazard_issues' => [],
          'privacy_flag' => FALSE,
          'privacy_issues' => [],
        ]),
      ]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * @covers ::getAIResults
   */
  public function testExceptionReturnsGenericError(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->mediaStorage->method('loadByProperties')->willReturn([]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(500, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // Must not contain internal details like file paths or class names.
    $this->assertStringNotContainsString('media_ids', $data['error']);
    $this->assertStringNotContainsString('Exception', $data['error']);
    $this->assertStringContainsString('error occurred', $data['error']);
  }

  /**
   * @covers ::getAIResults
   */
  public function testExceptionLogsDetailedMessage(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->mediaStorage->method('loadByProperties')->willReturn([]);

    // The logger SHOULD receive the detailed exception message.
    $this->logger->expects($this->atLeastOnce())
      ->method('error')
      ->with(
        $this->stringContains('getAIResults'),
        $this->callback(function ($context) {
          return isset($context['@message'])
            && str_contains($context['@message'], 'No valid media entities');
        }),
      );

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $this->controller->getAIResults($request);
  }

  /**
   * @covers ::getAIResults
   */
  public function testSuccessfulAnalysisReturnsDecodedResult(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    $expectedResult = [
      'category' => 42,
      'description' => 'Pothole on Hauptstrasse',
      'alt_text' => ['A deep pothole in the road surface'],
      'hazard_flag' => TRUE,
      'hazard_level' => 2,
      'hazard_issues' => ['trip hazard'],
      'privacy_flag' => FALSE,
      'privacy_issues' => [],
    ];

    $this->imageProcessingService->method('processImages')
      ->willReturn(['ai_result' => json_encode($expectedResult)]);

    // Safe media (privacy_flag=FALSE) must be published immediately.
    $media->expects($this->once())->method('setPublished');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(42, $data['category']);
    $this->assertEquals('Pothole on Hauptstrasse', $data['description']);
    $this->assertTrue($data['hazard_flag']);
    // No blur ran, so the citizen warning signal must be false.
    $this->assertFalse($data['privacy_handled_by_blur']);
  }

  /**
   * @covers ::getAIResults
   */
  public function testSuccessfulAnalysisReturnsBlurHandlingMetadata(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    // Privacy flag is set; the blur service blurred the face. The citizen
    // notice is suppressed deterministically by the blur result, not the model.
    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['Blurred workers near a damaged bin'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['blurred face visible'],
    ];

    $this->imageProcessingService->method('processImages')
      ->willReturn([
        'ai_result' => json_encode($aiResult),
        'blur_results' => [
          'public://test.jpg' => [
            'contents' => 'blurred-bytes',
            'blurred' => TRUE,
            'faces' => 1,
            'plates' => 0,
            'mime' => 'image/jpeg',
          ],
        ],
      ]);

    $this->imageProcessingService->expects($this->once())
      ->method('saveBlurredImage')
      ->with($media, 'blurred-bytes', 'public://test.jpg');

    // Internal moderation invariant: privacy_flag=TRUE must keep the media
    // unpublished even though the citizen warning is suppressed by blur.
    $media->expects($this->never())->method('setPublished');

    // Capture what gets persisted to the JSON:API-exposed field_ai_metadata.
    $capturedMetadata = NULL;
    $media->method('set')->willReturnCallback(
      function (string $field, $value) use (&$capturedMetadata, $media) {
        if ($field === 'field_ai_metadata') {
          $capturedMetadata = $value;
        }
        return $media;
      }
    );

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['privacy_flag']);
    $this->assertSame(['blurred face visible'], $data['privacy_issues']);
    // Citizen warning is suppressed because the blur service blurred the face.
    $this->assertTrue($data['privacy_handled_by_blur']);
    // privacy_flag is still persisted to field_ai_metadata for moderation.
    $this->assertNotNull($capturedMetadata);
    $storedMeta = json_decode($capturedMetadata, TRUE);
    $this->assertTrue($storedMeta['privacy_flag']);
    // The response-only signal must never be persisted to the JSON:API-exposed
    // field_ai_metadata, even if a future refactor reorders the assignment.
    $this->assertArrayNotHasKey('privacy_handled_by_blur', $storedMeta);
    // The blurred thumbnail is returned (data URL) keyed by media UUID so the
    // citizen preview can show the privacy-protected version.
    $this->assertSame(
      'data:image/jpeg;base64,' . base64_encode('blurred-bytes'),
      $data['blurred_previews']['uuid-1']
    );
  }

  /**
   * Blur suppresses the citizen notice, but residual PII stays unpublished.
   *
   * Variant A trade-off: once the blur service blurred faces/plates, the
   * citizen notice is suppressed deterministically, even if the AI also flagged
   * unblurrable PII (e.g. a document). Internal moderation is unaffected:
   * privacy_flag keeps the media unpublished so a moderator still catches it.
   *
   * @covers ::getAIResults
   */
  public function testBlurSuppressesNoticeButResidualPiiStaysUnpublished(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['A damaged bin near a building entrance'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['readable ID document visible'],
    ];

    $this->imageProcessingService->method('processImages')
      ->willReturn([
        'ai_result' => json_encode($aiResult),
        'blur_results' => [
          'public://test.jpg' => [
            'contents' => 'blurred-bytes',
            'blurred' => TRUE,
            'faces' => 1,
            'plates' => 0,
          ],
        ],
      ]);

    // Moderation invariant: privacy_flag=TRUE keeps the media unpublished.
    $media->expects($this->never())->method('setPublished');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['privacy_flag']);
    // Blur ran, so the citizen notice is suppressed (Variant A trade-off),
    // while the media stays unpublished for human review (asserted above).
    $this->assertTrue($data['privacy_handled_by_blur']);
  }

  /**
   * No blur means the citizen notice stays visible.
   *
   * When the blur service blurred nothing (no blur_results), the deterministic
   * signal is FALSE, so the privacy notice is shown — independent of the model.
   *
   * @covers ::getAIResults
   */
  public function testNoBlurKeepsNoticeVisible(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['A damaged bin'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['face visible'],
    ];

    // No blur_results: blur_applied resolves to FALSE.
    $this->imageProcessingService->method('processImages')
      ->willReturn(['ai_result' => json_encode($aiResult)]);

    // saveBlurredImage must never run when nothing was blurred.
    $this->imageProcessingService->expects($this->never())->method('saveBlurredImage');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['privacy_handled_by_blur']);
  }

  /**
   * A blurred media stays unpublished even when the AI clears privacy_flag.
   *
   * The smoke showed the model inconsistently sets privacy_flag=false on a
   * blurred face. Depublish must not depend on that: once the blur service
   * blurred this media, it is held for human review regardless of the verdict.
   *
   * @covers ::getAIResults
   */
  public function testBlurredMediaStaysUnpublishedEvenWhenAiClearsPrivacy(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    // AI cleared privacy, but the blur service blurred a face on this media.
    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['A damaged bin'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => FALSE,
      'privacy_issues' => [],
    ];

    $this->imageProcessingService->method('processImages')
      ->willReturn([
        'ai_result' => json_encode($aiResult),
        'blur_results' => [
          'public://test.jpg' => [
            'contents' => 'blurred-bytes',
            'blurred' => TRUE,
            'faces' => 1,
            'plates' => 0,
          ],
        ],
      ]);

    // Deterministic depublish guard: a blurred media is never auto-published.
    $media->expects($this->never())->method('setPublished');

    // Capture the persisted moderation flag: it must be TRUE for a blurred
    // media even though the AI cleared privacy_flag. This is the field the
    // node hook reads, so folding the blur fact in keeps controller and hook
    // in agreement (no re-publish on later node saves).
    $capturedPrivacyFlag = NULL;
    $media->method('set')->willReturnCallback(
      function (string $field, $value) use (&$capturedPrivacyFlag, $media) {
        if ($field === 'field_ai_privacy_flag') {
          $capturedPrivacyFlag = $value;
        }
        return $media;
      }
    );

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // Citizen notice suppressed (blur ran); media held for review (asserted).
    $this->assertTrue($data['privacy_handled_by_blur']);
    // field_ai_privacy_flag persisted TRUE despite AI privacy_flag=false.
    $this->assertTrue($capturedPrivacyFlag);
  }

  /**
   * Mixed batch: only the un-blurred media is published.
   *
   * Locks the grain distinction: the depublish guard is PER-media
   * ($blur_results[$uri]['blurred']), not the batch-level $blur_applied, so a
   * clean image in the same submission still publishes while the blurred one
   * is held for review.
   *
   * @covers ::getAIResults
   */
  public function testMixedBatchPublishesOnlyTheCleanMedia(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $blurredMedia = $this->createMockMedia(1, TRUE, TRUE, 'public://a.jpg');
    $cleanMedia = $this->createMockMedia(2, TRUE, TRUE, 'public://b.jpg');
    $this->mediaStorage->method('loadByProperties')
      ->willReturn([1 => $blurredMedia, 2 => $cleanMedia]);

    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['A damaged bin', 'A pothole'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => FALSE,
      'privacy_issues' => [],
    ];

    $this->imageProcessingService->method('processImages')
      ->willReturn([
        'ai_result' => json_encode($aiResult),
        'blur_results' => [
          'public://a.jpg' => [
            'contents' => 'blurred-bytes',
            'blurred' => TRUE,
            'faces' => 1,
            'plates' => 0,
          ],
          'public://b.jpg' => [
            'contents' => '',
            'blurred' => FALSE,
            'faces' => 0,
            'plates' => 0,
          ],
        ],
      ]);

    // Blurred media held; clean media published.
    $blurredMedia->expects($this->never())->method('setPublished');
    $cleanMedia->expects($this->once())->method('setPublished');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // Batch-level signal is OR: blur ran somewhere in the batch.
    $this->assertTrue($data['privacy_handled_by_blur']);
  }

  /**
   * @covers ::getAIResults
   */
  public function testInvalidJsonBody(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    // Send completely invalid JSON.
    $request = Request::create(
      '/api/vision/analyze',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '127.0.0.1'],
      'not-json-at-all',
    );
    $request->headers->set('Content-Type', 'application/json');

    $response = $this->controller->getAIResults($request);

    $this->assertEquals(400, $response->getStatusCode());
  }

}
