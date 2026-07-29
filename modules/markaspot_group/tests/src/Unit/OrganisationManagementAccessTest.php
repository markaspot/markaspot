<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\OrganisationManagementAccess;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 3) . '/src/Service/OrganisationManagementAccess.php';

/**
 * Tests jurisdiction-scoped organisation management access.
 */
#[CoversClass(OrganisationManagementAccess::class)]
#[Group('markaspot_group')]
class OrganisationManagementAccessTest extends UnitTestCase {

  /**
   * Tests direct and root administrator memberships are accepted.
   */
  public function testDirectMembershipCanManageJurisdiction(): void {
    $account = $this->account(12, ['tenant_admin']);
    $roles_seen = new \ArrayObject();
    $checker = $this->checker(
      [9],
      6,
      $roles_seen,
    );

    $this->assertTrue($checker->canManageJurisdiction($account, 9));
    $this->assertContains('jur-tenant_admin', $roles_seen);
    $this->assertContains('jur-admin', $roles_seen);
  }

  /**
   * Tests a management role on the root covers a submitted child.
   */
  public function testRootMembershipCanManageChildJurisdiction(): void {
    $account = $this->account(12, ['tenant_admin']);
    $roles_seen = new \ArrayObject();
    $checker = $this->checker(
      [6],
      6,
      $roles_seen,
    );

    $this->assertTrue($checker->canManageJurisdiction($account, 9));
  }

  /**
   * Tests a child administrator keeps access after storage normalizes to root.
   */
  public function testChildMembershipCanManageStoredRootOrganisation(): void {
    $account = $this->account(12, ['tenant_admin']);
    $roles_seen = new \ArrayObject();
    $checker = $this->checker(
      [9],
      6,
      $roles_seen,
      [6, 9],
    );

    $this->assertTrue($checker->canManageJurisdiction($account, 6));
  }

  /**
   * Tests an unresolved hierarchy fails closed.
   */
  public function testUnresolvedJurisdictionCannotBeManaged(): void {
    $account = $this->account(12, ['tenant_admin']);
    $roles_seen = new \ArrayObject();
    $checker = $this->checker([9], NULL, $roles_seen);

    $this->assertFalse($checker->canManageJurisdiction($account, 9));
  }

  /**
   * Tests a foreign jurisdiction membership is rejected.
   */
  public function testForeignMembershipCannotManageJurisdiction(): void {
    $account = $this->account(12, ['tenant_admin']);
    $roles_seen = new \ArrayObject();
    $checker = $this->checker(
      [20],
      6,
      $roles_seen,
    );

    $this->assertFalse($checker->canManageJurisdiction($account, 9));
  }

  /**
   * Tests a child manager does not gain access to a sibling workspace.
   */
  public function testChildMembershipCannotManageSiblingJurisdiction(): void {
    $account = $this->account(12, ['tenant_admin']);
    $roles_seen = new \ArrayObject();
    $checker = $this->checker([9], 6, $roles_seen);

    $this->assertFalse($checker->canManageJurisdiction($account, 10));
  }

  /**
   * Tests uid 1 and Drupal administrators are recognized as bypass accounts.
   */
  public function testDrupalAdministratorBypass(): void {
    $roles_seen = new \ArrayObject();
    $checker = $this->checker([], 6, $roles_seen);

    $this->assertTrue($checker->hasBypass($this->account(1, ['authenticated'])));
    $this->assertTrue($checker->hasBypass($this->account(8, ['administrator'])));
    $this->assertFalse($checker->hasBypass($this->account(8, ['tenant_admin'])));
  }

  /**
   * Builds the access checker with memberships on the supplied group IDs.
   *
   * @param int[] $membership_group_ids
   *   Jurisdiction group IDs where the account has a management role.
   * @param int|null $root_id
   *   Resolved root jurisdiction ID.
   * @param \ArrayObject<int, string> $roles_seen
   *   Captured role filter passed to the membership loader.
   * @param int[] $descendant_ids
   *   Jurisdiction IDs in the root subtree.
   */
  private function checker(
    array $membership_group_ids,
    ?int $root_id,
    \ArrayObject $roles_seen,
    array $descendant_ids = [],
  ): OrganisationManagementAccess {
    $memberships = [];
    foreach ($membership_group_ids as $group_id) {
      $group = $this->createMock(GroupInterface::class);
      $group->method('bundle')->willReturn('jur');
      $group->method('id')->willReturn($group_id);

      $membership = $this->createMock(GroupMembershipInterface::class);
      $membership->method('getGroup')->willReturn($group);
      $memberships[] = $membership;
    }

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')->willReturn($root_id);
    $resolver->method('getDescendantIds')->willReturn($descendant_ids);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    return new TestOrganisationManagementAccess(
      $resolver,
      $config_factory,
      $memberships,
      $roles_seen,
    );
  }

  /**
   * Creates an account mock.
   */
  private function account(int $uid, array $roles): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

}

/**
 * Test double for the static Group membership entity loader.
 */
final class TestOrganisationManagementAccess extends OrganisationManagementAccess {

  /**
   * Constructs the test access checker.
   *
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   Jurisdiction hierarchy resolver.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   * @param \Drupal\group\Entity\GroupMembershipInterface[] $memberships
   *   Memberships returned by the loader seam.
   * @param \ArrayObject<int, string> $rolesSeen
   *   Captured management role IDs.
   */
  public function __construct(
    JurisdictionHierarchyResolverInterface $hierarchyResolver,
    ConfigFactoryInterface $configFactory,
    private readonly array $memberships,
    private readonly \ArrayObject $rolesSeen,
  ) {
    parent::__construct($hierarchyResolver, $configFactory);
  }

  /**
   * {@inheritdoc}
   */
  protected function loadManagementMemberships(AccountInterface $account): array {
    $this->rolesSeen->exchangeArray($this->managementRoleIds());
    return $this->memberships;
  }

}
