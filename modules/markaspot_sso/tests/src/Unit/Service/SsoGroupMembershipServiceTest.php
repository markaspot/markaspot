<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
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
   * First-login staff role assignment is fail-closed.
   *
   * @param string $roleId
   *   Group role ID that requires a pre-linked identity.
   *
   * @dataProvider privilegedGroupRoles
   */
  public function testStaffRolesRequireExistingIdentity(string $roleId): void {
    $service = $this->service();

    $this->expectException(\RuntimeException::class);
    $this->invokePrivilegedRoleGuard($service, $roleId, FALSE);
  }

  /**
   * Pre-linked identities may receive privileged staff memberships.
   */
  public function testStaffRoleAllowedForExistingIdentity(): void {
    $service = $this->service();

    $this->invokePrivilegedRoleGuard($service, 'jur-editorial', TRUE);
    $this->addToAssertionCount(1);
  }

  /**
   * The allowlisted member role is grantable on first login.
   */
  public function testMemberRoleAllowedForFirstLogin(): void {
    $service = $this->service();

    $this->invokePrivilegedRoleGuard($service, 'jur-member', FALSE);
    $this->addToAssertionCount(1);
  }

  /**
   * Unrecognised roles fail closed: not on the allowlist means pre-link.
   *
   * This is the allowlist guarantee a blocklist could not give: a role that
   * is neither member nor a known staff suffix is still rejected on first
   * login instead of silently slipping through.
   */
  public function testUnknownRoleRequiresExistingIdentity(): void {
    $service = $this->service();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SSO role "jur-viewer" requires a pre-linked identity before login.');
    $this->invokePrivilegedRoleGuard($service, 'jur-viewer', FALSE);
  }

  /**
   * First-login organisation membership assignment is fail-closed.
   */
  public function testOrganisationRoleRequiresExistingIdentity(): void {
    $service = $this->service();

    $this->expectException(\RuntimeException::class);
    $this->invokeOrganisationRoleGuard($service, 'org-member', FALSE);
  }

  /**
   * Pre-linked identities may receive organisation memberships.
   */
  public function testOrganisationRoleAllowedForExistingIdentity(): void {
    $service = $this->service();

    $this->invokeOrganisationRoleGuard($service, 'org-member', TRUE);
    $this->addToAssertionCount(1);
  }

  /**
   * Organisation preflight runs before any membership write.
   */
  public function testOrganisationRolePreflightRunsBeforeMembershipWrite(): void {
    $jurisdiction = $this->group('jur');
    $jurisdiction->method('id')->willReturn('1');
    $jurisdiction->expects($this->never())->method('addMember');

    $organisation = $this->organisationWithJurisdiction(2, 1);
    $organisation->expects($this->never())->method('addMember');

    $service = $this->service(
          [
            'jur-member' => 'jur',
            'org-member' => 'org',
          ],
          NULL,
          [
            1 => $jurisdiction,
            2 => $organisation,
          ],
      );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SSO organisation role "org-member" requires a pre-linked identity before login.');

    $service->apply(
          $this->createMock(UserInterface::class),
          [
            'jurisdiction_id' => 1,
            'org_id' => 2,
            'default_role' => 'member',
          ],
          FALSE,
      );
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
    $this->invokeJurisdictionMembershipGuard($service, $group, $user);
  }

  /**
   * The final jurisdiction membership verification allows existing members.
   */
  public function testJurisdictionMembershipAllowsExistingMember(): void {
    $service = $this->service();
    $group = $this->group('jur');
    $group->method('getMember')->willReturn($this->createMock(GroupMembershipInterface::class));
    $user = $this->createMock(UserInterface::class);

    $this->invokeJurisdictionMembershipGuard($service, $group, $user);
    $this->addToAssertionCount(1);
  }

  /**
   * Organisation groups must carry a jurisdiction reference.
   */
  public function testOrganisationMustReferenceJurisdiction(): void {
    $service = $this->service();
    $organisation = $this->group('org');
    $organisation->method('id')->willReturn('2');
    $organisation->method('hasField')->with('field_jurisdiction')->willReturn(FALSE);

    $this->expectException(\RuntimeException::class);
    $this->invokeOrganisationJurisdictionGuard($service, $organisation, 1);
  }

  /**
   * Organisation groups must belong to the configured jurisdiction.
   */
  public function testOrganisationMustBelongToExpectedJurisdiction(): void {
    $service = $this->service();
    $organisation = $this->organisationWithJurisdiction(2, 9);

    $this->expectException(\RuntimeException::class);
    $this->invokeOrganisationJurisdictionGuard($service, $organisation, 1);
  }

  /**
   * Organisation groups linked to the configured jurisdiction are allowed.
   */
  public function testOrganisationBelongingToJurisdictionIsAllowed(): void {
    $service = $this->service();
    $organisation = $this->organisationWithJurisdiction(2, 1);

    $this->invokeOrganisationJurisdictionGuard($service, $organisation, 1);
    $this->addToAssertionCount(1);
  }

  /**
   * Staff group roles that must be pre-linked before first login.
   *
   * @return array<string, array{0: string}>
   *   Role test cases.
   */
  public static function privilegedGroupRoles(): array {
    return [
      'jurisdiction tenant admin' => ['jur-tenant_admin'],
      'jurisdiction editorial' => ['jur-editorial'],
      'jurisdiction moderator' => ['jur-moderator'],
      'organisation moderator' => ['org-moderator'],
      'organisation insider' => ['org-insider'],
    ];
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
   * Invokes the final jurisdiction membership guard.
   */
  private function invokeJurisdictionMembershipGuard(
    SsoGroupMembershipService $service,
    GroupInterface $jurisdiction,
    UserInterface $user,
  ): void {
    $method = new \ReflectionMethod(SsoGroupMembershipService::class, 'assertJurisdictionMembership');
    $method->setAccessible(TRUE);
    $method->invoke($service, $jurisdiction, $user);
  }

  /**
   * Invokes the organisation-to-jurisdiction guard.
   */
  private function invokeOrganisationJurisdictionGuard(
    SsoGroupMembershipService $service,
    GroupInterface $organisation,
    int $jurisdiction_id,
  ): void {
    $method = new \ReflectionMethod(SsoGroupMembershipService::class, 'assertOrganisationBelongsToJurisdiction');
    $method->setAccessible(TRUE);
    $method->invoke($service, $organisation, $jurisdiction_id);
  }

  /**
   * Invokes the organisation role guard.
   */
  private function invokeOrganisationRoleGuard(
    SsoGroupMembershipService $service,
    string $role_id,
    bool $existing_identity,
  ): void {
    $method = new \ReflectionMethod(SsoGroupMembershipService::class, 'assertOrganisationRoleAllowed');
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
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups
   *   Group entities keyed by ID.
   */
  private function service(array $roles = [], ?LoggerInterface $logger = NULL, array $groups = []): SsoGroupMembershipService {
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

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->willReturnCallback(static function (mixed $group_id) use ($groups): ?GroupInterface {
        if (!is_scalar($group_id)) {
          return NULL;
        }
        return $groups[(int) $group_id] ?? NULL;
      });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static function (string $entity_type) use ($roleStorage, $groupStorage): EntityStorageInterface {
        return match ($entity_type) {
          'group_role' => $roleStorage,
          'group' => $groupStorage,
          default => throw new \InvalidArgumentException(sprintf('Unexpected storage "%s".', $entity_type)),
        };
      });

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
   * Builds an organisation group with a field_jurisdiction target.
   */
  private function organisationWithJurisdiction(int $group_id, int $jurisdiction_id): GroupInterface {
    $target = $this->createMock(TypedDataInterface::class);
    $target->method('getValue')->willReturn($jurisdiction_id);

    $item = $this->createMock(FieldItemInterface::class);
    $item->method('get')
      ->with('target_id')
      ->willReturn($target);

    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(FALSE);
    $list->method('first')->willReturn($item);

    $group = $this->group('org');
    $group->method('id')->willReturn((string) $group_id);
    $group->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $group->method('get')->with('field_jurisdiction')->willReturn($list);

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
