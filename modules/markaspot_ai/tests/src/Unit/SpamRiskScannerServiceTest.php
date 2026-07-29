<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\SpamRiskScannerService;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Service/SpamRiskScannerService.php';

/**
 * Tests spam risk scanning helpers.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\SpamRiskScannerService
 */
class SpamRiskScannerServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected SpamRiskScannerService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    $this->service = new SpamRiskScannerService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(AiClientService::class),
      $time,
      $this->createMock(FeatureScopeResolver::class),
      $loggerFactory,
    );
  }

  /**
   * @covers ::scoreWorkspaceSignals
   */
  public function testScoreWorkspaceSignalsFlagsBurstLinksAndDuplicates(): void {
    $result = $this->service->scoreWorkspaceSignals([
      'request_count' => 12,
      'oldest_created' => 1_699_999_000,
      'newest_created' => 1_699_999_800,
      'workspace_created' => 1_699_998_000,
      'texts' => [
        'Broken lamp. Visit https://spam.example now.',
        'Broken lamp. Visit https://spam.example now.',
        'Casino SEO backlink offer for everyone.',
      ],
    ]);

    $this->assertGreaterThanOrEqual(80, $result['risk_score']);
    $this->assertContains($result['decision'], ['review_high', 'block_recommended']);
    $this->assertNotEmpty($result['reasons']);
  }

  /**
   * @covers ::scoreWorkspaceSignals
   */
  public function testScoreWorkspaceSignalsKeepsNormalFirstRequestsLowRisk(): void {
    $result = $this->service->scoreWorkspaceSignals([
      'request_count' => 3,
      'oldest_created' => 1_699_999_000,
      'newest_created' => 1_699_999_800,
      'workspace_created' => 1_699_998_000,
      'texts' => [
        'The street light on Main Street is broken.',
        'There is a pothole near the school crossing.',
        'Trash bins were not collected this morning.',
      ],
    ]);

    $this->assertLessThan(50, $result['risk_score']);
    $this->assertSame('clean', $result['decision']);
  }

  /**
   * @covers ::redactForAi
   */
  public function testRedactForAiMasksPiiAndLinks(): void {
    $redacted = $this->service->redactForAi('Call +49 221 123456 or mail jane@example.com. Visit https://example.com/path');

    $this->assertStringContainsString('[phone]', $redacted);
    $this->assertStringContainsString('[email]', $redacted);
    $this->assertStringContainsString('[url]', $redacted);
    $this->assertStringNotContainsString('jane@example.com', $redacted);
    $this->assertStringNotContainsString('https://example.com/path', $redacted);
  }

  /**
   * Regression: mergeAiDecision must default to the Anthropic adapter.
   *
   * Without explicit provider, the scanner used to fall through to
   * AiClientService::chat()'s default_provider — silently routing redacted
   * tenant samples to OpenAI/Azure/IONOS. The privacy contract requires the
   * existing chatAnthropic() adapter to be used unless the operator passes
   * an explicit provider override.
   */
  public function testMergeAiDecisionPinsProviderToAnthropic(): void {
    $aiClient = $this->createMock(AiClientService::class);
    $aiClient->expects($this->once())
      ->method('chat')
      ->with(
        $this->isType('array'),
        $this->callback(static fn(array $options): bool =>
          ($options['provider'] ?? NULL) === 'anthropic'
          && ($options['temperature'] ?? NULL) === 0
        )
      )
      ->willReturn([
        'choices' => [
          ['message' => ['content' => '{"risk_score":60,"decision":"review","reasons":["bot"]}']],
        ],
      ]);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    $service = new SpamRiskScannerService(
      $this->createMock(EntityTypeManagerInterface::class),
      $aiClient,
      $time,
      $this->createMock(FeatureScopeResolver::class),
      $loggerFactory,
    );

    $method = new \ReflectionMethod($service, 'mergeAiDecision');
    $method->setAccessible(TRUE);
    $method->invoke(
      $service,
      ['risk_score' => 55, 'decision' => 'review', 'reasons' => ['burst']],
      ['request_count' => 12, 'texts' => ['Sample report content for testing.']],
      // No provider override — must still go to anthropic.
      NULL,
    );
  }

  /**
   * Explicit operator override is still honoured (e.g. --provider=openai).
   */
  public function testMergeAiDecisionAllowsExplicitProviderOverride(): void {
    $aiClient = $this->createMock(AiClientService::class);
    $aiClient->expects($this->once())
      ->method('chat')
      ->with(
        $this->isType('array'),
        $this->callback(static fn(array $options): bool =>
          ($options['provider'] ?? NULL) === 'openai'
        )
      )
      ->willReturn(['choices' => [['message' => ['content' => '{}']]]]);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    $service = new SpamRiskScannerService(
      $this->createMock(EntityTypeManagerInterface::class),
      $aiClient,
      $time,
      $this->createMock(FeatureScopeResolver::class),
      $loggerFactory,
    );

    $method = new \ReflectionMethod($service, 'mergeAiDecision');
    $method->setAccessible(TRUE);
    $method->invoke(
      $service,
      ['risk_score' => 55, 'decision' => 'review', 'reasons' => []],
      ['request_count' => 12, 'texts' => ['sample']],
      'openai',
    );
  }

  /**
   * Auto-blocking must never change a self-hosted workspace.
   *
   * @covers ::blockWorkspace
   */
  public function testBlockWorkspaceSkipsSelfHostedPlatform(): void {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->method('isSelfServicePlatform')->willReturn(FALSE);
    $service = $this->scannerWithPlatformResolver($resolver);

    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->never())->method('set');
    $group->expects($this->never())->method('save');

    $this->assertSame(
      'skipped_not_self_service',
      $this->invokeBlockWorkspace($service, $group),
    );
  }

  /**
   * Auto-blocking retains its existing behavior on the SaaS platform.
   *
   * @covers ::blockWorkspace
   */
  public function testBlockWorkspaceBlocksOnSelfServicePlatform(): void {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->method('isSelfServicePlatform')->willReturn(TRUE);
    $service = $this->scannerWithPlatformResolver($resolver);

    $visibility = $this->createMock(FieldItemListInterface::class);
    $visibility->method('isEmpty')->willReturn(TRUE);
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->with('field_visibility')->willReturn(TRUE);
    $group->method('get')->with('field_visibility')->willReturn($visibility);
    $group->expects($this->once())
      ->method('set')
      ->with('field_visibility', 'blocked');
    $group->expects($this->once())->method('save');

    $this->assertSame('blocked', $this->invokeBlockWorkspace($service, $group));
  }

  /**
   * Builds a scanner with a controlled platform classifier.
   */
  private function scannerWithPlatformResolver(FeatureScopeResolver $resolver): SpamRiskScannerService {
    $time = $this->createMock(TimeInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    return new SpamRiskScannerService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(AiClientService::class),
      $time,
      $resolver,
      $loggerFactory,
    );
  }

  /**
   * Invokes the protected workspace block decision.
   */
  private function invokeBlockWorkspace(SpamRiskScannerService $service, GroupInterface $group): string {
    $method = new \ReflectionMethod($service, 'blockWorkspace');
    $method->setAccessible(TRUE);
    return (string) $method->invoke($service, $group);
  }

}
