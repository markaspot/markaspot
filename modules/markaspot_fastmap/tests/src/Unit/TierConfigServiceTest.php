<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Database\Query\Delete;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the TierConfigService.
 *
 * @group markaspot_fastmap
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\TierConfigService
 */
class TierConfigServiceTest extends UnitTestCase {

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TimeInterface $time;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface $logger;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_fastmap\Service\TierConfigService
   */
  protected TierConfigService $service;

  /**
   * The tier limits config used in tests.
   *
   * @var array|null
   */
  protected ?array $tierLimits = NULL;

  /**
   * The member limits config used in tests.
   *
   * @var array|null
   */
  protected ?array $memberLimits = NULL;

  /**
   * The assignable role config used in tests.
   *
   * @var array|null
   */
  protected ?array $assignableRoles = NULL;

  /**
   * The AI budgets config used in tests.
   *
   * @var array|null
   */
  protected ?array $aiBudgets = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tierLimits = [
      'free' => ['limit' => 50, 'period' => 'published'],
      'starter' => ['limit' => 500, 'period' => 'published'],
      'pro' => ['limit' => 2000, 'period' => 'published'],
      'heart' => ['limit' => NULL, 'period' => 'published'],
    ];
    $this->memberLimits = NULL;
    $this->assignableRoles = NULL;
    $this->aiBudgets = NULL;

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'tier_limits' => $this->tierLimits,
        'member_limits' => $this->memberLimits,
        'assignable_roles' => $this->assignableRoles,
        'ai_budgets' => $this->aiBudgets,
        default => $this->resolveDottedKey($key),
      });

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('markaspot_fastmap.settings')
      ->willReturn($config);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->database = $this->createMock(Connection::class);

    $this->service = new TierConfigService(
      $this->configFactory,
      $this->entityTypeManager,
      $this->time,
      $this->logger,
      $this->database,
    );
  }

  /**
   * Resolves dotted config keys like 'tier_limits.free'.
   *
   * @param string $key
   *   The dotted key.
   *
   * @return mixed
   *   The value or NULL.
   */
  private function resolveDottedKey(string $key): mixed {
    if (str_starts_with($key, 'tier_limits.')) {
      $tier = substr($key, strlen('tier_limits.'));
      return $this->tierLimits[$tier] ?? NULL;
    }
    return NULL;
  }

  /**
   * Tests getLimits() returns correct config for known tiers.
   *
   * @covers ::getLimits
   */
  public function testGetLimitsFreeTier(): void {
    $result = $this->service->getLimits('free');

    $this->assertIsArray($result);
    $this->assertEquals(50, $result['limit']);
    $this->assertEquals('published', $result['period']);
    $this->assertArrayNotHasKey('unlimited', $result);
  }

  /**
   * Tests getLimits() returns correct config for starter tier.
   *
   * @covers ::getLimits
   */
  public function testGetLimitsStarterTier(): void {
    $result = $this->service->getLimits('starter');

    $this->assertIsArray($result);
    $this->assertEquals(500, $result['limit']);
    $this->assertEquals('published', $result['period']);
  }

  /**
   * Tests getLimits() returns correct config for pro tier.
   *
   * @covers ::getLimits
   */
  public function testGetLimitsProTier(): void {
    $result = $this->service->getLimits('pro');

    $this->assertIsArray($result);
    $this->assertEquals(2000, $result['limit']);
    $this->assertEquals('published', $result['period']);
  }

  /**
   * Tests getLimits() returns unlimited for heart tier.
   *
   * @covers ::getLimits
   * @covers ::buildLimitsResult
   */
  public function testGetLimitsHeartTierIsUnlimited(): void {
    $result = $this->service->getLimits('heart');

    $this->assertIsArray($result);
    $this->assertNull($result['limit']);
    $this->assertEquals('published', $result['period']);
    $this->assertTrue($result['unlimited']);
  }

  /**
   * Tests getLimits() falls back to free tier for unknown tiers.
   *
   * @covers ::getLimits
   */
  public function testGetLimitsUnknownTierFallsBackToFree(): void {
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Unknown or misconfigured tier'),
        $this->arrayHasKey('@tier')
      );

    $result = $this->service->getLimits('nonexistent');

    $this->assertIsArray($result);
    $this->assertEquals(50, $result['limit']);
    $this->assertEquals('published', $result['period']);
  }

  /**
   * Tests getLimits() returns NULL when no tier_limits config exists.
   *
   * @covers ::getLimits
   */
  public function testGetLimitsReturnsNullWithoutConfig(): void {
    $this->tierLimits = NULL;

    // Need to rebuild config mock since tierLimits changed.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'tier_limits' => $this->tierLimits,
        default => NULL,
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_fastmap.settings')
      ->willReturn($config);

    $service = new TierConfigService(
      $configFactory,
      $this->entityTypeManager,
      $this->time,
      $this->logger,
      $this->database,
    );

    $result = $service->getLimits('free');
    $this->assertNull($result);
  }

  /**
   * Tests getLimits() returns hard default when even free config is broken.
   *
   * @covers ::getLimits
   */
  public function testGetLimitsHardDefaultWhenFreeIsBroken(): void {
    $this->tierLimits = [
      'free' => 'corrupted',
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'tier_limits' => $this->tierLimits,
        default => NULL,
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_fastmap.settings')
      ->willReturn($config);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Tier limits config is missing or corrupted'),
        $this->anything()
      );

    $service = new TierConfigService(
      $configFactory,
      $this->entityTypeManager,
      $this->time,
      $this->logger,
      $this->database,
    );

    $result = $service->getLimits('starter');

    $this->assertIsArray($result);
    $this->assertEquals(50, $result['limit']);
    $this->assertEquals('published', $result['period']);
  }

  /**
   * Tests getLimits() does not log a warning when requesting the free tier.
   *
   * @covers ::getLimits
   */
  public function testGetLimitsDoesNotLogWarningForFreeTier(): void {
    $this->logger->expects($this->never())
      ->method('warning');

    $this->service->getLimits('free');
  }

  /**
   * Tests getAllLimits() returns all configured tiers.
   *
   * @covers ::getAllLimits
   */
  public function testGetAllLimitsReturnsAllTiers(): void {
    $result = $this->service->getAllLimits();

    $this->assertArrayHasKey('free', $result);
    $this->assertArrayHasKey('starter', $result);
    $this->assertArrayHasKey('pro', $result);
    $this->assertArrayHasKey('heart', $result);
    $this->assertEquals(50, $result['free']['limit']);
    $this->assertEquals(500, $result['starter']['limit']);
    $this->assertEquals(2000, $result['pro']['limit']);
    $this->assertNull($result['heart']['limit']);
  }

  /**
   * Tests getTierLimitConfigIssues() returns no issues for valid config.
   *
   * @covers ::getTierLimitConfigIssues
   * @covers ::validateTierLimitConfig
   * @covers ::isValidLimitValue
   */
  public function testGetTierLimitConfigIssuesReturnsEmptyForValidConfig(): void {
    $this->assertSame([], $this->service->getTierLimitConfigIssues());
  }

  /**
   * Tests getTierLimitConfigIssues() reports missing config.
   *
   * @covers ::getTierLimitConfigIssues
   */
  public function testGetTierLimitConfigIssuesReportsMissingConfig(): void {
    $this->tierLimits = NULL;

    $this->assertSame([
      'tier_limits is missing or empty.',
    ], $this->service->getTierLimitConfigIssues());
  }

  /**
   * Tests getTierLimitConfigIssues() reports drift from field_tier values.
   *
   * @covers ::getTierLimitConfigIssues
   * @covers ::validateTierLimitConfig
   * @covers ::isValidLimitValue
   */
  public function testGetTierLimitConfigIssuesReportsConfigDrift(): void {
    $this->tierLimits = [
      'free' => ['limit' => 'invalid', 'period' => 'published'],
      'starter' => ['period' => 'monthly'],
      'pro' => ['limit' => 2000, 'period' => 'weekly'],
      'enterprise' => ['limit' => 9999, 'period' => 'published'],
    ];

    $this->assertSame([
      'tier_limits.free.limit must be a non-negative integer or null.',
      'tier_limits.starter.limit is missing.',
      'tier_limits.pro.period must be one of: published, monthly, total.',
      'tier_limits.heart is missing.',
      'tier_limits.enterprise is not a supported field_tier value.',
    ], $this->service->getTierLimitConfigIssues());
  }

  /**
   * Tests countRequests() with 'published' period uses status=1 condition.
   *
   * @covers ::countRequests
   */
  public function testCountRequestsPublishedPeriod(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn(42);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $result = $this->service->countRequests(14, 'published');
    $this->assertEquals(42, $result);
  }

  /**
   * Tests countRequests() with 'monthly' period applies time condition.
   *
   * @covers ::countRequests
   */
  public function testCountRequestsMonthlyPeriod(): void {
    // March 15, 2026 12:00:00.
    $now = mktime(12, 0, 0, 3, 15, 2026);
    $this->time->method('getRequestTime')->willReturn($now);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->expects($this->exactly(3))
      ->method('condition')
      ->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn(7);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $result = $this->service->countRequests(14, 'monthly');
    $this->assertEquals(7, $result);
  }

  /**
   * Tests countRequests() with 'total' period has no time or status filter.
   *
   * @covers ::countRequests
   */
  public function testCountRequestsTotalPeriod(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    // Should only have type and jurisdiction conditions.
    $query->expects($this->exactly(2))
      ->method('condition')
      ->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn(100);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $result = $this->service->countRequests(14, 'total');
    $this->assertEquals(100, $result);
  }

  /**
   * Tests getMemberLimit() returns configured values.
   *
   * @covers ::getMemberLimit
   */
  public function testGetMemberLimitFromConfig(): void {
    $this->memberLimits = [
      'free' => 2,
      'starter' => 10,
      'pro' => 50,
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'tier_limits' => $this->tierLimits,
        'member_limits' => $this->memberLimits,
        'ai_budgets' => $this->aiBudgets,
        default => NULL,
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_fastmap.settings')
      ->willReturn($config);

    $service = new TierConfigService(
      $configFactory,
      $this->entityTypeManager,
      $this->time,
      $this->logger,
      $this->database,
    );

    $this->assertEquals(2, $service->getMemberLimit('free'));
    $this->assertEquals(10, $service->getMemberLimit('starter'));
    $this->assertEquals(50, $service->getMemberLimit('pro'));
  }

  /**
   * Tests getMemberLimit() returns hardcoded defaults when config is empty.
   *
   * @covers ::getMemberLimit
   */
  public function testGetMemberLimitHardcodedDefaults(): void {
    $this->assertEquals(1, $this->service->getMemberLimit('free'));
    $this->assertEquals(5, $this->service->getMemberLimit('starter'));
    $this->assertEquals(20, $this->service->getMemberLimit('pro'));
    $this->assertEquals(5, $this->service->getMemberLimit('heart'));
  }

  /**
   * Tests getMemberLimit() returns NULL for unknown tiers.
   *
   * @covers ::getMemberLimit
   */
  public function testGetMemberLimitUnknownTier(): void {
    $this->assertNull($this->service->getMemberLimit('nonexistent'));
  }

  /**
   * Tests getAssignableRoleIds() returns hardcoded defaults.
   *
   * @covers ::getAssignableRoleIds
   * @covers ::normalizeRoleIds
   */
  public function testGetAssignableRoleIdsHardcodedDefaults(): void {
    $this->assertSame(['jur-member', 'org-member'], $this->service->getAssignableRoleIds('free'));
    $this->assertSame([
      'jur-member',
      'jur-moderator',
      'org-member',
      'org-moderator',
      'jur-tenant_admin',
      'org-tenant_admin',
    ], $this->service->getAssignableRoleIds('starter'));
  }

  /**
   * Tests getAssignableRoleIds() returns configured values.
   *
   * @covers ::getAssignableRoleIds
   * @covers ::normalizeRoleIds
   */
  public function testGetAssignableRoleIdsFromConfig(): void {
    $this->assignableRoles = [
      'free' => ['jur-member', '', 'jur-member', ' org-member '],
      'starter' => ['jur-member', 'jur-moderator'],
    ];

    $this->assertSame(['jur-member', 'org-member'], $this->service->getAssignableRoleIds('free'));
    $this->assertSame(['jur-member', 'jur-moderator'], $this->service->getAssignableRoleIds('starter'));
  }

  /**
   * Tests getAssignableRoleIds() falls back to free roles for unknown tiers.
   *
   * @covers ::getAssignableRoleIds
   */
  public function testGetAssignableRoleIdsUnknownTierFallsBackToFree(): void {
    $this->assertSame(['jur-member', 'org-member'], $this->service->getAssignableRoleIds('nonexistent'));
  }

  /**
   * Tests countMembers() returns zero for nonexistent group.
   *
   * @covers ::countMembers
   */
  public function testCountMembersGroupNotFound(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $storage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $result = $this->service->countMembers(999);
    $this->assertEquals(0, $result);
  }

  /**
   * Tests countMembers() queries with correct membership type.
   *
   * @covers ::countMembers
   */
  public function testCountMembersQueriesCorrectType(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->with(14)->willReturn($group);

    $relationshipQuery = $this->createMock(QueryInterface::class);
    $relationshipQuery->method('accessCheck')->willReturnSelf();
    $relationshipQuery->method('condition')->willReturnSelf();
    $relationshipQuery->method('count')->willReturnSelf();
    $relationshipQuery->method('execute')->willReturn(3);

    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $relationshipStorage->method('getQuery')->willReturn($relationshipQuery);

    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $groupStorage,
        'group_relationship' => $relationshipStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $result = $this->service->countMembers(14);
    $this->assertEquals(3, $result);
  }

  /**
   * Tests getAIBudget() returns configured values.
   *
   * @covers ::getAIBudget
   */
  public function testGetAiBudgetFromConfig(): void {
    $this->aiBudgets = [
      'free' => 100,
      'starter' => 300,
      'pro' => 1000,
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'tier_limits' => $this->tierLimits,
        'member_limits' => $this->memberLimits,
        'ai_budgets' => $this->aiBudgets,
        default => NULL,
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_fastmap.settings')
      ->willReturn($config);

    $service = new TierConfigService(
      $configFactory,
      $this->entityTypeManager,
      $this->time,
      $this->logger,
      $this->database,
    );

    $this->assertEquals(100, $service->getAIBudget('free'));
    $this->assertEquals(300, $service->getAIBudget('starter'));
    $this->assertEquals(1000, $service->getAIBudget('pro'));
  }

  /**
   * Tests getAIBudget() returns hardcoded defaults when config is empty.
   *
   * @covers ::getAIBudget
   */
  public function testGetAiBudgetHardcodedDefaults(): void {
    $this->assertEquals(50, $this->service->getAIBudget('free'));
    $this->assertEquals(200, $this->service->getAIBudget('starter'));
    $this->assertEquals(500, $this->service->getAIBudget('pro'));
    $this->assertEquals(100, $this->service->getAIBudget('heart'));
    $this->assertEquals(0, $this->service->getAIBudget('partner'));
  }

  /**
   * Tests getAIBudget() returns free default for unknown tiers.
   *
   * @covers ::getAIBudget
   */
  public function testGetAiBudgetUnknownTierDefault(): void {
    $this->assertEquals(50, $this->service->getAIBudget('nonexistent'));
  }

  /**
   * Tests isAIBudgetExhausted() returns FALSE for unlimited (0) budgets.
   *
   * @covers ::isAIBudgetExhausted
   */
  public function testIsAiBudgetExhaustedFalseForUnlimited(): void {
    // Partner tier has budget 0 (unlimited).
    $result = $this->service->isAIBudgetExhausted(14, 'partner');
    $this->assertFalse($result);
  }

  /**
   * Tests pruneAIUsage() calls database delete with correct condition.
   *
   * @covers ::pruneAIUsage
   */
  public function testPruneAiUsage(): void {
    $olderThan = 1700000000;

    $delete = $this->createMock(Delete::class);
    $delete->expects($this->once())
      ->method('condition')
      ->with('created', $olderThan, '<')
      ->willReturnSelf();
    $delete->method('execute')->willReturn(5);

    $this->database->expects($this->once())
      ->method('delete')
      ->with('markaspot_ai_usage')
      ->willReturn($delete);

    $result = $this->service->pruneAIUsage($olderThan);
    $this->assertEquals(5, $result);
  }

  /**
   * Tests buildLimitsResult() normalizes unlimited tiers.
   *
   * @covers ::buildLimitsResult
   */
  public function testBuildLimitsResultWithPeriodDefault(): void {
    // A tier without an explicit period should default to 'published'.
    $this->tierLimits['custom'] = ['limit' => 100];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'tier_limits' => $this->tierLimits,
        default => $this->resolveDottedKey($key),
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_fastmap.settings')
      ->willReturn($config);

    $service = new TierConfigService(
      $configFactory,
      $this->entityTypeManager,
      $this->time,
      $this->logger,
      $this->database,
    );

    $result = $service->getLimits('custom');

    $this->assertIsArray($result);
    $this->assertEquals(100, $result['limit']);
    $this->assertEquals('published', $result['period']);
  }

}
