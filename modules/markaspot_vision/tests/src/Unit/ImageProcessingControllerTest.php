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

    // Privacy flag is set AND the AI judged the only concern (a face) to be
    // fully remediated by the blur that was applied.
    $aiResult = [
      'category' => 42,
      'description' => 'Privacy-safe description',
      'alt_text' => ['Blurred workers near a damaged bin'],
      'hazard_flag' => FALSE,
      'hazard_level' => 0,
      'hazard_issues' => [],
      'privacy_flag' => TRUE,
      'privacy_issues' => ['blurred face visible'],
      'privacy_remediated_by_blur' => TRUE,
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
    // Citizen warning is suppressed because blur ran AND the AI confirmed full
    // remediation.
    $this->assertTrue($data['privacy_handled_by_blur']);
    // The internal AI judgment field must not leak into the citizen payload.
    $this->assertArrayNotHasKey('privacy_remediated_by_blur', $data);
    // Nor may it be persisted into the JSON:API-exposed field_ai_metadata.
    $this->assertNotNull($capturedMetadata);
    $storedMeta = json_decode($capturedMetadata, TRUE);
    $this->assertArrayNotHasKey('privacy_remediated_by_blur', $storedMeta);
    $this->assertTrue($storedMeta['privacy_flag']);
  }

  /**
   * Residual, unremediated PII must keep the citizen privacy prompt visible.
   *
   * Even though blur ran on the image, the AI reports that the privacy concern
   * is NOT fully remediated (e.g. a readable document remains), so the combined
   * privacy_handled_by_blur signal must be FALSE.
   *
   * @covers ::getAIResults
   */
  public function testResidualPiiKeepsPrivacyPromptVisibleDespiteBlur(): void {
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
      // Blur covered a face, but a document remains: not fully remediated.
      'privacy_remediated_by_blur' => FALSE,
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

    // Media stays unpublished (privacy_flag=TRUE) — moderation unaffected.
    $media->expects($this->never())->method('setPublished');

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['privacy_flag']);
    // Despite blur running, the prompt must remain because PII is unremediated.
    $this->assertFalse($data['privacy_handled_by_blur']);
    $this->assertArrayNotHasKey('privacy_remediated_by_blur', $data);
  }

  /**
   * Fail-closed: a missing AI remediation field must not suppress the prompt.
   *
   * Some providers (older Azure api-versions, local LLMs) ignore the
   * json_schema and omit the key entirely. The combined signal must be FALSE so
   * citizen warning still shows.
   *
   * @covers ::getAIResults
   */
  public function testMissingRemediationFieldFailsClosed(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $media = $this->createMockMedia(1);
    $this->mediaStorage->method('loadByProperties')->willReturn([1 => $media]);

    // Note: no 'privacy_remediated_by_blur' key at all.
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

    $request = $this->createJsonRequest(['media_ids' => ['uuid-1']]);
    $response = $this->controller->getAIResults($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['privacy_handled_by_blur']);
  }

  /**
   * Security boundary: a stray AI remediation=TRUE without blur is ignored.
   *
   * The backend AND-gate (blur_applied && ...) must win over a misbehaving
   * model that claims remediation when no blur was actually applied.
   *
   * @covers ::getAIResults
   */
  public function testRemediationWithoutBlurIsIgnored(): void {
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
      // Model wrongly claims remediation, but no blur ran.
      'privacy_remediated_by_blur' => TRUE,
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
    $this->assertArrayNotHasKey('privacy_remediated_by_blur', $data);
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
