<?php

namespace Drupal\Tests\markaspot_tenant_admin\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_tenant_admin\TenantAdminHelper;
use Drupal\taxonomy\TermInterface;
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

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);

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
   * Sets up the mock chain for TenantAdminHelper jurisdiction membership IDs.
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
   * Registers a hierarchy resolver returning the provided ID map.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container to register the resolver in.
   * @param array<int,int[]> $map
   *   Map of direct jurisdiction IDs to expanded term jurisdiction IDs.
   */
  protected function setUpHierarchyResolver(ContainerBuilder $container, array $map): void {
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getTermJurisdictionIds')
      ->willReturnCallback(static fn(int $groupId): array => $map[$groupId] ?? [$groupId]);
    $container->set('markaspot_group.hierarchy_resolver', $resolver);
  }

  /**
   * Registers a renderer that captures bubbled query cacheability.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container to register the renderer in.
   * @param array|null $capturedBuild
   *   Captured render array.
   */
  protected function setUpRenderContextCapture(ContainerBuilder $container, ?array &$capturedBuild): void {
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('hasRenderContext')->willReturn(TRUE);
    $renderer->method('render')
      ->willReturnCallback(static function (array &$build) use (&$capturedBuild): Markup {
        $capturedBuild = $build;
        return Markup::create(' ');
      });
    $container->set('renderer', $renderer);
  }

  /**
   * Builds a managed taxonomy term mock.
   *
   * @param bool $published
   *   Whether the term is published.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction target ID.
   * @param \Drupal\group\Entity\GroupInterface|null $jurisdiction
   *   Optional loaded jurisdiction entity.
   *
   * @return \Drupal\Core\Entity\EntityPublishedInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked taxonomy term.
   */
  protected function createManagedTerm(bool $published, ?int $jurisdictionId, ?GroupInterface $jurisdiction = NULL): EntityPublishedInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('bundle')->willReturn('service_status');
    $term->method('isPublished')->willReturn($published);
    $term->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $term->method('get')
      ->with('field_jurisdiction')
      ->willReturn($this->createJurisdictionField($jurisdictionId, $jurisdiction));
    $term->method('getCacheContexts')->willReturn([]);
    $term->method('getCacheTags')->willReturn(['taxonomy_term:7']);
    $term->method('getCacheMaxAge')->willReturn(-1);
    return $term;
  }

  /**
   * Builds a field_jurisdiction item list mock.
   *
   * @param int|null $jurisdictionId
   *   Optional jurisdiction target ID.
   * @param \Drupal\group\Entity\GroupInterface|null $jurisdiction
   *   Optional loaded jurisdiction entity.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked field item list.
   */
  protected function createJurisdictionField(?int $jurisdictionId, ?GroupInterface $jurisdiction): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($jurisdictionId === NULL);
    $field->method('__get')
      ->willReturnCallback(static fn(string $name): mixed => match ($name) {
        'target_id' => $jurisdictionId,
        'entity' => $jurisdiction,
        default => NULL,
      });
    return $field;
  }

  /**
   * Builds a jurisdiction group mock.
   *
   * @param int $groupId
   *   The group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked jurisdiction group.
   */
  protected function createJurisdictionGroup(int $groupId): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('bundle')->willReturn('jur');
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(['group:' . $groupId]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    return $group;
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
   * Any jurisdiction group membership counts for read visibility.
   */
  public function testMemberJurisdictionIdsUseAnyJurisdictionMembership(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('id')->willReturn(5);

    $container = $this->setUpContainer($currentUser);
    $this->setUpMembershipMocks($container, 5, [10, 12]);
    \Drupal::setContainer($container);

    $this->assertSame([10, 12], TenantAdminHelper::getUserMemberJurisdictionIds($currentUser));
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
   * Tests that citizens without jurisdiction memberships bypass the filter.
   */
  public function testCitizenWithoutMembershipBypassesFilter(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);
    $currentUser->method('getRoles')->willReturn(['authenticated']);
    $currentUser->method('id')->willReturn(5);

    $container = $this->setUpContainer($currentUser);
    $this->setUpMembershipMocks($container, 5, []);
    $capturedBuild = NULL;
    $this->setUpRenderContextCapture($container, $capturedBuild);
    \Drupal::setContainer($container);

    $query = $this->createMock(SelectInterface::class);
    $query->expects($this->never())->method('leftJoin');
    $query->expects($this->never())->method('where');

    markaspot_tenant_admin_query_taxonomy_term_access_alter($query);

    $this->assertIsArray(
      $capturedBuild,
      'Authenticated non-member bypass cacheability must bubble.'
    );
    $this->assertContains('user', $capturedBuild['#cache']['contexts']);
    $this->assertContains('user.roles', $capturedBuild['#cache']['contexts']);
    $this->assertContains('user.group_permissions', $capturedBuild['#cache']['contexts']);
    $this->assertContains(
      'group_relationship_list:plugin:group_membership:entity:5',
      $capturedBuild['#cache']['tags']
    );
    $this->assertSame(
      [],
      array_values(array_filter(
        $capturedBuild['#cache']['contexts'],
        static fn(string $context): bool => str_starts_with($context, 'user.is_group_member:')
      )),
      'Authenticated non-member bypass must not add specific group membership contexts.'
    );
  }

  /**
   * Tests that anonymous users bypass the filter.
   */
  public function testAnonymousUserBypassesFilter(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);
    $currentUser->method('getRoles')->willReturn(['anonymous']);
    $currentUser->method('id')->willReturn(0);

    $container = $this->setUpContainer($currentUser);
    $capturedBuild = NULL;
    $this->setUpRenderContextCapture($container, $capturedBuild);

    $query = $this->createMock(SelectInterface::class);
    $query->expects($this->never())->method('leftJoin');
    $query->expects($this->never())->method('where');

    markaspot_tenant_admin_query_taxonomy_term_access_alter($query);

    $this->assertNull(
      $capturedBuild,
      'Anonymous bypass must not add user cache context to public term listings.'
    );
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
   * Tests hierarchy resolution for child jurisdiction staff.
   *
   * A QPW staff member (group 10, child of BCP Council group 8) must see terms
   * from the full hierarchy [8, 9, 10], not just their direct group [10].
   * Without the hierarchy resolver, child staff get 0 results from
   * JSON:API because taxonomy terms are assigned to the root parent.
   */
  public function testChildJurisdictionResolvesFullHierarchy(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('hasPermission')->willReturn(FALSE);
    $currentUser->method('getRoles')->willReturn(['authenticated', 'moderator']);
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
    $capturedBuild = NULL;
    $this->setUpRenderContextCapture($container, $capturedBuild);
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
      'Child jurisdiction staff must see terms from full hierarchy tree, not just direct group.');
    $this->assertIsArray($capturedBuild, 'Query access cacheability must bubble to the render context.');
    $this->assertContains('user', $capturedBuild['#cache']['contexts']);
    $this->assertContains('user.is_group_member:10', $capturedBuild['#cache']['contexts']);
    $this->assertContains('group_relationship_list:plugin:group_membership:group:10', $capturedBuild['#cache']['tags']);
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:5', $capturedBuild['#cache']['tags']);
    $this->assertContains('group:10', $capturedBuild['#cache']['tags']);
    $this->assertContains('group_list', $capturedBuild['#cache']['tags']);
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

  /**
   * Jurisdiction staff can view unpublished terms in their own scope.
   */
  public function testJurisdictionMemberCanViewOwnUnpublishedTerm(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'moderator']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, [42]);
    $this->setUpHierarchyResolver($container, [42 => [42]]);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, 42, $this->createJurisdictionGroup(42)),
      'view',
      $account
    );

    $this->assertTrue($result->isAllowed(), 'Jurisdiction staff can view unpublished terms in their own jurisdiction.');
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains('user.is_group_member:42', $result->getCacheContexts());
    $this->assertContains('taxonomy_term:7', $result->getCacheTags());
    $this->assertContains('group:42', $result->getCacheTags());
    $this->assertContains('group_list', $result->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:group:42', $result->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:5', $result->getCacheTags());
  }

  /**
   * Jurisdiction membership alone is enough for read visibility.
   */
  public function testJurisdictionMembershipAloneCanViewOwnUnpublishedTerm(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, [42]);
    $this->setUpHierarchyResolver($container, [42 => [42]]);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, 42, $this->createJurisdictionGroup(42)),
      'view',
      $account
    );

    $this->assertTrue($result->isAllowed(), 'Any jurisdiction group membership grants read visibility.');
  }

  /**
   * Jurisdiction staff get neutral access for another jurisdiction's term.
   */
  public function testJurisdictionMemberGetsNeutralForForeignUnpublishedTerm(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'moderator']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, [99]);
    $this->setUpHierarchyResolver($container, [99 => [99]]);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, 42, $this->createJurisdictionGroup(42)),
      'view',
      $account
    );

    $this->assertTrue($result->isNeutral(), 'Foreign jurisdiction terms are left to normal taxonomy access.');
    $this->assertContains('user.is_group_member:42', $result->getCacheContexts());
    $this->assertContains('user.is_group_member:99', $result->getCacheContexts());
    $this->assertContains('group:42', $result->getCacheTags());
    $this->assertContains('group:99', $result->getCacheTags());
    $this->assertContains('group_list', $result->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:group:42', $result->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:group:99', $result->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:5', $result->getCacheTags());
  }

  /**
   * Unloadable term jurisdictions do not emit group-member cache contexts.
   */
  public function testUnloadableTermJurisdictionOmitsSpecificMembershipContext(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'moderator']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, [99]);
    $this->setUpHierarchyResolver($container, [99 => [99]]);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, 42, NULL),
      'view',
      $account
    );

    $this->assertTrue(
      $result->isNeutral(),
      'Terms pointing at missing jurisdictions are not directly granted.'
    );
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains('user.is_group_member:99', $result->getCacheContexts());
    $this->assertNotContains('user.is_group_member:42', $result->getCacheContexts());
  }

  /**
   * Global unpublished terms remain viewable to jurisdiction staff.
   */
  public function testJurisdictionMemberCanViewGlobalUnpublishedTerm(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'moderator']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, [42]);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, NULL),
      'view',
      $account
    );

    $this->assertTrue($result->isAllowed(), 'Jurisdiction staff can view shared unpublished terms.');
    $this->assertContains('user.is_group_member:42', $result->getCacheContexts());
    $this->assertContains('group:42', $result->getCacheTags());
    $this->assertContains('group_list', $result->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:group:42', $result->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:5', $result->getCacheTags());
  }

  /**
   * Child jurisdiction staff can view inherited root terms.
   */
  public function testJurisdictionMemberCanViewHierarchyExpandedUnpublishedTerm(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'moderator']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, [10]);
    $this->setUpHierarchyResolver($container, [10 => [8, 9, 10]]);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, 8, $this->createJurisdictionGroup(8)),
      'view',
      $account
    );

    $this->assertTrue($result->isAllowed(), 'Child jurisdiction staff can view inherited root terms.');
    $this->assertContains('user.is_group_member:8', $result->getCacheContexts());
    $this->assertContains('user.is_group_member:10', $result->getCacheContexts());
    $this->assertContains('group:8', $result->getCacheTags());
    $this->assertContains('group:10', $result->getCacheTags());
    $this->assertContains('group_list', $result->getCacheTags());
  }

  /**
   * Citizens without jurisdiction memberships are unaffected.
   */
  public function testCitizenWithoutMembershipGetsNeutralForUnpublishedTerm(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, []);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, 42, $this->createJurisdictionGroup(42)),
      'view',
      $account
    );

    $this->assertTrue($result->isNeutral(), 'Citizens without memberships are left to normal taxonomy access.');
  }

  /**
   * Drifted tenant_admin users without memberships are not directly granted.
   */
  public function testTenantAdminWithoutMembershipGetsNeutralForGlobalUnpublishedTerm(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, []);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(FALSE, NULL),
      'view',
      $account
    );

    $this->assertTrue($result->isNeutral(), 'Tenant admins without memberships follow the same fail-closed membership rule.');
  }

  /**
   * Published terms remain untouched by the unpublished-term grant.
   */
  public function testPublishedTermViewStaysNeutral(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'moderator']);

    $container = $this->setUpContainer($account);
    $this->setUpMembershipMocks($container, 5, [42]);
    \Drupal::setContainer($container);

    $result = markaspot_tenant_admin_taxonomy_term_access(
      $this->createManagedTerm(TRUE, 42, $this->createJurisdictionGroup(42)),
      'view',
      $account
    );

    $this->assertTrue($result->isNeutral(), 'Published terms are handled by normal taxonomy access.');
  }

}
