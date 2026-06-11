<?php

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests EmergencyModeService state transitions and snapshot key logic.
 *
 * Only unit-testable behaviour is covered here: State reads/writes and
 * derived getters. Category-management methods require entity storage mocks
 * that add no value at unit level; those are covered by functional tests.
 *
 * @group markaspot_emergency
 * @coversDefaultClass \Drupal\markaspot_emergency\Service\EmergencyModeService
 */
class EmergencyModeServiceTest extends UnitTestCase {

  /**
   * @covers ::getStatus
   * @covers ::isActive
   */
  public function testStatusDefaultsToOff(): void {
    $service = $this->buildService(['status' => 'off']);

    $this->assertEquals('off', $service->getStatus());
    $this->assertFalse($service->isActive());
  }

  /**
   * @covers ::isActive
   */
  public function testIsActiveReturnsTrueWhenStatusIsActive(): void {
    $service = $this->buildService(['status' => 'active']);

    $this->assertTrue($service->isActive());
  }

  /**
   * @covers ::getActivatedAt
   */
  public function testGetActivatedAtReturnsNullWhenNotSet(): void {
    $service = $this->buildService([]);

    $this->assertNull($service->getActivatedAt());
  }

  /**
   * @covers ::getActivatedAt
   */
  public function testGetActivatedAtReturnsIntWhenSet(): void {
    $ts = 1_700_000_000;
    $service = $this->buildService(['activated_at' => $ts]);

    $this->assertSame($ts, $service->getActivatedAt());
  }

  /**
   * @covers ::getActivatedBy
   */
  public function testGetActivatedByReturnsNullWhenNotSet(): void {
    $service = $this->buildService([]);

    $this->assertNull($service->getActivatedBy());
  }

  /**
   * @covers ::getActivatedBy
   */
  public function testGetActivatedByReturnsIntWhenSet(): void {
    $service = $this->buildService(['activated_by' => 42]);

    $this->assertSame(42, $service->getActivatedBy());
  }

  /**
   * @covers ::getSnapshotKey
   */
  public function testSnapshotKeyWithoutJurisdiction(): void {
    $service = $this->buildService([]);

    $this->assertEquals(
      'markaspot_emergency.original_published_tids',
      $service->getSnapshotKey(NULL)
    );
  }

  /**
   * @covers ::getSnapshotKey
   */
  public function testSnapshotKeyWithJurisdiction(): void {
    $service = $this->buildService([]);

    $this->assertEquals(
      'markaspot_emergency.original_published_tids.7',
      $service->getSnapshotKey(7)
    );
  }

  /**
   * Builds a minimal EmergencyModeService with mocked dependencies.
   *
   * @param array $stateValues
   *   Key/value pairs for state (keyed by short name: 'status', 'activated_at',
   *   'activated_by').
   *
   * @return \Drupal\markaspot_emergency\Service\EmergencyModeService
   *   The service under test.
   */
  private function buildService(array $stateValues): EmergencyModeService {
    $stateMap = [];
    if (isset($stateValues['status'])) {
      $stateMap[] = [EmergencyModeService::STATE_STATUS, 'off', $stateValues['status']];
    }
    if (isset($stateValues['activated_at'])) {
      $stateMap[] = [EmergencyModeService::STATE_ACTIVATED_AT, NULL, $stateValues['activated_at']];
    }
    if (isset($stateValues['activated_by'])) {
      $stateMap[] = [EmergencyModeService::STATE_ACTIVATED_BY, NULL, $stateValues['activated_by']];
    }

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnMap($stateMap);

    $config = $this->createMock(Config::class);
    $config->method('get')->willReturn(NULL);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $configFactory->method('getEditable')->willReturn($config);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $account->method('getDisplayName')->willReturn('admin');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new EmergencyModeService(
      $configFactory,
      $state,
      $entityTypeManager,
      $entityFieldManager,
      $account,
      $loggerFactory,
    );
  }

}
