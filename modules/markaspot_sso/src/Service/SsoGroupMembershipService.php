<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies jurisdiction and organisation memberships after staff SSO login.
 */
final class SsoGroupMembershipService {
  /**
   * Role aliases accepted in provider config and IdP-adjacent naming.
   */
  private const ROLE_ALIASES = [
    'service_request_manager' => 'editorial',
    'case_worker' => 'editorial',
    'editorial_board' => 'editorial',
    'jurisdiction_admin' => 'tenant_admin',
    'workspace_admin' => 'tenant_admin',
  ];

  /**
   * Constructs the group membership service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Applies memberships and returns frontend-safe group data.
   *
   * @param \Drupal\user\UserInterface $user
   *   The authenticated user.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param bool $existing_identity
   *   TRUE when this SSO subject was already linked before this login.
   *
   * @return array<int, array<string, mixed>>
   *   Affected group memberships.
   */
  public function apply(UserInterface $user, array $provider, bool $existing_identity): array {
    $jurisdiction_id = $this->positiveInt($provider['jurisdiction_id'] ?? NULL);
    if ($jurisdiction_id === NULL) {
      throw new \RuntimeException('SSO provider is missing jurisdiction_id.');
    }

    $jurisdiction = $this->loadGroup($jurisdiction_id, $this->jurisdictionGroupType(), 'jurisdiction');
    $default_role = $this->providerRole($provider);
    $jurisdiction_role = $this->resolveRoleId($default_role, $jurisdiction);
    $this->assertPrivilegedRoleAllowed($jurisdiction_role, $existing_identity);
    $this->upsertMembership($jurisdiction, $user, [$jurisdiction_role]);

    $groups = [
      $this->groupPayload($jurisdiction, $user),
    ];

    $org_id = $this->positiveInt($provider['org_id'] ?? NULL);
    if ($org_id !== NULL) {
      $organisation = $this->loadGroup($org_id, $this->organisationGroupType(), 'organisation');
      $this->assertOrganisationBelongsToJurisdiction($organisation, $jurisdiction_id);
      $organisation_role = $this->resolveRoleId($default_role, $organisation);
      $this->upsertMembership($organisation, $user, [$organisation_role]);
      $groups[] = $this->groupPayload($organisation, $user);
    }

    $this->assertJurisdictionMembership($jurisdiction, $user);

    return $groups;
  }

  /**
   * Returns a positive integer, or NULL.
   */
  private function positiveInt(mixed $value): ?int {
    if (!is_scalar($value) || trim((string) $value) === '') {
      return NULL;
    }
    $int = (int) $value;
    return $int > 0 ? $int : NULL;
  }

  /**
   * Loads and validates a group by ID and expected bundle.
   */
  private function loadGroup(int $group_id, string $expected_type, string $label): GroupInterface {
    $group = $this->entityTypeManager->getStorage('group')->load($group_id);
    if (!$group instanceof GroupInterface) {
      throw new \RuntimeException(sprintf('Configured SSO %s group %d was not found.', $label, $group_id));
    }
    if ($group->bundle() !== $expected_type) {
      throw new \RuntimeException(sprintf(
            'Configured SSO %s group %d has type "%s", expected "%s".',
            $label,
            $group_id,
            $group->bundle(),
            $expected_type,
        ));
    }

    return $group;
  }

  /**
   * Returns the configured provider role, defaulting to member.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function providerRole(array $provider): string {
    $role = $provider['default_role'] ?? 'member';
    return is_scalar($role) && trim((string) $role) !== '' ? trim((string) $role) : 'member';
  }

  /**
   * Resolves a configured role to a concrete group role ID.
   */
  private function resolveRoleId(string $role, GroupInterface $group): string {
    $group_type = $group->bundle();
    $role = strtolower(trim($role));
    $role = self::ROLE_ALIASES[$role] ?? $role;

    if ($group_type === $this->organisationGroupType() && $role === 'editorial') {
      $role = 'moderator';
    }
    if ($group_type === $this->organisationGroupType() && $role === 'tenant_admin') {
      $role = 'member';
    }

    $role_id = str_contains($role, '-') ? $role : $group_type . '-' . $role;
    if (
          $group_type === $this->jurisdictionGroupType()
          && str_starts_with($role_id, 'jur-')
          && $group_type !== 'jur'
      ) {
      $role_id = $group_type . '-' . substr($role_id, 4);
    }

    if (MembershipRoleNormalizer::isInternalRoleId($role_id)) {
      throw new \RuntimeException(sprintf('SSO role "%s" is managed internally.', $role_id));
    }
    if (str_ends_with($role_id, '-admin')) {
      throw new \RuntimeException(sprintf('SSO role "%s" is not assignable.', $role_id));
    }

    $role_entity = $this->entityTypeManager->getStorage('group_role')->load($role_id);
    if (!$role_entity instanceof GroupRoleInterface || $role_entity->getGroupTypeId() !== $group_type) {
      throw new \RuntimeException(sprintf(
            'SSO role "%s" does not exist for group type "%s".',
            $role_id,
            $group_type,
        ));
    }

    return $role_id;
  }

  /**
   * Blocks first-login privilege elevation.
   */
  private function assertPrivilegedRoleAllowed(string $role_id, bool $existing_identity): void {
    if (!$existing_identity && str_ends_with($role_id, '-tenant_admin')) {
      throw new \RuntimeException('Jurisdiction admin SSO identities must be pre-linked before login.');
    }
  }

  /**
   * Adds or updates a group membership without removing existing roles.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group receiving the membership.
   * @param \Drupal\user\UserInterface $user
   *   User receiving the group roles.
   * @param string[] $role_ids
   *   Group role IDs to add.
   */
  private function upsertMembership(GroupInterface $group, UserInterface $user, array $role_ids): void {
    $role_ids = MembershipRoleNormalizer::normalize($role_ids, $group->bundle());
    $membership = GroupMembership::loadSingle($group, $user);
    if (!$membership instanceof GroupMembershipInterface) {
      $group->addMember($user, ['group_roles' => $role_ids]);
      return;
    }

    $existing_values = $membership->get('group_roles')->getValue();
    $existing_roles = [];
    foreach ($existing_values as $value) {
      if (is_array($value) && is_string($value['target_id'] ?? NULL)) {
        $existing_roles[] = $value['target_id'];
      }
    }
    $membership->set('group_roles', array_values(array_unique([...$existing_roles, ...$role_ids])));
    $membership->save();
  }

  /**
   * Verifies the organisation is scoped to the configured jurisdiction.
   */
  private function assertOrganisationBelongsToJurisdiction(GroupInterface $organisation, int $jurisdiction_id): void {
    if (!$organisation->hasField('field_jurisdiction') || $organisation->get('field_jurisdiction')->isEmpty()) {
      throw new \RuntimeException(sprintf(
            'SSO organisation group %d is not linked to a jurisdiction.',
            (int) $organisation->id(),
        ));
    }

    $item = $organisation->get('field_jurisdiction')->first();
    $target_id = $item ? (int) $item->get('target_id')->getValue() : 0;
    if ($target_id !== $jurisdiction_id) {
      throw new \RuntimeException(sprintf(
            'SSO organisation group %d belongs to jurisdiction %d, expected %d.',
            (int) $organisation->id(),
            $target_id,
            $jurisdiction_id,
        ));
    }
  }

  /**
   * Fails closed unless the user is actually a member of the jurisdiction.
   */
  private function assertJurisdictionMembership(GroupInterface $jurisdiction, UserInterface $user): void {
    if ($jurisdiction->getMember($user)) {
      return;
    }

    $this->logger->error('SSO user @uid is not a member of jurisdiction @gid after login.', [
      '@uid' => $user->id(),
      '@gid' => $jurisdiction->id(),
    ]);
    throw new \RuntimeException('SSO jurisdiction membership verification failed.');
  }

  /**
   * Builds a lightweight group payload.
   *
   * @return array<string, mixed>
   *   Group payload.
   */
  private function groupPayload(GroupInterface $group, UserInterface $user): array {
    $membership = GroupMembership::loadSingle($group, $user);
    $roles = [];
    if ($membership instanceof GroupMembershipInterface) {
      foreach ($membership->getRoles(FALSE) as $role) {
        $role_id = $role->id();
        if (!is_string($role_id) || MembershipRoleNormalizer::isInternalRoleId($role_id)) {
          continue;
        }
        if (
              $group->bundle() === $this->jurisdictionGroupType()
              && $this->jurisdictionGroupType() !== 'jur'
              && str_starts_with($role_id, $this->jurisdictionGroupType() . '-')
          ) {
          $role_id = 'jur-' . substr($role_id, strlen($this->jurisdictionGroupType()) + 1);
        }
        $roles[] = [
          'id' => $role_id,
          'label' => $role->label(),
        ];
      }
    }

    return [
      'id' => (int) $group->id(),
      'uuid' => $group->uuid(),
      'label' => $group->label(),
      'type' => $group->bundle() === $this->jurisdictionGroupType() ? 'jur' : $group->bundle(),
      'roles' => $roles,
    ];
  }

  /**
   * Gets the configured jurisdiction group type.
   */
  private function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');
    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Gets the configured organisation group type.
   */
  private function organisationGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('organisation_group_type');
    return is_string($configured) && $configured !== '' ? $configured : 'org';
  }

}
