<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests tier-limit lock behavior in the FastMap presave hook.
 *
 * @group markaspot_fastmap
 */
class TierLimitLockTest extends UnitTestCase {

  /**
   * The jurisdiction lock name used by the tests.
   */
  private const LOCK_NAME = 'markaspot_fastmap:tier_limit:14';

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    drupal_static_reset('_markaspot_fastmap_tier_limit_locks');
    drupal_static_reset('_markaspot_fastmap_tier_limit_node_locks');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    drupal_static_reset('_markaspot_fastmap_tier_limit_locks');
    drupal_static_reset('_markaspot_fastmap_tier_limit_node_locks');
    parent::tearDown();
  }

  /**
   * Tests that successful saves count while holding the jurisdiction lock.
   */
  public function testPresaveCountsUnderLockAndHoldsUntilShutdown(): void {
    $lock = $this->createLockBackend();
    $node = $this->createServiceRequestNode();
    $tierConfig = $this->createTierConfig(49, function () use ($lock): void {
      $this->assertTrue($lock->isHeld(self::LOCK_NAME));
    });
    $this->setDrupalContainer($tierConfig, $lock);

    markaspot_fastmap_node_presave($node);

    $this->assertTrue($lock->isHeld(self::LOCK_NAME));
    _markaspot_fastmap_release_node_tier_limit_lock($node);
    $this->assertFalse($lock->isHeld(self::LOCK_NAME));
    $this->assertSame([
      ['acquire', self::LOCK_NAME, 30.0],
      ['release', self::LOCK_NAME],
    ], $lock->events);
  }

  /**
   * Tests that lock contention aborts without counting or saving hidden nodes.
   */
  public function testPresaveThrowsWhenLockCannotBeAcquired(): void {
    $lock = $this->createLockBackend([FALSE, FALSE]);
    $node = $this->createServiceRequestNode();
    $tierConfig = $this->createMock(TierConfigService::class);
    $tierConfig->expects($this->never())->method('countRequests');
    $tierConfig->method('getLimits')
      ->willReturn(['limit' => 50, 'period' => 'published']);
    $this->setDrupalContainer($tierConfig, $lock);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Cannot acquire tier limit lock for jurisdiction 14.');

    try {
      markaspot_fastmap_node_presave($node);
    }
    finally {
      $this->assertFalse($lock->isHeld(self::LOCK_NAME));
      $this->assertSame([
        ['acquire', self::LOCK_NAME, 30.0],
        ['wait', self::LOCK_NAME, 1],
        ['acquire', self::LOCK_NAME, 30.0],
      ], $lock->events);
    }
  }

  /**
   * Tests that new nodes at the limit are unpublished and release the lock.
   */
  public function testPresaveUnpublishesNewNodeAtLimitAndReleasesLock(): void {
    $lock = $this->createLockBackend();
    $node = $this->createServiceRequestNode(expectUnpublished: TRUE);
    $tierConfig = $this->createTierConfig(50, function () use ($lock): void {
      $this->assertTrue($lock->isHeld(self::LOCK_NAME));
    });
    $this->setDrupalContainer($tierConfig, $lock);

    markaspot_fastmap_node_presave($node);

    $this->assertFalse($lock->isHeld(self::LOCK_NAME));
    $this->assertSame([
      ['acquire', self::LOCK_NAME, 30.0],
      ['release', self::LOCK_NAME],
    ], $lock->events);
  }

  /**
   * Tests that publish transitions at the limit throw and release the lock.
   */
  public function testPublishTransitionAtLimitThrowsAndReleasesLock(): void {
    $lock = $this->createLockBackend();
    $original = $this->createMock(NodeInterface::class);
    $original->method('isPublished')->willReturn(FALSE);
    $node = $this->createServiceRequestNode(isNew: FALSE, original: $original);
    $tierConfig = $this->createTierConfig(50, function () use ($lock): void {
      $this->assertTrue($lock->isHeld(self::LOCK_NAME));
    });
    $this->setDrupalContainer($tierConfig, $lock);

    try {
      markaspot_fastmap_node_presave($node);
      $this->fail('Expected EntityStorageException was not thrown.');
    }
    catch (EntityStorageException $e) {
      $this->assertSame(
        'Cannot publish service request: jurisdiction 14 has reached the published report limit of 50.',
        $e->getMessage()
      );
    }

    $this->assertFalse($lock->isHeld(self::LOCK_NAME));
    $this->assertSame([
      ['acquire', self::LOCK_NAME, 30.0],
      ['release', self::LOCK_NAME],
    ], $lock->events);
  }

  /**
   * Builds a Drupal container for the presave hook under test.
   */
  private function setDrupalContainer(TierConfigService $tierConfig, LockBackendInterface $lock): void {
    $group = $this->createJurisdictionGroup();
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->with(14)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $entityTypeId) => match ($entityTypeId) {
        'group' => $groupStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);

    $requestStack = new RequestStack();
    $requestStack->push(new Request());

    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_fastmap')
      ->willReturn($logger);

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')->willReturnCallback(
      fn(string $key) => $key === 'jurisdiction_group_type' ? 'jur' : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      fn(string $name) => $name === 'markaspot_open311.settings' ? $open311Config : $this->createMock(ImmutableConfig::class),
    );

    $container = new ContainerBuilder();
    $container->set('current_user', $currentUser);
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('markaspot_fastmap.tier_config', $tierConfig);
    $container->set('lock', $lock);
    $container->set('request_stack', $requestStack);
    $container->set('logger.factory', $loggerFactory);
    $container->set('config.factory', $configFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a service request node mock with a jurisdiction field.
   */
  private function createServiceRequestNode(
    bool $isNew = TRUE,
    bool $expectUnpublished = FALSE,
    ?NodeInterface $original = NULL,
  ): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn($isNew);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('id')->willReturn(99);
    $node->method('getOriginal')->willReturn($original);
    $node->method('hasField')
      ->willReturnCallback(fn(string $field): bool => $field === 'field_jurisdiction');
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($this->createJurisdictionField());

    if ($expectUnpublished) {
      $node->expects($this->once())->method('setUnpublished');
    }
    else {
      $node->expects($this->never())->method('setUnpublished');
    }

    return $node;
  }

  /**
   * Creates a field item list pointing at the test jurisdiction.
   */
  private function createJurisdictionField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')
      ->willReturnCallback(fn(string $property): mixed => match ($property) {
        'target_id' => 14,
        'entity' => $this->createJurisdictionGroup(),
        default => NULL,
      });

    return $field;
  }

  /**
   * Creates the test jurisdiction group with a published-count tier.
   */
  private function createJurisdictionGroup(): GroupInterface {
    $tierField = $this->createMock(FieldItemListInterface::class);
    $tierField->method('isEmpty')->willReturn(FALSE);
    $tierField->method('__get')
      ->with('value')
      ->willReturn('free');

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')
      ->willReturnCallback(fn(string $field): bool => $field === 'field_tier');
    $group->method('get')
      ->with('field_tier')
      ->willReturn($tierField);

    return $group;
  }

  /**
   * Creates a tier config service mock.
   */
  private function createTierConfig(int $count, callable $onCount): TierConfigService {
    $tierConfig = $this->createMock(TierConfigService::class);
    $tierConfig->method('getLimits')
      ->with('free')
      ->willReturn(['limit' => 50, 'period' => 'published']);
    $tierConfig->expects($this->once())
      ->method('countRequests')
      ->with(14, 'published')
      ->willReturnCallback(function () use ($count, $onCount): int {
        $onCount();
        return $count;
      });

    return $tierConfig;
  }

  /**
   * Creates an inspectable lock backend.
   */
  private function createLockBackend(array $acquireResults = [TRUE]): LockBackendInterface {
    return new class($acquireResults) implements LockBackendInterface {

      /**
       * Recorded lock operations.
       *
       * @var array<int, array<int, mixed>>
       */
      public array $events = [];

      /**
       * Held locks keyed by lock name.
       *
       * @var array<string, bool>
       */
      private array $heldLocks = [];

      /**
       * Constructs the lock backend.
       *
       * @param bool[] $acquireResults
       *   Results returned by acquire() calls.
       */
      public function __construct(private array $acquireResults) {}

      /**
       * {@inheritdoc}
       */
      public function acquire($name, $timeout = 30.0): bool {
        $this->events[] = ['acquire', $name, $timeout];
        $result = array_shift($this->acquireResults) ?? TRUE;
        if ($result) {
          $this->heldLocks[$name] = TRUE;
        }
        return $result;
      }

      /**
       * {@inheritdoc}
       */
      public function lockMayBeAvailable($name): bool {
        return empty($this->heldLocks[$name]);
      }

      /**
       * {@inheritdoc}
       */
      public function wait($name, $delay = 30): bool {
        $this->events[] = ['wait', $name, $delay];
        return $this->lockMayBeAvailable($name);
      }

      /**
       * {@inheritdoc}
       */
      public function release($name): void {
        $this->events[] = ['release', $name];
        unset($this->heldLocks[$name]);
      }

      /**
       * {@inheritdoc}
       */
      public function releaseAll($lockId = NULL): void {
        $this->heldLocks = [];
      }

      /**
       * {@inheritdoc}
       */
      public function getLockId(): string {
        return 'tier-limit-test-lock';
      }

      /**
       * Checks if a lock is currently held.
       */
      public function isHeld(string $name): bool {
        return !empty($this->heldLocks[$name]);
      }

    };
  }

}
