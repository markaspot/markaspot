<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Controller\GroupInvitationController;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the GroupInvitationController access check and role validation.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Controller\GroupInvitationController
 */
class GroupInvitationControllerTest extends UnitTestCase {

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
   * Creates a controller instance with mocked dependencies.
   *
   * Uses reflection to access the protected getPermittedRoles() method
   * and the public accessCheck() method without the full dependency set.
   *
   * @return \Drupal\markaspot_group\Controller\GroupInvitationController
   *   The controller with minimal dependencies set.
   */
  protected function createControllerForAccessCheck(): GroupInvitationController {
    $this->membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);

    // Create a partial mock that only mocks what we do not test.
    $controller = $this->getMockBuilder(GroupInvitationController::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    // Inject the membershipLoader via reflection.
    $ref = new \ReflectionClass($controller);
    $prop = $ref->getProperty('membershipLoader');
    $prop->setAccessible(TRUE);
    $prop->setValue($controller, $this->membershipLoader);

    return $controller;
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForSuperAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForAdministratorRole(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'administrator']);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForTenantAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(10);
    $account->method('getRoles')->willReturn(['authenticated']);

    // Has jur-tenant_admin membership.
    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn(['some-membership']);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckDeniesRegularUser(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(20);
    $account->method('getRoles')->willReturn(['authenticated']);

    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([]);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests getPermittedRoles for non-admin users.
   *
   * @covers ::getPermittedRoles
   */
  public function testGetPermittedRolesForNonAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, FALSE);

    $this->assertContains('jur-member', $roles);
    $this->assertContains('jur-moderator', $roles);
    $this->assertContains('org-member', $roles);
    $this->assertContains('org-moderator', $roles);
    $this->assertNotContains('jur-tenant_admin', $roles);
    $this->assertNotContains('org-tenant_admin', $roles);
  }

  /**
   * Tests getPermittedRoles for admin users.
   *
   * @covers ::getPermittedRoles
   */
  public function testGetPermittedRolesForAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, TRUE);

    $this->assertContains('jur-tenant_admin', $roles);
    $this->assertContains('org-tenant_admin', $roles);
    $this->assertContains('jur-member', $roles);
  }

}
