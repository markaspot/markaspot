<?php

namespace Drupal\Tests\markaspot_tenant_admin\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests request_image media access scoping for tenant staff.
 *
 * @group markaspot_tenant_admin
 */
class TenantAdminMediaAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../markaspot_tenant_admin.module';
    drupal_static_reset();
  }

  /**
   * Builds a media entity mock.
   */
  private function createRequestImageMedia(int $id): EntityInterface {
    $media = $this->createMock(EntityInterface::class);
    $media->method('getEntityTypeId')->willReturn('media');
    $media->method('bundle')->willReturn('request_image');
    $media->method('id')->willReturn((string) $id);
    $media->method('getCacheContexts')->willReturn([]);
    $media->method('getCacheTags')->willReturn(["media:$id"]);
    $media->method('getCacheMaxAge')->willReturn(-1);
    return $media;
  }

  /**
   * Builds an account mock.
   *
   * @param string[] $roles
   *   Account roles.
   * @param string[] $permissions
   *   Permission strings granted to the account.
   */
  private function createAccount(array $roles, array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

  /**
   * Sets up the container for tenant-admin membership and media parent counts.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account registered as current_user.
   * @param int[] $groupIds
   *   Jurisdiction groups where the account has a publish-capable role.
   * @param int[] $queryCounts
   *   Consecutive entity query counts: total then allowed parent count.
   */
  private function setUpContainer(AccountInterface $account, array $groupIds, array $queryCounts): void {
    $container = new ContainerBuilder();
    $container->set('current_user', $account);

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $container->set('logger.factory', $loggerFactory);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);
    $container->set('config.factory', $configFactory);

    $userEntity = $this->createMock(UserInterface::class);
    $userEntity->method('id')->willReturn(5);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(5)->willReturn($userEntity);

    $relationships = [];
    $relIds = [];
    foreach ($groupIds as $i => $groupId) {
      $relId = 100 + $i;
      $relIds[] = $relId;

      $group = $this->createMock(GroupInterface::class);
      $group->method('id')->willReturn((string) $groupId);
      $group->method('bundle')->willReturn('jur');

      $rel = $this->createMock(GroupRelationshipInterface::class);
      $rel->method('getGroup')->willReturn($group);
      $rel->method('getGroupId')->willReturn($groupId);
      $relationships[$relId] = $rel;
    }

    $groupRelStorage = $this->createMock(EntityStorageInterface::class);
    $groupRelStorage->method('loadMultiple')->willReturn($relationships);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    if ($queryCounts !== []) {
      $nodeStorage->method('getQuery')
        ->willReturnOnConsecutiveCalls(...array_map(
            fn(int $count): QueryInterface => $this->createCountQuery($count),
            $queryCounts
              ));
    }

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
          fn(string $type) => match ($type) {
              'user' => $userStorage,
              'group_relationship' => $groupRelStorage,
              'node' => $nodeStorage,
              default => $this->createMock(EntityStorageInterface::class),
          }
      );
    $container->set('entity_type.manager', $entityTypeManager);

    $entityTypeRepository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entityTypeRepository->method('getEntityTypeFromClass')
      ->willReturnCallback(fn(string $class) => match (TRUE) {
            str_contains($class, 'User') => 'user',
            default => 'group_relationship',
      });
    $container->set('entity_type.repository', $entityTypeRepository);

    $cacheData = (object) ['data' => $relIds];
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($cacheData);
    $container->set('cache.group_memberships_chained', $cache);

    \Drupal::setContainer($container);
  }

  /**
   * Creates a fluent entity count query.
   */
  private function createCountQuery(int $count): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('count')->willReturnSelf();
    $query->method('execute')->willReturn($count);
    return $query;
  }

  /**
   * Tenant staff may update request media scoped to their jurisdiction.
   */
  public function testTenantAdminCanUpdateMediaForOwnRequest(): void {
    $account = $this->createAccount(
          ['authenticated', 'tenant_admin'],
          ['edit any request_image media']
      );
    $this->setUpContainer($account, [10], [1, 1]);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(77),
          'update',
          $account
      );

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tenant staff cannot update request media owned by another jurisdiction.
   */
  public function testTenantAdminCannotUpdateCrossTenantMedia(): void {
    $account = $this->createAccount(
          ['authenticated', 'tenant_admin'],
          ['edit any request_image media']
      );
    $this->setUpContainer($account, [10], [1, 0]);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(77),
          'update',
          $account
      );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Orphaned request media fail closed for tenant staff.
   */
  public function testTenantAdminCannotUpdateOrphanedMedia(): void {
    $account = $this->createAccount(
          ['authenticated', 'tenant_admin'],
          ['edit any request_image media']
      );
    $this->setUpContainer($account, [10], [0, 0]);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(77),
          'update',
          $account
      );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Global editorial staff keep core media access handling.
   */
  public function testBypassNodeAccessRoleKeepsCoreMediaAccess(): void {
    $account = $this->createAccount(
          ['authenticated', 'tenant_admin', 'editorial_board'],
          ['edit any request_image media', 'bypass node access']
      );
    $this->setUpContainer($account, [], []);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(77),
          'update',
          $account
      );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * The moderator role is scoped identically to tenant_admin.
   */
  public function testModeratorIsScopedLikeTenantAdmin(): void {
    $account = $this->createAccount(
          ['authenticated', 'moderator'],
          ['edit any request_image media']
      );
    $this->setUpContainer($account, [10], [1, 1]);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(77),
          'update',
          $account
      );

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Accounts without the media permission are left to core (neutral).
   */
  public function testAccountWithoutMediaPermissionIsNeutral(): void {
    $account = $this->createAccount(['authenticated', 'tenant_admin'], []);
    $this->setUpContainer($account, [], []);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(77),
          'update',
          $account
      );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Media shared across jurisdictions fails closed when only partly managed.
   */
  public function testPartiallyScopedSharedMediaIsForbidden(): void {
    $account = $this->createAccount(
          ['authenticated', 'tenant_admin'],
          ['edit any request_image media']
      );
    // Two parent requests, only one in a managed jurisdiction.
    $this->setUpContainer($account, [10], [2, 1]);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(77),
          'update',
          $account
      );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Unsaved media (no persisted ID) fails closed for tenant staff.
   */
  public function testUnsavedMediaIsForbidden(): void {
    $account = $this->createAccount(
          ['authenticated', 'tenant_admin'],
          ['edit any request_image media']
      );
    $this->setUpContainer($account, [], []);

    $result = markaspot_tenant_admin_entity_access(
          $this->createRequestImageMedia(0),
          'update',
          $account
      );

    $this->assertTrue($result->isForbidden());
  }

}
