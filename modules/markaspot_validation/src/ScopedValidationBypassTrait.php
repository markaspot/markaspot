<?php

namespace Drupal\markaspot_validation;

use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\ValidationBypassScope;
use Drupal\node\NodeInterface;

/**
 * Decides whether an account may skip the Mark-a-Spot report validation.
 *
 * 'administer nodes' is operator-level and always skips it. The global
 * 'bypass mas validation' (moderators, tenant admins) only counts inside the
 * jurisdictions the account belongs to: otherwise staff of one tenant could
 * file reports in a foreign tenant past the limits a citizen would hit there.
 * Group scoping is delegated to markaspot_group; without it (or for anything
 * that is not a node) the permission keeps its global meaning, because such
 * a site has no tenants to separate. In production markaspot_group is always
 * present (markaspot_open311 depends on it).
 */
trait ScopedValidationBypassTrait {

  /**
   * Whether the account bypasses validation for the entity being validated.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account.
   * @param mixed $value
   *   The validated value: the entity, or a field item (list) on it.
   *
   * @return bool
   *   TRUE if the validation does not apply.
   */
  protected function accountBypassesValidation(AccountInterface $account, mixed $value): bool {
    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }
    if (!$account->hasPermission('bypass mas validation')) {
      return FALSE;
    }
    $entity = is_object($value) && !$value instanceof NodeInterface && method_exists($value, 'getEntity')
      ? $value->getEntity()
      : $value;
    if (!$entity instanceof NodeInterface
      || !class_exists(ValidationBypassScope::class)
      || !function_exists('_markaspot_group_user_is_member_in_jurisdiction_scope')) {
      return TRUE;
    }
    return ValidationBypassScope::accountInScope($account, $entity);
  }

}
