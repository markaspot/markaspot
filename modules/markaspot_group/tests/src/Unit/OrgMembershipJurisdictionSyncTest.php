<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests organisation membership jurisdiction sync wiring.
 *
 * @group markaspot_group
 */
class OrgMembershipJurisdictionSyncTest extends UnitTestCase {

  /**
   * Tests org membership inserts sync the implied jurisdiction membership.
   */
  public function testOrgMembershipInsertSyncsJurisdictionMembership(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents($module_root . '/markaspot_group.module');

    $this->assertStringContainsString('function markaspot_group_group_relationship_insert(GroupRelationshipInterface $relationship): void', $source);
    $this->assertStringContainsString('_markaspot_group_sync_org_membership_to_jurisdiction($relationship);', $source);
    $this->assertStringContainsString('function _markaspot_group_sync_org_membership_to_jurisdiction(GroupRelationshipInterface $relationship): void', $source);
    $this->assertStringContainsString("\$relationship->getPluginId() !== 'group_membership'", $source);
    $this->assertStringContainsString("\$org_group->bundle() !== _markaspot_group_get_org_group_type()", $source);
    $this->assertStringContainsString('_markaspot_group_derived_jurisdiction_role_id($jur_group)', $source);
    $this->assertStringContainsString('_markaspot_group_jurisdiction_membership_bundle($jur_group)', $source);
    $this->assertStringContainsString('_markaspot_group_cleanup_org_membership_jurisdiction($relationship);', $source);
    $this->assertStringContainsString('function _markaspot_group_resync_org_memberships_for_org_move(GroupInterface $org_group, ?int $new_jurisdiction_id, ?int $original_jurisdiction_id): void', $source);
  }

  /**
   * Tests update 11928 backfills existing org-derived memberships.
   */
  public function testUpdate11928BackfillsOrgDerivedMemberships(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents($module_root . '/markaspot_group.install');
    $role_config = file_get_contents($module_root . '/config/install/group.role.jur-org_member.yml');

    $this->assertStringContainsString('function markaspot_group_update_11928(): string', $source);
    $this->assertStringContainsString('_markaspot_group_ensure_derived_jurisdiction_role();', $source);
    $this->assertStringContainsString("->condition('type', 'org-group_membership')", $source);
    $this->assertStringContainsString('_markaspot_group_update_derived_jurisdiction_role_id($jur_group)', $source);
    $this->assertStringContainsString('_markaspot_group_update_jurisdiction_membership_bundle($jur_group)', $source);
    $this->assertStringContainsString('function _markaspot_group_resolve_org_backfill_jurisdiction', $source);
    $this->assertStringContainsString('getRootJurisdictionId($jurisdiction_id)', $source);

    $this->assertStringContainsString('id: jur-org_member', $role_config);
    $this->assertStringContainsString('scope: individual', $role_config);
    $this->assertStringContainsString("'view group_node:boilerplate entity'", $role_config);
    $this->assertStringContainsString("'view group_node:service_request entity'", $role_config);
  }

  /**
   * Tests jurisdiction moderator role can read boilerplate templates.
   */
  public function testJurisdictionModeratorCanReadBoilerplates(): void {
    $module_root = dirname(__DIR__, 3);
    $role_config = file_get_contents($module_root . '/config/install/group.role.jur-moderator.yml');

    $this->assertStringContainsString('id: jur-moderator', $role_config);
    $this->assertStringContainsString("'view group_node:boilerplate entity'", $role_config);
  }

  /**
   * Tests jurisdiction members can read public service requests.
   */
  public function testJurisdictionMembersCanReadPublicServiceRequests(): void {
    $module_root = dirname(__DIR__, 3);
    $member_config = file_get_contents($module_root . '/config/install/group.role.jur-member.yml');
    $derived_config = file_get_contents($module_root . '/config/install/group.role.jur-org_member.yml');
    $install_source = file_get_contents($module_root . '/markaspot_group.install');

    $this->assertStringContainsString('id: jur-member', $member_config);
    $this->assertStringContainsString("'view group_node:service_request entity'", $member_config);
    $this->assertStringContainsString('id: jur-org_member', $derived_config);
    $this->assertStringContainsString("'view group_node:service_request entity'", $derived_config);
    $this->assertStringContainsString('function markaspot_group_update_11930(): string', $install_source);
    $this->assertStringContainsString('$group_type = _markaspot_group_update_jurisdiction_group_type();', $install_source);
    $this->assertStringContainsString("_markaspot_group_ensure_derived_jurisdiction_role();", $install_source);
    $this->assertStringContainsString("\$group_type . '-member'", $install_source);
    $this->assertStringContainsString('_markaspot_group_update_derived_jurisdiction_role_id()', $install_source);
    $this->assertStringContainsString('$current_permissions = $role->getPermissions();', $install_source);
    $this->assertStringNotContainsString("'permissions' => \$permissions,\n    ];\n    foreach (\$expected_values", $install_source);
    $this->assertStringContainsString('Skipped unexpected jurisdiction group roles', $install_source);
  }

}
