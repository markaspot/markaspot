<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects invalid direct group_roles on membership relationships.
 *
 * @HealthCheck(
 *   id = "membership_group_roles_valid",
 *   label = @Translation("Membership group_roles validity"),
 *   severity = "error",
 *   description = @Translation("Verifies every group membership stores only existing individual-scope group roles for the membership's group type."),
 *   fix_hint = @Translation("Remove non-individual, missing, or wrong-group-type role IDs from membership group_roles; synchronized roles must come from plain membership plus Drupal roles."),
 * )
 */
class MembershipGroupRolesValidCheck extends HealthCheckPluginBase {

  /**
   * Maximum detail rows returned in the health payload.
   */
  private const DETAILS_LIMIT = 20;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group_relationship') || !$this->entityTypeManager->hasDefinition('group_role')) {
      return $this->pass('Group module not enabled; check skipped.');
    }

    $relationship_storage = $this->entityTypeManager->getStorage('group_relationship');
    $ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->execute();
    if ($ids === []) {
      return $this->pass('No group memberships found.');
    }

    $relationships = $relationship_storage->loadMultiple($ids);
    $role_ids = $this->collectMembershipRoleIds($relationships);
    if ($role_ids === []) {
      return $this->pass(sprintf('%d group membership(s) checked; none store direct group_roles.', count($relationships)));
    }

    $roles = $this->entityTypeManager
      ->getStorage('group_role')
      ->loadMultiple($role_ids);

    $details = [];
    $truncated = 0;
    foreach ($relationships as $relationship) {
      if (!$relationship instanceof GroupRelationshipInterface) {
        continue;
      }
      $invalid_roles = $this->invalidMembershipRoleIds($relationship, $roles);
      if ($invalid_roles === []) {
        continue;
      }
      $this->appendDetail($details, $truncated, [
        'relationship_id' => (int) $relationship->id(),
        'group_id' => $relationship->getGroupId(),
        'group_type' => $relationship->getGroupTypeId(),
        'entity_id' => $relationship->getEntityId(),
        'issue' => 'invalid_membership_group_roles',
        'roles' => implode(',', $invalid_roles),
      ]);
    }

    $count = count($details) + $truncated;
    if ($count === 0) {
      return $this->pass(sprintf('%d group membership(s) store only valid individual group_roles.', count($relationships)));
    }

    return $this->fail(
      $count,
      sprintf('%d group membership(s) store invalid direct group_roles.', $count),
      $details,
      $truncated,
    );
  }

  /**
   * Collects unique direct role IDs from membership relationships.
   *
   * @param array<int|string, mixed> $relationships
   *   Loaded group relationship entities.
   *
   * @return string[]
   *   Unique role IDs.
   */
  protected function collectMembershipRoleIds(array $relationships): array {
    $role_ids = [];
    foreach ($relationships as $relationship) {
      if (!$relationship instanceof GroupRelationshipInterface || !$relationship->hasField('group_roles')) {
        continue;
      }
      foreach ($relationship->get('group_roles')->getValue() as $value) {
        if (is_array($value) && is_string($value['target_id'] ?? NULL) && $value['target_id'] !== '') {
          $role_ids[] = $value['target_id'];
        }
      }
    }

    return array_values(array_unique($role_ids));
  }

  /**
   * Returns invalid direct role assignments for one membership.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship entity.
   * @param array<string, \Drupal\group\Entity\GroupRoleInterface> $roles
   *   Group roles keyed by ID.
   *
   * @return string[]
   *   Role IDs with a compact reason suffix.
   */
  protected function invalidMembershipRoleIds(GroupRelationshipInterface $relationship, array $roles): array {
    if (!$relationship->hasField('group_roles')) {
      return [];
    }

    $invalid = [];
    foreach ($relationship->get('group_roles')->getValue() as $value) {
      if (!is_array($value) || !is_string($value['target_id'] ?? NULL) || $value['target_id'] === '') {
        continue;
      }
      $role_id = $value['target_id'];
      $role = $roles[$role_id] ?? NULL;
      if (!$role instanceof GroupRoleInterface) {
        $invalid[] = $role_id . ' (missing)';
        continue;
      }
      if ($role->getGroupTypeId() !== $relationship->getGroupTypeId()) {
        $invalid[] = sprintf('%s (wrong_group_type:%s)', $role_id, $role->getGroupTypeId());
        continue;
      }
      if ($role->getScope() !== PermissionScopeInterface::INDIVIDUAL_ID) {
        $invalid[] = sprintf('%s (%s)', $role_id, $role->getScope());
      }
    }

    return array_values(array_unique($invalid));
  }

  /**
   * Appends a bounded detail row.
   *
   * @param array<int, array<string, mixed>> $details
   *   Detail rows.
   * @param int $truncated
   *   Truncated row counter.
   * @param array<string, mixed> $row
   *   Row to append.
   */
  protected function appendDetail(array &$details, int &$truncated, array $row): void {
    if (count($details) >= self::DETAILS_LIMIT) {
      $truncated++;
      return;
    }

    $details[] = $row;
  }

}
