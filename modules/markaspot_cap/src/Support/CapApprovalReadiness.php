<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\Support;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Utility\UpdateException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Fail-closed readiness audit for the CAP approval field.
 */
final class CapApprovalReadiness {

  public const READY_STATE = 'markaspot_cap.approval_field_ready';

  public const PENDING_STATE = 'markaspot_cap.approval_field_pending';

  public const ERROR_STATE = 'markaspot_cap.approval_field_error';

  public const BOOTSTRAP_AUDITED_STATE = 'markaspot_cap.approval_field_bootstrap_audited';

  /**
   * Revalidates the active CAP approval field and updates runtime readiness.
   *
   * @return bool
   *   TRUE only when the field, legacy data, and role grants are all safe.
   */
  public static function refresh(): bool {
    $state = \Drupal::state();
    // Every audit begins closed. A DB/configuration failure cannot leave a
    // previously true value opening the CAP feed.
    $state->delete(self::READY_STATE);

    if (!self::approvalFieldConfigurationExists()) {
      $state->set(self::PENDING_STATE, TRUE);
      $state->delete(self::ERROR_STATE);
      return FALSE;
    }

    $requiresBootstrapAudit = !$state->get(self::BOOTSTRAP_AUDITED_STATE, FALSE);
    try {
      self::assertSafeApprovalFieldConfiguration();
      if ($requiresBootstrapAudit) {
        self::assertNoLegacyApprovalData();
      }
      self::assertNoUnsafeApprovalRoleGrants();
    }
    catch (\Throwable $exception) {
      $previousError = (string) $state->get(self::ERROR_STATE, '');
      $state->set(self::ERROR_STATE, $exception->getMessage());
      $state->delete(self::PENDING_STATE);
      if ($previousError !== $exception->getMessage()) {
        \Drupal::logger('markaspot_cap')->error(
          'CAP approval readiness remains disabled: @message',
          ['@message' => $exception->getMessage()],
        );
      }
      return FALSE;
    }

    self::markReady($requiresBootstrapAudit);
    return TRUE;
  }

  /**
   * Marks the CAP approval field ready after a successful update audit.
   */
  public static function markReady(bool $bootstrapAudited = FALSE): void {
    $state = \Drupal::state();
    $state->set(self::READY_STATE, TRUE);
    if ($bootstrapAudited) {
      $state->set(self::BOOTSTRAP_AUDITED_STATE, TRUE);
    }
    $state->delete(self::PENDING_STATE);
    $state->delete(self::ERROR_STATE);
  }

  /**
   * Determines whether an entity change can change CAP approval safety.
   */
  public static function isApprovalRelevantEntity(EntityInterface $entity): bool {
    return match ($entity->getEntityTypeId()) {
      'user_role' => TRUE,
      'field_storage_config' => $entity->id() === 'node.field_cap_publish',
      'field_config' => $entity->id() === 'node.service_request.field_cap_publish',
      default => FALSE,
    };
  }

  /**
   * Throws if install config did not produce a safe approval field.
   */
  public static function assertSafeApprovalFieldConfiguration(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_cap_publish');
    $field = FieldConfig::loadByName('node', 'service_request', 'field_cap_publish');
    if ($storage === NULL
      || $field === NULL
      || $storage->getType() !== 'boolean'
      || $storage->getCardinality() !== 1
      || $storage->isTranslatable()
      || $field->isTranslatable()
      || $storage->getThirdPartySetting('field_permissions', 'permission_type') !== 'custom'
      || $field->getDefaultValueLiteral() !== [['value' => 0]]
      || !in_array($field->getDefaultValueCallback(), [NULL, ''], TRUE)) {
      throw new UpdateException('CAP installation did not produce a non-translatable single-value boolean approval field with a disabled default and custom Field Permissions. The CAP feed remains disabled.');
    }
  }

  /**
   * Throws when an existing approval value has unknown provenance.
   */
  public static function assertNoLegacyApprovalData(): void {
    if (!FieldConfig::loadByName('node', 'service_request', 'field_cap_publish')) {
      return;
    }

    $approved = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_cap_publish', 1)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if ($approved !== []) {
      throw new UpdateException('Existing field_cap_publish data contains approved rows with unknown provenance. Clear or explicitly migrate those values before installing or updating CAP. The CAP feed remains disabled until then.');
    }
  }

  /**
   * Throws when a non-administrator role can mutate CAP approval.
   */
  public static function assertNoUnsafeApprovalRoleGrants(): void {
    $configuredRoles = \Drupal::config('markaspot_cap.settings')
      ->get('approval_roles');
    $configuredRoles = is_array($configuredRoles)
      ? array_values(array_unique(array_filter(
        $configuredRoles,
        static fn(mixed $roleId): bool => is_string($roleId) && $roleId !== '',
      )))
      : [];
    $allowedRolePermissions = [
      'view field_cap_publish',
      'edit field_cap_publish',
    ];
    $approvalFieldPermissions = [
      'create field_cap_publish',
      'edit own field_cap_publish',
      'edit field_cap_publish',
      'view own field_cap_publish',
      'view field_cap_publish',
    ];
    $unsafeRoleGrants = [];
    $foundConfiguredRoles = [];
    foreach (\Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple() as $role) {
      if ($role->isAdmin()) {
        continue;
      }
      if (in_array($role->id(), $configuredRoles, TRUE)) {
        $foundConfiguredRoles[] = $role->id();
      }
      $grants = array_values(array_intersect(
        $role->getPermissions(),
        $approvalFieldPermissions,
      ));
      if ($grants === []) {
        continue;
      }
      if (!in_array($role->id(), $configuredRoles, TRUE)) {
        $unsafeRoleGrants[] = sprintf(
          '%s (%s) is not listed in approval_roles',
          $role->label(),
          implode(', ', $grants),
        );
        continue;
      }
      $disallowedGrants = array_values(array_diff(
        $grants,
        $allowedRolePermissions,
      ));
      if ($disallowedGrants !== []
        || !in_array('view field_cap_publish', $grants, TRUE)
        || !in_array('edit field_cap_publish', $grants, TRUE)) {
        $unsafeRoleGrants[] = sprintf(
          '%s (%s) must have exactly view and edit field_cap_publish',
          $role->label(),
          implode(', ', $grants),
        );
      }
    }
    $missingConfiguredRoles = array_diff($configuredRoles, $foundConfiguredRoles);
    if ($missingConfiguredRoles !== []) {
      $unsafeRoleGrants[] = sprintf(
        'configured role IDs not found: %s',
        implode(', ', $missingConfiguredRoles),
      );
    }
    if ($unsafeRoleGrants !== []) {
      throw new UpdateException(sprintf(
        'CAP approval field access is unsafe: %s. Only explicitly configured approval_roles may have view and edit field_cap_publish; all other roles must have no CAP approval field permissions.',
        implode('; ', $unsafeRoleGrants),
      ));
    }
  }

  /**
   * Returns whether configuration import has created both approval entities.
   */
  private static function approvalFieldConfigurationExists(): bool {
    return FieldStorageConfig::loadByName('node', 'field_cap_publish') !== NULL
      && FieldConfig::loadByName('node', 'service_request', 'field_cap_publish') !== NULL;
  }

}
