<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\MembershipGroupRolesValidCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the membership group_roles validity health check.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\MembershipGroupRolesValidCheck
 */
class MembershipGroupRolesValidCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'membership_group_roles_valid',
    'label' => 'Membership group_roles validity',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenGroupRelationshipsAreUnavailable(): void {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')
      ->willReturnCallback(static fn(string $entity_type): bool => $entity_type !== 'group_relationship');

    $plugin = new MembershipGroupRolesValidCheck([], 'membership_group_roles_valid', $this->definition, $etm);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('Group module not enabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenMembershipRolesAreIndividual(): void {
    $relationship = $this->relationship(11, 7, 'jur', 42, ['jur-member']);
    $plugin = new MembershipGroupRolesValidCheck(
      [],
      'membership_group_roles_valid',
      $this->definition,
      $this->entityTypeManager([$relationship], [
        'jur-member' => ['group_type' => 'jur', 'scope' => 'individual'],
      ]),
    );

    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertSame(0, $result->count);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsForNonIndividualRoleOnAnyMembership(): void {
    $relationship = $this->relationship(12, 9, 'jur', 77, ['jur-admin']);
    $plugin = new MembershipGroupRolesValidCheck(
      [],
      'membership_group_roles_valid',
      $this->definition,
      $this->entityTypeManager([$relationship], [
        'jur-admin' => ['group_type' => 'jur', 'scope' => 'insider'],
      ]),
    );

    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertSame('invalid_membership_group_roles', $result->details[0]['issue']);
    $this->assertSame(12, $result->details[0]['relationship_id']);
    $this->assertSame(77, $result->details[0]['entity_id']);
    $this->assertSame('jur-admin (insider)', $result->details[0]['roles']);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsForMissingOrWrongGroupTypeRole(): void {
    $relationship = $this->relationship(13, 10, 'org', 88, ['missing-role', 'jur-member']);
    $plugin = new MembershipGroupRolesValidCheck(
      [],
      'membership_group_roles_valid',
      $this->definition,
      $this->entityTypeManager([$relationship], [
        'jur-member' => ['group_type' => 'jur', 'scope' => 'individual'],
      ]),
    );

    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertStringContainsString('missing-role (missing)', $result->details[0]['roles']);
    $this->assertStringContainsString('jur-member (wrong_group_type:jur)', $result->details[0]['roles']);
  }

  /**
   * Builds an entity type manager mock.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface[] $relationships
   *   Membership relationships returned by storage.
   * @param array<string, array{group_type: string, scope: string}> $roleDefinitions
   *   Role definitions keyed by role ID.
   */
  protected function entityTypeManager(array $relationships, array $roleDefinitions): EntityTypeManagerInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->with('plugin_id', 'group_membership')->willReturnSelf();
    $query->method('execute')->willReturn(array_map(static fn(GroupRelationshipInterface $relationship): int => (int) $relationship->id(), $relationships));

    $relationship_storage = $this->createMock(EntityStorageInterface::class);
    $relationship_storage->method('getQuery')->willReturn($query);
    $relationship_storage->method('loadMultiple')->willReturn($relationships);

    $role_storage = $this->createMock(EntityStorageInterface::class);
    $role_storage->method('loadMultiple')->willReturnCallback(function (array $role_ids) use ($roleDefinitions): array {
      $roles = [];
      foreach ($role_ids as $role_id) {
        if (!isset($roleDefinitions[$role_id])) {
          continue;
        }
        $role = $this->createMock(GroupRoleInterface::class);
        $role->method('getGroupTypeId')->willReturn($roleDefinitions[$role_id]['group_type']);
        $role->method('getScope')->willReturn($roleDefinitions[$role_id]['scope']);
        $roles[$role_id] = $role;
      }
      return $roles;
    });

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturnCallback(
      static fn(string $entity_type): bool => in_array($entity_type, ['group_relationship', 'group_role'], TRUE),
    );
    $etm->method('getStorage')->willReturnCallback(static function (string $entity_type) use ($relationship_storage, $role_storage): EntityStorageInterface {
      return match ($entity_type) {
        'group_relationship' => $relationship_storage,
        'group_role' => $role_storage,
        default => throw new \LogicException("Unexpected storage $entity_type"),
      };
    });

    return $etm;
  }

  /**
   * Builds a group relationship mock with direct group_roles values.
   *
   * @param int $id
   *   Relationship ID.
   * @param int $group_id
   *   Group ID.
   * @param string $group_type
   *   Group bundle.
   * @param int $entity_id
   *   Member entity ID.
   * @param string[] $role_ids
   *   Role IDs assigned directly to the membership.
   */
  protected function relationship(int $id, int $group_id, string $group_type, int $entity_id, array $role_ids): GroupRelationshipInterface {
    $field = new class($role_ids) {

      /**
       * Constructs the test field item list.
       *
       * @param string[] $role_ids
       *   Role IDs assigned directly to the membership.
       */
      public function __construct(private readonly array $role_ids) {}

      /**
       * Returns the raw field values.
       *
       * @return array<int, array{target_id: string}>
       *   Field item values.
       */
      public function getValue(): array {
        return array_map(
          static fn(string $role_id): array => ['target_id' => $role_id],
          $this->role_ids,
        );
      }

    };

    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('id')->willReturn((string) $id);
    $relationship->method('getGroupId')->willReturn($group_id);
    $relationship->method('getGroupTypeId')->willReturn($group_type);
    $relationship->method('getEntityId')->willReturn($entity_id);
    $relationship->method('hasField')->with('group_roles')->willReturn(TRUE);
    $relationship->method('get')->with('group_roles')->willReturn($field);

    return $relationship;
  }

}
