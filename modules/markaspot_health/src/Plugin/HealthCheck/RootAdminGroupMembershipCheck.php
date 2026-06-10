<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects root administrator group membership drift.
 *
 * @HealthCheck(
 *   id = "root_admin_group_membership",
 *   label = @Translation("Root admin group membership"),
 *   severity = "error",
 *   description = @Translation("Verifies uid 1 is an active administrator member of every jur/org group without non-individual group_roles assignments."),
 *   fix_hint = @Translation("Run drush deploy on an image containing markaspot_group_update_11933, or run drush mas:onboard-admin --user=<administrator-name> -y on older images."),
 * )
 */
class RootAdminGroupMembershipCheck extends HealthCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
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
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return $this->pass('Group module not enabled; check skipped.');
    }

    $user = $this->entityTypeManager->getStorage('user')->load(1);
    if (!$user instanceof UserInterface) {
      return $this->fail(1, 'uid 1 is missing.');
    }
    if ($user->isBlocked()) {
      return $this->fail(1, 'uid 1 is blocked.');
    }
    if (!$user->hasRole('administrator')) {
      return $this->fail(1, 'uid 1 lacks the administrator role.');
    }

    $group_storage = $this->entityTypeManager->getStorage('group');
    $ids = $group_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->groupTypes(), 'IN')
      ->execute();
    if ($ids === []) {
      return $this->pass('No jur/org groups found.');
    }

    $details = [];
    $truncated = 0;
    foreach ($group_storage->loadMultiple($ids) as $group) {
      $member = $group->getMember($user);
      if (!$member) {
        $this->appendDetail($details, $truncated, [
          'group_id' => (int) $group->id(),
          'group_type' => $group->bundle(),
          'group_label' => (string) $group->label(),
          'issue' => 'missing_membership',
        ]);
        continue;
      }

      $invalid_roles = $this->nonIndividualMembershipRoleIds($member);
      if ($invalid_roles !== []) {
        $this->appendDetail($details, $truncated, [
          'group_id' => (int) $group->id(),
          'group_type' => $group->bundle(),
          'group_label' => (string) $group->label(),
          'issue' => 'non_individual_group_roles',
          'roles' => implode(',', $invalid_roles),
        ]);
      }
    }

    $count = count($details) + $truncated;
    if ($count === 0) {
      return $this->pass('uid 1 is an administrator member of every jur/org group.');
    }

    return $this->fail(
      $count,
      sprintf('uid 1 group membership drift detected for %d group(s).', $count),
      $details,
      $truncated,
    );
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
    if (count($details) >= 20) {
      $truncated++;
      return;
    }

    $details[] = $row;
  }

  /**
   * Returns non-individual group_roles assigned directly to a membership.
   *
   * @return string[]
   *   Role IDs assigned directly although their scope is not individual.
   */
  protected function nonIndividualMembershipRoleIds($member): array {
    $relationship = $member->getGroupRelationship();
    if (!$relationship->hasField('group_roles')) {
      return [];
    }

    $values = $relationship->get('group_roles')->getValue();
    $role_ids = array_values(array_filter(array_column($values, 'target_id'), 'is_string'));
    if ($role_ids === []) {
      return [];
    }

    $roles = $this->entityTypeManager
      ->getStorage('group_role')
      ->loadMultiple($role_ids);

    $non_individual = [];
    foreach ($role_ids as $role_id) {
      $role = $roles[$role_id] ?? NULL;
      if ($role && method_exists($role, 'getScope') && $role->getScope() !== 'individual') {
        $non_individual[] = $role_id;
      }
    }

    return array_values(array_unique($non_individual));
  }

  /**
   * Returns group types where uid 1 must keep runtime membership.
   *
   * @return string[]
   *   Group bundle machine names.
   */
  protected function groupTypes(): array {
    return array_values(array_unique([$this->jurisdictionGroupType(), 'org']));
  }

  /**
   * Gets the configured jurisdiction group type.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
