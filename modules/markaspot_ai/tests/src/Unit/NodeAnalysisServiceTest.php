<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\NodeAnalysisService;
use Drupal\markaspot_ai\Service\RiskScoreCalculator;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the node analysis service (combined sentiment + hazard).
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\NodeAnalysisService
 */
class NodeAnalysisServiceTest extends UnitTestCase {

  /**
   * Tests hazard level constants.
   *
   * @covers ::HAZARD_NONE
   * @covers ::HAZARD_LOW
   * @covers ::HAZARD_MEDIUM
   * @covers ::HAZARD_HIGH
   * @covers ::HAZARD_CRITICAL
   */
  public function testHazardLevelConstants(): void {
    $this->assertEquals(0, NodeAnalysisService::HAZARD_NONE);
    $this->assertEquals(1, NodeAnalysisService::HAZARD_LOW);
    $this->assertEquals(2, NodeAnalysisService::HAZARD_MEDIUM);
    $this->assertEquals(3, NodeAnalysisService::HAZARD_HIGH);
    $this->assertEquals(4, NodeAnalysisService::HAZARD_CRITICAL);
  }

  /**
   * Tests valid hazard categories.
   *
   * @covers ::VALID_HAZARD_CATEGORIES
   */
  public function testValidHazardCategories(): void {
    $expected = [
      'Infra', 'Transport', 'Safety', 'Env',
      'Fire', 'Health', 'Geo', 'Met', 'Other',
    ];
    $this->assertEquals($expected, NodeAnalysisService::VALID_HAZARD_CATEGORIES);
  }

  /**
   * Tests validation of valid AI response.
   *
   * @covers ::validateResult
   */
  public function testValidateResultValid(): void {
    $service = $this->createService();

    $input = [
      'sentiment' => [
        'sentiment' => 'frustrated',
        'score' => -0.8,
        'confidence' => 0.95,
        'reasoning' => 'Upset about pothole',
      ],
      'hazard' => [
        'level' => 3,
        'category' => 'Infra',
        'reasoning' => 'Deep pothole danger',
      ],
    ];

    $result = $this->invokeMethod($service, 'validateResult', [$input]);

    $this->assertEquals('frustrated', $result['sentiment']['sentiment']);
    $this->assertEquals(-0.8, $result['sentiment']['score']);
    $this->assertEquals(0.95, $result['sentiment']['confidence']);
    $this->assertEquals(3, $result['hazard']['level']);
    $this->assertEquals('Infra', $result['hazard']['category']);
  }

  /**
   * Tests validation clamps out-of-range values.
   *
   * @covers ::validateResult
   */
  public function testValidateResultClampedValues(): void {
    $service = $this->createService();

    $input = [
      'sentiment' => [
        'sentiment' => 'neutral',
        'score' => -5.0,
        'confidence' => 2.0,
        'reasoning' => '',
      ],
      'hazard' => [
        'level' => 10,
        'category' => 'Transport',
        'reasoning' => '',
      ],
    ];

    $result = $this->invokeMethod($service, 'validateResult', [$input]);

    $this->assertEquals(-1.0, $result['sentiment']['score']);
    $this->assertEquals(1.0, $result['sentiment']['confidence']);
    $this->assertEquals(4, $result['hazard']['level']);
  }

  /**
   * Tests validation normalizes invalid sentiment.
   *
   * @covers ::validateResult
   */
  public function testValidateResultInvalidSentimentNormalized(): void {
    $service = $this->createService();

    $input = [
      'sentiment' => [
        'sentiment' => 'angry',
        'score' => -0.5,
        'confidence' => 0.8,
        'reasoning' => '',
      ],
      'hazard' => [
        'level' => 1,
        'category' => 'Unknown',
        'reasoning' => '',
      ],
    ];

    $result = $this->invokeMethod($service, 'validateResult', [$input]);

    $this->assertEquals('neutral', $result['sentiment']['sentiment']);
    $this->assertEquals('Other', $result['hazard']['category']);
  }

  /**
   * Tests validation handles missing fields with defaults.
   *
   * @covers ::validateResult
   */
  public function testValidateResultMissingFields(): void {
    $service = $this->createService();

    $input = [
      'sentiment' => [],
      'hazard' => [],
    ];

    $result = $this->invokeMethod($service, 'validateResult', [$input]);

    $this->assertEquals('neutral', $result['sentiment']['sentiment']);
    $this->assertEquals(0.0, $result['sentiment']['score']);
    $this->assertEquals(0.5, $result['sentiment']['confidence']);
    $this->assertEquals('', $result['sentiment']['reasoning']);
    $this->assertEquals(0, $result['hazard']['level']);
    $this->assertEquals('Other', $result['hazard']['category']);
    $this->assertEquals('', $result['hazard']['reasoning']);
  }

  /**
   * Tests default result structure.
   *
   * @covers ::getDefaultResult
   */
  public function testGetDefaultResult(): void {
    $service = $this->createService();
    $result = $this->invokeMethod($service, 'getDefaultResult', []);

    $this->assertEquals('neutral', $result['sentiment']['sentiment']);
    $this->assertEquals(0.0, $result['sentiment']['score']);
    $this->assertEquals(0.0, $result['sentiment']['confidence']);
    $this->assertEquals('Unable to analyze.', $result['sentiment']['reasoning']);
    $this->assertEquals(0, $result['hazard']['level']);
    $this->assertEquals('Other', $result['hazard']['category']);
  }

  /**
   * Tests summarizing a service definition.
   *
   * @covers ::summarizeServiceDefinition
   */
  public function testSummarizeServiceDefinition(): void {
    $service = $this->createService();

    $definition = [
      [
        'description' => 'Size of the pothole',
        'variable_name' => 'size',
        'values' => [
          ['name' => 'Small'],
          ['name' => 'Medium'],
          ['name' => 'Large'],
        ],
      ],
      [
        'description' => 'Material type',
      ],
      [
        'variable_name' => 'notes_field',
      ],
    ];

    $result = $this->invokeMethod($service, 'summarizeServiceDefinition', [$definition]);

    $this->assertStringContainsString('Size of the pothole', $result);
    $this->assertStringContainsString('Small, Medium, Large', $result);
    $this->assertStringContainsString('Material type', $result);
    $this->assertStringContainsString('notes_field', $result);
  }

  /**
   * Tests summarizing empty definition.
   *
   * @covers ::summarizeServiceDefinition
   */
  public function testSummarizeServiceDefinitionEmpty(): void {
    $service = $this->createService();
    $result = $this->invokeMethod($service, 'summarizeServiceDefinition', [[]]);
    $this->assertEquals('', $result);
  }

  /**
   * Tests summarizing citizen-filled attributes.
   *
   * @covers ::summarizeAttributes
   */
  public function testSummarizeAttributes(): void {
    $service = $this->createService();

    $attributes = [
      'size' => 'Large',
      'material' => ['asphalt', 'concrete'],
      'notes' => 'Very deep hole',
      'empty_field' => '',
    ];

    $result = $this->invokeMethod($service, 'summarizeAttributes', [$attributes]);

    $this->assertStringContainsString('size: Large', $result);
    $this->assertStringContainsString('material: asphalt, concrete', $result);
    $this->assertStringContainsString('notes: Very deep hole', $result);
    $this->assertStringNotContainsString('empty_field', $result);
  }

  /**
   * Tests the three-tier chat-model fallback chain.
   *
   * @covers ::resolveChatModel
   */
  public function testModelFallbackChain(): void {
    // Tier 1: explicit sentiment_analysis.model wins outright.
    $service = $this->createServiceWithConfig([
      'sentiment_analysis.model' => 'foo',
      'default_provider' => 'azure',
      'providers.azure.chat_model' => 'gpt-4.1-mini',
    ]);
    $this->assertSame('foo', $this->invokeMethod($service, 'resolveChatModel', []));

    // Tier 2: no explicit override, provider's chat_model wins.
    $service = $this->createServiceWithConfig([
      'sentiment_analysis.model' => NULL,
      'default_provider' => 'azure',
      'providers.azure.chat_model' => 'gpt-4.1-mini',
    ]);
    $this->assertSame('gpt-4.1-mini', $this->invokeMethod($service, 'resolveChatModel', []));

    // Tier 3: default_provider points at an unconfigured provider, so
    // neither the explicit override nor the provider's chat_model resolve.
    // The hardcoded safety net must fire independently of the 'openai' ?:
    // default in resolveChatModel().
    $service = $this->createServiceWithConfig([
      'sentiment_analysis.model' => NULL,
      'default_provider' => 'xyz',
      'providers.xyz.chat_model' => NULL,
    ]);
    $this->assertSame('gpt-4.1-mini', $this->invokeMethod($service, 'resolveChatModel', []));
  }

  /**
   * Creates a NodeAnalysisService with the given config key/value map.
   *
   * @param array<string, mixed> $configMap
   *   Map of config key => return value.
   *
   * @return \Drupal\markaspot_ai\Service\NodeAnalysisService
   *   The service instance.
   */
  protected function createServiceWithConfig(array $configMap): NodeAnalysisService {
    $aiClient = $this->createMock(AiClientService::class);
    $sentimentService = $this->createMock(SentimentService::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $tokenTracking = $this->createMock(TokenTrackingService::class);
    $riskScoreCalculator = $this->createMock(RiskScoreCalculator::class);
    $database = $this->createMock(Connection::class);
    $logger = $this->createMock(LoggerInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => $configMap[$key] ?? NULL,
    );

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new NodeAnalysisService(
      $aiClient,
      $sentimentService,
      $entityTypeManager,
      $configFactory,
      $loggerFactory,
      $tokenTracking,
      $riskScoreCalculator,
      $database,
    );
  }

  /**
   * Creates a NodeAnalysisService instance with mocked dependencies.
   *
   * @return \Drupal\markaspot_ai\Service\NodeAnalysisService
   *   The service instance.
   */
  protected function createService(): NodeAnalysisService {
    $aiClient = $this->createMock(AiClientService::class);
    $sentimentService = $this->createMock(SentimentService::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $tokenTracking = $this->createMock(TokenTrackingService::class);
    $riskScoreCalculator = $this->createMock(RiskScoreCalculator::class);
    $database = $this->createMock(Connection::class);
    $logger = $this->createMock(LoggerInterface::class);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new NodeAnalysisService(
      $aiClient,
      $sentimentService,
      $entityTypeManager,
      $configFactory,
      $loggerFactory,
      $tokenTracking,
      $riskScoreCalculator,
      $database,
    );
  }

  /**
   * Invokes a protected method on an object.
   *
   * @param object $object
   *   The object to invoke the method on.
   * @param string $methodName
   *   The method name.
   * @param array $parameters
   *   The method parameters.
   *
   * @return mixed
   *   The return value of the method.
   */
  protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed {
    $reflection = new \ReflectionMethod($object, $methodName);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($object, $parameters);
  }

}
