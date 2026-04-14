<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the image processing service for AI vision APIs.
 *
 * @group markaspot_vision
 * @coversDefaultClass \Drupal\markaspot_vision\Service\ImageProcessingService
 */
class ImageProcessingServiceTest extends UnitTestCase {

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

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
    $fileSystem = $this->createMock(FileSystemInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_vision')
      ->willReturn($this->logger);

    return new ImageProcessingService(
      $httpClient,
      $configFactory,
      $entityTypeManager,
      $fileSystem,
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
   *
   * @covers ::getApiConfig
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
   *
   * @covers ::getApiConfig
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
   *
   * @covers ::getApiConfig
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
   *
   * @covers ::prepareRequestPayload
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
   *
   * @covers ::prepareRequestPayload
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
   *
   * @covers ::prepareRequestPayload
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

    // Verify hazard_level and hazard_category are in properties.
    $this->assertArrayHasKey('hazard_level', $schema['properties']);
    $this->assertArrayHasKey('hazard_category', $schema['properties']);
  }

  /**
   * Tests language name resolution.
   *
   * @covers ::resolveLanguageName
   *
   * @dataProvider languageNameProvider
   */
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
   * Tests resolveBlurBearer: canonical MARKASPOT_BLUR_API_KEY wins.
   *
   * @covers ::resolveBlurBearer
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
   *
   * @covers ::resolveBlurBearer
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
   *
   * @covers ::resolveBlurBearer
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
   *
   * @covers ::resolveBlurUrl
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
   *
   * @covers ::resolveBlurUrl
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
   *
   * @covers ::resolveBlurUrl
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
   *
   * @covers ::resolveBlurUrl
   */
  public function testResolveBlurUrlReturnsEmptyWhenNothingConfigured(): void {
    $this->logger->expects($this->never())->method('warning');

    $service = $this->createService();
    $url = $this->invokeMethod($service, 'resolveBlurUrl', [NULL]);

    $this->assertSame('', $url);
  }

  /**
   * Tests resolveBlurUrl: empty config string is treated as not-set.
   *
   * @covers ::resolveBlurUrl
   */
  public function testResolveBlurUrlEmptyConfigFallsThroughToEnv(): void {
    putenv('MARKASPOT_BLUR_URL=https://canonical.example/blur');

    $service = $this->createService();
    $url = $this->invokeMethod($service, 'resolveBlurUrl', ['']);

    $this->assertSame('https://canonical.example/blur', $url);
  }

}
