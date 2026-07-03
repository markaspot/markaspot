<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the image processing service for AI vision APIs.
 */
#[CoversClass(ImageProcessingService::class)]
#[Group('markaspot_vision')]
class ImageProcessingServiceTest extends UnitTestCase {

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * Saved ENV variables to restore after each test.
   *
   * @var array<string, string|false>
   */
  protected array $savedEnv = [];

  /**
   * ENV variable names that override config in getApiConfig/resolveApiKey.
   */
  private const ENV_KEYS = [
    'MARKASPOT_VISION_API_KEY',
    'MARKASPOT_VISION_API_URL',
    'MARKASPOT_VISION_AUTH_TYPE',
    'OPENAI_API_KEY',
    'VISION_BLUR_URL',
    'MARKASPOT_BLUR_API_KEY',
    'MARKASPOT_BLUR_URL',
    'AI_API_KEY',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
    // Isolate tests from host ENV: save and clear vision-related variables.
    foreach (self::ENV_KEYS as $key) {
      $this->savedEnv[$key] = getenv($key);
      putenv($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Restore original ENV variables.
    foreach ($this->savedEnv as $key => $value) {
      if ($value === FALSE) {
        putenv($key);
      }
      else {
        putenv("$key=$value");
      }
    }
    parent::tearDown();
  }

  /**
   * Creates a service instance with mocked dependencies.
   *
   * @param array $configValues
   *   Config values for markaspot_vision.settings.
   *
   * @return \Drupal\markaspot_vision\Service\ImageProcessingService
   *   The service instance.
   */
  protected function createService(array $configValues = []): ImageProcessingService {
    $defaults = [
      'auth_type' => 'bearer',
      'api_key' => 'test-key',
      'api_url' => 'https://api.openai.com/v1/chat/completions',
      'ai_model' => 'gpt-4o',
      'image_prompt' => 'Analyze this image. Categories: {categories}. Language: {language}.',
      'temperature' => 0.7,
      'top_p' => NULL,
      'max_tokens' => 300,
      'system_prompt' => '',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'http://markaspot-vision:8200/blur',
    ];

    $configValues = array_merge($defaults, $configValues);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) use ($configValues) {
        return $configValues[$key] ?? NULL;
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_vision.settings')
      ->willReturn($config);

    $httpClient = $this->createMock(ClientInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_vision')
      ->willReturn($this->logger);

    return new ImageProcessingService(
      $httpClient,
      $configFactory,
      $entityTypeManager,
      $this->fileSystem,
      $loggerFactory,
    );
  }

  /**
   * Invokes a protected or private method on an object.
   *
   * @param object $object
   *   The object to invoke the method on.
   * @param string $methodName
   *   The method name.
   * @param array $parameters
   *   The method parameters.
   *
   * @return mixed
   *   The return value.
   */
  protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed {
    $reflection = new \ReflectionMethod($object, $methodName);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($object, $parameters);
  }

  /**
   * Tests bearer auth config.
   */
  public function testGetApiConfigBearer(): void {
    $service = $this->createService([
      'auth_type' => 'bearer',
      'api_key' => 'sk-test123',
      'api_url' => 'https://api.openai.com/v1/chat/completions',
      'ai_model' => 'gpt-4o',
    ]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'auth_type' => 'bearer',
          'api_key' => 'sk-test123',
          'api_url' => 'https://api.openai.com/v1/chat/completions',
          'ai_model' => 'gpt-4o',
          default => NULL,
        };
      });

    $result = $this->invokeMethod($service, 'getApiConfig', [$config]);

    $this->assertEquals('https://api.openai.com/v1/chat/completions', $result['url']);
    $this->assertEquals('gpt-4o', $result['model']);
    $this->assertEquals('Bearer sk-test123', $result['headers']['Authorization']);
    $this->assertEquals('application/json', $result['headers']['Content-Type']);
  }

  /**
   * Tests API key header auth config.
   */
  public function testGetApiConfigApiKeyHeader(): void {
    $service = $this->createService();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'auth_type' => 'api_key_header',
          'api_key' => 'azure-key-456',
          'api_url' => 'https://my-azure.openai.azure.com/v1',
          'ai_model' => 'gpt-4o',
          default => NULL,
        };
      });

    $result = $this->invokeMethod($service, 'getApiConfig', [$config]);

    $this->assertEquals('azure-key-456', $result['headers']['api-key']);
    $this->assertArrayNotHasKey('Authorization', $result['headers']);
  }

  /**
   * Tests no auth config.
   */
  public function testGetApiConfigNoAuth(): void {
    $service = $this->createService();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'auth_type' => 'none',
          'api_key' => '',
          'api_url' => 'http://localhost:11434/v1/chat/completions',
          'ai_model' => 'llama3',
          default => NULL,
        };
      });

    $result = $this->invokeMethod($service, 'getApiConfig', [$config]);

    $this->assertArrayNotHasKey('Authorization', $result['headers']);
    $this->assertArrayNotHasKey('api-key', $result['headers']);
    $this->assertEquals('llama3', $result['model']);
  }

  /**
   * Tests request payload structure.
   */
  public function testPrepareRequestPayloadStructure(): void {
    $service = $this->createService([
      'temperature' => 0.7,
      'top_p' => NULL,
      'max_tokens' => 300,
    ]);

    $messages = [
      ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'test']]],
    ];
    $apiConfig = ['model' => 'gpt-4o'];

    $payload = $this->invokeMethod($service, 'prepareRequestPayload', [$messages, $apiConfig]);

    $this->assertEquals($messages, $payload['messages']);
    $this->assertEquals('gpt-4o', $payload['model']);
    $this->assertArrayHasKey('response_format', $payload);
    $this->assertEquals('json_schema', $payload['response_format']['type']);
    $this->assertEquals(0.7, $payload['temperature']);
    $this->assertEquals(300, $payload['max_tokens']);
  }

  /**
   * Tests payload omits max_tokens for vision models.
   */
  public function testPrepareRequestPayloadVisionModelNoMaxTokens(): void {
    $service = $this->createService([
      'max_tokens' => 300,
    ]);

    $messages = [];
    $apiConfig = ['model' => 'gpt-4-vision-preview'];

    $payload = $this->invokeMethod($service, 'prepareRequestPayload', [$messages, $apiConfig]);

    $this->assertArrayNotHasKey('max_tokens', $payload);
  }

  /**
   * Tests JSON schema includes all required fields.
   */
  public function testPrepareRequestPayloadJsonSchemaFields(): void {
    $service = $this->createService();

    $payload = $this->invokeMethod($service, 'prepareRequestPayload', [[], ['model' => 'gpt-4o']]);

    $schema = $payload['response_format']['json_schema']['schema'];
    $required = $schema['required'];

    $this->assertContains('category', $required);
    $this->assertContains('description', $required);
    $this->assertContains('alt_text', $required);
    $this->assertContains('hazard_flag', $required);
    $this->assertContains('hazard_level', $required);
    $this->assertContains('hazard_issues', $required);
    $this->assertContains('privacy_flag', $required);
    $this->assertContains('privacy_issues', $required);
    // Per-image privacy attribution (WBD #137).
    $this->assertContains('privacy_image_flags', $required);
    $this->assertArrayHasKey('privacy_image_flags', $schema['properties']);
    $this->assertSame('boolean', $schema['properties']['privacy_image_flags']['items']['type']);

    // Verify hazard_level and hazard_category are in properties.
    $this->assertArrayHasKey('hazard_level', $schema['properties']);
    $this->assertArrayHasKey('hazard_category', $schema['properties']);
  }

  /**
   * The privacy instruction demands an exact-length per-image flag array.
   */
  public function testPrivacyInstructionRequestsPerImageAttribution(): void {
    $service = $this->createService();
    $instruction = $this->invokeMethod($service, 'buildPrivacyInstruction', [FALSE, 3]);
    $this->assertStringContainsString('privacy_image_flags', $instruction);
    $this->assertStringContainsString('exactly 3 booleans', $instruction);
    // Without a count the wording stays generic but still demands the array.
    $generic = $this->invokeMethod($service, 'buildPrivacyInstruction', [FALSE]);
    $this->assertStringContainsString('privacy_image_flags', $generic);
    $this->assertStringNotContainsString('exactly', $generic);
  }

  /**
   * Tests language name resolution.
   */
  #[DataProvider('languageNameProvider')]
  public function testResolveLanguageName(?string $langcode, string $expected): void {
    $service = $this->createService();
    $result = $this->invokeMethod($service, 'resolveLanguageName', [$langcode]);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for language name resolution.
   *
   * @return array
   *   Test cases.
   */
  public static function languageNameProvider(): array {
    return [
      'German' => ['de', 'German'],
      'English' => ['en', 'English'],
      'French' => ['fr', 'French'],
      'Dutch' => ['nl', 'Dutch'],
      'Spanish' => ['es', 'Spanish'],
      'Italian' => ['it', 'Italian'],
      'Portuguese' => ['pt', 'Portuguese'],
      'Polish' => ['pl', 'Polish'],
      'Danish' => ['da', 'Danish'],
      'Turkish' => ['tr', 'Turkish'],
      'Ukrainian' => ['uk', 'Ukrainian'],
      'Arabic' => ['ar', 'Arabic'],
      'Unknown defaults to English' => ['xx', 'English'],
      'NULL defaults to English' => [NULL, 'English'],
    ];
  }

  /**
   * The privacy instruction must always carry the deterministic flag policy.
   *
   * Internal moderation (depublishing) keys off privacy_flag, so the prompt
   * must instruct the AI to set it without depending on a tenant-configured
   * privacy policy that may be empty.
   */
  public function testBuildPrivacyInstructionAlwaysCarriesBaselinePolicy(): void {
    $service = $this->createService();

    foreach ([TRUE, FALSE] as $blurApplied) {
      $instruction = $this->invokeMethod($service, 'buildPrivacyInstruction', [$blurApplied]);

      // Deterministic detection criteria must be present and self-contained.
      $this->assertStringContainsString('set privacy_flag to true', $instruction);
      $this->assertStringContainsString('faces, license plates', $instruction);
      // It must NOT delegate the policy to a (maybe empty) configured prompt.
      $this->assertStringNotContainsString('configured prompt', $instruction);
      // It must never tell the model to suppress the moderation flag.
      $this->assertStringNotContainsString('Do not set privacy_flag', $instruction);
    }
  }

  /**
   * With blur applied, the AI must only flag residual privacy issues.
   *
   * The citizen-facing suppression is decided deterministically by the
   * controller from the blur result, NOT by the model. Successful blur alone is
   * safe to publish, but visible or insufficiently anonymised PII must still be
   * held for review.
   */
  public function testBuildPrivacyInstructionOnlyFlagsResidualIssuesWhenBlurred(): void {
    $service = $this->createService();

    $instruction = $this->invokeMethod($service, 'buildPrivacyInstruction', [TRUE]);

    $this->assertStringContainsString('blurred by preprocessing', $instruction);
    $this->assertStringContainsString('Already blurred regions alone are not privacy concerns', $instruction);
    $this->assertStringContainsString('remains visible, readable, or insufficiently anonymised', $instruction);
    $this->assertStringNotContainsString('including the already blurred regions', $instruction);
    // The model must NOT be asked to self-report a blur remediation verdict.
    $this->assertStringNotContainsString('privacy_remediated_by_blur', $instruction);
  }

  /**
   * Without blur, the prompt carries no blur-specific language.
   */
  public function testBuildPrivacyInstructionHasNoBlurLanguageWithoutBlur(): void {
    $service = $this->createService();

    $instruction = $this->invokeMethod($service, 'buildPrivacyInstruction', [FALSE]);

    $this->assertStringContainsString('set privacy_flag to true', $instruction);
    $this->assertStringNotContainsString('blurred by preprocessing', $instruction);
    $this->assertStringNotContainsString('privacy_remediated_by_blur', $instruction);
  }

  /**
   * The reportability instruction defines both true and false cases.
   */
  public function testBuildReportabilityInstructionCoversBothCases(): void {
    $service = $this->createService();

    $instruction = $this->invokeMethod($service, 'buildReportabilityInstruction', []);

    $this->assertStringContainsString('is_reportable_issue', $instruction);
    // True case: a real public-space issue.
    $this->assertStringContainsString('reportable public-space issue', $instruction);
    // False case: off-domain content.
    $this->assertStringContainsString('portrait or selfie', $instruction);
    // It must NOT block: best-guess category is still returned.
    $this->assertStringContainsString('best-guess category', $instruction);
  }

  /**
   * Disabled blur preprocessing returns the original image.
   */
  public function testBlurSensitiveAreasReturnsOriginalWhenDisabled(): void {
    $service = $this->createService([
      'enable_blur_preprocessing' => FALSE,
    ]);

    $result = $service->blurSensitiveAreas('image-bytes', 'image/jpeg');

    $this->assertSame('image-bytes', $result['contents']);
    $this->assertFalse($result['blurred']);
  }

  /**
   * Enabled blur preprocessing is fail-closed when no URL is configured.
   */
  public function testBlurSensitiveAreasThrowsWhenEnabledWithoutUrl(): void {
    $this->logger->expects($this->once())->method('error');

    $service = $this->createService([
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => '',
    ]);

    $this->expectException(\RuntimeException::class);
    $service->blurSensitiveAreas('image-bytes', 'image/jpeg');
  }

  /**
   * Persisting blurred images fails closed for non-public URIs.
   */
  public function testSaveBlurredImageThrowsForNonPublicUri(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(123);

    $service = $this->createService();

    $this->expectException(\RuntimeException::class);
    $service->saveBlurredImage($media, 'blurred-bytes', 'private://test.jpg');
  }

  /**
   * Blur URLs are logged without credentials or query strings.
   */
  public function testRedactUrlForLogRemovesSecrets(): void {
    $service = $this->createService();

    $result = $this->invokeMethod($service, 'redactUrlForLog', [
      'https://user:pass@example.com:9443/blur?token=secret',
    ]);

    $this->assertSame('https://example.com:9443/blur', $result);
  }

  /**
   * Tests resolveBlurBearer: canonical MARKASPOT_BLUR_API_KEY wins.
   */
  public function testResolveBlurBearerCanonicalEnvTakesPrecedence(): void {
    putenv('MARKASPOT_BLUR_API_KEY=canonical-bearer');
    putenv('AI_API_KEY=legacy-bearer');

    $this->logger->expects($this->never())->method('warning');

    $service = $this->createService();
    $bearer = $this->invokeMethod($service, 'resolveBlurBearer');

    $this->assertSame('canonical-bearer', $bearer);
  }

  /**
   * Tests resolveBlurBearer: legacy AI_API_KEY fires deprecation warning.
   */
  public function testResolveBlurBearerLegacyEnvTriggersWarning(): void {
    putenv('AI_API_KEY=legacy-bearer');

    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('Deprecated ENV AI_API_KEY'));

    $service = $this->createService();
    $bearer = $this->invokeMethod($service, 'resolveBlurBearer');

    $this->assertSame('legacy-bearer', $bearer);
  }

  /**
   * Tests resolveBlurBearer: returns empty when no ENV is set.
   */
  public function testResolveBlurBearerEmptyWhenNoEnv(): void {
    // setUp has cleared both MARKASPOT_BLUR_API_KEY and AI_API_KEY.
    $this->logger->expects($this->never())->method('warning');

    $service = $this->createService();
    $bearer = $this->invokeMethod($service, 'resolveBlurBearer');

    $this->assertSame('', $bearer);
  }

  /**
   * Tests resolveBlurUrl: config value wins over both ENV names.
   */
  public function testResolveBlurUrlConfigTakesPrecedence(): void {
    putenv('MARKASPOT_BLUR_URL=https://canonical.example/blur');
    putenv('VISION_BLUR_URL=https://legacy.example/blur');

    $this->logger->expects($this->never())->method('warning');

    $service = $this->createService();
    $url = $this->invokeMethod($service, 'resolveBlurUrl', ['https://config.example/blur']);

    $this->assertSame('https://config.example/blur', $url);
  }

  /**
   * Tests resolveBlurUrl: canonical MARKASPOT_BLUR_URL wins over legacy.
   */
  public function testResolveBlurUrlCanonicalEnvTakesPrecedenceOverLegacy(): void {
    putenv('MARKASPOT_BLUR_URL=https://canonical.example/blur');
    putenv('VISION_BLUR_URL=https://legacy.example/blur');

    $this->logger->expects($this->never())->method('warning');

    $service = $this->createService();
    $url = $this->invokeMethod($service, 'resolveBlurUrl', [NULL]);

    $this->assertSame('https://canonical.example/blur', $url);
  }

  /**
   * Tests resolveBlurUrl: legacy VISION_BLUR_URL fires deprecation warning.
   */
  public function testResolveBlurUrlLegacyEnvTriggersWarning(): void {
    putenv('VISION_BLUR_URL=https://legacy.example/blur');

    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('Deprecated ENV VISION_BLUR_URL'));

    $service = $this->createService();
    $url = $this->invokeMethod($service, 'resolveBlurUrl', [NULL]);

    $this->assertSame('https://legacy.example/blur', $url);
  }

  /**
   * Tests resolveBlurUrl: returns empty when nothing is configured.
   */
  public function testResolveBlurUrlReturnsEmptyWhenNothingConfigured(): void {
    $this->logger->expects($this->never())->method('warning');

    $service = $this->createService();
    $url = $this->invokeMethod($service, 'resolveBlurUrl', [NULL]);

    $this->assertSame('', $url);
  }

  /**
   * Tests resolveBlurUrl: empty config string is treated as not-set.
   */
  public function testResolveBlurUrlEmptyConfigFallsThroughToEnv(): void {
    putenv('MARKASPOT_BLUR_URL=https://canonical.example/blur');

    $service = $this->createService();
    $url = $this->invokeMethod($service, 'resolveBlurUrl', ['']);

    $this->assertSame('https://canonical.example/blur', $url);
  }

}
