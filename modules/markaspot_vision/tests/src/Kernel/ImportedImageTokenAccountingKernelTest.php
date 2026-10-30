<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Imported analysis uses central accounting with the existing Vision transport.
 */
#[Group('markaspot_vision')]
#[RunTestsInSeparateProcesses]
final class ImportedImageTokenAccountingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Real accounting persists one row, including rejected or usage-less answers.
   */
  #[DataProvider('providerAnswers')]
  public function testCentralAccounting(?array $usage, string $content, ?int $retryStatus = NULL): void {
    $c = $this->container;
    $database = $c->get('database');
    require_once dirname(__DIR__, 4) . '/markaspot_ai/markaspot_ai.install';
    $database->schema()->createTable('markaspot_ai_token_usage', markaspot_ai_schema()['markaspot_ai_token_usage']);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([['token_tracking.enabled', TRUE]]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('markaspot_ai.settings')->willReturn($config);
    $tracking = new TokenTrackingService($database, $factory, $c->get('logger.factory'));
    $responses = [];
    if ($retryStatus !== NULL) {
      $responses[] = new Response($retryStatus, [], json_encode([
        'error' => [
          'message' => "Unsupported parameter: 'temperature'.",
          'param' => 'temperature', 'code' => 'unsupported_parameter',
        ],
      ]));
    }
    $answer = ['choices' => [['message' => ['content' => $content]]]];
    if ($usage !== NULL) {
      $answer['usage'] = $usage;
    }
    // A second response exercises reuse without leaking import attribution.
    $responses[] = new Response(200, [], json_encode($answer));
    $responses[] = new Response(200, [], json_encode(['choices' => [['message' => ['content' => '{"privacy_flag":false}']]]]));
    $handler = new MockHandler($responses);
    $http = new Client(['handler' => HandlerStack::create($handler)]);
    $client = new AiClientService($http, $factory, $c->get('logger.factory'), $tracking);
    $images = new class(
      $http, $c->get('config.factory'), $this->createMock(EntityTypeManagerInterface::class),
      $c->get('file_system'), $c->get('logger.factory'), $c->get('state'), $c->get('datetime.time'),
      NULL, NULL, NULL, $client,
    ) extends ImageProcessingService {

      /**
       * Supplies a prepared payload; keeps the real transport and accounting.
       */
      public function processImages(array $file_uris, ?string $langcode = NULL, ?int $jurisdictionId = NULL): ?array {
        return $this->sendRequestWithRetry([
          'url' => 'https://vision.test/chat', 'model' => 'vision-deployment', 'headers' => [],
        ], [
          'model' => 'vision-deployment', 'temperature' => 0.7,
          'messages' => [['role' => 'user', 'content' => 'Inspect this fictional image.']],
          'response_format' => ['type' => 'json_schema'],
        ]);
      }

      /**
       * {@inheritdoc}
       */
      protected function wait(int $seconds): void {
      }

    };
    try {
      $images->processScreenedImages([], 'en', 41, NULL, 73);
      $this->assertSame('{"privacy_flag":false}', $content);
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Vision response lacks a boolean privacy_flag.', $e->getMessage());
      $this->assertSame('invalid response', $content);
    }
    $rows = $database->select('markaspot_ai_token_usage', 't')->fields('t')->execute()->fetchAll(FetchAs::Associative);
    $this->assertCount(1, $rows);
    $this->assertSame('vision', $rows[0]['provider']);
    $this->assertSame('vision-deployment', $rows[0]['model']);
    $this->assertSame('vision', $rows[0]['operation']);
    $this->assertSame('media', $rows[0]['entity_type']);
    $this->assertSame(73, (int) $rows[0]['entity_id']);
    if ($usage !== NULL) {
      $this->assertSame(17, (int) $rows[0]['input_tokens']);
      $this->assertSame(9, (int) $rows[0]['output_tokens']);
    }
    else {
      $this->assertGreaterThan(0, (int) $rows[0]['input_tokens']);
      $this->assertGreaterThan(0, (int) $rows[0]['output_tokens']);
    }
    $images->processImages([]);
    $this->assertSame(0, $handler->count());
    $this->assertSame(1, (int) $database->select('markaspot_ai_token_usage')->countQuery()->execute()->fetchField());
  }

  /**
   * Provider responses that must each produce exactly one central usage row.
   */
  public static function providerAnswers(): array {
    $usage = ['prompt_tokens' => 17, 'completion_tokens' => 9];
    return [
      'success' => [$usage, '{"privacy_flag":false}'],
      'parameter repair' => [$usage, '{"privacy_flag":false}', 400],
      'transient retry' => [$usage, '{"privacy_flag":false}', 503],
      'invalid content' => [$usage, 'invalid response'],
      'missing usage' => [NULL, '{"privacy_flag":false}'],
      'central total-token normalization' => [
        ['total_tokens' => 26, 'completion_tokens' => 9], '{"privacy_flag":false}',
      ],
    ];
  }

}
