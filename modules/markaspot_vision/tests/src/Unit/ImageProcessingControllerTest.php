<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_vision\Controller\ImageProcessingController;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests security hardening of the ImageProcessingController.
 */
#[CoversClass(ImageProcessingController::class)]
#[Group('markaspot_vision')]
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
   * Mocked media analysis access guard.
   *
   * @var \Drupal\markaspot_vision\Service\MediaAnalysisAccessGuard|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mediaAnalysisAccessGuard;

  /**
   * Default media analysis guard result for tests.
   *
   * @var bool
   */
  protected bool $allowMediaAnalysis = TRUE;

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
   * Manual publication marks served by the mocked keyvalue store.
   *
   * Keyed by media ID. Tests set a truthy value to simulate media whose
   * publication state is under explicit editorial control via the GeoReport
   * media publication API (markaspot_open311.media_publication_manual).
   *
   * @var array
   */
  protected array $manualPublicationMarks = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->imageProcessingService = $this->createMock(ImageProcessingService::class);
    $this->mediaAnalysisAccessGuard = $this->createMock(MediaAnalysisAccessGuard::class);
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
      $this->mediaAnalysisAccessGuard,
    );

    // Inject entityTypeManager via reflection (ControllerBase stores it
    // as a protected property from EntityTypeManagerTrait).
    $ref = new \ReflectionProperty($this->controller, 'entityTypeManager');
    $ref->setAccessible(TRUE);
    $ref->setValue($this->controller, $entityTypeManager);

    // Inject the keyvalue store ControllerBase::keyValue() lazily resolves:
    // the controller reads markaspot_open311.media_publication_manual to let
    // explicit GeoReport publication decisions win over re-analysis. Default
    // (no mark) means "not manually controlled" — publish flow unaffected.
    $manualPublication = $this->createMock(KeyValueStoreInterface::class);
    $manualPublication->method('get')
      ->willReturnCallback(fn ($key) => $this->manualPublicationMarks[$key] ?? NULL);
    $keyValueRef = new \ReflectionProperty($this->controller, 'keyValue');
    $keyValueRef->setAccessible(TRUE);
    $keyValueRef->setValue($this->controller, $manualPublication);

    // Inject string translation stub so $this->t() works in tests.
    $this->controller->setStringTranslation($this->getStringTranslationStub());

    $this->mediaAnalysisAccessGuard->method('canAnalyze')
      ->willReturnCallback(fn () => $this->allowMediaAnalysis);
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
   */
  public function testNonArrayMediaIdsReturns400(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $request = $this->createJsonRequest(['media_ids' => 'not-an-array']);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   */
  public function testEmptyMediaIdsReturns400(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $request = $this->createJsonRequest(['media_ids' => []]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
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
   */
  public function testUnauthorizedMediaReturns403BeforeProcessing(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1, viewAccess: FALSE, updateAccess: FALSE);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    $this->allowMediaAnalysis = FALSE;
    $this->imageProcessingService->expects($this->never())->method('processImages');
    $media->expects($this->never())->method('save');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
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
   */
  public function testSuccessfulAnalysisReturnsBlurHandlingMetadata(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    // Privacy flag is set; the blur service blurred the face. The model may
    // still describe a visible person, but that is covered by anonymisation.
    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['Blurred workers near a damaged bin'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['person visible'],
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

    // Successful anonymisation publishes the media even when the AI still
    // describes the blurred region as a privacy concern.
    $media->expects($this->once())->method('setPublished');

    // Capture what gets persisted to the JSON:API-exposed field_ai_metadata.
    $capturedMetadata = NULL;
    $capturedPrivacyFlag = NULL;
    $capturedPrivacyIssues = NULL;
    $media->method('set')->willReturnCallback(
      function (string $field, $value) use (&$capturedMetadata, &$capturedPrivacyFlag, &$capturedPrivacyIssues, $media) {
        if ($field === 'field_ai_metadata') {
          $capturedMetadata = $value;
        }
        if ($field === 'field_ai_privacy_flag') {
          $capturedPrivacyFlag = $value;
        }
        if ($field === 'field_ai_privacy_issues') {
          $capturedPrivacyIssues = $value;
        }
        return $media;
      }
    );

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['privacy_flag']);
    $this->assertSame([], $data['privacy_issues']);
    // Citizen warning is suppressed because the blur service blurred the face.
    $this->assertTrue($data['privacy_handled_by_blur']);
    // Persisted moderation fields are also cleared so the node save hook does
    // not unpublish the already-anonymised media later.
    $this->assertFalse($capturedPrivacyFlag);
    $this->assertSame('', $capturedPrivacyIssues);
    // privacy_flag is still persisted to field_ai_metadata for audit.
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
   * Valid per-image attribution scopes the response flag to the right media.
   */
  public function testPerImageAttributionScopesResponsePrivacyFlags(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $mediaA = $this->createMockMedia(1, fileUri: 'public://a.jpg');
    $mediaB = $this->createMockMedia(2, fileUri: 'public://b.jpg');
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $mediaA, 2 => $mediaB]);

    $aiResult = [
      'category' => 42,
      'description' => 'desc',
      'alt_text' => ['a', 'b'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['face visible'],
      'privacy_image_flags' => [TRUE, FALSE],
    ];
    $this->imageProcessingService->method('processImages')
      ->willReturn(['ai_result' => json_encode($aiResult)]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // Aggregate stays authoritative for the banner/block.
    $this->assertTrue($data['privacy_flag']);
    // Only the offending media is flagged per thumbnail.
    $this->assertSame(['uuid-1' => TRUE, 'uuid-2' => FALSE], $data['privacy_flags']);
  }

  /**
   * Missing attribution falls back to the aggregate verdict on every media.
   */
  public function testMissingAttributionFlagsAllMediaFromAggregate(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $mediaA = $this->createMockMedia(1, fileUri: 'public://a.jpg');
    $mediaB = $this->createMockMedia(2, fileUri: 'public://b.jpg');
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $mediaA, 2 => $mediaB]);

    $aiResult = [
      'category' => 42,
      'description' => 'desc',
      'alt_text' => ['a', 'b'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['face visible'],
    ];
    $this->imageProcessingService->method('processImages')
      ->willReturn(['ai_result' => json_encode($aiResult)]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(['uuid-1' => TRUE, 'uuid-2' => TRUE], $data['privacy_flags']);
  }

  /**
   * Aggregate-positive but attribution-empty output fails closed on all media.
   */
  public function testContradictoryAttributionFailsClosedOnAllMedia(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $mediaA = $this->createMockMedia(1, fileUri: 'public://a.jpg');
    $mediaB = $this->createMockMedia(2, fileUri: 'public://b.jpg');
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $mediaA, 2 => $mediaB]);

    $aiResult = [
      'category' => 42,
      'description' => 'desc',
      'alt_text' => ['a', 'b'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['readable document'],
      'privacy_image_flags' => [FALSE, FALSE],
    ];
    $this->imageProcessingService->method('processImages')
      ->willReturn(['ai_result' => json_encode($aiResult)]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(['uuid-1' => TRUE, 'uuid-2' => TRUE], $data['privacy_flags']);
  }

  /**
   * Skipped media fails closed while analysed siblings keep their attribution.
   */
  public function testSkippedMediaFailsClosedInResponsePrivacyFlags(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $mediaA = $this->createMockMedia(1, fileUri: 'public://a.jpg');
    $mediaB = $this->createMockMedia(2, fileUri: 'public://b.jpg');
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $mediaA, 2 => $mediaB]);

    $aiResult = [
      'category' => 42,
      'description' => 'desc',
      'alt_text' => ['a'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => FALSE,
      'privacy_issues' => [],
      // One analysed image (media B was skipped), attributed clean.
      'privacy_image_flags' => [FALSE],
    ];
    $this->imageProcessingService->method('processImages')
      ->willReturn([
        'ai_result' => json_encode($aiResult),
        'skipped_uris' => ['public://b.jpg' => 'unreadable'],
      ]);

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $data = json_decode($response->getContent(), TRUE);
    // The skip taints the batch banner, but attribution keeps the analysed
    // clean sibling unflagged per thumbnail.
    $this->assertTrue($data['privacy_flag']);
    $this->assertSame(['uuid-1' => FALSE, 'uuid-2' => TRUE], $data['privacy_flags']);
  }

  /**
   * Privacy issues hold media even when the model omits privacy_flag.
   */
  public function testPrivacyIssuesHoldMediaWhenFlagIsFalse(): void {
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
      'privacy_flag' => FALSE,
      'privacy_issues' => ['readable ID document visible'],
    ];

    $this->imageProcessingService->method('processImages')
      ->willReturn(['ai_result' => json_encode($aiResult)]);

    $media->expects($this->never())->method('setPublished');

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
    $this->assertTrue($data['privacy_flag']);
    $this->assertSame(['readable ID document visible'], $data['privacy_issues']);
    $this->assertTrue($capturedPrivacyFlag);
  }

  /**
   * Media skipped from AI analysis is held while analyzed media can publish.
   */
  public function testSkippedMediaHeldWhileAnalyzedMediaCanPublish(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $skippedMedia = $this->createMockMedia(1, TRUE, TRUE, 'public://skipped.jpg');
    $analyzedMedia = $this->createMockMedia(2, TRUE, TRUE, 'public://analyzed.jpg');
    $this->mediaStorage->method('loadByProperties')
      ->willReturn([1 => $skippedMedia, 2 => $analyzedMedia]);

    $aiResult = [
      'category' => 42,
      'description' => 'Clean analyzed media',
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
        'skipped_uris' => [
          'public://skipped.jpg' => 'unreadable',
        ],
      ]);

    $skippedMedia->expects($this->never())->method('setPublished');
    $analyzedMedia->expects($this->once())->method('setPublished');

    $capturedSkippedFlag = NULL;
    $capturedSkippedIssues = NULL;
    $skippedMedia->method('set')->willReturnCallback(
      function (string $field, $value) use (&$capturedSkippedFlag, &$capturedSkippedIssues, $skippedMedia) {
        if ($field === 'field_ai_privacy_flag') {
          $capturedSkippedFlag = $value;
        }
        if ($field === 'field_ai_privacy_issues') {
          $capturedSkippedIssues = $value;
        }
        return $skippedMedia;
      }
    );

    $capturedAnalyzedFlag = NULL;
    $analyzedMedia->method('set')->willReturnCallback(
      function (string $field, $value) use (&$capturedAnalyzedFlag, $analyzedMedia) {
        if ($field === 'field_ai_privacy_flag') {
          $capturedAnalyzedFlag = $value;
        }
        return $analyzedMedia;
      }
    );

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['privacy_flag']);
    $this->assertSame(['One or more uploaded images could not be AI-screened.'], $data['privacy_issues']);
    $this->assertTrue($capturedSkippedFlag);
    $this->assertFalse($capturedAnalyzedFlag);
    $this->assertStringContainsString('AI analysis skipped for this media (unreadable)', $capturedSkippedIssues);
  }

  /**
   * Failing to persist a blurred image fails the analysis response closed.
   */
  public function testBlurredImagePersistenceFailureFailsClosed(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['Blurred face near a bin'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['face visible'],
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

    $this->imageProcessingService->method('saveBlurredImage')
      ->willThrowException(new \RuntimeException('persist failed'));

    $media->expects($this->never())->method('save');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(500, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertArrayNotHasKey('blurred_previews', $data);
    $this->assertArrayNotHasKey('privacy_handled_by_blur', $data);
  }

  /**
   * Successful blur keeps unhandled PII findings unpublished.
   *
   * Face and plate concerns are handled by the blur service. Other findings,
   * such as readable documents, still need review after the image bytes were
   * overwritten.
   */
  public function testSuccessfulBlurKeepsResidualUnhandledPiiUnpublished(): void {
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
      'privacy_issues' => [
        'readable ID document visible',
        'identifiable person visible',
      ],
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

    $media->expects($this->never())->method('setPublished');

    $capturedPrivacyFlag = NULL;
    $capturedPrivacyIssues = NULL;
    $media->method('set')->willReturnCallback(
      function (string $field, $value) use (&$capturedPrivacyFlag, &$capturedPrivacyIssues, $media) {
        if ($field === 'field_ai_privacy_flag') {
          $capturedPrivacyFlag = $value;
        }
        if ($field === 'field_ai_privacy_issues') {
          $capturedPrivacyIssues = $value;
        }
        return $media;
      }
    );

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['privacy_flag']);
    $this->assertSame([
      'readable ID document visible',
      'identifiable person visible',
    ], $data['privacy_issues']);
    $this->assertTrue($data['privacy_handled_by_blur']);
    $this->assertTrue($capturedPrivacyFlag);
    $this->assertSame('readable ID document visible, identifiable person visible', $capturedPrivacyIssues);
  }

  /**
   * A privacy flag without classifiable issues stays fail-closed.
   */
  public function testSuccessfulBlurKeepsUnclassifiedPrivacyFlagUnpublished(): void {
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

    $media->expects($this->never())->method('setPublished');

    $capturedPrivacyFlag = NULL;
    $capturedPrivacyIssues = NULL;
    $media->method('set')->willReturnCallback(
      function (string $field, $value) use (&$capturedPrivacyFlag, &$capturedPrivacyIssues, $media) {
        if ($field === 'field_ai_privacy_flag') {
          $capturedPrivacyFlag = $value;
        }
        if ($field === 'field_ai_privacy_issues') {
          $capturedPrivacyIssues = $value;
        }
        return $media;
      }
    );

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['privacy_flag']);
    $this->assertSame([
      'AI privacy flag set without a classifiable privacy issue.',
    ], $data['privacy_issues']);
    $this->assertTrue($data['privacy_handled_by_blur']);
    $this->assertTrue($capturedPrivacyFlag);
    $this->assertSame(
      'AI privacy flag set without a classifiable privacy issue.',
      $capturedPrivacyIssues
    );
  }

  /**
   * No blur means the citizen notice stays visible.
   *
   * When the blur service blurred nothing (no blur_results), the deterministic
   * signal is FALSE, so the privacy notice is shown — independent of the model.
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
   * A successfully blurred media publishes when the AI clears privacy_flag.
   *
   * The smoke showed the model inconsistently sets privacy_flag=false on a
   * blurred face. A successful blur overwrite means the exported/public media
   * is already anonymised, so the blur fact alone must not block GeoReport
   * media_url.
   */
  public function testBlurredMediaPublishesWhenAiClearsPrivacy(): void {
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

    $this->imageProcessingService->expects($this->once())
      ->method('saveBlurredImage')
      ->with($media, 'blurred-bytes', 'public://test.jpg');

    // Successful anonymisation plus no residual privacy finding publishes.
    $media->expects($this->once())->method('setPublished');

    // Capture the persisted moderation flag. This is the field the node hook
    // reads, so it must not fold in successful blur without a residual privacy
    // finding.
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
    // Citizen notice suppressed (blur ran); media published (asserted).
    $this->assertTrue($data['privacy_handled_by_blur']);
    // field_ai_privacy_flag follows the AI verdict, not the blur fact.
    $this->assertFalse($capturedPrivacyFlag);
  }

  /**
   * Tests re-analysis never republishes media under manual publication control.
   *
   * Media whose publication state was explicitly set through the GeoReport
   * media publication API carries a mark in the
   * markaspot_open311.media_publication_manual keyvalue collection. That
   * editorial decision is authoritative: even when the AI verdict clears
   * privacy, a re-analysis must not flip the state back (WBD #131).
   */
  public function testManualPublicationDecisionSurvivesReanalysis(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    // Publication of media 1 is under explicit editorial control.
    $this->manualPublicationMarks[1] = TRUE;

    // AI clears privacy — without the manual mark this would publish.
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
      ->willReturn(['ai_result' => json_encode($aiResult)]);

    // The editorial decision wins: no automatic republish on re-analysis.
    $media->expects($this->never())->method('setPublished');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Mixed batch: successfully anonymised and clean media both publish.
   *
   * Locks the grain distinction: the publish guard is per media and follows
   * residual privacy findings, not the batch-level blur signal.
   */
  public function testMixedBatchPublishesBlurredAndCleanMediaWithoutResidualPrivacy(): void {
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

    // Both media are safe after analysis; blur alone is not a hold.
    $blurredMedia->expects($this->once())->method('setPublished');
    $cleanMedia->expects($this->once())->method('setPublished');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1', 'uuid-2']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    // Batch-level signal is OR: blur ran somewhere in the batch.
    $this->assertTrue($data['privacy_handled_by_blur']);
  }

  /**
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
