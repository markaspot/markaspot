<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the sentiment analysis service.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\SentimentService
 */
class SentimentServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_ai\Service\SentimentService
   */
  protected SentimentService $service;

  /**
   * The mocked AI client.
   *
   * @var \Drupal\markaspot_ai\Service\AiClientService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $aiClient;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

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
   * The mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * The mocked config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->aiClient = $this->createMock(AiClientService::class);
    $this->database = $this->createMock(Connection::class);
    $this->tokenTracking = $this->createMock(TokenTrackingService::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1700000000);

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'sentiment_analysis.model' => 'gpt-4o-mini',
          default => NULL,
        };
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($this->config);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_ai')
      ->willReturn($this->logger);

    $this->service = new SentimentService(
      $this->aiClient,
      $this->database,
      $entityTypeManager,
      $configFactory,
      $loggerFactory,
      $this->tokenTracking,
      $this->time,
      $moduleHandler,
    );
  }

  /**
   * Tests sentiment constants are correct.
   *
   * @covers ::SENTIMENT_FRUSTRATED
   * @covers ::SENTIMENT_NEUTRAL
   * @covers ::SENTIMENT_POSITIVE
   */
  public function testSentimentConstants(): void {
    $this->assertEquals('frustrated', SentimentService::SENTIMENT_FRUSTRATED);
    $this->assertEquals('neutral', SentimentService::SENTIMENT_NEUTRAL);
    $this->assertEquals('positive', SentimentService::SENTIMENT_POSITIVE);

    $this->assertContains('frustrated', SentimentService::VALID_SENTIMENTS);
    $this->assertContains('neutral', SentimentService::VALID_SENTIMENTS);
    $this->assertContains('positive', SentimentService::VALID_SENTIMENTS);
    $this->assertCount(3, SentimentService::VALID_SENTIMENTS);
  }

  /**
   * Tests empty text returns neutral default.
   *
   * @covers ::analyzeSentiment
   */
  public function testAnalyzeSentimentEmptyText(): void {
    $result = $this->service->analyzeSentiment('   ');

    $this->assertEquals('neutral', $result['sentiment']);
    $this->assertEquals(0.0, $result['score']);
    $this->assertEquals(1.0, $result['confidence']);
    $this->assertEquals('Empty text provided.', $result['reasoning']);
  }

  /**
   * Tests successful sentiment analysis.
   *
   * @covers ::analyzeSentiment
   */
  public function testAnalyzeSentimentSuccess(): void {
    $aiResponse = [
      'choices' => [[
        'message' => [
          'content' => json_encode([
            'sentiment' => 'frustrated',
            'score' => -0.8,
            'confidence' => 0.95,
            'reasoning' => 'Citizen is upset about recurring issue.',
          ]),
        ],
      ],
      ],
      'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
    ];

    $this->aiClient->method('chat')->willReturn($aiResponse);

    $result = $this->service->analyzeSentiment('This pothole has been here for months! Fix it already!');

    $this->assertEquals('frustrated', $result['sentiment']);
    $this->assertEquals(-0.8, $result['score']);
    $this->assertEquals(0.95, $result['confidence']);
  }

  /**
   * Tests invalid sentiment value is normalized to neutral.
   *
   * @covers ::analyzeSentiment
   */
  public function testAnalyzeSentimentInvalidValueNormalized(): void {
    $aiResponse = [
      'choices' => [[
        'message' => [
          'content' => json_encode([
            'sentiment' => 'angry',
            'score' => -0.5,
            'confidence' => 0.8,
            'reasoning' => 'test',
          ]),
        ],
      ],
      ],
      'usage' => [],
    ];

    $this->aiClient->method('chat')->willReturn($aiResponse);
    $result = $this->service->analyzeSentiment('test text');

    // "angry" is not a valid sentiment, should fall back to neutral.
    $this->assertEquals('neutral', $result['sentiment']);
  }

  /**
   * Tests score is clamped to -1.0 to 1.0.
   *
   * @covers ::analyzeSentiment
   */
  public function testAnalyzeSentimentScoreClamped(): void {
    $aiResponse = [
      'choices' => [[
        'message' => [
          'content' => json_encode([
            'sentiment' => 'frustrated',
            'score' => -5.0,
            'confidence' => 2.0,
            'reasoning' => 'test',
          ]),
        ],
      ],
      ],
      'usage' => [],
    ];

    $this->aiClient->method('chat')->willReturn($aiResponse);
    $result = $this->service->analyzeSentiment('test text');

    $this->assertEquals(-1.0, $result['score']);
    $this->assertEquals(1.0, $result['confidence']);
  }

  /**
   * Tests malformed JSON response returns default.
   *
   * @covers ::analyzeSentiment
   */
  public function testAnalyzeSentimentMalformedJsonReturnsDefault(): void {
    $aiResponse = [
      'choices' => [[
        'message' => [
          'content' => 'not-json',
        ],
      ],
      ],
      'usage' => [],
    ];

    $this->aiClient->method('chat')->willReturn($aiResponse);
    $result = $this->service->analyzeSentiment('test text');

    $this->assertEquals('neutral', $result['sentiment']);
    $this->assertEquals(0.0, $result['score']);
    $this->assertEquals(0.0, $result['confidence']);
  }

  /**
   * Tests API failure propagates exception.
   *
   * @covers ::analyzeSentiment
   */
  public function testAnalyzeSentimentApiFailureThrows(): void {
    $this->aiClient->method('chat')
      ->willThrowException(new \Exception('API timeout'));

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('API timeout');

    $this->service->analyzeSentiment('test text');
  }

  /**
   * Tests token usage is tracked.
   *
   * @covers ::analyzeSentiment
   */
  public function testAnalyzeSentimentTracksTokens(): void {
    $aiResponse = [
      'choices' => [[
        'message' => [
          'content' => json_encode([
            'sentiment' => 'neutral',
            'score' => 0.0,
            'confidence' => 0.9,
            'reasoning' => 'test',
          ]),
        ],
      ],
      ],
      'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
    ];

    $this->aiClient->method('chat')->willReturn($aiResponse);

    $this->tokenTracking->expects($this->once())
      ->method('logUsage')
      ->with('openai', 'gpt-4o-mini', 'sentiment', 100, 30);

    $this->service->analyzeSentiment('test text');
  }

  /**
   * Tests sentiment is stored via merge query.
   *
   * @covers ::storeSentiment
   */
  public function testStoreSentiment(): void {
    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->expects($this->once())->method('execute');

    $this->database->expects($this->once())
      ->method('merge')
      ->with('markaspot_ai_sentiment')
      ->willReturn($merge);

    $this->service->storeSentiment(42, 'frustrated', -0.8, 0.95, 'test reason');
  }

  /**
   * Tests getting existing sentiment.
   *
   * @covers ::getSentiment
   */
  public function testGetSentimentExists(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')
      ->willReturn([
        'sentiment' => 'frustrated',
        'score' => '-0.8',
        'confidence' => '0.95',
        'reasoning' => 'test',
        'analyzed_at' => '1700000000',
      ]);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')
      ->with('markaspot_ai_sentiment', 's')
      ->willReturn($select);

    $result = $this->service->getSentiment(42);

    $this->assertNotNull($result);
    $this->assertEquals('frustrated', $result['sentiment']);
    $this->assertEquals(-0.8, $result['score']);
    $this->assertEquals(0.95, $result['confidence']);
    $this->assertEquals(1700000000, $result['analyzed_at']);
  }

  /**
   * Tests getting nonexistent sentiment returns NULL.
   *
   * @covers ::getSentiment
   */
  public function testGetSentimentNotFound(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $result = $this->service->getSentiment(999);
    $this->assertNull($result);
  }

}
