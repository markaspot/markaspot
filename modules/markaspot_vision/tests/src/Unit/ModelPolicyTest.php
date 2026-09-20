<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Psr\Log\LoggerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\markaspot_ai\Utility\BlurAdvisory;
use org\bovigo\vfs\vfsStream;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests vision policy, bounded repairs, JSON extraction and enforced blur.
 */
#[CoversClass(ImageProcessingService::class)]
#[Group('markaspot_vision')]
class ModelPolicyTest extends UnitTestCase {

  /**
   * HTTP history and saved environment.
   */
  private array $history = [];
  /**
   * Environment values restored after each test.
   */
  private array $environment = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'MARKASPOT_BLUR_REQUIRED',
      'MARKASPOT_BLUR_API_KEY',
      'MARKASPOT_BLUR_URL',
      'AI_API_KEY',
      'VISION_BLUR_URL',
      'MARKASPOT_VISION_API_URL',
    ] as $key) {
      $this->environment[$key] = getenv($key);
      putenv($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->environment as $key => $value) {
      putenv($value === FALSE ? $key : "$key=$value");
    }
    parent::tearDown();
  }

  /**
   * Creates a service using an offline Guzzle handler.
   */
  private function service(array $config = [], array $responses = [], ?LoggerInterface $log = NULL): ImageProcessingService {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $factory = $this->getConfigFactoryStub(['markaspot_vision.settings' => $config + [
      'api_url' => 'https://vision.example/chat/completions',
      'ai_model' => 'configured-model',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example/blur',
    ]]);
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn($log ?? new NullLogger());
    return $this->getMockBuilder(ImageProcessingService::class)->setConstructorArgs([
      new Client(['handler' => $stack]), $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileSystemInterface::class), $logger,
      $this->createMock(StateInterface::class), $this->createMock(TimeInterface::class),
    ])->onlyMethods(['wait', 'getAllCategoriesHierarchical'])->getMock();
  }

  /**
   * Calls a protected request helper.
   */
  private function invoke(ImageProcessingService $service, string $method, array $args): mixed {
    return (new \ReflectionMethod($service, $method))->invokeArgs($service, $args);
  }

  /**
   * Every policy value is honored, even for a former hardcoded vision model.
   */
  #[DataProvider('policies')]
  public function testPolicy(string $limit, string $format): void {
    $service = $this->service(['max_tokens' => 150, 'max_tokens_param' => $limit, 'response_format_mode' => $format]);
    $payload = $this->invoke($service, 'prepareRequestPayload', [[], ['model' => 'gpt-4-vision-preview']]);
    if ($limit === 'none') {
      self::assertArrayNotHasKey('max_tokens', $payload);
      self::assertArrayNotHasKey('max_completion_tokens', $payload);
    }
    else {
      self::assertSame(150, $payload[$limit]);
    }
    if ($format === 'none') {
      self::assertArrayNotHasKey('response_format', $payload);
    }
    else {
      self::assertSame($format, $payload['response_format']['type']);
    }
    if ($format !== 'json_schema') {
      self::assertStringContainsString('privacy_image_flags', $payload['messages'][0]['content']);
      self::assertStringContainsString('attributes', $payload['messages'][0]['content']);
    }
    else {
      self::assertSame([], $payload['messages']);
    }
  }

  /**
   * All token and response-format modes.
   */
  public static function policies(): iterable {
    foreach (['max_tokens', 'max_completion_tokens', 'none'] as $limit) {
      foreach (['json_schema', 'json_object', 'none'] as $format) {
        yield [$limit, $format];
      }
    }
  }

  /**
   * Unset token limit remains omitted.
   */
  public function testNoImplicitTokenLimit(): void {
    $payload = $this->invoke($this->service(), 'prepareRequestPayload', [[], ['model' => 'any']]);
    self::assertArrayNotHasKey('max_tokens', $payload);
  }

  /**
   * Repairs are bounded even when the next response also rejects a parameter.
   */
  #[DataProvider('rejections')]
  public function testRepair(string $parameter, string $format, bool $secondFails): void {
    $error = new Response(400, [], json_encode(['error' => ['message' => "Unknown parameter $parameter"]]));
    $valid_json = json_encode([
      'category' => 0,
      'is_reportable_issue' => FALSE,
      'description' => 'test',
      'alt_text' => [],
      'hazard_flag' => FALSE,
      'hazard_issues' => [],
      'privacy_flag' => FALSE,
      'privacy_issues' => [],
      'privacy_image_flags' => [],
      'hazard_level' => 0,
      'hazard_category' => NULL,
      'attributes' => [],
    ]);
    $service = $this->service([
      'response_format_mode' => $format,
      'max_tokens' => 150,
      'temperature' => 0.3,
      'top_p' => 0.9,
    ], [

      $error, $secondFails ? $error : new Response(200, [], json_encode([
        'choices' => [
          [
            'message' => [
              'content' => $valid_json,
            ],
          ],
        ],
      ])),
    ]);
    $api = ['url' => 'https://vision.example', 'headers' => [], 'model' => 'any'];
    $payload = $this->invoke($service, 'prepareRequestPayload', [[], $api]);
    try {
      $result = $this->invoke($service, 'sendRequestWithRetry', [$api, $payload]);
      self::assertFalse($secondFails);
      if ($parameter === 'response_format') {
        self::assertSame($valid_json, $result['choices'][0]['message']['content']);
      }
    }
    catch (\RuntimeException $e) {
      self::assertTrue($secondFails);
      self::assertSame(400, $e->getCode());
    }
    self::assertCount(2, $this->history);
    $body = json_decode((string) $this->history[1]['request']->getBody(), TRUE);
    if ($parameter === 'max_tokens') {
      self::assertSame(150, $body['max_completion_tokens']);
      self::assertArrayNotHasKey('max_tokens', $body);
    }
    elseif ($parameter === 'response_format' && $format === 'json_schema') {
      self::assertSame(['type' => 'json_object'], $body['response_format']);
      self::assertStringContainsString('privacy_image_flags', $body['messages'][0]['content']);
    }
    else {
      self::assertArrayNotHasKey($parameter, $body);
    }
  }

  /**
   * All repair branches and second-400 failures.
   */
  public static function rejections(): iterable {
    foreach ([FALSE, TRUE] as $fails) {
      foreach (['temperature', 'top_p', 'max_tokens', 'response_format'] as $parameter) {
        yield [$parameter, 'json_schema', $fails];
      }
      yield ['response_format', 'json_object', $fails];
    }
  }

  /**
   * Extraction respects nested objects, escaped quotes and surrounding text.
   */
  public function testJsonExtraction(): void {
    $json = $this->validJson(['description' => 'brace } and quote "']);
    self::assertSame($json, $this->invoke($this->service(), 'extractJsonObject', ["prefix ```json\n$json\n``` suffix {}"]));
  }

  /**
   * Empty reasoning output is a failure without retries.
   */
  public function testEmptyBudget(): void {
    $service = $this->service([], [
      new Response(200,
       [],
       '{"choices":[{"message":{"content":" "},"finish_reason":"length"}]}'),
    ]);
    $this->expectExceptionMessage('token budget exhausted');
    $this->invoke($service, 'sendRequestWithRetry', [['url' => 'https://vision.example', 'headers' => []], []]);
  }

  /**
   * The OSS default never contacts blur.
   */
  public function testOssDefault(): void {
    $result = $this->service()->blurSensitiveAreas('original', 'image/png');
    self::assertSame('original', $result['contents']);
    self::assertFalse($result['processed']);
    self::assertCount(0, $this->history);
  }

  /**
   * Platform policy overrides false config and accepts a clean scanned image.
   */
  #[DataProvider('requiredModes')]
  public function testRequiredBlurRuns(?string $required): void {
    putenv($required === NULL ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $required);
    putenv('MARKASPOT_BLUR_API_KEY=test');
    putenv('MARKASPOT_BLUR_URL=https://blur.example/blur');
    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);
    $service = $this->service(['blur_service_url' => 'https://untrusted.example/blur'], [
      new Response(200, ['X-Image-Blurred' => 'false'], $png),
      new Response(200, [], json_encode([
        'choices' => [
          [
            'message' => [
              'content' => json_encode([
                'category' => 0,
                'is_reportable_issue' => FALSE,
                'description' => 'synthetic',
                'alt_text' => [],
                'hazard_flag' => FALSE,
                'hazard_issues' => [],
                'privacy_flag' => FALSE,
                'privacy_issues' => [],
                'privacy_image_flags' => [],
                'hazard_level' => 0,
                'hazard_category' => NULL,
                'attributes' => [],
              ]),
            ],
          ],
        ],
      ])),
    ]);
    $result = $service->selfTest();
    self::assertTrue($result['blur_processed']);
    self::assertFalse($result['blur_applied']);
    self::assertCount(2, $this->history);
    self::assertSame('blur.example', $this->history[0]['request']->getUri()->getHost());
    self::assertFalse($this->history[0]['options']['allow_redirects']);
    self::assertStringContainsString(base64_encode($png), (string) $this->history[1]['request']->getBody());
  }

  /**
   * Explicit and automatically required hosting modes.
   */
  public static function requiredModes(): iterable {
    yield ['1'];
    yield [NULL];
  }

  /**
   * Blur failure sends nothing to vision, even with config disabled.
   */
  #[DataProvider('requiredModes')]
  public function testRequiredBlurFailureStopsVision(?string $required): void {
    putenv($required === NULL ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $required);
    putenv('MARKASPOT_BLUR_API_KEY=test');
    putenv('MARKASPOT_BLUR_URL=https://blur.example/blur');
    $service = $this->service([], [new Response(503)]);
    try {
      $service->selfTest();
      self::fail('Expected fail-closed error.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('refusing', $e->getMessage());
      self::assertCount(1, $this->history);
      self::assertSame('blur.example', $this->history[0]['request']->getUri()->getHost());
    }
  }

  /**
   * Empty and whitespace keys are missing in both required modes.
   */
  public static function missingKeys(): iterable {
    yield ['1', ''];
    yield ['1', '   '];
    yield [NULL, ''];
    yield [NULL, '   '];
  }

  /**
   * A missing platform blur key fails before any HTTP request.
   */
  #[DataProvider('missingKeys')]
  public function testRequiredBlurNeedsKey(?string $required, string $key): void {
    putenv($required === NULL ? 'MARKASPOT_BLUR_REQUIRED' : 'MARKASPOT_BLUR_REQUIRED=' . $required);
    putenv('MARKASPOT_BLUR_API_KEY=' . $key);
    putenv('MARKASPOT_BLUR_URL=https://blur.example/blur');
    $this->expectExceptionMessage('MARKASPOT_BLUR_REQUIRED');
    $this->service()->blurSensitiveAreas('original', 'image/png');
  }

  /**
   * Fallback output cannot omit privacy fields or substitute their types.
   */
  public function testFallbackFailsClosedOnMissingPrivacyFields(): void {
    $service = $this->service([
      'response_format_mode' => 'none',
    ], [
      new Response(200,
       [],
       '{"choices":[{"message":{"content":"{}"}}]}'),
    ]);
    $this->expectExceptionMessage('required JSON schema');
    $service->selfTest();
  }

  /**
   * Builds a schema-valid synthetic verdict.
   */
  private function validJson(array $changes = []): string {
    return json_encode($changes + [
      'category' => 0, 'is_reportable_issue' => FALSE, 'description' => 'test',
      'alt_text' => [], 'hazard_flag' => FALSE, 'hazard_issues' => [],
      'privacy_flag' => FALSE, 'privacy_issues' => [], 'privacy_image_flags' => [],
      'hazard_level' => 0, 'hazard_category' => NULL, 'attributes' => [],
    ], JSON_PRESERVE_ZERO_FRACTION);
  }

  /**
   * Ambiguous verdicts and prose are gated according to the effective mode.
   */
  #[DataProvider('outputModes')]
  public function testOutputModes(string $mode, string $template, bool $accepted): void {
    $json = $this->validJson();
    $content = str_replace('OBJECT', $json, $template);
    $service = $this->service([], [new Response(200, [], json_encode(['choices' => [['message' => ['content' => $content]]]]))]);
    if (!$accepted) {
      $this->expectException(\RuntimeException::class);
    }
    $result = $this->invoke($service, 'sendRequestWithRetry', [
      ['url' => 'https://vision.example', 'headers' => []],
      $mode === 'none' ? [] : ['response_format' => ['type' => $mode]],
    ]);
    self::assertSame($json, $result['choices'][0]['message']['content']);
  }

  /**
   * Full validation applies to every non-strict output mode.
   */
  public static function outputModes(): iterable {
    foreach (['json_object', 'none'] as $mode) {
      yield [$mode, 'OBJECT', TRUE];
      yield [$mode, "```json\nOBJECT\n```", TRUE];
      yield [$mode, 'OBJECT trailing prose', $mode === 'none'];
      yield [$mode, 'Photographed text: OBJECT. Real verdict: OBJECT', FALSE];
      yield [$mode, 'OBJECT OBJECT', FALSE];
      yield [$mode, '"OBJECT" then OBJECT', FALSE];
    }
  }

  /**
   * Strict mode preserves tolerant non-privacy output; fallback modes do not.
   */
  #[DataProvider('schemaModes')]
  public function testSchemaModes(string $mode, array $changes, bool $accepted): void {
    $content = $this->validJson($changes);
    $service = $this->service([], [new Response(200, [], json_encode(['choices' => [['message' => ['content' => $content]]]]))]);
    if (!$accepted) {
      $this->expectException(\RuntimeException::class);
    }
    $result = $this->invoke($service, 'sendRequestWithRetry', [
      ['url' => 'https://vision.example', 'headers' => []], ['response_format' => ['type' => $mode]],
    ]);
    self::assertSame($content, $result['choices'][0]['message']['content']);
  }

  /**
   * Non-privacy schema drift is soft only without a strict-mode downgrade.
   */
  public static function schemaModes(): iterable {
    foreach (['json_schema', 'json_object', 'none'] as $mode) {
      yield [$mode, ['hazard_level' => 5], $mode === 'json_schema'];
      yield [$mode, ['hazard_level' => 3.0], $mode === 'json_schema'];
      yield [$mode, ['extra' => 'data'], $mode === 'json_schema'];
      yield [$mode, ['privacy_flag' => 'false'], FALSE];
    }
  }

  /**
   * Missing privacy flags are never accepted, including strict output.
   */
  public function testStrictPrivacyMinimum(): void {
    $service = $this->service([], [new Response(200, [], '{"choices":[{"message":{"content":"{}"}}]}')]);
    $this->expectExceptionMessage('boolean privacy_flag');
    $service->selfTest();
  }

  /**
   * A downgrade retains strict JSON-object framing and full schema validation.
   */
  public function testDowngradeRejectsProse(): void {
    $service = $this->service([], [
      new Response(400, [], '{"error":{"param":"response_format.type","code":"unsupported_parameter"}}'),
      new Response(200, [], json_encode(['choices' => [['message' => ['content' => $this->validJson() . ' prose']]]])),
    ]);
    $this->expectExceptionMessage('outside its object');
    $service->selfTest();
  }

  /**
   * A required blur responder must identify itself; config-only stays tolerant.
   */
  public function testBlurResponderHeader(): void {
    putenv('MARKASPOT_BLUR_REQUIRED=true');
    putenv('MARKASPOT_BLUR_URL=https://blur.example');
    putenv('MARKASPOT_BLUR_API_KEY=test');
    $service = $this->service([], [new Response(200, [], 'not-an-image')]);
    try {
      $service->blurSensitiveAreas('synthetic', 'image/png');
      self::fail('Missing header must fail.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('X-Image-Blurred', $e->getPrevious()->getMessage());
    }
    putenv('MARKASPOT_BLUR_REQUIRED=0');
    $result = $this->service(['enable_blur_preprocessing' => TRUE], [new Response(200, [], 'scanned')])->blurSensitiveAreas('synthetic', 'image/png');
    self::assertTrue($result['processed']);
  }

  /**
   * Value/content-filter errors are not repaired; diagnostics are bounded.
   */
  #[DataProvider('unrepairableErrors')]
  public function testUnrepairableError(array $error): void {
    $service = $this->service([], [new Response(400, [], json_encode(['error' => $error]))]);
    try {
      $this->invoke($service, 'sendRequestWithRetry', [
        ['url' => 'https://vision.example', 'headers' => [], 'model' => 'model'],
        ['max_tokens' => 150, 'temperature' => 0.3],
      ]);
      self::fail('Expected request failure.');
    }
    catch (\RuntimeException $e) {
      self::assertSame(400, $e->getCode());
      self::assertLessThanOrEqual(300, mb_strlen($e->getMessage()));
      self::assertCount(1, $this->history);
    }
  }

  /**
   * Value, content filtering and input-echo diagnostics.
   */
  public static function unrepairableErrors(): iterable {
    yield [['type' => 'invalid_request_error', 'message' => 'max_tokens is too large: 99999']];
    yield [['param' => 'temperature', 'code' => 'content_filter', 'message' => 'unsupported temperature content']];
    yield [['message' => str_repeat('x', 5000)]];
  }

  /**
   * Soft schema drift warnings never contain response content.
   */
  public function testStrictSchemaWarningIsContentFree(): void {
    $content = $this->validJson(['hazard_level' => 5, 'description' => str_repeat('private', 1000)]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning')->with($this->callback(static fn ($message) => strlen($message) <= 300 && !str_contains($message, 'private')));
    $service = $this->service([], [new Response(200, [], json_encode(['choices' => [['message' => ['content' => $content]]]]))], $logger);
    $result = $this->invoke($service, 'sendRequestWithRetry', [
      ['url' => 'https://vision.example', 'headers' => []], ['response_format' => ['type' => 'json_schema']],
    ]);
    self::assertSame($content, $result['choices'][0]['message']['content']);
  }

  /**
   * OSS images are unchanged; Vision and chat share the daily advisory budget.
   */
  public function testOssRequestAndDailyAdvisory(): void {
    $root = vfsStream::setup('images', NULL, ['synthetic.png' => 'synthetic original']);
    $uri = $root->url() . '/synthetic.png';
    $response = new Response(200, [], json_encode(['choices' => [['message' => ['content' => $this->validJson()]]]]));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))->method('warning')->with(BlurAdvisory::message()->getUntranslatedString());
    $logger->method('error')->willReturnCallback(static function ($message, array $context): void {
      self::fail($context['@message'] ?? $message);
    });
    $service = $this->service(['image_prompt' => 'Synthetic test'], [$response, $response], $logger);
    $style = $this->createMock(ImageStyleInterface::class);
    $style->method('buildUri')->willReturn($uri);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($style);
    $storage->method('loadByProperties')->willReturn([]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturn($storage);
    (new \ReflectionProperty($service, 'entityTypeManager'))->setValue($service, $entities);
    $last = NULL;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with(BlurAdvisory::STATE_KEY)->willReturnCallback(static function () use (&$last) {
      return $last;
    });
    $state->expects($this->exactly(2))->method('set')->with(BlurAdvisory::STATE_KEY, $this->anything())->willReturnCallback(static function ($key, $value) use (&$last) {
      $last = $value;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(100000);
    (new \ReflectionProperty($service, 'state'))->setValue($service, $state);
    (new \ReflectionProperty($service, 'time'))->setValue($service, $time);
    self::assertNotNull($service->processImages([$uri], 'en'));
    self::assertNotNull($service->processImages([$uri], 'en'));
    self::assertCount(2, $this->history);
    foreach ($this->history as $request) {
      self::assertSame('vision.example', $request['request']->getUri()->getHost());
      self::assertStringContainsString(base64_encode('synthetic original'), (string) $request['request']->getBody());
    }
    // Another module/logger uses the same persisted timestamp.
    BlurAdvisory::warn($state, $logger, 100100, 'auto-unprotected', FALSE);
    BlurAdvisory::warn($state, $logger, 186400, 'auto-unprotected', FALSE);
  }

  /**
   * Config-only blur remains tolerant; explicit off suppresses the advisory.
   */
  public function testConfigBlurAndOptOut(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');
    $service = $this->service(['enable_blur_preprocessing' => TRUE], [new Response(200, [], 'processed')], $logger);
    self::assertSame('auto-unprotected', $service->getBlurMode());
    self::assertTrue($service->blurSensitiveAreas('original', 'image/png')['processed']);
    self::assertTrue($this->history[0]['options']['allow_redirects'] !== FALSE);
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->never())->method('set');
    BlurAdvisory::warn($state, $logger, 100000, $service->getBlurMode(), TRUE);
    putenv('MARKASPOT_BLUR_REQUIRED=0');
    putenv('MARKASPOT_BLUR_URL=https://hosted.example/blur');
    $off = $this->service([], [], $logger);
    self::assertSame('off', $off->getBlurMode());
    self::assertFalse($off->blurSensitiveAreas('original', 'image/png')['processed']);
    BlurAdvisory::warn($state, $logger, 100000, 'off', FALSE);
    self::assertCount(1, $this->history);
  }

  /**
   * Strict mode cannot use the install-config URL when the ENV URL is absent.
   */
  public function testStrictWithoutEnvironmentUrl(): void {
    putenv('MARKASPOT_BLUR_REQUIRED=1');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')->with($this->stringContains('MARKASPOT_BLUR_REQUIRED'));
    try {
      $this->service([], [], $logger)->selfTest();
      self::fail('Expected required blur to fail.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('no blur service URL', $e->getMessage());
    }
    self::assertCount(0, $this->history);
  }

}
