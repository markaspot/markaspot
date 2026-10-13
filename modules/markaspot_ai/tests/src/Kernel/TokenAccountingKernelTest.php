<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * AiClientService books every provider response once against the daily limit.
 *
 * Uses the real TokenTrackingService on a real table and the real client;
 * only the provider's HTTP transport (a Guzzle mock) and the settings are
 * replaced.
 */
#[Group('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class TokenAccountingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Isolated kernel database.
   */
  private Connection $database;

  /**
   * Queued provider answers.
   */
  private MockHandler $handler;

  /**
   * Daily token limit of the mocked settings.
   */
  private int $dailyLimit = 100000;

  /**
   * Default provider of the mocked settings.
   */
  private string $provider = 'openai';

  /**
   * Real token tracking on the kernel database.
   */
  private TokenTrackingService $tracking;

  /**
   * The client under test.
   */
  private AiClientService $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->database = $this->container->get('database');
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
    $table = 'markaspot_ai_token_usage';
    $this->database->schema()->createTable($table, markaspot_ai_schema()[$table]);
    $this->handler = new MockHandler();
    $this->rebuildClient();
  }

  /**
   * Builds tracking and client on the current settings.
   */
  private function rebuildClient(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn(string $key) => match ($key) {
      'token_tracking.enabled' => TRUE,
      'token_tracking.daily_limit' => $this->dailyLimit,
      'default_provider' => $this->provider,
      'providers.openai' => [
        'api_url' => 'https://provider.test/v1',
        'api_key' => 'k',
        'chat_model' => 'chat-model',
        'embedding_model' => 'embed-model',
      ],
      'providers.anthropic' => [
        'api_url' => 'https://anthropic.test/v1',
        'api_key' => 'k',
        'chat_model' => 'claude-test',
      ],
      default => NULL,
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $this->tracking = new TokenTrackingService($this->database, $factory, $this->container->get('logger.factory'));
    $this->client = new class(
      new Client(['handler' => HandlerStack::create($this->handler)]),
      $factory,
      $this->container->get('logger.factory'),
      $this->tracking,
    ) extends AiClientService {

      /**
       * {@inheritdoc}
       */
      protected function wait(int $seconds): void {
      }

    };
    $this->container->set('markaspot_ai.client', $this->client);
  }

  /**
   * Booked rows, oldest first.
   */
  private function rows(): array {
    return $this->database->select('markaspot_ai_token_usage', 't')
      ->fields('t', ['provider', 'model', 'operation', 'input_tokens', 'output_tokens', 'entity_type', 'entity_id'])
      ->orderBy('id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * An OpenAI-shaped chat answer.
   */
  private function chatAnswer(
    string $content,
    ?array $usage = ['prompt_tokens' => 40, 'completion_tokens' => 10],
    string $finish = 'stop',
    ?string $refusal = NULL,
  ): Response {
    $message = ['content' => $content];
    if ($refusal !== NULL) {
      $message['refusal'] = $refusal;
    }
    $body = ['choices' => [['message' => $message, 'finish_reason' => $finish]]];
    if ($usage !== NULL) {
      $body['usage'] = $usage;
    }
    return new Response(200, [], json_encode($body));
  }

  /**
   * An embedding answer with the given usage.
   */
  private function embedAnswer(array $usage): Response {
    return new Response(200, [], json_encode(['data' => [['embedding' => [0.1, 0.2]]], 'usage' => $usage]));
  }

  /**
   * Operation and token counts of a booked row.
   */
  private function summary(array $row): array {
    return [$row['operation'], (int) $row['input_tokens'], (int) $row['output_tokens']];
  }

  /**
   * A successful chat is booked once, with the caller's operation and entity.
   */
  public function testSuccessfulChatIsBookedOnce(): void {
    $this->handler->append($this->chatAnswer('ok'));
    $this->client->chat([['role' => 'user', 'content' => 'hi']], [
      'operation' => 'form_assist', 'entity_type' => 'node', 'entity_id' => 7,
    ]);
    $this->assertSame([[
      'provider' => 'openai', 'model' => 'chat-model', 'operation' => 'form_assist',
      'input_tokens' => '40', 'output_tokens' => '10', 'entity_type' => 'node', 'entity_id' => '7',
    ]], $this->rows());
  }

  /**
   * Unsaved drafts (ID 0) are booked without an entity reference.
   */
  public function testDraftWithoutIdIsBookedWithoutEntity(): void {
    $this->handler->append($this->chatAnswer('ok'));
    $this->client->chat([['role' => 'user', 'content' => 'hi']], [
      'operation' => 'form_assist',
      'entity_type' => 'node',
      'entity_id' => 0,
    ]);
    $row = $this->rows()[0];
    $this->assertNull($row['entity_type']);
    $this->assertNull($row['entity_id']);
  }

  /**
   * Anthropic usage (input/output tokens) is booked through the same owner.
   */
  public function testAnthropicChatIsBookedOnce(): void {
    $this->provider = 'anthropic';
    $this->rebuildClient();
    $this->handler->append(new Response(200, [], json_encode([
      'content' => [['type' => 'text', 'text' => 'ok']],
      'stop_reason' => 'end_turn',
      'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
    ])));
    $this->client->chat([['role' => 'user', 'content' => 'hi']], ['operation' => 'sentiment']);
    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $this->assertSame(['anthropic', 'claude-test'], [$rows[0]['provider'], $rows[0]['model']]);
    $this->assertSame(['sentiment', 12, 3], $this->summary($rows[0]));
  }

  /**
   * Embeddings are booked, also when only total_tokens is reported.
   */
  public function testEmbeddingsAreBooked(): void {
    $this->handler->append(
      $this->embedAnswer(['prompt_tokens' => 9, 'total_tokens' => 9]),
      $this->embedAnswer(['total_tokens' => 11]),
    );
    $this->client->embed('first');
    $this->client->embed('second', ['operation' => 'duplicate_scan']);
    $rows = $this->rows();
    $this->assertSame(['embed', 9, 0], $this->summary($rows[0]));
    $this->assertSame(['duplicate_scan', 11, 0], $this->summary($rows[1]));
    $this->assertSame('embed-model', $rows[1]['model']);
  }

  /**
   * Refusals and token-exhausted answers consumed tokens and are booked.
   */
  public function testRefusalAndExhaustedAnswersAreBooked(): void {
    $this->handler->append(
      $this->chatAnswer('', ['prompt_tokens' => 30, 'completion_tokens' => 2], 'stop', 'I cannot help with that.'),
      $this->chatAnswer('', ['prompt_tokens' => 50, 'completion_tokens' => 300], 'length'),
    );
    $response = $this->client->chat([['role' => 'user', 'content' => 'x']], ['operation' => 'attribute_filling']);
    $this->assertSame('I cannot help with that.', $response['choices'][0]['message']['refusal']);
    try {
      $this->client->chat([['role' => 'user', 'content' => 'x']], ['operation' => 'attribute_filling']);
      $this->fail('Expected the exhausted answer to fail.');
    }
    catch (\Exception $e) {
      $this->assertStringContainsString('Token budget exhausted', $e->getMessage());
    }
    $expected = [['attribute_filling', 30, 2], ['attribute_filling', 50, 300]];
    $this->assertSame($expected, array_map($this->summary(...), $this->rows()));
  }

  /**
   * Retries book only the attempt that returned an answer; failures book none.
   */
  public function testRetriesAndFailuresBookOnlyAnswers(): void {
    $request = new Request('POST', 'https://provider.test/v1/chat/completions');
    $this->handler->append(
      new Response(503, [], '{"error":{"message":"busy"}}'),
      new ConnectException('Connection timeout', $request),
      $this->chatAnswer('ok', ['prompt_tokens' => 20, 'completion_tokens' => 5]),
    );
    $this->client->chat([['role' => 'user', 'content' => 'x']], ['operation' => 'spam_scan']);
    $this->assertCount(1, $this->rows());

    $this->handler->append(new Response(401, [], '{"error":{"message":"bad key"}}'));
    try {
      $this->client->chat([['role' => 'user', 'content' => 'x']], ['operation' => 'spam_scan']);
      $this->fail('Expected the rejected request to fail.');
    }
    catch (\Exception) {
    }
    $this->assertCount(1, $this->rows(), 'A rejected request returned no usage and books nothing.');
  }

  /**
   * A one-time parameter repair books the repaired answer only.
   */
  public function testParameterRepairBooksTheAnsweredAttemptOnly(): void {
    $this->handler->append(
      new Response(400, [], json_encode(['error' => [
        'message' => 'Unsupported parameter: temperature',
        'param' => 'temperature',
        'code' => 'unsupported_parameter',
      ],
      ])),
      $this->chatAnswer('ok', ['prompt_tokens' => 15, 'completion_tokens' => 4]),
    );
    $this->client->chat([['role' => 'user', 'content' => 'x']], [
      'operation' => 'mail_category',
      'temperature' => 0.2,
    ]);
    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $this->assertSame('19', (string) ($rows[0]['input_tokens'] + $rows[0]['output_tokens']));
  }

  /**
   * Missing usage books an estimate; images count with a fixed budget.
   */
  public function testMissingUsageBooksAnEstimate(): void {
    $text = str_repeat('a', 400);
    $image = 'data:image/jpeg;base64,' . str_repeat('A', 400000);
    $this->handler->append($this->chatAnswer(str_repeat('b', 40), NULL));
    $this->client->chat([[
      'role' => 'user',
      'content' => [
        ['type' => 'text', 'text' => $text],
        ['type' => 'image_url', 'image_url' => ['url' => $image]],
      ],
    ]], ['operation' => 'attribute_filling']);
    $row = $this->rows()[0];
    // 400 bytes of text = 100 tokens, plus the fixed image budget; the
    // 400 KB data URL itself must not be counted.
    $this->assertSame(100 + 765, (int) $row['input_tokens']);
    $this->assertSame(10, (int) $row['output_tokens']);
  }

  /**
   * Usage across workflows advances the daily limit and then blocks requests.
   */
  public function testCumulativeUsageBlocksFurtherRequests(): void {
    $this->dailyLimit = 100;
    $this->rebuildClient();
    $this->handler->append(
      $this->chatAnswer('ok', ['prompt_tokens' => 40, 'completion_tokens' => 10]),
      $this->embedAnswer(['prompt_tokens' => 60, 'total_tokens' => 60]),
    );
    $this->client->chat([['role' => 'user', 'content' => 'x']], ['operation' => 'pii_name_detection']);
    $this->assertTrue($this->tracking->checkLimit());
    $this->client->embed('x', ['operation' => 'embedding']);
    $this->assertFalse($this->tracking->checkLimit());

    foreach (['chat', 'embed'] as $method) {
      try {
        $method === 'chat' ? $this->client->chat([['role' => 'user', 'content' => 'x']]) : $this->client->embed('x');
        $this->fail("Expected the $method request to be blocked.");
      }
      catch (\Exception $e) {
        $this->assertStringContainsString('Daily token limit exceeded', $e->getMessage());
      }
    }
    $this->assertSame(0, $this->handler->count(), 'Both answers were consumed.');
    $this->assertCount(2, $this->rows(), 'Blocked requests reach no provider and book nothing.');
  }

  /**
   * A booking survives the rollback of the caller's transaction.
   *
   * Provider calls can run inside an entity save or provisioning
   * transaction; the provider consumed the tokens even when that
   * transaction rolls back.
   */
  public function testBookingSurvivesCallerRollback(): void {
    $this->handler->append(
      $this->chatAnswer('ok', ['prompt_tokens' => 21, 'completion_tokens' => 4]),
      $this->embedAnswer(['prompt_tokens' => 7, 'total_tokens' => 7]),
    );
    $transaction = $this->database->startTransaction();
    $this->client->chat([['role' => 'user', 'content' => 'x']], ['operation' => 'pii_name_detection']);
    $this->client->embed('x', ['operation' => 'embedding']);
    $transaction->rollBack();
    unset($transaction);

    $this->assertFalse($this->database->inTransaction());
    $this->assertSame([['pii_name_detection', 21, 4], ['embedding', 7, 0]], array_map($this->summary(...), $this->rows()));
    $this->assertSame(32, $this->tracking->getDailyUsage()['total_tokens'], 'The daily counter keeps the rolled-back usage.');
  }

  /**
   * Workflows that used to book themselves are not counted twice.
   */
  public function testFormerSelfBookingWorkflowsBookOnce(): void {
    $this->handler->append($this->chatAnswer(json_encode([
      'sentiment' => 'neutral', 'score' => 0.0, 'confidence' => 0.9, 'reasoning' => 'test',
    ]), ['prompt_tokens' => 100, 'completion_tokens' => 30]));
    $sentiment = new SentimentService(
      $this->client,
      $this->database,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->tracking,
      $this->createMock(TimeInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
    $sentiment->analyzeSentiment('The streetlight is broken.');
    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $this->assertSame(['sentiment', 100, 30], $this->summary($rows[0]));
  }

  /**
   * Embeddings and PII name detection, previously unbooked, are now booked.
   */
  public function testFormerlyUnbookedWorkflowsAreBooked(): void {
    $this->handler->append(
      $this->embedAnswer(['prompt_tokens' => 8, 'total_tokens' => 8]),
      $this->chatAnswer('{"names_found": []}', ['prompt_tokens' => 25, 'completion_tokens' => 6]),
    );
    $embedding = new EmbeddingService(
      $this->client,
      $this->database,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->container->get('logger.factory'),
    );
    $embedding->generateEmbedding('A pothole on Main Street.');

    require_once dirname(__DIR__, 3) . '/markaspot_ai.module';
    $result = _markaspot_ai_detect_names_via_llm('A pothole on Main Street.', 'openai', 'key', 'node:test');
    $this->assertFalse($result->isFailed());

    $this->assertSame(['embedding', 'pii_name_detection'], array_column($this->rows(), 'operation'));
  }

  /**
   * Production code books chat and embedding usage only inside the client.
   *
   * Guards against a caller re-introducing its own logUsage() next to the
   * central booking, which would count those tokens twice.
   */
  public function testNoCallerBooksChatOrEmbeddingUsageItself(): void {
    $modules = dirname(__DIR__, 4);
    $allowed = [
      'markaspot_ai/src/Service/AiClientService.php',
      'markaspot_ai/src/Service/TokenTrackingService.php',
      // The Vision self-test bypasses AiClientService.
      'markaspot_ai/src/Drush/Commands/MarkaspotAiCommands.php',
    ];
    $offenders = [];
    $directory = new \RecursiveDirectoryIterator($modules, \FilesystemIterator::SKIP_DOTS);
    $iterator = new \RecursiveIteratorIterator($directory);
    foreach ($iterator as $file) {
      $path = str_replace($modules . '/', '', $file->getPathname());
      $production = preg_match('/\.(php|module|inc|install)$/', $path) && !str_contains($path, '/tests/');
      if (!$production || in_array($path, $allowed, TRUE)) {
        continue;
      }
      $source = (string) file_get_contents($file->getPathname());
      if (str_contains($source, '->logUsage(')) {
        $offenders[] = $path;
      }
      $calls_client = preg_match('/->(chat|embed)\(/', $source) && str_contains($source, 'AiClientService');
      if ($calls_client && !str_contains($source, "'operation' =>")) {
        $offenders[] = $path . ' (no operation attribution)';
      }
    }
    $this->assertSame([], $offenders);
  }

}
