<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\markaspot_sso\Service\SsoGroupMembershipService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests Mark-a-Spot group membership role mapping and guards.
 *
 * @group markaspot_sso
 */
final class SsoGroupMembershipServiceTest extends UnitTestCase {

  /**
   * Keycloak-style service manager roles map to jurisdiction editorial roles.
   */
  public function testServiceRequestManagerMapsToJurisdictionEditorial(): void {
    $service = $this->service([
      'jur-editorial' => 'jur',
    ]);

    $this->assertSame(
          'jur-editorial',
          $this->invokeResolveRoleId($service, 'service_request_manager', $this->group('jur')),
      );
  }

  /**
   * Jurisdiction staff roles map to the matching organisation moderator role.
   */
  public function testServiceRequestManagerMapsToOrganisationModerator(): void {
    $service = $this->service([
      'org-moderator' => 'org',
    ]);

    $this->assertSame(
          'org-moderator',
          $this->invokeResolveRoleId($service, 'service_request_manager', $this->group('org')),
      );
  }

  /**
   * First-login tenant admin assignment is fail-closed.
   */
  public function testTenantAdminRequiresExistingIdentity(): void {
    $service = $this->service();

    $this->expectException(\RuntimeException::class);
    $this->invokePrivilegedRoleGuard($service, 'jur-tenant_admin', FALSE);
  }

  /**
   * Pre-linked identities may receive tenant admin memberships.
   */
  public function testTenantAdminAllowedForExistingIdentity(): void {
    $service = $this->service();

    $this->invokePrivilegedRoleGuard($service, 'jur-tenant_admin', TRUE);
    $this->addToAssertionCount(1);
  }

  /**
   * The final jurisdiction membership verification fails closed.
   */
  public function testJurisdictionMembershipMustExistAfterLogin(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');
    $service = $this->service([], $logger);
    $group = $this->group('jur');
    $group->method('getMember')->willReturn(NULL);
    $group->method('id')->willReturn('1');
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('7');

    $this->expectException(\RuntimeException::class);
    $method = new \ReflectionMethod(SsoGroupMembershipService::class, 'assertJurisdictionMembership');
    $method->setAccessible(TRUE);
    $method->invoke($service, $group, $user);
  }

  /**
   * Invokes the role resolver.
   */
  private function invokeResolveRoleId(
    SsoGroupMembershipService $service,
    string $role,
    GroupInterface $group,
  ): string {
    $method = new \ReflectionMethod(SsoGroupMembershipService::class, 'resolveRoleId');
    $method->setAccessible(TRUE);
    return (string) $method->invoke($service, $role, $group);
  }

  /**
   * Invokes the first-login privilege guard.
   */
  private function invokePrivilegedRoleGuard(
    SsoGroupMembershipService $service,
    string $role_id,
    bool $existing_identity,
  ): void {
    $method = new \ReflectionMethod(SsoGroupMembershipService::class, 'assertPrivilegedRoleAllowed');
    $method->setAccessible(TRUE);
    $method->invoke($service, $role_id, $existing_identity);
  }

  /**
   * Builds the service under test.
   *
   * @param array<string, string> $roles
   *   Role entity IDs mapped to group type IDs.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Logger override.
   */
  private function service(array $roles = [], ?LoggerInterface $logger = NULL): SsoGroupMembershipService {
    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->willReturnCallback(function (mixed $role_id) use ($roles): ?GroupRoleInterface {
        if (!is_string($role_id) || !isset($roles[$role_id])) {
          return NULL;
        }
            $role = $this->createMock(GroupRoleInterface::class);
            $role->method('getGroupTypeId')->willReturn($roles[$role_id]);
            return $role;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group_role')
      ->willReturn($roleStorage);

    return new SsoGroupMembershipService(
          $entityTypeManager,
          $this->configFactory(),
          $logger ?? $this->createMock(LoggerInterface::class),
      );
  }

  /**
   * Builds a group mock with a fixed bundle.
   */
  private function group(string $bundle): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    return $group;
  }

  /**
   * Builds Mark-a-Spot group type config.
   */
  private function configFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'jurisdiction_group_type' => 'jur',
                'organisation_group_type' => 'org',
                default => NULL,
            };
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    return $factory;
  }

}
