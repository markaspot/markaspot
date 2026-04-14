<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\NlpClientService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the NLP client service's ENV/config resolution.
 *
 * Per #309 schema, MARKASPOT_NLP_API_KEY / MARKASPOT_NLP_API_URL are the only
 * accepted ENV names. No deprecated alias exists, so the resolution path is
 * canonical ENV → config → (URL only) docker-internal default.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\NlpClientService
 */
class NlpClientServiceTest extends UnitTestCase {

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
   * ENV variable names touched by NLP resolution.
   */
  private const ENV_KEYS = [
    'MARKASPOT_NLP_API_KEY',
    'MARKASPOT_NLP_API_URL',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
    foreach (self::ENV_KEYS as $key) {
      $this->savedEnv[$key] = getenv($key);
      putenv($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
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
   * Builds a service with a config map for markaspot_ai.settings.
   *
   * @param array<string, mixed> $configMap
   *   The config keys returned by ImmutableConfig::get().
   */
  protected function createService(array $configMap = []): NlpClientService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) use ($configMap) {
        return $configMap[$key] ?? NULL;
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_ai')
      ->willReturn($this->logger);

    return new NlpClientService(
      $this->createMock(ClientInterface::class),
      $configFactory,
      $loggerFactory,
    );
  }

  /**
   * Invokes a protected method via reflection.
   */
  protected function invoke(NlpClientService $service, string $method): string {
    $reflection = new \ReflectionMethod($service, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($service, []);
  }

  /**
   * Tests resolveApiKey: canonical ENV wins over config.
   *
   * @covers ::resolveApiKey
   */
  public function testResolveApiKeyCanonicalEnvTakesPrecedence(): void {
    putenv('MARKASPOT_NLP_API_KEY=canonical-nlp-key');

    $service = $this->createService([
      'nlp_service.api_key' => 'config-key',
    ]);

    $this->assertSame('canonical-nlp-key', $this->invoke($service, 'resolveApiKey'));
  }

  /**
   * Tests resolveApiKey: config fallback when no ENV is set.
   *
   * @covers ::resolveApiKey
   */
  public function testResolveApiKeyConfigFallback(): void {
    $service = $this->createService([
      'nlp_service.api_key' => 'config-key',
    ]);

    $this->assertSame('config-key', $this->invoke($service, 'resolveApiKey'));
  }

  /**
   * Tests resolveApiKey: returns empty when nothing is configured.
   *
   * @covers ::resolveApiKey
   */
  public function testResolveApiKeyEmptyWhenNothingConfigured(): void {
    $service = $this->createService();
    $this->assertSame('', $this->invoke($service, 'resolveApiKey'));
  }

  /**
   * Tests getServiceUrl: canonical ENV wins.
   *
   * @covers ::getServiceUrl
   */
  public function testGetServiceUrlCanonicalEnvTakesPrecedence(): void {
    putenv('MARKASPOT_NLP_API_URL=https://nlp.cloud.mark-a-spot.com');

    $service = $this->createService([
      'nlp_service.url' => 'http://config.example/nlp',
    ]);

    $this->assertSame(
      'https://nlp.cloud.mark-a-spot.com',
      $this->invoke($service, 'getServiceUrl')
    );
  }

  /**
   * Tests getServiceUrl: config fallback when no ENV is set.
   *
   * @covers ::getServiceUrl
   */
  public function testGetServiceUrlConfigFallback(): void {
    $service = $this->createService([
      'nlp_service.url' => 'http://config.example/nlp',
    ]);

    $this->assertSame('http://config.example/nlp', $this->invoke($service, 'getServiceUrl'));
  }

  /**
   * Tests getServiceUrl: docker-internal default when nothing is configured.
   *
   * @covers ::getServiceUrl
   */
  public function testGetServiceUrlDockerInternalDefault(): void {
    $service = $this->createService();
    $this->assertSame('http://markaspot-nlp:8100', $this->invoke($service, 'getServiceUrl'));
  }

}
