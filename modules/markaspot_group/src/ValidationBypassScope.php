<?php

namespace Drupal\markaspot_group;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Scopes 'bypass mas validation' to the jurisdictions an account belongs to.
 *
 * The permission is global, but on a site with several tenants (root
 * jurisdictions, e.g. FastMap workspaces) moderation and tenant
 * administration act only where the account is a member. A site with one
 * root has no foreign tenant to protect, and its staff may well work without
 * group memberships, so the permission keeps its global meaning there.
 *
 * A report's target jurisdictions are its field_jurisdiction values; while
 * those are still empty (a new report before the presave stamping), the
 * category's jurisdictions stand in. A membership on the target, an ancestor
 * or a descendant counts: a district moderator files into the tenant root
 * and the boundary later places the report in the district. Without any
 * target the bypass is refused, so a report that cannot be placed is
 * validated like a citizen's.
 *
 * Callers check the permission itself (see ScopedValidationBypassTrait in
 * markaspot_validation); this only answers the membership question.
 */
final class ValidationBypassScope {

  /**
   * Whether the account belongs to every target jurisdiction of the report.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account.
   * @param \Drupal\node\NodeInterface $node
   *   The report under validation.
   *
   * @return bool
   *   TRUE if the bypass may apply.
   */
  public static function accountInScope(AccountInterface $account, NodeInterface $node): bool {
    if ($node->bundle() !== 'service_request' || !$node->hasField('field_jurisdiction')) {
      return TRUE;
    }

    if (count(self::rootJurisdictionIds()) <= 1) {
      return TRUE;
    }

    $jurisdictionIds = self::targetJurisdictionIds($node);
    if ($jurisdictionIds === []) {
      return FALSE;
    }

    foreach ($jurisdictionIds as $jurisdictionId) {
      if (!self::accountInTree($account, $jurisdictionId)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether the account belongs to the jurisdiction or one of its descendants.
   *
   * Ancestor memberships are covered by the shared membership check itself.
   */
  private static function accountInTree(AccountInterface $account, int $jurisdictionId): bool {
    if (_markaspot_group_user_is_member_in_jurisdiction_scope($account, $jurisdictionId)) {
      return TRUE;
    }
    if (!\Drupal::hasService('markaspot_group.hierarchy_resolver')) {
      return FALSE;
    }
    foreach (\Drupal::service('markaspot_group.hierarchy_resolver')->getDescendantIds($jurisdictionId) as $descendantId) {
      if ((int) $descendantId !== $jurisdictionId
        && _markaspot_group_user_is_member_in_jurisdiction_scope($account, (int) $descendantId)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the report's jurisdictions, falling back to its category's.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  private static function targetJurisdictionIds(NodeInterface $node): array {
    $ids = array_values(array_unique(array_filter(array_map(
      'intval',
      array_column($node->get('field_jurisdiction')->getValue(), 'target_id'),
    ))));
    if ($ids !== [] || !$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return $ids;
    }

    $term = $node->get('field_category')->entity;
    if ($term && $term->hasField('field_jurisdiction') && !$term->get('field_jurisdiction')->isEmpty()) {
      return array_values(array_unique(array_filter(array_map(
        'intval',
        array_column($term->get('field_jurisdiction')->getValue(), 'target_id'),
      ))));
    }
    return [];
  }

  /**
   * Returns the IDs of jurisdictions without a parent (at most two), cached.
   *
   * Two are enough to tell a single-root site from a multi-root one.
   *
   * @return int[]
   *   Root jurisdiction group IDs.
   */
  private static function rootJurisdictionIds(): array {
    $roots = &drupal_static(__METHOD__);
    if ($roots !== NULL) {
      return $roots;
    }
    $query = \Drupal::entityTypeManager()->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', _markaspot_group_jurisdiction_group_type())
      ->range(0, 2);
    $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('group');
    if (isset($definitions['field_parent_jurisdiction'])) {
      $query->notExists('field_parent_jurisdiction');
    }
    $roots = array_map('intval', array_values($query->execute()));
    return $roots;
  }

}
