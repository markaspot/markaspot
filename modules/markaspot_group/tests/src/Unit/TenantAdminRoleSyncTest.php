<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\markaspot_group\Service\TenantAdminHelper;
use Drupal\Tests\UnitTestCase;

/**
 * Tests tenant-admin Drupal role and group role sync wiring.
 *
 * @group markaspot_group
 */
class TenantAdminRoleSyncTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jurisdiction');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Tests user update strips jur tenant-admin roles when Drupal role is removed.
   */
  public function testUserUpdateStripsGroupRolesWhenDrupalRoleRemoved(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $moduleFile = $moduleRoot . '/markaspot_group.module';
    $source = file_get_contents($moduleFile);

    $this->assertStringContainsString('function markaspot_group_user_update(UserInterface $account)', $source);
    $this->assertStringContainsString('$original_roles = $original instanceof UserInterface ? $original->getRoles() : [];', $source);
    $this->assertStringContainsString('$new_roles = $account->getRoles();', $source);
    $this->assertStringContainsString("in_array('tenant_admin', \$original_roles, TRUE) && !in_array('tenant_admin', \$new_roles, TRUE)", $source);
    $this->assertStringContainsString('TenantAdminHelper::stripAllGroupRoles($account)', $source);
  }

  /**
   * Tests helper exposes scoped group-role stripping.
   */
  public function testTenantAdminHelperExposesGroupRoleStripping(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $helperFile = $moduleRoot . '/src/Service/TenantAdminHelper.php';
    $source = file_get_contents($helperFile);

    $this->assertStringContainsString('public static function stripGroupRole(AccountInterface $account, int $jurisdiction_id): bool', $source);
    $this->assertStringContainsString('GroupMembership::loadByUser($user, self::getTenantAdminRoleIds())', $source);
    $this->assertStringContainsString('self::isJurisdictionGroup($group)', $source);
    $this->assertStringContainsString('self::filterTenantAdminRoleValues($role_values)', $source);
    $this->assertStringContainsString('public static function getTenantAdminRoleIds(): array', $source);
    $this->assertStringContainsString("\$membership->set('group_roles', \$filtered);", $source);
    $this->assertStringContainsString('public static function stripAllGroupRoles(AccountInterface $account): int', $source);
  }

  /**
   * Tests filtering removes only the elevated group role.
   */
  public function testFilterTenantAdminRoleValuesKeepsOtherRoles(): void {
    $filtered = TenantAdminHelper::filterTenantAdminRoleValues([
      ['target_id' => 'jur-member'],
      ['target_id' => TenantAdminHelper::GROUP_ROLE_ID],
      ['target_id' => 'jurisdiction-tenant_admin'],
      ['target_id' => 'jur-editorial'],
    ]);

    $this->assertSame([
      ['target_id' => 'jur-member'],
      ['target_id' => 'jur-editorial'],
    ], $filtered);
  }

}
