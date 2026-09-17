<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Controller\GroupMembersController;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Exercises global profile mutations with real controller authorization.
 */
#[CoversClass(GroupMembersController::class)]
#[Group('markaspot_group')]
final class GroupMembersGlobalProfileAuthorityTest extends UnitTestCase {

  /**
   * Builds in-memory entities; only session SQL is replaced in the controller.
   */
  private function fixture(
    array $targetGroupDefinitions = [[101, 'jur']],
    array $managedIds = [101],
    array $targetRoles = ['authenticated', 'moderator'],
    array $targetPermissions = [],
    bool $allGroups = FALSE,
    int $actorId = 10,
    array $actorRoles = ['authenticated', 'tenant_admin'],
    ?array $membershipsAfterLock = NULL,
    bool $targetAdminRole = FALSE,
  ): array {
    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $container->set('cache_tags.invalidator', $invalidator);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    $container->set('logger.factory', $factory);
    $validator = $this->createMock(EmailValidatorInterface::class);
    $validator->method('isValid')->willReturn(TRUE);
    $container->set('email.validator', $validator);
    \Drupal::setContainer($container);

    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn($actorId);
    $actor->method('getRoles')->willReturn($actorRoles);
    $actor->method('getDisplayName')->willReturn('Acting administrator');
    $target = $this->createMock(UserInterface::class);
    $target->method('id')->willReturn(42);
    $target->method('getRoles')->willReturn($targetRoles);
    $target->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, $targetPermissions, TRUE),
    );
    $target->method('getAccountName')->willReturn('target');
    $target->method('getDisplayName')->willReturn('Target');
    $target->method('getEmail')->willReturn('old@example.test');
    $target->method('isActive')->willReturn(TRUE);
    $target->method('validate')->willReturn(new ConstraintViolationList());
    $target->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_all_groups_member',
    );
    $allGroupsField = $this->createMock(FieldItemListInterface::class);
    $allGroupsField->method('__get')->with('value')->willReturn($allGroups);
    $target->method('get')->with('field_all_groups_member')->willReturn($allGroupsField);

    $groups = [];
    foreach (array_merge($targetGroupDefinitions, $membershipsAfterLock ?? [], array_map(
      static fn(int $id): array => [$id, 'jur'],
      $managedIds,
    )) as $definition) {
      [$id, $bundle] = $definition;
      $jurisdiction = $definition[2] ?? NULL;
      $group = $this->createMock(GroupInterface::class);
      $group->method('id')->willReturn($id);
      $group->method('bundle')->willReturn($bundle);
      $group->method('hasField')->willReturn($jurisdiction !== NULL);
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn($jurisdiction === NULL);
      $field->method('__get')->with('target_id')->willReturn($jurisdiction);
      $group->method('get')->with('field_jurisdiction')->willReturn($field);
      $groups[$id] = $group;
    }
    $wrap = function (array $definitions) use (&$groups): array {
      return array_map(function (array $definition) use (&$groups): GroupMembershipInterface {
        $membership = $this->createMock(GroupMembershipInterface::class);
        $membership->method('getGroup')->willReturn($groups[$definition[0]]);
        return $membership;
      }, $definitions);
    };
    $targetMemberships = $wrap($targetGroupDefinitions);
    $freshMemberships = $membershipsAfterLock === NULL ? $targetMemberships : $wrap($membershipsAfterLock);
    $managedMemberships = $wrap(array_map(static fn(int $id): array => [$id, 'jur'], $managedIds));
    $refreshed = FALSE;
    // The existing controller constructor still requires Group's legacy loader.
    // @phpstan-ignore classConstant.deprecatedInterface
    $loader = $this->createMock(GroupMembershipLoaderInterface::class);
    $loader->method('loadByUser')->willReturnCallback(
      static function ($account, $roles = NULL) use (
        $actor, $target, $managedMemberships, $targetMemberships, $freshMemberships, &$refreshed,
      ): array {
        if ($account === $actor && $roles === ['jur-tenant_admin']) {
          return $managedMemberships;
        }
        if ($account === $target && $roles === NULL) {
          return $refreshed ? $freshMemberships : $targetMemberships;
        }
        throw new \LogicException('Unexpected account or role filter.');
      },
    );
    $invalidator->method('invalidateTags')->willReturnCallback(
      static function (array $tags) use (&$refreshed): void {
        if (in_array('group_relationship_list:plugin:group_membership:entity:42', $tags, TRUE)) {
          $refreshed = TRUE;
        }
      },
    );
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn(0);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($target);
    $storage->method('getQuery')->willReturn($query);
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->willReturnCallback(static fn($id) => $groups[$id] ?? NULL);
    $groupStorage->method('loadMultiple')->willReturnCallback(
      static fn(array $ids): array => array_intersect_key($groups, array_flip($ids)),
    );
    $orgIds = [];
    foreach ($targetGroupDefinitions as $definition) {
      if ($definition[1] === 'org' && in_array($definition[2] ?? NULL, $managedIds, TRUE)) {
        $orgIds[] = $definition[0];
      }
    }
    $groupQuery = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'sort'] as $method) {
      $groupQuery->method($method)->willReturnSelf();
    }
    $groupQuery->method('execute')->willReturn($orgIds);
    $groupStorage->method('getQuery')->willReturn($groupQuery);
    $relationshipStorage = $this->createMock(EntityStorageInterface::class);
    $role = $this->createMock(RoleInterface::class);
    $role->method('isAdmin')->willReturn($targetAdminRole);
    $role->method('getPermissions')->willReturn($targetPermissions);
    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('loadMultiple')->with($targetRoles)->willReturn([$role]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([
      ['user', $storage],
      ['group', $groupStorage],
      ['group_relationship', $relationshipStorage],
      ['user_role', $roleStorage],
    ]);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getDescendantIds')->willReturnCallback(static fn(int $id): array => [$id]);
    $permissionHandler = $this->createMock(PermissionHandlerInterface::class);
    $definitions = [];
    foreach ($targetPermissions as $permission) {
      $definitions[$permission] = [
        'restrict access' => $permission !== 'administer custom global integration',
      ];
    }
    $permissionHandler->method('getPermissions')->willReturn($definitions);
    $controller = new class($manager, $loader, $hierarchy, $actor, $lock, $permissionHandler) extends GroupMembersController {

      /**
       * Recorded SQL session invalidations.
       */
      public array $invalidated = [];

      /**
       * {@inheritdoc}
       */
      protected function invalidateUserSessions(int $uid): void {
        $this->invalidated[] = $uid;
      }

    };
    return compact('controller', 'target', 'actor', 'groups', 'lock', 'storage', 'invalidator', 'groupStorage', 'relationshipStorage');
  }

  /**
   * Calls the actual profile endpoint with a JSON body.
   */
  private function patch(GroupMembersController $controller, array $body, int $uid = 42): JsonResponse {
    return $controller->updateUserProfile(Request::create(
      '/api/group-members/' . $uid . '/profile',
      'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'],
      json_encode($body, JSON_THROW_ON_ERROR),
    ), $uid);
  }

  /**
   * Shared or unowned accounts cannot be changed by a tenant administrator.
   */
  #[DataProvider('unmanagedProfiles')]
  public function testUnmanagedGlobalMutationHasNoSideEffects(array $groups, array $body): void {
    $fixture = $this->fixture(targetGroupDefinitions: $groups);
    $this->assertTrue($fixture['controller']->accessCheck($fixture['actor'])->isAllowed());
    foreach (['save', 'setUsername', 'setEmail', 'block', 'activate'] as $method) {
      $fixture['target']->expects($this->never())->method($method);
    }
    foreach ($fixture['groups'] as $group) {
      $group->expects($this->never())->method('removeMember');
    }
    $fixture['lock']->expects($this->never())->method('acquire');
    $fixture['storage']->expects($this->never())->method('getQuery');
    $this->assertSame(403, $this->patch($fixture['controller'], $body)->getStatusCode());
    $this->assertSame([], $fixture['controller']->invalidated);
  }

  /**
   * Unauthorized account scopes and all global mutation types.
   */
  public static function unmanagedProfiles(): iterable {
    foreach ([
      'shared jurisdiction' => [[101, 'jur'], [202, 'jur']],
      'foreign organisation' => [[101, 'jur'], [303, 'org', 202]],
      'unscoped organisation' => [[101, 'jur'], [303, 'org']],
      'unknown group bundle' => [[101, 'jur'], [404, 'other']],
      'no memberships' => [],
      'foreign only' => [[202, 'jur']],
    ] as $scope => $groups) {
      foreach ([
        'name' => ['name' => 'Changed'],
        'email' => ['email' => 'changed@example.test'],
        'status' => ['status' => 0],
        'anonymize' => ['anonymize' => TRUE],
      ] as $operation => $body) {
        yield "$scope / $operation" => [$groups, $body];
      }
    }
  }

  /**
   * Global authority is protected even with only one local membership.
   */
  #[DataProvider('privilegedProfiles')]
  public function testPrivilegedAccountCannotBeTakenOver(array $roles, array $permissions, bool $allGroups): void {
    $fixture = $this->fixture(targetRoles: $roles, targetPermissions: $permissions, allGroups: $allGroups);
    $fixture['target']->expects($this->never())->method('setEmail');
    $fixture['target']->expects($this->never())->method('save');
    $fixture['lock']->expects($this->never())->method('acquire');
    $this->assertSame(403, $this->patch($fixture['controller'], ['email' => 'changed@example.test'])->getStatusCode());
  }

  /**
   * Named operator roles, effective custom privileges and auto-membership.
   */
  public static function privilegedProfiles(): iterable {
    foreach (['administrator', 'editorial_board', 'api_user'] as $role) {
      yield $role => [['authenticated', $role], [], FALSE];
    }
    foreach ([
      'administer users', 'administer permissions', 'administer account settings',
      'administer nodes', 'bypass node access', 'administer group', 'access platform admin',
      'administer modules', 'administer themes', 'administer site configuration',
      'administer software updates', 'administer filters', 'administer actions',
      'administer custom global integration',
      'import configuration', 'synchronize configuration', 'export configuration',
      'switch users', 'custom critical capability', 'view fastmap billing admin',
    ] as $permission) {
      yield $permission => [['authenticated', 'custom_operator'], [$permission], FALSE];
    }
    yield 'all groups member' => [['authenticated'], [], TRUE];
  }

  /**
   * A custom Drupal administrator role is protected even without named grants.
   */
  public function testCustomAdministratorRoleIsProtected(): void {
    $fixture = $this->fixture(targetRoles: ['authenticated', 'custom_admin'], targetAdminRole: TRUE);
    $fixture['target']->expects($this->never())->method('setEmail');
    $fixture['target']->expects($this->never())->method('save');
    $this->assertSame(403, $this->patch($fixture['controller'], ['email' => 'changed@example.test'])->getStatusCode());
  }

  /**
   * Existing restricted tenant staff capabilities remain compatible.
   */
  #[DataProvider('compatibleTenantPermissions')]
  public function testExistingTenantCapabilityRemainsManageable(string $permission): void {
    $fixture = $this->fixture(targetPermissions: [$permission]);
    $fixture['target']->expects($this->once())->method('setEmail')->with('changed@example.test');
    $fixture['target']->expects($this->once())->method('save');
    $this->assertSame(200, $this->patch($fixture['controller'], ['email' => 'changed@example.test'])->getStatusCode());
  }

  /**
   * Explicit compatibility exceptions do not make arbitrary restrictions safe.
   */
  public static function compatibleTenantPermissions(): iterable {
    foreach ([
      'administer markaspot mail texts', 'bypass mas validation',
      'access dashboard notifications', 'access dashboard kpis',
      'manage dashboard notes', 'add dashboard status notes',
      'split service requests', 'use service request management form',
      'assign service requests',
    ] as $permission) {
      yield $permission => [$permission];
    }
  }

  /**
   * Exclusive accounts and wholly managed shared accounts can be updated.
   */
  #[DataProvider('managedProfiles')]
  public function testManagedModeratorProfileCanBeUpdated(array $groups, array $managed): void {
    $fixture = $this->fixture(targetGroupDefinitions: $groups, managedIds: $managed);
    $fixture['target']->expects($this->once())->method('setUsername')->with('Changed');
    $fixture['target']->expects($this->once())->method('setEmail')->with('changed@example.test');
    $fixture['target']->expects($this->once())->method('save');
    $fixture['storage']->expects($this->once())->method('resetCache')->with([42]);
    $fixture['relationshipStorage']->expects($this->once())->method('resetCache');
    $fixture['groupStorage']->expects($this->once())->method('resetCache');
    $response = $this->patch($fixture['controller'], ['name' => 'Changed', 'email' => 'changed@example.test']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['name', 'email'], json_decode($response->getContent(), TRUE)['changes']);
  }

  /**
   * Fully managed scopes include org memberships and multiple jurisdictions.
   */
  public static function managedProfiles(): iterable {
    yield 'exclusive tenant' => [[[101, 'jur']], [101]];
    yield 'same tenant organisation' => [[[101, 'jur'], [303, 'org', 101]], [101]];
    yield 'all shared tenants managed' => [[[101, 'jur'], [202, 'jur'], [303, 'org', 202]], [101, 202]];
  }

  /**
   * Tenant lifecycle authority remains valid for a wholly managed account.
   */
  public function testManagedTenantCanAnonymizeItsExclusiveAccount(): void {
    $fixture = $this->fixture(targetGroupDefinitions: [[101, 'jur'], [303, 'org', 101]]);
    $fixture['target']->expects($this->once())->method('setUsername')->with('anonymized_42');
    $fixture['target']->expects($this->once())->method('setEmail')->with('anonymized_42@deleted.invalid');
    $fixture['target']->expects($this->once())->method('block');
    $fixture['target']->expects($this->once())->method('save');
    foreach ($fixture['groups'] as $group) {
      $group->expects($this->once())->method('removeMember')->with($fixture['target']);
    }
    $this->assertSame(200, $this->patch($fixture['controller'], ['anonymize' => TRUE])->getStatusCode());
    $this->assertSame([42], $fixture['controller']->invalidated);
  }

  /**
   * Shared accounts remain manageable through the tenant-local membership API.
   */
  public function testLocalMembershipRemovalDoesNotRequireGlobalProfileAuthority(): void {
    $fixture = $this->fixture(targetGroupDefinitions: [[101, 'jur'], [202, 'jur'], [303, 'org', 101]]);
    $fixture['target']->expects($this->never())->method('save');
    $fixture['groups'][303]->expects($this->once())->method('removeMember')->with($fixture['target']);
    $fixture['groups'][101]->expects($this->never())->method('removeMember');
    $fixture['groups'][202]->expects($this->never())->method('removeMember');
    $request = Request::create('/api/group-members/42', 'PATCH', [], [], [], [], json_encode([
      'memberships' => [303 => ['action' => 'remove'], 202 => ['action' => 'remove']],
    ], JSON_THROW_ON_ERROR));
    $response = $fixture['controller']->updateMemberships($request, 42);
    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('partial', $body['status']);
    $this->assertSame([303], array_keys($body['updated']));
    $this->assertSame(['Membership update rejected for group 202.'], $body['errors']);
  }

  /**
   * The authorization is re-evaluated after membership caches are refreshed.
   */
  public function testForeignMembershipAddedBeforeLockPreventsGlobalMutation(): void {
    $fixture = $this->fixture(membershipsAfterLock: [[101, 'jur'], [202, 'jur']]);
    $fixture['target']->expects($this->never())->method('setEmail');
    $fixture['target']->expects($this->never())->method('save');
    $fixture['lock']->expects($this->once())->method('acquire')->with('markaspot_group:membership_update:42', 120.0);
    $fixture['lock']->expects($this->once())->method('release')->with('markaspot_group:membership_update:42');
    $this->assertSame(403, $this->patch($fixture['controller'], ['email' => 'changed@example.test'])->getStatusCode());
  }

  /**
   * Basic self-service remains possible when one's account is shared.
   */
  public function testSharedSelfCanChangeNameAndEmail(): void {
    $fixture = $this->fixture(targetGroupDefinitions: [[101, 'jur'], [202, 'jur']], actorId: 42);
    $fixture['target']->expects($this->once())->method('setUsername')->with('Changed');
    $fixture['target']->expects($this->once())->method('setEmail')->with('changed@example.test');
    $fixture['target']->expects($this->once())->method('save');
    $this->assertSame(200, $this->patch($fixture['controller'], ['name' => 'Changed', 'email' => 'changed@example.test'])->getStatusCode());
  }

  /**
   * Self-service cannot deactivate or anonymize the caller.
   */
  #[DataProvider('selfLifecycleMutations')]
  public function testSelfLifecycleRemainsForbidden(array $body): void {
    $fixture = $this->fixture(actorId: 42);
    $fixture['target']->expects($this->never())->method('save');
    $fixture['target']->expects($this->never())->method('block');
    $this->assertSame(400, $this->patch($fixture['controller'], $body)->getStatusCode());
    $this->assertSame([], $fixture['controller']->invalidated);
  }

  /**
   * Forbidden self lifecycle changes, including mixed profile requests.
   */
  public static function selfLifecycleMutations(): iterable {
    yield 'block' => [['status' => 0]];
    yield 'anonymize' => [['anonymize' => TRUE]];
    yield 'mixed block' => [['name' => 'Changed', 'status' => 0]];
    yield 'mixed anonymize' => [['email' => 'changed@example.test', 'anonymize' => TRUE]];
  }

  /**
   * Global administrators retain authority over shared operator accounts.
   */
  #[DataProvider('platformAdministrators')]
  public function testPlatformAdministratorCanAnonymizeSharedAccount(int $actorId, array $actorRoles): void {
    $fixture = $this->fixture(
      targetGroupDefinitions: [[101, 'jur'], [202, 'jur']],
      targetRoles: ['authenticated', 'editorial_board'],
      actorId: $actorId,
      actorRoles: $actorRoles,
    );
    $fixture['target']->expects($this->once())->method('setUsername')->with('anonymized_42');
    $fixture['target']->expects($this->once())->method('setEmail')->with('anonymized_42@deleted.invalid');
    $fixture['target']->expects($this->once())->method('block');
    $fixture['target']->expects($this->once())->method('save');
    foreach ($fixture['groups'] as $group) {
      $group->expects($this->once())->method('removeMember')->with($fixture['target']);
    }
    $this->assertSame(200, $this->patch($fixture['controller'], ['anonymize' => TRUE])->getStatusCode());
    $this->assertSame([42], $fixture['controller']->invalidated);
    $this->assertSame(403, $this->patch($fixture['controller'], ['name' => 'Changed'], 1)->getStatusCode());
  }

  /**
   * Both existing forms of platform administrator authority.
   */
  public static function platformAdministrators(): iterable {
    yield 'uid 1' => [1, ['authenticated']];
    yield 'administrator role' => [10, ['authenticated', 'administrator']];
  }

}
