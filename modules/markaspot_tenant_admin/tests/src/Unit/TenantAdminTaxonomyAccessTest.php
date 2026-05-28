<?php

namespace Drupal\Tests\markaspot_tenant_admin\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_tenant_admin\TenantAdminHelper;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Tests taxonomy term access query alter for tenant admins.
 *
 * Ensures child jurisdiction admins see taxonomy terms from parent
 * jurisdictions by resolving the full hierarchy tree. This prevents the
 * regression where a child admin (e.g., Queens Park Ward, group 10)
 * couldn't see root parent's terms (e.g., BCP Council, group 8) because
 * the query filter only used direct jurisdiction IDs.
 *
 * @group markaspot_tenant_admin
 */
class TenantAdminTaxonomyAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../markaspot_tenant_admin.module';
    // Reset drupal_static cache to prevent test ordering dependencies.
    // TenantAdminHelper::getUserJurisdictionIds() uses drupal_static().
    drupal_static_reset();
  }

  /**
   * Sets up the Drupal container with a given current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The mocked current user.
   * @param string $jurisdictionGroupType
   *   The configured jurisdiction group type.
   *
   * @return \Drupal\Core\DependencyInjection\ContainerBuilder
   *   The container for further service registration.
   */
  protected function setUpContainer(
    AccountInterface $currentUser,
    string $jurisdictionGroupType = 'jur',
  ): ContainerBuilder {
    $container = new ContainerBuilder();
    $container->set('current_user', $currentUser);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn($jurisdictionGroupType);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);
    $container->set('config.factory', $configFactory);
    \Drupal::setContainer($container);
    return $container;
  }

  /**
   * Sets up the mock chain for TenantAdminHelper::getUserJurisdictionIds().
   *
   * Mocks User::load(), GroupMembership::loadByUser() (via cache hit),
   * and the entity storages needed by both static method chains.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container to register services in.
   * @param int $uid
   *   The user ID.
   * @param int[] $groupIds
   *   The jurisdiction group IDs the user belongs to. Empty = no memberships.
   */
  protected function setUpMembershipMocks(ContainerBuilder $container, int $uid, array $groupIds): void {
    // Mock User::load() chain via entity_type.manager.
    $userEntity = $this->createMock(UserInterface::class);
    $userEntity->method('id')->willReturn($uid);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with($uid)->willReturn($userEntity);

    // Build mock group relationships for GroupMembership::loadByUser().
    $relationships = [];
    $relIds = [];
    foreach ($groupIds as $i => $groupId) {
      $relId = 100 + $i;
      $relIds[] = $relId;

      $group = $this->createMock(GroupInterface::class);
      $group->method('id')->willReturn($groupId);
      $group->method('bundle')->willReturn('jur');

      $rel = $this->createMock(GroupRelationshipInterface::class);
      $rel->method('getGroup')->willReturn($group);
      $rel->method('getGroupId')->willReturn($groupId);

      $relationships[$relId] = $rel;
    }

    $groupRelStorage = $this->createMock(EntityStorageInterface::class);
    $groupRelStorage->method('loadMultiple')->willReturn($relationships);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      fn(string $type) => match ($type) {
        'user' => $userStorage,
        'group_relationship' => $groupRelStorage,
        default => $this->createMock(EntityStorageInterface::class),
      }
    );
    $container->set('entity_type.manager', $entityTypeManager);

    // Drupal 11 uses entity_type.repository in EntityBase::load() to
    // resolve the entity type ID from the class name.
    $entityTypeRepository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entityTypeRepository->method('getEntityTypeFromClass')
      ->willReturnCallback(fn(string $class) => match (TRUE) {
        str_contains($class, 'User') => 'user',
        default => 'group_relationship',
      });
    $container->set('entity_type.repository', $entityTypeRepository);

    // Mock the group membership cache. A cache hit bypasses the complex
    // entity query inside GroupMembershipTrait::loadByUser() and goes
    // directly to loadMultiple() with the cached relationship IDs.
    $cacheData = (object) ['data' => $relIds];
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($cacheData);
    $container->set('cache.group_memberships_chained', $cache);
  }

  /**
   * Tests configured tenant-admin role IDs include legacy compatibility.
   */
  public function testConfiguredTenantAdminRoleIdsIncludeLegacyRole(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $this->setUpContainer($currentUser, 'jurisdiction');

    $this->assertSame(
      ['jurisdiction-tenant_admin', 'jur-tenant_admin'],
      TenantAdminHelper::getTenantAdminRoleIds()
    );
  }

  /**
   * Internal status is part of the managed tenant taxonomy contract.
   */
  public function testInternalStatusIsManagedTenantTaxonomy(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $moduleSource = file_get_contents($moduleRoot . '/markaspot_tenant_admin.module');
    $this->assertIsString($moduleSource);

    $this->assertStringContainsString("'taxonomy_term_internal_status_form'", $moduleSource);
    $this->assertStringContainsString("['service_category', 'service_status', 'internal_status']", $moduleSource);
    $this->assertStringContainsString("foreach (['service_category', 'service_status', 'internal_status'] as \$vocabulary)", $moduleSource);

    $roleConfig = file_get_contents($moduleRoot . '/config/install/user.role.tenant_admin.yml');
    $this->assertIsString($roleConfig);
    $this->assertStringContainsString('create terms in internal_status', $roleConfig);
    $this->assertStringContainsString('edit terms in internal_status', $roleConfig);
    $this->assertStringContainsString('delete terms in internal_status', $roleConfig);
    $this->assertStringContainsString('translate internal_status taxonomy_term', $roleConfig);
  }

  /**
   * Tests that users with 'administer taxonomy' bypass the filter.
   */
  public function testFullAdminBypassesFilter(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')
      ->with('administer taxonomy')
      ->willReturn(TRUE);

    $this->setUpContainer($currentUser);

    $query = $this->createMock(SelectInterface::class);
    $query->expects($this->never())->method('leftJoin');
    $query->expects($this->never())->method('where');

    markaspot_tenant_admin_query_taxonomy_term_access_alter($query);
  }

  /**
   * Tests that non-tenant-admin users bypass the filter.
   */
  public function testNonTenantAdminBypassesFilter(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);
    $currentUser->method('getRoles')->willReturn(['authenticated']);

    $this->setUpContainer($currentUser);

    $query = $this->createMock(SelectInterface::class);
    $query->expects($this->never())->method('leftJoin');
    $query->expects($this->never())->method('where');

    markaspot_tenant_admin_query_taxonomy_term_access_alter($query);
  }

  /**
   * Tests that tenant admin with no jurisdictions gets an impossible condition.
   */
  public function testNoJurisdictionsBlocksAllTerms(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);
    $currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);
    $currentUser->method('id')->willReturn(5);

    $container = $this->setUpContainer($currentUser);
    $this->setUpMembershipMocks($container, 5, []);
    \Drupal::setContainer($container);

    $query = $this->createMock(SelectInterface::class);
    $query->expects($this->once())->method('where')->with('1 = 0');
    $query->expects($this->never())->method('leftJoin');

    markaspot_tenant_admin_query_taxonomy_term_access_alter($query);
  }

  /**
   * Tests hierarchy resolution for child jurisdiction admin.
   *
   * A QPW admin (group 10, child of BCP Council group 8) must see terms
   * from the full hierarchy [8, 9, 10], not just their direct group [10].
   * Without the hierarchy resolver, child admins get 0 results from
   * JSON:API because taxonomy terms are assigned to the root parent.
   */
  public function testChildJurisdictionResolvesFullHierarchy(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);
    $currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);
    $currentUser->method('id')->willReturn(5);

    $container = $this->setUpContainer($currentUser);
    $this->setUpMembershipMocks($container, 5, [10]);

    // The hierarchy resolver must expand child group [10] to full tree.
    // BCP Council (8) -> Bournemouth (9) -> Queens Park Ward (10).
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->expects($this->once())
      ->method('getTermJurisdictionIds')
      ->with(10)
      ->willReturn([8, 9, 10]);
    $container->set('markaspot_group.hierarchy_resolver', $resolver);
    \Drupal::setContainer($container);

    // Mock the query with a taxonomy_term_field_data table.
    $query = $this->createMock(SelectInterface::class);
    $query->method('getTables')->willReturn([
      'td' => ['table' => 'taxonomy_term_field_data', 'alias' => 'td'],
    ]);

    // Capture the jurisdiction IDs passed to the IN condition.
    $capturedJurIds = NULL;
    $orGroup = $this->createMock(ConditionInterface::class);
    $orGroup->method('condition')
      ->willReturnCallback(function ($field, $value = NULL, $operator = NULL) use ($orGroup, &$capturedJurIds) {
        if ($field === 'fj.field_jurisdiction_target_id') {
          $capturedJurIds = $value;
        }
        return $orGroup;
      });
    $orGroup->method('isNull')->willReturnSelf();

    $query->method('orConditionGroup')->willReturn($orGroup);
    $query->expects($this->once())->method('leftJoin');
    $query->method('condition')->willReturnSelf();

    markaspot_tenant_admin_query_taxonomy_term_access_alter($query);

    // The critical assertion: filter must include the full hierarchy tree.
    $this->assertNotNull($capturedJurIds, 'Jurisdiction filter condition must be set.');
    sort($capturedJurIds);
    $this->assertEquals([8, 9, 10], $capturedJurIds,
      'Child jurisdiction admin must see terms from full hierarchy tree, not just direct group.');
  }

  /**
   * Tests query is not altered without taxonomy_term_field_data.
   */
  public function testNoBaseTableSkipsAlter(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);
    $currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);
    $currentUser->method('id')->willReturn(5);

    $container = $this->setUpContainer($currentUser);
    $this->setUpMembershipMocks($container, 5, [10]);

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getTermJurisdictionIds')->willReturn([8, 9, 10]);
    $container->set('markaspot_group.hierarchy_resolver', $resolver);
    \Drupal::setContainer($container);

    // Query without taxonomy_term_field_data table.
    $query = $this->createMock(SelectInterface::class);
    $query->method('getTables')->willReturn([
      'n' => ['table' => 'node_field_data', 'alias' => 'n'],
    ]);
    $query->expects($this->never())->method('leftJoin');

    markaspot_tenant_admin_query_taxonomy_term_access_alter($query);
  }

}
