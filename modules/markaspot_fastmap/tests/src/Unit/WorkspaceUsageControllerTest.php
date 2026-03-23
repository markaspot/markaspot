<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Controller\WorkspaceUsageController;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Tests the WorkspaceUsageController.
 *
 * @group markaspot_fastmap
 * @coversDefaultClass \Drupal\markaspot_fastmap\Controller\WorkspaceUsageController
 */
class WorkspaceUsageControllerTest extends UnitTestCase {

  /**
   * The mocked tier config service.
   *
   * @var \Drupal\markaspot_fastmap\Service\TierConfigService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TierConfigService $tierConfig;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_fastmap\Controller\WorkspaceUsageController
   */
  protected WorkspaceUsageController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $config = $this->createMock(ImmutableConfig::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    // Set up container for Cache::mergeContexts() used by AccessResult.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    // Set up the Drupal container.
    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('markaspot_fastmap.tier_config', $this->tierConfig);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $this->controller = WorkspaceUsageController::create($container);
  }

  /**
   * Creates a mock group entity with optional fields.
   *
   * @param array $fields
   *   Field values keyed by field name.
   * @param int $id
   *   The group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(array $fields = [], int $id = 14): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn('jur');
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $group->method('getCacheContexts')->willReturn([]);

    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => array_key_exists($name, $fields));

    $group->method('get')
      ->willReturnCallback(function (string $name) use ($fields) {
        $value = $fields[$name] ?? NULL;
        // @phpcs:disable Drupal.Commenting.DocComment
        return new class ($value) {

          /**
           * The field value.
           *
           * @var mixed
           */
          public $value;

          /**
           * Whether the field is empty.
           *
           * @var bool
           */
          private bool $empty;

          /**
           * Constructs a field item stub.
           */
          public function __construct($value) {
            $this->value = $value;
            $this->empty = ($value === NULL);
          }

          /**
           * Returns whether the field is empty.
           */
          public function isEmpty(): bool {
            return $this->empty;
          }

        };
        // @phpcs:enable
      });

    return $group;
  }

  /**
   * Tests access() returns forbidden when group is not found.
   *
   * @covers ::access
   */
  public function testAccessForbiddenWhenGroupNotFound(): void {
    $account = $this->createMock(AccountInterface::class);
    $this->groupStorage->method('load')->willReturn(NULL);

    $result = $this->controller->access('999', $account);

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests access() grants access to admin users.
   *
   * @covers ::access
   */
  public function testAccessAllowedForAdminUser(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(fn(string $perm) => $perm === 'administer nodes');

    $result = $this->controller->access('14', $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests access() denies access when user lacks permission.
   *
   * @covers ::access
   */
  public function testAccessDeniedWithoutPermission(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = $this->controller->access('14', $account);

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests usage() returns 404 when group is not found.
   *
   * @covers ::usage
   */
  public function testUsageReturns404WhenGroupNotFound(): void {
    $this->groupStorage->method('load')->willReturn(NULL);

    $response = $this->controller->usage('999');

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(404, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Jurisdiction not found', $data['error']);
  }

  /**
   * Tests usage() returns free tier data with defaults.
   *
   * @covers ::usage
   */
  public function testUsageReturnsFreeTierDefaults(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->tierConfig->method('getLimits')
      ->with('free')
      ->willReturn(['limit' => 50, 'period' => 'published']);

    $this->tierConfig->method('countRequests')
      ->with(14, 'published')
      ->willReturn(10);

    $response = $this->controller->usage('14');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('free', $data['tier']);
    $this->assertEquals(50, $data['limit']);
    $this->assertEquals(10, $data['count']);
    $this->assertEquals('published', $data['period']);
    $this->assertFalse($data['unlimited']);
    $this->assertEquals(40, $data['remaining']);
    $this->assertEquals(20, $data['percentage']);
  }

  /**
   * Tests usage() returns unlimited data for heart tier.
   *
   * @covers ::usage
   */
  public function testUsageReturnsUnlimitedForHeartTier(): void {
    $group = $this->createMockGroup([
      'field_tier' => 'heart',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->tierConfig->method('getLimits')
      ->with('heart')
      ->willReturn([
        'limit' => NULL,
        'period' => 'published',
        'unlimited' => TRUE,
      ]);

    $this->tierConfig->method('countRequests')
      ->with(14, 'published')
      ->willReturn(500);

    $response = $this->controller->usage('14');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('heart', $data['tier']);
    $this->assertNull($data['limit']);
    $this->assertEquals(500, $data['count']);
    $this->assertTrue($data['unlimited']);
  }

  /**
   * Tests usage() returns correct percentage at 100%.
   *
   * @covers ::usage
   */
  public function testUsagePercentageCapsAt100(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->tierConfig->method('getLimits')
      ->with('free')
      ->willReturn(['limit' => 50, 'period' => 'published']);

    // Count exceeds limit.
    $this->tierConfig->method('countRequests')
      ->with(14, 'published')
      ->willReturn(75);

    $response = $this->controller->usage('14');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(100, $data['percentage']);
    $this->assertEquals(0, $data['remaining']);
  }

  /**
   * Tests usage() handles NULL tier limits config (module not configured).
   *
   * @covers ::usage
   */
  public function testUsageHandlesNullTierLimits(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->tierConfig->method('getLimits')
      ->with('free')
      ->willReturn(NULL);

    $response = $this->controller->usage('14');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertNull($data['limit']);
    $this->assertNull($data['count']);
    $this->assertTrue($data['unlimited']);
  }

  /**
   * Tests usage() reads tier from entity field when present.
   *
   * @covers ::usage
   */
  public function testUsageReadsTierFromEntity(): void {
    $group = $this->createMockGroup([
      'field_tier' => 'pro',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->tierConfig->expects($this->once())
      ->method('getLimits')
      ->with('pro')
      ->willReturn(['limit' => 2000, 'period' => 'published']);

    $this->tierConfig->method('countRequests')->willReturn(100);

    $response = $this->controller->usage('14');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('pro', $data['tier']);
    $this->assertEquals(2000, $data['limit']);
  }

  /**
   * Tests usage() calculates percentage correctly for partial usage.
   *
   * @covers ::usage
   */
  public function testUsageCalculatesPercentageCorrectly(): void {
    $group = $this->createMockGroup([
      'field_tier' => 'starter',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->tierConfig->method('getLimits')
      ->with('starter')
      ->willReturn(['limit' => 500, 'period' => 'published']);

    // 333 of 500 = 66.6% -> rounds to 67%.
    $this->tierConfig->method('countRequests')
      ->with(14, 'published')
      ->willReturn(333);

    $response = $this->controller->usage('14');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(67, $data['percentage']);
    $this->assertEquals(167, $data['remaining']);
  }

}
