<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\RootAdminGroupMembershipCheck;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Tests the root-admin group-membership health check.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\RootAdminGroupMembershipCheck
 */
class RootAdminGroupMembershipCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'root_admin_group_membership',
    'label' => 'Root admin group membership',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunFailsWhenMembershipMissing(): void {
    $group = $this->group(7, 'jur', 'Bonn', NULL);
    $plugin = new RootAdminGroupMembershipCheck(
      [],
      'root_admin_group_membership',
      $this->definition,
      $this->entityTypeManager([$group], []),
      $this->configFactory(),
    );

    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertSame('missing_membership', $result->details[0]['issue']);
    $this->assertSame(7, $result->details[0]['group_id']);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenMembershipHasNonIndividualRole(): void {
    $member = $this->memberWithRoles(['jur-admin']);
    $group = $this->group(9, 'jur', 'Tenant', $member);
    $plugin = new RootAdminGroupMembershipCheck(
      [],
      'root_admin_group_membership',
      $this->definition,
      $this->entityTypeManager([$group], ['jur-admin' => 'insider']),
      $this->configFactory(),
    );

    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertSame('non_individual_group_roles', $result->details[0]['issue']);
    $this->assertSame('jur-admin', $result->details[0]['roles']);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenMembershipsAreClean(): void {
    $member = $this->memberWithRoles(['jur-member']);
    $group = $this->group(1, 'jur', 'Amsterdam', $member);
    $plugin = new RootAdminGroupMembershipCheck(
      [],
      'root_admin_group_membership',
      $this->definition,
      $this->entityTypeManager([$group], ['jur-member' => 'individual']),
      $this->configFactory(),
    );

    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertSame(0, $result->count);
  }

  /**
   * Builds an entity type manager mock.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Groups returned by storage.
   * @param array<string, string> $roleScopes
   *   Role ID to scope map.
   */
  protected function entityTypeManager(array $groups, array $roleScopes): EntityTypeManagerInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('isBlocked')->willReturn(FALSE);
    $user->method('hasRole')->with('administrator')->willReturn(TRUE);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(1)->willReturn($user);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_map(static fn(GroupInterface $group): int => (int) $group->id(), $groups));

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('getQuery')->willReturn($query);
    $groupStorage->method('loadMultiple')->willReturn($groups);

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('loadMultiple')->willReturnCallback(function (array $roleIds) use ($roleScopes): array {
      $roles = [];
      foreach ($roleIds as $roleId) {
        if (!isset($roleScopes[$roleId])) {
          continue;
        }
        $role = $this->getMockBuilder(\stdClass::class)
          ->addMethods(['getScope'])
          ->getMock();
        $role->method('getScope')->willReturn($roleScopes[$roleId]);
        $roles[$roleId] = $role;
      }
      return $roles;
    });

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('group')->willReturn(TRUE);
    $etm->method('getStorage')->willReturnCallback(static function (string $entityType) use ($userStorage, $groupStorage, $roleStorage): EntityStorageInterface {
      return match ($entityType) {
        'user' => $userStorage,
        'group' => $groupStorage,
        'group_role' => $roleStorage,
        default => throw new \LogicException("Unexpected storage $entityType"),
      };
    });

    return $etm;
  }

  /**
   * Builds a group mock.
   */
  protected function group(int $id, string $bundle, string $label, $member): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label);
    $group->method('getMember')->willReturn($member);

    return $group;
  }

  /**
   * Builds a group membership mock with group_roles values.
   *
   * @param string[] $roleIds
   *   Role IDs assigned to the membership.
   */
  protected function memberWithRoles(array $roleIds) {
    $field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getValue'])
      ->getMock();
    $field->method('getValue')->willReturn(array_map(
      static fn(string $roleId): array => ['target_id' => $roleId],
      $roleIds,
    ));

    $relationship = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['hasField', 'get'])
      ->getMock();
    $relationship->method('hasField')->with('group_roles')->willReturn(TRUE);
    $relationship->method('get')->with('group_roles')->willReturn($field);

    $member = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getGroupRelationship'])
      ->getMock();
    $member->method('getGroupRelationship')->willReturn($relationship);

    return $member;
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
