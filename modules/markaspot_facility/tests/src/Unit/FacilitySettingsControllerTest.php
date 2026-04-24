<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_facility\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_facility\Controller\FacilitySettingsController;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the facility tenant settings controller.
 *
 * @group markaspot_facility
 * @coversDefaultClass \Drupal\markaspot_facility\Controller\FacilitySettingsController
 */
class FacilitySettingsControllerTest extends UnitTestCase {

  /**
   * Group storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * Facility manager mock.
   *
   * @var \Drupal\markaspot_facility\Service\FacilityManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected FacilityManager $facilityManager;

  /**
   * Controller under test.
   *
   * @var \Drupal\markaspot_facility\Controller\FacilitySettingsController
   */
  protected FacilitySettingsController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->facilityManager = $this->createMock(FacilityManager::class);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('markaspot_facility.manager', $this->facilityManager);

    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('getDisplayName')->willReturn('tester');
    $container->set('current_user', $current_user);

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $container->set('logger.factory', $logger_factory);

    \Drupal::setContainer($container);

    $this->controller = FacilitySettingsController::create($container);
  }

  /**
   * @covers ::getFacilitiesSettings
   */
  public function testGetFacilitiesSettingsReturns404ForMissingJurisdiction(): void {
    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $response = $this->controller->getFacilitiesSettings(Request::create('/api/tenant-settings/999/facilities', 'GET'), '999');

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * @covers ::getFacilitiesSettings
   */
  public function testGetFacilitiesSettingsReturnsNormalizedPayload(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $this->groupStorage->method('load')->with(14)->willReturn($group);
    $this->facilityManager->method('getDashboardSettings')->with($group)->willReturn([
      'enabled' => TRUE,
      'hideMapPicker' => FALSE,
      'items' => [],
    ]);

    $response = $this->controller->getFacilitiesSettings(Request::create('/api/tenant-settings/14/facilities', 'GET'), '14');

    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(14, $data['jurisdiction_id']);
    $this->assertTrue($data['facilities']['enabled']);
  }

  /**
   * @covers ::updateFacilitiesSettings
   */
  public function testUpdateFacilitiesSettingsRejectsInvalidJson(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->updateFacilitiesSettings(
      Request::create('/api/tenant-settings/14/facilities', 'PATCH', [], [], [], [], '{oops'),
      '14'
    );

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::updateFacilitiesSettings
   */
  public function testUpdateFacilitiesSettingsReturnsValidationErrors(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $this->groupStorage->method('load')->with(14)->willReturn($group);
    $this->facilityManager->method('saveDashboardSettings')
      ->willThrowException(new \InvalidArgumentException('enabled is required and must be a boolean.'));

    $response = $this->controller->updateFacilitiesSettings(
      Request::create('/api/tenant-settings/14/facilities', 'PATCH', [], [], [], [], json_encode(['facilities' => []])),
      '14'
    );

    $this->assertSame(422, $response->getStatusCode());
  }

}
