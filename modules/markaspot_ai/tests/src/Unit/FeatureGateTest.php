<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__ . '/../../../markaspot_ai.module';

/**
 * Tests markaspot_ai tenant and backfill gates.
 *
 * @group markaspot_ai
 */
final class FeatureGateTest extends UnitTestCase {

  /**
   * Explicit tenant opt-in enables markaspot_ai processing.
   */
  public function testTenantOptInEnablesAiProcessing(): void {
    $this->setGroupStorage($this->buildGroup(json_encode([
      'features' => ['aiProcessing' => TRUE],
    ])));

    $this->assertTrue(_markaspot_ai_is_ai_enabled_for_node($this->buildNode(42)));
  }

  /**
   * Explicit tenant opt-out disables markaspot_ai processing.
   */
  public function testTenantOptOutDisablesAiProcessing(): void {
    $this->setGroupStorage($this->buildGroup(json_encode([
      'features' => ['aiProcessing' => FALSE],
    ])));

    $this->assertFalse(_markaspot_ai_is_ai_enabled_for_node($this->buildNode(42)));
  }

  /**
   * Missing tenant config fails closed and does not fall back to aiAnalysis.
   */
  public function testMissingTenantConfigFailsClosed(): void {
    $this->setGroupStorage($this->buildGroup(json_encode([
      'features' => ['aiAnalysis' => TRUE],
    ])));

    $this->assertFalse(_markaspot_ai_is_ai_enabled_for_node($this->buildNode(42)));
    $this->assertFalse(_markaspot_ai_is_ai_enabled_for_node($this->buildNode(NULL)));

    $this->setGroupStorage($this->buildGroup(NULL));
    $this->assertFalse(_markaspot_ai_is_ai_enabled_for_node($this->buildNode(42)));

    $this->setGroupStorage($this->buildGroup('{invalid-json'));
    $this->assertFalse(_markaspot_ai_is_ai_enabled_for_node($this->buildNode(42)));
  }

  /**
   * PII redaction uses its own explicit tenant opt-in flag.
   */
  public function testPiiRedactionRequiresExplicitFlag(): void {
    $this->setGroupStorage($this->buildGroup(json_encode([
      'features' => ['aiProcessing' => TRUE],
    ])));
    $this->assertFalse(_markaspot_ai_is_feature_enabled_for_node(
      $this->buildNode(42),
      'piiRedaction'
    ));

    $this->setGroupStorage($this->buildGroup(json_encode([
      'features' => ['piiRedaction' => TRUE],
    ])));
    $this->assertTrue(_markaspot_ai_is_feature_enabled_for_node(
      $this->buildNode(42),
      'piiRedaction'
    ));
  }

  /**
   * Cron does not auto-backfill when ai_processing.auto_backfill is absent/off.
   */
  public function testCronDoesNotBackfillWithoutExplicitConfig(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => match ($key) {
      'default_provider' => 'openai',
      'providers.openai' => ['api_key' => 'test-key'],
      'ai_processing.auto_backfill' => FALSE,
      default => NULL,
    });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with('markaspot_ai.last_cron', 0)
      ->willReturn(0);
    $state->expects($this->once())
      ->method('set')
      ->with('markaspot_ai.last_cron', 7200);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(7200);

    $embedding = $this->createMock(EmbeddingService::class);
    $embedding->expects($this->never())->method('findMissingEmbeddings');

    $tokenTracking = $this->createMock(TokenTrackingService::class);
    $tokenTracking->expects($this->once())
      ->method('cleanupOldRecords')
      ->with(90)
      ->willReturn(0);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('state', $state);
    $container->set('datetime.time', $time);
    $container->set('markaspot_ai.embedding', $embedding);
    $container->set('markaspot_ai.token_tracking', $tokenTracking);
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);

    markaspot_ai_cron();
  }

  /**
   * Installs a mock group storage in the Drupal container.
   */
  private function setGroupStorage(?GroupInterface $group): void {
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->willReturnCallback(
      static fn(int|string $id): ?GroupInterface => (string) $id === '42' ? $group : NULL,
    );

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entityTypeManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a service request node with an optional direct jurisdiction field.
   */
  private function buildNode(?int $jurisdictionId): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(100);
    $node->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_jurisdiction' && $jurisdictionId !== NULL,
    );
    $node->method('get')->willReturnCallback(
      fn(string $field): FieldItemListInterface => $this->buildField(
        targetId: $jurisdictionId,
        empty: $jurisdictionId === NULL,
      ),
    );

    return $node;
  }

  /**
   * Builds a jurisdiction group with optional field_nuxt_config JSON.
   */
  private function buildGroup(?string $json): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')
      ->willReturnCallback(static fn(string $field): bool => $field === 'field_nuxt_config' && $json !== NULL);
    $group->method('get')
      ->willReturnCallback(fn(string $field): FieldItemListInterface => $this->buildField(
        value: $json,
        empty: $json === NULL || $json === '',
      ));

    return $group;
  }

  /**
   * Builds a typed field-list mock with Drupal's structured field values.
   */
  private function buildField(mixed $value = NULL, ?int $targetId = NULL, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('getValue')->willReturn(match (TRUE) {
      $targetId !== NULL => [['target_id' => $targetId]],
      $value !== NULL => [['value' => $value]],
      default => [],
    });

    return $field;
  }

}
