<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\MissingTenantAdminRoleCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the missing-tenant-admin-role detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\MissingTenantAdminRoleCheck
 */
class MissingTenantAdminRoleCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'missing_tenant_admin_role',
    'label' => 'Missing tenant_admin group role',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenGroupModuleAbsent(): void {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('group_role')->willReturn(FALSE);

    $plugin = new MissingTenantAdminRoleCheck([], 'missing_tenant_admin_role', $this->definition, $etm, $this->configFactory());
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('Group module not enabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenRoleMissing(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('jur-tenant_admin')->willReturn(NULL);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->with('group_role')->willReturn($storage);

    $plugin = new MissingTenantAdminRoleCheck([], 'missing_tenant_admin_role', $this->definition, $etm, $this->configFactory());
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertStringContainsString('jur-tenant_admin', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenRolePresent(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('jur-tenant_admin')->willReturn(new \stdClass());
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->willReturn($storage);

    $plugin = new MissingTenantAdminRoleCheck([], 'missing_tenant_admin_role', $this->definition, $etm, $this->configFactory());
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunUsesConfiguredJurisdictionGroupType(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('municipality-tenant_admin')->willReturn(new \stdClass());
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->willReturn($storage);

    $plugin = new MissingTenantAdminRoleCheck([], 'missing_tenant_admin_role', $this->definition, $etm, $this->configFactory('municipality'));
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * Builds a config factory mock with a jurisdiction group type.
   */
  protected function configFactory(string $jurisdictionGroupType = 'jur'): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn($jurisdictionGroupType);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    return $factory;
  }

}
