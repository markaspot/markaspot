<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Controller\GroupMembersController;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;

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
      $currentUser
    );
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

    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn(['membership-object']);

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

}
