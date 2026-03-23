<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Tests the provider-agnostic AI HTTP client service.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\AiClientService
 */
class AiClientServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_ai\Service\AiClientService
   */
  protected AiClientService $service;

  /**
   * The mocked HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * The mocked config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The mocked token tracking service.
   *
   * @var \Drupal\markaspot_ai\Service\TokenTrackingService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $tokenTracking;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->tokenTracking = $this->createMock(TokenTrackingService::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'default_provider' => 'openai',
          'providers.openai' => [
            'api_url' => 'https://api.openai.com/v1',
            'auth_type' => 'bearer',
            'api_key' => 'test-key-123',
            'chat_model' => 'gpt-4o',
            'embedding_model' => 'text-embedding-3-large',
          ],
          'providers.azure' => [
            'api_url' => 'https://my-azure.openai.azure.com/v1',
            'auth_type' => 'api_key_header',
            'api_key' => 'azure-key-456',
            'chat_model' => 'gpt-4o',
          ],
          'providers.local' => [
            'api_url' => 'http://localhost:11434/v1',
            'auth_type' => 'none',
            'chat_model' => 'llama3',
          ],
          default => NULL,
        };
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($this->config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_ai')
      ->willReturn($this->logger);

    $this->service = $this->getMockBuilder(AiClientService::class)
      ->setConstructorArgs([
        $this->httpClient,
        $configFactory,
        $loggerFactory,
        $this->tokenTracking,
      ])
      ->onlyMethods(['wait'])
      ->getMock();
    $this->service->method('wait')->willReturnCallback(function () {});
  }

  /**
   * Tests bearer auth header.
   *
   * @covers ::buildAuthHeaders
   */
  public function testBearerAuthHeader(): void {
    $headers = $this->service->buildAuthHeaders('bearer', 'my-key');

    $this->assertEquals('Bearer my-key', $headers['Authorization']);
    $this->assertEquals('application/json', $headers['Content-Type']);
    $this->assertEquals('application/json', $headers['Accept']);
  }

  /**
   * Tests API key header auth.
   *
   * @covers ::buildAuthHeaders
   */
  public function testApiKeyHeader(): void {
    $headers = $this->service->buildAuthHeaders('api_key_header', 'my-key');

    $this->assertEquals('my-key', $headers['api-key']);
    $this->assertArrayNotHasKey('Authorization', $headers);
  }

  /**
   * Tests no auth header.
   *
   * @covers ::buildAuthHeaders
   */
  public function testNoAuthHeader(): void {
    $headers = $this->service->buildAuthHeaders('none', 'my-key');

    $this->assertArrayNotHasKey('Authorization', $headers);
    $this->assertArrayNotHasKey('api-key', $headers);
    $this->assertEquals('application/json', $headers['Content-Type']);
  }

  /**
   * Tests empty API key does not add auth header.
   *
   * @covers ::buildAuthHeaders
   */
  public function testEmptyApiKeyNoAuthHeader(): void {
    $headers = $this->service->buildAuthHeaders('bearer', '');
    $this->assertArrayNotHasKey('Authorization', $headers);

    $headers = $this->service->buildAuthHeaders('api_key_header', '');
    $this->assertArrayNotHasKey('api-key', $headers);
  }

  /**
   * Tests successful chat completion.
   *
   * @covers ::chat
   */
  public function testChatSuccess(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $apiResponse = [
      'choices' => [
        ['message' => ['content' => 'Hello!']],
      ],
      'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
      'model' => 'gpt-4o',
    ];

    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], json_encode($apiResponse)));

    $result = $this->service->chat([
      ['role' => 'user', 'content' => 'Hi'],
    ]);

    $this->assertEquals($apiResponse, $result);
  }

  /**
   * Tests that chat throws when token limit is exceeded.
   *
   * @covers ::chat
   */
  public function testChatBlockedByTokenLimit(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(FALSE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Daily token limit exceeded');

    $this->service->chat([
      ['role' => 'user', 'content' => 'Hi'],
    ]);
  }

  /**
   * Tests chat with optional parameters.
   *
   * @covers ::chat
   */
  public function testChatWithOptionalParameters(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        $this->stringContains('/chat/completions'),
        $this->callback(function (array $options) {
          $payload = $options['json'];
          return $payload['temperature'] === 0.5
            && $payload['max_tokens'] === 100
            && $payload['response_format'] === ['type' => 'json_object']
            && $payload['top_p'] === 0.9;
        })
      )
      ->willReturn(new Response(200, [], json_encode(['choices' => []])));

    $this->service->chat(
      [['role' => 'user', 'content' => 'test']],
      [
        'temperature' => 0.5,
        'max_tokens' => 100,
        'response_format' => ['type' => 'json_object'],
        'top_p' => 0.9,
      ]
    );
  }

  /**
   * Tests chat with provider override.
   *
   * @covers ::chat
   */
  public function testChatWithProviderOverride(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://my-azure.openai.azure.com/v1/chat/completions',
        $this->callback(function (array $options) {
          return isset($options['headers']['api-key']);
        })
      )
      ->willReturn(new Response(200, [], json_encode(['choices' => []])));

    $this->service->chat(
      [['role' => 'user', 'content' => 'test']],
      ['provider' => 'azure']
    );
  }

  /**
   * Tests successful embedding generation.
   *
   * @covers ::embed
   */
  public function testEmbedSuccess(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $apiResponse = [
      'data' => [['embedding' => [0.1, 0.2, 0.3]]],
      'usage' => ['prompt_tokens' => 5],
      'model' => 'text-embedding-3-large',
    ];

    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], json_encode($apiResponse)));

    $result = $this->service->embed('test text');

    $this->assertEquals($apiResponse, $result);
  }

  /**
   * Tests embed with dimensions parameter.
   *
   * @covers ::embed
   */
  public function testEmbedWithDimensions(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        $this->stringContains('/embeddings'),
        $this->callback(function (array $options) {
          return $options['json']['dimensions'] === 256;
        })
      )
      ->willReturn(new Response(200, [], json_encode(['data' => []])));

    $this->service->embed('test', ['dimensions' => 256]);
  }

  /**
   * Tests embed blocked by token limit.
   *
   * @covers ::embed
   */
  public function testEmbedBlockedByTokenLimit(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(FALSE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Daily token limit exceeded');

    $this->service->embed('test');
  }

  /**
   * Tests successful execution on first attempt.
   *
   * @covers ::executeWithRetry
   */
  public function testRetrySuccessOnFirstAttempt(): void {
    $result = $this->service->executeWithRetry(function () {
      return 'success';
    });

    $this->assertEquals('success', $result);
  }

  /**
   * Tests retry on retryable error.
   *
   * @covers ::executeWithRetry
   */
  public function testRetryOnRetryableError(): void {
    $attempts = 0;
    $result = $this->service->executeWithRetry(function () use (&$attempts) {
      $attempts++;
      if ($attempts < 2) {
        throw new \Exception('timeout', 0);
      }
      return 'recovered';
    }, 3);

    $this->assertEquals('recovered', $result);
    $this->assertEquals(2, $attempts);
  }

  /**
   * Tests retry exhaustion throws exception.
   *
   * @covers ::executeWithRetry
   */
  public function testRetryExhaustion(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('AI API request failed after 3 attempts');

    $this->service->executeWithRetry(function () {
      throw new \Exception('connection refused', 0);
    }, 3);
  }

  /**
   * Tests non-retryable error fails immediately.
   *
   * @covers ::executeWithRetry
   */
  public function testNonRetryableErrorFailsImmediately(): void {
    $attempts = 0;

    try {
      $this->service->executeWithRetry(function () use (&$attempts) {
        $attempts++;
        throw new \Exception('Invalid API key', 401);
      }, 3);
    }
    catch (\Exception $e) {
      // Expected.
    }

    // Non-retryable 401 should not trigger retries.
    $this->assertEquals(1, $attempts);
  }

  /**
   * Tests rate limit (429) is retryable.
   *
   * @covers ::executeWithRetry
   */
  public function testRateLimitIsRetryable(): void {
    $attempts = 0;

    $result = $this->service->executeWithRetry(function () use (&$attempts) {
      $attempts++;
      if ($attempts < 2) {
        throw new \Exception('Rate limited', 429);
      }
      return 'ok';
    }, 3);

    $this->assertEquals('ok', $result);
    $this->assertEquals(2, $attempts);
  }

  /**
   * Tests server errors (500, 502, 503, 504) are retryable.
   *
   * @covers ::executeWithRetry
   *
   * @dataProvider retryableStatusCodesProvider
   */
  public function testServerErrorsAreRetryable(int $code): void {
    $attempts = 0;
    $result = $this->service->executeWithRetry(function () use (&$attempts, $code) {
      $attempts++;
      if ($attempts < 2) {
        throw new \Exception('Server error', $code);
      }
      return 'recovered';
    }, 3);

    $this->assertEquals('recovered', $result);
    $this->assertEquals(2, $attempts);
  }

  /**
   * Data provider for retryable HTTP status codes.
   *
   * @return array
   *   Test cases with HTTP status codes.
   */
  public static function retryableStatusCodesProvider(): array {
    return [
      '429 Rate Limited' => [429],
      '500 Internal Server Error' => [500],
      '502 Bad Gateway' => [502],
      '503 Service Unavailable' => [503],
      '504 Gateway Timeout' => [504],
    ];
  }

  /**
   * Tests connection errors are retryable by message pattern.
   *
   * @covers ::executeWithRetry
   *
   * @dataProvider retryableMessagePatternsProvider
   */
  public function testConnectionErrorsRetryable(string $message): void {
    $attempts = 0;
    $result = $this->service->executeWithRetry(function () use (&$attempts, $message) {
      $attempts++;
      if ($attempts < 2) {
        throw new \Exception($message, 0);
      }
      return 'recovered';
    }, 3);

    $this->assertEquals('recovered', $result);
  }

  /**
   * Data provider for retryable message patterns.
   *
   * @return array
   *   Test cases with error messages.
   */
  public static function retryableMessagePatternsProvider(): array {
    return [
      'timeout' => ['Connection timeout'],
      'connection reset' => ['Connection reset by peer'],
      'connection refused' => ['Connection refused'],
      'network unreachable' => ['Network is unreachable'],
      'temporarily unavailable' => ['Service temporarily unavailable'],
    ];
  }

  /**
   * Tests 429 rate limit response raises exception.
   *
   * @covers ::chat
   */
  public function testRateLimitResponseThrows(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $this->httpClient->method('request')
      ->willReturn(new Response(429, [], '{"error": "rate limited"}'));

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('AI API request failed after');

    $this->service->chat([['role' => 'user', 'content' => 'test']]);
  }

  /**
   * Tests API error response with structured error message.
   *
   * @covers ::chat
   */
  public function testApiErrorWithStructuredMessage(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $errorBody = json_encode([
      'error' => [
        'message' => 'Invalid model specified',
        'type' => 'invalid_request_error',
      ],
    ]);

    $this->httpClient->method('request')
      ->willReturn(new Response(400, [], $errorBody));

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid model specified');

    $this->service->chat([['role' => 'user', 'content' => 'test']]);
  }

  /**
   * Tests malformed JSON response throws exception.
   *
   * @covers ::chat
   */
  public function testMalformedJsonResponseThrows(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], 'not-json'));

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Failed to decode API response');

    $this->service->chat([['role' => 'user', 'content' => 'test']]);
  }

  /**
   * Tests Guzzle exception wrapping.
   *
   * @covers ::chat
   */
  public function testGuzzleExceptionWrapped(): void {
    $this->tokenTracking->method('checkLimit')->willReturn(TRUE);

    $guzzleException = new ConnectException(
      'Connection refused',
      new Request('POST', 'https://api.openai.com/v1/chat/completions')
    );

    $this->httpClient->method('request')
      ->willThrowException($guzzleException);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('AI API request failed after');

    $this->service->chat([['role' => 'user', 'content' => 'test']]);
  }

}
