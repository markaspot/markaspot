<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\group\GroupMembership;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\markaspot_group\Controller\GroupMembersController;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the GroupMembersController access check logic.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Controller\GroupMembersController
 */
class GroupMembersControllerTest extends UnitTestCase {

  /**
   * Mocked membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoaderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $membershipLoader;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up container for Cache::mergeContexts() used by AccessResult.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a controller for access check testing.
   *
   * @return \Drupal\markaspot_group\Controller\GroupMembersController
   *   The controller instance.
   */
  protected function createController(): GroupMembersController {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $currentUser = $this->createMock(AccountInterface::class);

    return new GroupMembersController(
      $entityTypeManager,
      $this->membershipLoader,
      $hierarchyResolver,
      $currentUser,
      $this->createMock(LockBackendInterface::class),
    );
  }

  /**
   * Creates entity storage mocks for membership PATCH preflight checks.
   */
  protected function createMembershipUpdateEntityTypeManager(?UserInterface $targetUser): EntityTypeManagerInterface {
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')
      ->with(42)
      ->willReturn($targetUser);

    $groupStorage = $this->createMock(EntityStorageInterface::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static fn(string $type) => match ($type) {
        'user' => $userStorage,
        'group' => $groupStorage,
        default => throw new \LogicException("Unexpected storage '$type'."),
      });

    return $entityTypeManager;
  }

  /**
   * Creates a regular target user for membership PATCH tests.
   */
  protected function createMembershipPatchTargetUser(): UserInterface {
    $targetUser = $this->createMock(UserInterface::class);
    $targetUser->method('id')->willReturn(42);
    $targetUser->method('getRoles')->willReturn(['authenticated']);
    $targetUser->method('hasField')->with('field_all_groups_member')->willReturn(FALSE);
    return $targetUser;
  }

  /**
   * Creates a superadmin current account for membership PATCH tests.
   */
  protected function createMembershipPatchCurrentAccount(): AccountInterface {
    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(1);
    return $currentAccount;
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForSuperAdmin(): void {
    $controller = $this->createController();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user', $result->getCacheContexts());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForAdministrator(): void {
    $controller = $this->createController();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'administrator']);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.roles', $result->getCacheContexts());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForTenantAdmin(): void {
    $controller = $this->createController();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(10);
    $account->method('getRoles')->willReturn(['authenticated']);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $membership = $this->getMockBuilder(GroupMembership::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getGroup'])
      ->getMock();
    $membership->method('getGroup')->willReturn($group);

    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([$membership]);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckDeniesForRegularUser(): void {
    $controller = $this->createController();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(20);
    $account->method('getRoles')->willReturn(['authenticated']);

    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([]);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user', $result->getCacheContexts());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckDeniesForAnonymous(): void {
    $controller = $this->createController();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(0);
    $account->method('getRoles')->willReturn(['anonymous']);

    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([]);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::loadVisibleGroups
   */
  public function testTenantAdminOrgMatrixGroupsAreScopedByJurisdiction(): void {
    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(10);
    $currentAccount->method('getRoles')->willReturn(['authenticated']);

    $tenantJurisdiction = $this->createMock(GroupInterface::class);
    $tenantJurisdiction->method('id')->willReturn(1);
    $tenantJurisdiction->method('bundle')->willReturn('jur');

    $membership = $this->getMockBuilder(GroupMembership::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getGroup'])
      ->getMock();
    $membership->method('getGroup')->willReturn($tenantJurisdiction);

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($currentAccount, ['jur-tenant_admin'])
      ->willReturn([$membership]);

    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchyResolver->expects($this->once())
      ->method('getDescendantIds')
      ->with(1)
      ->willReturn([1, 2]);

    $conditions = [];
    $orgQuery = $this->createMock(QueryInterface::class);
    $orgQuery->expects($this->once())
      ->method('accessCheck')
      ->with(FALSE)
      ->willReturnSelf();
    $orgQuery->expects($this->exactly(2))
      ->method('condition')
      ->willReturnCallback(
        function (
          string $field,
          mixed $value = NULL,
          ?string $operator = NULL,
        ) use (&$conditions, $orgQuery): QueryInterface {
          $conditions[] = [$field, $value, $operator];
          return $orgQuery;
        }
      );
    $orgQuery->expects($this->once())
      ->method('sort')
      ->with('label')
      ->willReturnSelf();
    $orgQuery->expects($this->once())
      ->method('execute')
      ->willReturn([20 => 20]);

    $orgGroup = $this->createMock(GroupInterface::class);
    $orgGroup->method('id')->willReturn(20);
    $orgGroup->method('bundle')->willReturn('org');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('getQuery')
      ->willReturn($orgQuery);
    $groupStorage->expects($this->once())
      ->method('loadMultiple')
      ->with([20 => 20])
      ->willReturn([20 => $orgGroup]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $controller = new class(
      $entityTypeManager,
      $membershipLoader,
      $hierarchyResolver,
      $currentAccount,
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected loadVisibleGroups() method.
       */
      public function loadVisibleGroupsForTest(string $groupTypeFilter): array {
        return $this->loadVisibleGroups($groupTypeFilter);
      }

    };

    $this->assertSame(
      [$orgGroup],
      $controller->loadVisibleGroupsForTest('org')
    );
    $this->assertSame([
      ['type', 'org', NULL],
      ['field_jurisdiction', [1, 2], 'IN'],
    ], $conditions);
  }

  /**
   * @covers ::updateMemberships
   * @covers ::buildMembershipUpdateLockName
   */
  public function testUpdateMembershipsReturnsConflictWhenUserLockIsHeld(): void {
    $entityTypeManager = $this->createMembershipUpdateEntityTypeManager(
      $this->createMembershipPatchTargetUser(),
    );

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('markaspot_group:membership_update:42', 120.0)
      ->willReturn(FALSE);
    $lock->expects($this->never())
      ->method('release');

    $controller = new GroupMembersController(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMembershipPatchCurrentAccount(),
      $lock,
    );

    $request = Request::create(
      '/api/group-members/42',
      'PATCH',
      [],
      [],
      [],
      [],
      json_encode([
        'memberships' => [
          '1' => ['action' => 'set', 'roles' => ['jur-member']],
        ],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->updateMemberships($request, 42);

    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame([
      'error' => 'Membership update already in progress for this user.',
    ], json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * @covers ::updateMemberships
   */
  public function testUpdateMembershipsRejectsOversizedBatchBeforeLocking(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())
      ->method('getStorage');

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->never())
      ->method('acquire');

    $controller = new GroupMembersController(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMembershipPatchCurrentAccount(),
      $lock,
    );

    $memberships = [];
    for ($groupId = 1; $groupId <= 51; $groupId++) {
      $memberships[(string) $groupId] = ['action' => 'remove'];
    }

    $request = Request::create(
      '/api/group-members/42',
      'PATCH',
      [],
      [],
      [],
      [],
      json_encode(['memberships' => $memberships], JSON_THROW_ON_ERROR),
    );

    $response = $controller->updateMemberships($request, 42);

    $this->assertSame(413, $response->getStatusCode());
    $this->assertSame([
      'error' => 'Too many membership updates in one request.',
    ], json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * @covers ::updateMemberships
   */
  public function testUpdateMembershipsDoesNotLockUnknownUsers(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->never())
      ->method('acquire');

    $controller = new GroupMembersController(
      $this->createMembershipUpdateEntityTypeManager(NULL),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMembershipPatchCurrentAccount(),
      $lock,
    );

    $request = Request::create(
      '/api/group-members/42',
      'PATCH',
      [],
      [],
      [],
      [],
      json_encode([
        'memberships' => [
          '1' => ['action' => 'remove'],
        ],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->updateMemberships($request, 42);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame([
      'error' => 'User not found.',
    ], json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * @covers ::updateMemberships
   */
  public function testUpdateMembershipsReleasesUserLockAfterSuccessfulBatch(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('markaspot_group:membership_update:42', 120.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('markaspot_group:membership_update:42');

    $controller = new class(
      $this->createMembershipUpdateEntityTypeManager($this->createMembershipPatchTargetUser()),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMembershipPatchCurrentAccount(),
      $lock,
    ) extends GroupMembersController {

      /**
       * {@inheritdoc}
       */
      protected function doUpdateMemberships(array $content, int $uid): JsonResponse {
        return new JsonResponse([
          'status' => 'ok',
          'updated' => $content['memberships'],
        ]);
      }

    };

    $request = Request::create(
      '/api/group-members/42',
      'PATCH',
      [],
      [],
      [],
      [],
      json_encode([
        'memberships' => [
          '1' => ['action' => 'remove'],
        ],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->updateMemberships($request, 42);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'status' => 'ok',
      'updated' => [
        '1' => ['action' => 'remove'],
      ],
    ], json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * @covers ::updateMemberships
   */
  public function testUpdateMembershipsReleasesUserLockAfterFailure(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('markaspot_group:membership_update:42', 120.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('markaspot_group:membership_update:42');

    $controller = new class(
      $this->createMembershipUpdateEntityTypeManager($this->createMembershipPatchTargetUser()),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMembershipPatchCurrentAccount(),
      $lock,
    ) extends GroupMembersController {

      /**
       * {@inheritdoc}
       */
      protected function doUpdateMemberships(array $content, int $uid): JsonResponse {
        throw new \RuntimeException('Synthetic failure.');
      }

    };

    $request = Request::create(
      '/api/group-members/42',
      'PATCH',
      [],
      [],
      [],
      [],
      json_encode([
        'memberships' => [
          '1' => ['action' => 'remove'],
        ],
      ], JSON_THROW_ON_ERROR),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Synthetic failure.');

    $controller->updateMemberships($request, 42);
  }

  /**
   * @covers ::updateUserProfile
   * @covers ::buildMembershipUpdateLockName
   */
  public function testUpdateUserProfileUsesTargetUserLock(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('markaspot_group:membership_update:42', 120.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('markaspot_group:membership_update:42');

    $controller = new class(
      $this->createMembershipUpdateEntityTypeManager($this->createMembershipPatchTargetUser()),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMembershipPatchCurrentAccount(),
      $lock,
    ) extends GroupMembersController {

      /**
       * {@inheritdoc}
       */
      protected function doUpdateUserProfile(array $content, int $uid): JsonResponse {
        return new JsonResponse([
          'uid' => $uid,
          'changes' => array_keys($content),
        ]);
      }

    };

    $request = Request::create(
      '/api/group-members/42/profile',
      'PATCH',
      [],
      [],
      [],
      [],
      json_encode(['name' => 'New Name'], JSON_THROW_ON_ERROR),
    );

    $response = $controller->updateUserProfile($request, 42);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'uid' => 42,
      'changes' => ['name'],
    ], json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * @covers ::loadUsers
   */
  public function testLoadUsersReturnsEmptyForTenantAdminWithoutVisibleGroups(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())
      ->method('getStorage');

    $controller = new class(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected loadUsers() method.
       */
      public function loadUsersForTest(
        int $page,
        int $pageSize,
        string $search,
        array $groups,
        bool $isDrupalAdmin = FALSE,
        bool $includeInactive = FALSE,
      ): array {
        return $this->loadUsers($page, $pageSize, $search, $groups, $isDrupalAdmin, $includeInactive);
      }

    };

    $this->assertSame([[], 0], $controller->loadUsersForTest(
      0,
      50,
      '',
      [],
      FALSE,
      FALSE,
    ));
  }

  /**
   * @covers ::loadUserMembershipsBatch
   */
  public function testLoadUserMembershipsBatchAvoidsPerUserLoadByUser(): void {
    $userA = $this->createMock(UserInterface::class);
    $userA->method('id')->willReturn(10);
    $userB = $this->createMock(UserInterface::class);
    $userB->method('id')->willReturn(11);

    $groupA = $this->createMock(GroupInterface::class);
    $groupB = $this->createMock(GroupInterface::class);

    $individualRole = $this->createMock(GroupRoleInterface::class);
    $individualRole->method('id')->willReturn('jur-member');
    $individualRole->method('getScope')->willReturn(PermissionScopeInterface::INDIVIDUAL_ID);

    $internalRole = $this->createMock(GroupRoleInterface::class);
    $internalRole->method('id')->willReturn(MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID);
    $internalRole->method('getScope')->willReturn(PermissionScopeInterface::INDIVIDUAL_ID);

    $outsiderRole = $this->createMock(GroupRoleInterface::class);
    $outsiderRole->method('id')->willReturn('jur-outsider');
    $outsiderRole->method('getScope')->willReturn(PermissionScopeInterface::OUTSIDER_ID);

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->expects($this->once())
      ->method('loadMultiple')
      ->with([
        'jur-member' => 'jur-member',
        'jur-outsider' => 'jur-outsider',
        MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID => MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID,
      ])
      ->willReturn([
        'jur-member' => $individualRole,
        'jur-outsider' => $outsiderRole,
        MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID => $internalRole,
      ]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group_role')
      ->willReturn($roleStorage);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')
      ->willReturn([
        (object) ['id' => 100, 'gid' => 1, 'entity_id' => 10, 'role_id' => 'jur-member'],
        (object) ['id' => 100, 'gid' => 1, 'entity_id' => 10, 'role_id' => 'jur-outsider'],
        (object) [
          'id' => 100,
          'gid' => 1,
          'entity_id' => 10,
          'role_id' => MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID,
        ],
        (object) ['id' => 101, 'gid' => 2, 'entity_id' => 11, 'role_id' => NULL],
      ]);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('fields')
      ->with('gr', ['id', 'gid', 'entity_id'])
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('leftJoin')
      ->with(
        'group_relationship__group_roles',
        'gr_roles',
        'gr_roles.entity_id = gr.id AND gr_roles.deleted = 0 AND gr_roles.langcode = gr.langcode'
      )
      ->willReturn('gr_roles');
    $select->expects($this->once())
      ->method('addField')
      ->with('gr_roles', 'group_roles_target_id', 'role_id')
      ->willReturnSelf();
    $select->expects($this->exactly(3))
      ->method('condition')
      ->willReturnCallback(function ($field, $value, $operator = NULL) use ($select) {
        static $expected = [
          ['gr.entity_id', [10, 11], 'IN'],
          ['gr.gid', [1, 2], 'IN'],
          ['gr.type', ['jur-group_membership', 'org-group_membership'], 'IN'],
        ];
        $current = array_shift($expected);
        $this->assertSame($current[0], $field);
        $this->assertSame($current[1], $value);
        $this->assertSame($current[2], $operator);
        return $select;
      });
    $select->method('execute')->willReturn($statement);

    $connection = $this->createMock(Connection::class);
    $connection->expects($this->once())
      ->method('select')
      ->with('group_relationship_field_data', 'gr')
      ->willReturn($select);

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->never())
      ->method('loadByUser');

    $controller = new class(
      $entityTypeManager,
      $membershipLoader,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(LockBackendInterface::class),
      $connection,
    ) extends GroupMembersController {

      public function __construct(
        EntityTypeManagerInterface $entityTypeManager,
        GroupMembershipLoaderInterface $membershipLoader,
        JurisdictionHierarchyResolverInterface $hierarchyResolver,
        AccountInterface $currentUser,
        LockBackendInterface $lock,
        private readonly Connection $connection,
      ) {
        parent::__construct($entityTypeManager, $membershipLoader, $hierarchyResolver, $currentUser, $lock);
      }

      /**
       * Calls the protected loadUserMembershipsBatch() method.
       */
      public function loadUserMembershipsBatchForTest(array $users, array $groupIds): array {
        return $this->loadUserMembershipsBatch($users, $groupIds);
      }

      /**
       * {@inheritdoc}
       */
      protected function getDatabaseConnection(): Connection {
        return $this->connection;
      }

    };

    $result = $controller->loadUserMembershipsBatchForTest(
      [$userA, $userB],
      [1 => $groupA, 2 => $groupB],
    );

    $this->assertSame([
      10 => [
        '1' => ['roles' => ['jur-member']],
      ],
      11 => [
        '2' => ['roles' => []],
      ],
    ], $result);
  }

  /**
   * @covers ::addJurisdictionDepths
   * @covers ::calculateJurisdictionDepth
   */
  public function testAddJurisdictionDepthsUsesParentMap(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())
      ->method('getStorage');

    $controller = new class(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected addJurisdictionDepths() method.
       */
      public function addJurisdictionDepthsForTest(array $groupsData, array $parentById): array {
        return $this->addJurisdictionDepths($groupsData, $parentById);
      }

    };

    $result = $controller->addJurisdictionDepthsForTest(
      [
        ['id' => 1, 'label' => 'Root', 'type' => 'jur', 'parent_id' => NULL, 'depth' => 0],
        ['id' => 2, 'label' => 'Child', 'type' => 'jur', 'parent_id' => 1, 'depth' => 0],
        ['id' => 3, 'label' => 'Grandchild', 'type' => 'jur', 'parent_id' => 2, 'depth' => 0],
        ['id' => 4, 'label' => 'Missing parent', 'type' => 'jur', 'parent_id' => 999, 'depth' => 0],
        ['id' => 5, 'label' => 'Cycle A', 'type' => 'jur', 'parent_id' => 6, 'depth' => 0],
        ['id' => 6, 'label' => 'Cycle B', 'type' => 'jur', 'parent_id' => 5, 'depth' => 0],
        ['id' => 7, 'label' => 'Org', 'type' => 'org', 'parent_id' => NULL, 'depth' => 0],
        ['id' => 8, 'label' => 'Tenant subtree root', 'type' => 'jur', 'parent_id' => 9, 'depth' => 0],
      ],
      [
        1 => NULL,
        2 => 1,
        3 => 2,
        4 => 999,
        5 => 6,
        6 => 5,
        8 => 9,
        9 => NULL,
      ]
    );

    $depths = array_column($result, 'depth', 'id');

    $this->assertSame(0, $depths[1]);
    $this->assertSame(1, $depths[2]);
    $this->assertSame(2, $depths[3]);
    $this->assertSame(1, $depths[4]);
    $this->assertSame(0, $depths[5]);
    $this->assertSame(0, $depths[6]);
    $this->assertSame(0, $depths[7]);
    $this->assertSame(1, $depths[8]);
  }

  /**
   * @covers ::buildMembershipUpdateResponse
   */
  public function testBuildMembershipUpdateResponseStatuses(): void {
    $controller = new class(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected buildMembershipUpdateResponse() method.
       */
      public function buildMembershipUpdateResponseForTest(array $updated, array $errors): array {
        return $this->buildMembershipUpdateResponse($updated, $errors);
      }

    };

    $this->assertSame([
      'status' => 'ok',
      'updated' => [1 => ['action' => 'set']],
    ], $controller->buildMembershipUpdateResponseForTest(
      [1 => ['action' => 'set']],
      []
    ));

    $this->assertSame([
      'status' => 'partial',
      'updated' => [1 => ['action' => 'set']],
      'errors' => ['Membership update rejected for group 2.'],
    ], $controller->buildMembershipUpdateResponseForTest(
      [1 => ['action' => 'set']],
      ['Membership update rejected for group 2.']
    ));

    $this->assertSame([
      'status' => 'error',
      'updated' => [],
      'errors' => ['Membership update rejected for group 2.'],
    ], $controller->buildMembershipUpdateResponseForTest(
      [],
      ['Membership update rejected for group 2.']
    ));
  }

  /**
   * @covers ::getMembershipGroupRejectedMessage
   */
  public function testMembershipGroupRejectedMessageIsGeneric(): void {
    $controller = new class(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected getMembershipGroupRejectedMessage() method.
       */
      public function getMembershipGroupRejectedMessageForTest(int $groupId): string {
        return $this->getMembershipGroupRejectedMessage($groupId);
      }

    };

    $this->assertSame(
      'Membership update rejected for group 99.',
      $controller->getMembershipGroupRejectedMessageForTest(99)
    );
  }

  /**
   * @covers ::isDrupalAdminAccount
   */
  public function testSuperAdminIsTreatedAsDrupalAdminWithoutAdministratorRole(): void {
    $controller = new class(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected isDrupalAdminAccount() method.
       */
      public function isDrupalAdminAccountForTest(AccountInterface $account): bool {
        return $this->isDrupalAdminAccount($account);
      }

    };

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $account->expects($this->never())
      ->method('getRoles');

    $this->assertTrue($controller->isDrupalAdminAccountForTest($account));
  }

  /**
   * @covers ::handleSetMembership
   */
  public function testSetMembershipNormalizesTenantAdminRole(): void {
    $memberRole = $this->createMock(GroupRoleInterface::class);
    $memberRole->method('getGroupTypeId')->willReturn('jur');
    $memberRole->method('getScope')->willReturn(PermissionScopeInterface::INDIVIDUAL_ID);

    $tenantAdminRole = $this->createMock(GroupRoleInterface::class);
    $tenantAdminRole->method('getGroupTypeId')->willReturn('jur');
    $tenantAdminRole->method('getScope')->willReturn(PermissionScopeInterface::INDIVIDUAL_ID);

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->expects($this->exactly(2))
      ->method('load')
      ->willReturnMap([
        ['jur-member', $memberRole],
        ['jur-tenant_admin', $tenantAdminRole],
      ]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group_role')
      ->willReturn($roleStorage);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn(NULL);

    $targetUser = $this->createMock(UserInterface::class);
    $targetUser->method('id')->willReturn(10);

    $group->expects($this->once())
      ->method('addMember')
      ->with($targetUser, ['group_roles' => ['jur-member', 'jur-tenant_admin']]);

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(1);

    $controller = new class(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected handleSetMembership() method.
       */
      public function handleSetMembershipForTest(
        GroupInterface $group,
        UserInterface $targetUser,
        array $roleIds,
        AccountInterface $currentAccount,
        bool $isDrupalAdmin = FALSE,
      ): array {
        return $this->handleSetMembership($group, $targetUser, $roleIds, $currentAccount, $isDrupalAdmin);
      }

    };

    $result = $controller->handleSetMembershipForTest(
      $group,
      $targetUser,
      ['jur-tenant_admin'],
      $currentAccount,
      TRUE,
    );

    $this->assertTrue($result['success']);
    $this->assertSame(
      ['action' => 'set', 'group_id' => 5, 'roles' => ['jur-member', 'jur-tenant_admin']],
      $result['data']
    );
  }

  /**
   * @covers ::handleSetMembership
   */
  public function testSetMembershipRejectsMalformedRoleIds(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('bundle')->willReturn('jur');
    $group->expects($this->never())->method('addMember');

    $targetUser = $this->createMock(UserInterface::class);
    $targetUser->method('id')->willReturn(10);

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(1);

    $controller = new class(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected handleSetMembership() method.
       */
      public function handleSetMembershipForTest(
        GroupInterface $group,
        UserInterface $targetUser,
        array $roleIds,
        AccountInterface $currentAccount,
        bool $isDrupalAdmin = FALSE,
      ): array {
        return $this->handleSetMembership($group, $targetUser, $roleIds, $currentAccount, $isDrupalAdmin);
      }

    };

    $result = $controller->handleSetMembershipForTest(
      $group,
      $targetUser,
      ['jur-member', 123],
      $currentAccount,
      TRUE,
    );

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid role ID.', $result['error']);
  }

  /**
   * @covers ::handleSetMembership
   */
  public function testSetMembershipRejectsInternalDerivedRole(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('bundle')->willReturn('jur');
    $group->expects($this->never())->method('addMember');

    $targetUser = $this->createMock(UserInterface::class);
    $targetUser->method('id')->willReturn(10);

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(1);

    $controller = new class(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected handleSetMembership() method.
       */
      public function handleSetMembershipForTest(
        GroupInterface $group,
        UserInterface $targetUser,
        array $roleIds,
        AccountInterface $currentAccount,
        bool $isDrupalAdmin = FALSE,
      ): array {
        return $this->handleSetMembership($group, $targetUser, $roleIds, $currentAccount, $isDrupalAdmin);
      }

    };

    $result = $controller->handleSetMembershipForTest(
      $group,
      $targetUser,
      [MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID],
      $currentAccount,
      TRUE,
    );

    $this->assertFalse($result['success']);
    $this->assertSame(
      "Role 'jur-org_member' is managed internally.",
      $result['error']
    );
  }

  /**
   * @covers ::handleSetMembership
   */
  public function testSetMembershipPreservesInternalRoleWithoutReturningIt(): void {
    $memberRole = $this->createMock(GroupRoleInterface::class);
    $memberRole->method('getGroupTypeId')->willReturn('jur');
    $memberRole->method('getScope')->willReturn(PermissionScopeInterface::INDIVIDUAL_ID);

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->expects($this->once())
      ->method('load')
      ->with('jur-member')
      ->willReturn($memberRole);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group_role')
      ->willReturn($roleStorage);

    $groupRoles = $this->createMock(FieldItemListInterface::class);
    $groupRoles->method('getValue')
      ->willReturn([
        ['target_id' => MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID],
      ]);

    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('get')
      ->with('group_roles')
      ->willReturn($groupRoles);
    $relationship->expects($this->once())
      ->method('set')
      ->with('group_roles', [
        'jur-member',
        MembershipRoleNormalizer::ORG_DERIVED_JUR_ROLE_ID,
      ]);
    $relationship->expects($this->once())
      ->method('save');

    $existingMember = $this->getMockBuilder(GroupMembership::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getGroupRelationship'])
      ->getMock();
    $existingMember->method('getGroupRelationship')->willReturn($relationship);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn($existingMember);
    $group->expects($this->never())->method('addMember');

    $targetUser = $this->createMock(UserInterface::class);
    $targetUser->method('id')->willReturn(10);

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(1);

    $controller = new class(
      $entityTypeManager,
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $this->createMock(LockBackendInterface::class),
    ) extends GroupMembersController {

      /**
       * Calls the protected handleSetMembership() method.
       */
      public function handleSetMembershipForTest(
        GroupInterface $group,
        UserInterface $targetUser,
        array $roleIds,
        AccountInterface $currentAccount,
        bool $isDrupalAdmin = FALSE,
      ): array {
        return $this->handleSetMembership($group, $targetUser, $roleIds, $currentAccount, $isDrupalAdmin);
      }

    };

    $result = $controller->handleSetMembershipForTest(
      $group,
      $targetUser,
      ['jur-member'],
      $currentAccount,
      TRUE,
    );

    $this->assertTrue($result['success']);
    $this->assertSame(
      ['action' => 'set', 'group_id' => 5, 'roles' => ['jur-member']],
      $result['data']
    );
  }

}
