<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service {
  if (!function_exists(__NAMESPACE__ . '\\user_login_finalize')) {

    /**
     * Stubs Drupal login finalization for unit tests.
     */
    function user_login_finalize(mixed $user): void {
    }

  }

  if (!function_exists(__NAMESPACE__ . '\\user_logout')) {

    /**
     * Stubs Drupal logout for unit tests.
     */
    function user_logout(): void {
    }

  }
}

namespace Drupal\Tests\markaspot_sso\Unit\Service {
  use Drupal\Core\Config\Config;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\SelectInterface;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\Entity\EntityStorageInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\Site\Settings;
  use Drupal\group\Entity\GroupInterface;
  use Drupal\group\Entity\GroupRoleInterface;
  use Drupal\markaspot_sso\Service\SsoGroupMembershipService;
  use Drupal\markaspot_sso\Service\SsoIdentityLinker;
  use Drupal\Tests\UnitTestCase;
  use Drupal\user\UserInterface;
  use Psr\Log\LoggerInterface;

  /**
   * Tests identity persistence ordering.
   *
   * @group markaspot_sso
   */
  final class SsoIdentityLinkerPersistenceTest extends UnitTestCase {

    /**
     * Failed first-login privileged membership must not create an identity row.
     */
    public function testFailedFirstLoginDoesNotPersistIdentity(): void {
      new Settings(['hash_salt' => 'markaspot-sso-test']);

      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn('7');
      $user->method('getRoles')->willReturn(['authenticated']);
      $user->method('isActive')->willReturn(TRUE);

      $database = $this->databaseWithoutExistingIdentity();
      $database->expects($this->never())->method('merge');
      $database->expects($this->never())->method('update');

      $linker = new SsoIdentityLinker(
            $database,
            $this->entityTypeManagerForTenantAdminRejection($user),
            new SsoGroupMembershipService(
                $this->entityTypeManagerForTenantAdminRejection($user),
                $this->configFactory(),
                $this->createMock(LoggerInterface::class),
            ),
            $this->configFactory(),
            $this->createMock(LoggerInterface::class),
        );

      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('SSO role "jur-tenant_admin" requires a pre-linked identity before login.');

      $linker->authenticate(
            'keycloak',
            [
              'link_by_email' => TRUE,
              'jurisdiction_id' => 1,
              'default_role' => 'tenant_admin',
              'attribute_map' => [
                'email' => ['mail'],
              ],
            ],
            'external-subject',
            ['mail' => ['staff@example.test']],
            [],
        );
    }

    /**
     * Builds database mocks that return no existing identity.
     */
    private function databaseWithoutExistingIdentity(): Connection {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn(FALSE);

      $select = $this->createMock(SelectInterface::class);
      $select->method('fields')->willReturnSelf();
      $select->method('condition')->willReturnSelf();
      $select->method('range')->willReturnSelf();
      $select->method('execute')->willReturn($statement);

      $database = $this->createMock(Connection::class);
      $database->method('select')->willReturn($select);

      return $database;
    }

    /**
     * Builds entity storage mocks that reject first-login tenant admin.
     */
    private function entityTypeManagerForTenantAdminRejection(UserInterface $user): EntityTypeManagerInterface {
      $user_storage = $this->createMock(EntityStorageInterface::class);
      $user_storage->method('loadByProperties')
        ->with(['mail' => 'staff@example.test'])
        ->willReturn([$user]);

      $group = $this->createMock(GroupInterface::class);
      $group->method('bundle')->willReturn('jur');

      $group_storage = $this->createMock(EntityStorageInterface::class);
      $group_storage->method('load')
        ->with(1)
        ->willReturn($group);

      $role = $this->createMock(GroupRoleInterface::class);
      $role->method('getGroupTypeId')->willReturn('jur');

      $role_storage = $this->createMock(EntityStorageInterface::class);
      $role_storage->method('load')
        ->with('jur-tenant_admin')
        ->willReturn($role);

      $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
      $entity_type_manager->method('getStorage')
        ->willReturnMap([
          ['user', $user_storage],
          ['group', $group_storage],
          ['group_role', $role_storage],
        ]);

      return $entity_type_manager;
    }

    /**
     * Builds config required by subject hashing and group type defaults.
     */
    private function configFactory(): ConfigFactoryInterface {
      $config = $this->createMock(Config::class);
      $config->method('get')
        ->willReturnCallback(static fn(string $key): mixed => match ($key) {
                    'uuid' => 'test-site-uuid',
                    default => NULL,
        });

      $factory = $this->createMock(ConfigFactoryInterface::class);
      $factory->method('get')->willReturn($config);

      return $factory;
    }

  }
}
