<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests root administrator group-membership repair wiring.
 *
 * @group markaspot_group
 */
class RootAdminGroupMembershipTest extends UnitTestCase {

  /**
   * Tests new groups auto-onboard uid 1.
   */
  public function testGroupInsertOnboardsRootAdmin(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents($module_root . '/markaspot_group.module');

    $this->assertStringContainsString('function markaspot_group_group_insert(GroupInterface $group): void', $source);
    $this->assertStringContainsString('_markaspot_group_add_all_groups_members_to_group($group);', $source);
    $this->assertStringContainsString('_markaspot_group_add_root_admin_to_group($group);', $source);
    $this->assertStringContainsString('function _markaspot_group_add_root_admin_to_group(GroupInterface $group): void', $source);
    $this->assertStringContainsString('User::load(1)', $source);
    $this->assertStringContainsString('$account->isBlocked()', $source);
    $this->assertStringContainsString('$account->hasRole(\'administrator\')', $source);
    $this->assertStringContainsString('$group->addMember($account);', $source);
    $this->assertStringNotContainsString('$group->addMember($account, [\'group_roles\' => [$admin_role]])', $source);
  }

  /**
   * Tests update 11933 repairs existing root-admin group memberships.
   */
  public function testUpdate11933RepairsExistingMemberships(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents($module_root . '/markaspot_group.install');

    $this->assertStringContainsString('function markaspot_group_update_11933(): string', $source);
    $this->assertStringContainsString('_markaspot_group_update_onboard_root_admin()', $source);
    $this->assertStringContainsString('uid 1 is missing', $source);
    $this->assertStringContainsString('uid 1 is blocked', $source);
    // Self-heal: uid 1 is granted the administrator role instead of aborting updb.
    $this->assertStringNotContainsString('uid 1 lacks the administrator role', $source);
    $this->assertStringContainsString('Granted administrator role to uid 1', $source);
    $this->assertStringContainsString('Failed to converge uid 1 group admin membership for %d group(s)', $source);
    $this->assertStringContainsString('_markaspot_group_update_remove_non_individual_group_roles($member)', $source);
    $this->assertStringContainsString('$group->addMember($user);', $source);
    $this->assertStringContainsString('function _markaspot_group_update_remove_non_individual_group_roles($member): int', $source);
    $this->assertStringContainsString('$role->getScope() !== \'individual\'', $source);
    $this->assertStringNotContainsString('$group->addMember($user, [\'group_roles\' => [$admin_role]])', $source);
  }

}
