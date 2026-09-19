<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Pins default wire payloads and provider capability policies without network.
 */
#[CoversClass(AiClientService::class)]
#[Group('markaspot_ai')]
class ModelPolicyTest extends UnitTestCase {

  /**
   * Captured HTTP request bodies.
   */
  private array $bodies = [];

  /**
   * Creates a client with a finite response queue and captures every request.
   */
  private function client(array $policy = [], array $responses = [], string $provider = 'openai'): AiClientService {
    $factory = $this->getConfigFactoryStub(['markaspot_ai.settings' => [
      'default_provider' => $provider,
      'providers' => [$provider => $policy + ['chat_model' => 'deployment', 'embedding_model' => 'vectors']],
    ]]);
    $tokens = $this->createMock(TokenTrackingService::class);
    $tokens->method('checkLimit')->willReturn(TRUE);
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn(new NullLogger());
    $http = $this->createMock(ClientInterface::class);
    $responses = $responses ?: [new Response(200, [], '{"choices":[{"message":{"content":"ok"}}]}')];
    $http->method('request')->willReturnCallback(function ($method, $url, $options) use (&$responses) {
      $this->bodies[] = $options['json'];
      self::assertNotEmpty($responses, 'No unplanned retries are allowed.');
      return array_shift($responses);
    });
    $client = $this->getMockBuilder(AiClientService::class)->setConstructorArgs([
      $http,
      $factory,
      $logger,
      $tokens,
    ])->onlyMethods([
      'wait',
    ])->getMock();
    return $client;
  }

  /**
   * Default keys, order, types and values remain identical.
   */
  public function testDefaultPayload(): void {
    $this->client()->chat([['role' => 'user', 'content' => 'Hello']], [
      'temperature' => 0.3,
      'max_tokens' => 150,
      'response_format' => ['type' => 'json_object'],
      'top_p' => 0.9,
    ]);
    self::assertSame([
      'model' => 'deployment',
      'messages' => [['role' => 'user', 'content' => 'Hello']],
      'temperature' => 0.3,
      'max_tokens' => 150,
      'response_format' => ['type' => 'json_object'],
      'top_p' => 0.9,
    ], $this->bodies[0]);
  }

  /**
   * Every configured token/format mode works independently of model names.
   */
  #[DataProvider('policies')]
  public function testPolicy(string $limit, string $format): void {
    $schema = ['type' => 'json_schema', 'json_schema' => ['name' => 'test']];
    $this->client([
      'send_temperature' => FALSE,
      'max_tokens_param' => $limit,
      'max_tokens_min' => 2048,
      'response_format_mode' => $format,
    ])->chat([], [

      'temperature' => 0.3, 'max_tokens' => 150, 'response_format' => $schema,
    ]);
    $body = $this->bodies[0];
    self::assertArrayNotHasKey('temperature', $body);
    if ($limit === 'none') {
      self::assertArrayNotHasKey('max_tokens', $body);
      self::assertArrayNotHasKey('max_completion_tokens', $body);
    }
    else {
      self::assertSame(2048, $body[$limit]);
    }
    if ($format === 'none') {
      self::assertArrayNotHasKey('response_format', $body);
    }
    else {
      self::assertSame($format === 'native' ? $schema : ['type' => 'json_object'], $body['response_format']);
    }
  }

  /**
   * Policy combinations.
   */
  public static function policies(): iterable {
    foreach (['max_tokens', 'max_completion_tokens', 'none'] as $limit) {
      foreach (['native', 'json_object', 'none'] as $format) {
        yield "$limit/$format" => [$limit, $format];
      }
    }
  }

  /**
   * Repairs each supported rejection exactly once, including a second 400.
   */
  #[DataProvider('rejections')]
  public function testRejectedParameter(string $parameter, mixed $value, bool $secondFails): void {
    $error = new Response(400, [], json_encode([
      'error' => [
        'message' => 'Unsupported parameter',
        'param' => $parameter,
        'code' => 'unsupported_parameter',
      ],
    ]));
    $client = $this->client([], [
      $error,
      $secondFails ? $error : new Response(200,
       [],
       '{"choices":[{"message":{"content":"ok"}}]}'),
    ]);
    try {
      $client->chat([], [$parameter => $value]);
      self::assertFalse($secondFails);
    }
    catch (\Exception $e) {
      self::assertTrue($secondFails);
      self::assertSame(400, $e->getPrevious()->getCode());
    }
    self::assertCount(2, $this->bodies);
    $body = $this->bodies[1];
    if ($parameter === 'max_tokens') {
      self::assertSame($value, $body['max_completion_tokens']);
      self::assertArrayNotHasKey('max_tokens', $body);
    }
    elseif ($parameter === 'response_format' && $value['type'] === 'json_schema') {
      self::assertSame(['type' => 'json_object'], $body[$parameter]);
    }
    else {
      self::assertArrayNotHasKey($parameter, $body);
    }
  }

  /**
   * Rejection cases.
   */
  public static function rejections(): iterable {
    foreach ([FALSE, TRUE] as $second) {
      foreach ([
        'temperature' => 0.3,
        'top_p' => 0.9,
        'max_tokens' => 150,
        'response_format' => [
          'type' => 'json_schema',
        ],
      ] as $key => $value) {
        yield [$key, $value, $second];
      }
      yield ['response_format', ['type' => 'json_object'], $second];
    }
  }

  /**
   * A transient retry does not reset the parameter-repair allowance.
   */
  public function testTransientRetriesKeepRepairBudget(): void {
    $error = new Response(400, [], '{"error":{"message":"Unsupported temperature"}}');
    $client = $this->client([], [$error, new Response(429), $error]);
    try {
      $client->chat([], ['temperature' => 0.3]);
      self::fail('Expected failure.');
    }
    catch (\Exception $e) {
      self::assertSame(400, $e->getPrevious()->getCode());
      self::assertCount(3, $this->bodies);
    }
  }

  /**
   * Empty output at the budget limit must fail through the existing path.
   */
  #[DataProvider('emptyResponses')]
  public function testEmptyBudget(string $provider, array $response): void {
    $client = $this->client([], [new Response(200, [], json_encode($response))], $provider);
    $this->expectExceptionMessage("providers.$provider.max_tokens_min");
    $client->chat([]);
  }

  /**
   * Both API response shapes, including whitespace-only content.
   */
  public static function emptyResponses(): iterable {
    yield ['openai', ['choices' => [['message' => ['content' => '  '], 'finish_reason' => 'length']]]];
    yield [
      'anthropic',
      [
        'content' => [
          [
            'type' => 'thinking',
            'thinking' => 'reasoning',
          ],
        ],
        'stop_reason' => 'max_tokens',
      ],
    ];
  }

  /**
   * Anthropic keeps its token parameter and supports temperature repair.
   */
  public function testAnthropicPolicyAndRepair(): void {
    $client = $this->client(['max_tokens_param' => 'none', 'max_tokens_min' => 4096], [
      new Response(400, [], '{"error":{"message":"temperature is not supported","type":"invalid_request_error"}}'),
      new Response(200, [], '{"content":[{"type":"text","text":"ok"}]}'),
    ], 'anthropic');
    self::assertSame('ok', $client->chat([], [
      'max_tokens' => 150,
      'temperature' => 0.3,
    ])['choices'][0]['message']['content']);
    self::assertSame(4096, $this->bodies[1]['max_tokens']);
    self::assertArrayNotHasKey('temperature', $this->bodies[1]);
  }

  /**
   * Explicit, provider and fallback models are resolved in order.
   */
  public function testModelResolution(): void {
    $client = $this->client();
    self::assertSame('override', $client->resolveChatModel('override', 'missing'));
    self::assertSame('deployment', $client->resolveChatModel(''));
    self::assertSame('deployment', $client->resolveChatModel('   '));
    self::assertSame(AiClientService::DEFAULT_CHAT_MODEL, $client->resolveChatModel(NULL, 'missing'));
    self::assertSame('vectors', $client->resolveEmbeddingModel());
  }

  /**
   * Anthropic omits temperature while retaining its required token limit.
   */
  public function testAnthropicTemperatureOmission(): void {
    $client = $this->client([
      'send_temperature' => FALSE,
      'max_tokens_param' => 'max_completion_tokens',
    ], [
      new Response(200, [], '{"content":[{"type":"text","text":"ok"}]}'),
    ], 'anthropic');
    $client->chat([], ['temperature' => 0.3]);
    self::assertArrayNotHasKey('temperature', $this->bodies[0]);
    self::assertArrayNotHasKey('max_completion_tokens', $this->bodies[0]);
    self::assertSame(1024, $this->bodies[0]['max_tokens']);
  }

  /**
   * Value errors and filtering failures are not parameter repairs.
   */
  public function testValueErrorsAndBoundedMessage(): void {
    foreach ([
      ['message' => 'max_tokens is too large: 99999', 'type' => 'invalid_request_error'],
      ['message' => 'unsupported temperature content', 'code' => 'content_filter'],
      ['message' => str_repeat('x', 5000)],
    ] as $error) {
      $before = count($this->bodies);
      $client = $this->client([], [new Response(400, [], json_encode(['error' => $error]))]);
      try {
        $client->chat([], ['max_tokens' => 150, 'temperature' => 0.3]);
        self::fail('Expected failure.');
      }
      catch (\Exception $e) {
        self::assertSame(400, $e->getPrevious()->getCode());
        self::assertLessThanOrEqual(300, mb_strlen($e->getPrevious()->getMessage()));
        self::assertSame($before + 1, count($this->bodies));
      }
    }
  }

  /**
   * Short error.message diagnostics retain the pre-refactor text format.
   */
  public function testNeutralErrorText(): void {
    $client = $this->client();
    $method = new \ReflectionMethod($client, 'parseErrorMessage');
    self::assertSame('API error (400): max_tokens is too large', $method->invoke($client, '{"error":{"message":"max_tokens is too large","type":"invalid_request_error"}}', 400));
  }

  /**
   * Exhausted completions are billed and recorded before the failure is thrown.
   */
  public function testExhaustedUsageRecorded(): void {
    foreach (['openai', 'anthropic'] as $provider) {
      $response = $provider === 'openai'
        ? ['choices' => [['finish_reason' => 'length']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 150]]
        : ['stop_reason' => 'max_tokens', 'usage' => ['input_tokens' => 5, 'output_tokens' => 150]];
      $client = $this->client([], [new Response(200, [], json_encode($response))], $provider);
      $tracking = $this->createMock(TokenTrackingService::class);
      $tracking->method('checkLimit')->willReturn(TRUE);
      $tracking->expects($this->once())->method('logUsage')->with($provider, 'deployment', 'selftest', 5, 150);
      (new \ReflectionProperty($client, 'tokenTracking'))->setValue($client, $tracking);
      try {
        $client->chat([], ['operation' => 'selftest']);
        self::fail('Expected exhaustion failure.');
      }
      catch (\Exception $e) {
        self::assertStringContainsString('Token budget exhausted', $e->getMessage());
      }
    }
  }

}
