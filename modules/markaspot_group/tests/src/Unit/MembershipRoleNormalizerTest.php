<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\Tests\UnitTestCase;

/**
 * Tests membership role normalization.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\MembershipRoleNormalizer
 */
class MembershipRoleNormalizerTest extends UnitTestCase {

  /**
   * @covers ::normalize
   */
  public function testJurTenantAdminRequiresJurMember(): void {
    $this->assertSame(
      ['jur-member', 'jur-tenant_admin'],
      MembershipRoleNormalizer::normalize(['jur-tenant_admin'], 'jur')
    );
  }

  /**
   * @covers ::normalize
   */
  public function testExistingBaseRoleIsNotDuplicated(): void {
    $this->assertSame(
      ['jur-member', 'jur-tenant_admin'],
      MembershipRoleNormalizer::normalize(['jur-member', 'jur-tenant_admin', 'jur-member'], 'jur')
    );
  }

  /**
   * @covers ::normalize
   */
  public function testConfiguredJurTenantAdminRequiresConfiguredMember(): void {
    $this->assertSame(
      ['municipality-member', 'municipality-tenant_admin'],
      MembershipRoleNormalizer::normalize(['municipality-tenant_admin'], 'municipality')
    );
  }

  /**
   * @covers ::normalize
   */
  public function testNonJurGroupTypePreservesCleanRoleIds(): void {
    $this->assertSame(
      ['org-tenant_admin'],
      MembershipRoleNormalizer::normalize(['org-tenant_admin', '', 'org-tenant_admin', 123], 'org')
    );
  }

  /**
   * @covers ::isInternalRoleId
   */
  public function testInternalDerivedRoleIsNotUserAssignable(): void {
    $this->assertTrue(MembershipRoleNormalizer::isInternalRoleId('jur-org_member'));
    $this->assertTrue(MembershipRoleNormalizer::isInternalRoleId('municipality-org_member'));
    $this->assertFalse(MembershipRoleNormalizer::isInternalRoleId('jur-member'));
  }

}
