<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\Access\TemporaryQueryGuard;
use Drupal\jsonapi\JsonApiFilter;
use Drupal\jsonapi\Query\EntityCondition;
use Drupal\jsonapi\Query\EntityConditionGroup;

/**
 * Includes Group-authorised drafts in JSON:API's filter subsets.
 *
 * Core's published/own subsets do not model Group permissions. Add only the
 * service requests in organisations or jurisdictions with draft permission.
 * Node query access and Core's referenced-entity filter guards still apply.
 *
 * @internal Kept alongside the existing Core EntityResource integration.
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class OrganisationQueryGuard extends TemporaryQueryGuard {

  /**
   * {@inheritdoc}
   */
  protected static function applyAccessConditions(QueryInterface $query, $entity_type_id, $field_prefix, CacheableMetadata $cacheability) {
    if ($field_prefix !== NULL) {
      // Node grants constrain the root query, not every referenced node alias.
      // Keep Core's original protection for all traversed references.
      TemporaryQueryGuard::applyAccessConditions($query, $entity_type_id, $field_prefix, $cacheability);
      return;
    }
    parent::applyAccessConditions($query, $entity_type_id, $field_prefix, $cacheability);
  }

  /**
   * {@inheritdoc}
   */
  protected static function getAccessConditionForKnownSubsets(EntityTypeInterface $entity_type, AccountInterface $account, CacheableMetadata $cacheability) {
    $condition = parent::getAccessConditionForKnownSubsets($entity_type, $account, $cacheability);
    if ($condition === NULL || $entity_type->id() !== 'node') {
      return $condition;
    }

    if (!$account->isAuthenticated() || !$account->hasPermission('access content')) {
      return $condition;
    }
    // Adding a subset must never override an explicit module veto.
    $access = static::getAccessResultsFromEntityFilterHook($entity_type, $account);
    $cacheability->addCacheableDependency($access[JsonApiFilter::AMONG_ALL]);
    if ($access[JsonApiFilter::AMONG_ALL]->isForbidden()) {
      return $condition;
    }
    $grants = node_access_grants('view', $account);
    $scope_conditions = [];
    foreach ([
      'markaspot_contractor_organisation' => 'field_organisation.target_id',
      'markaspot_jurisdiction' => 'field_jurisdiction.target_id',
    ] as $realm => $field) {
      if (!empty($grants[$realm])) {
        $scope_conditions[] = new EntityCondition($field, $grants[$realm], 'IN');
      }
    }
    if ($scope_conditions === []) {
      return $condition;
    }
    $cacheability->addCacheContexts(['user', 'user.permissions', 'user.node_grants:view']);
    // Effective Group permissions and memberships can change independently of
    // Drupal roles. Avoid caching a response with a stale Group subset.
    $cacheability->setCacheMaxAge(0);

    return new EntityConditionGroup('OR', [
      $condition,
      new EntityConditionGroup('AND', [
        new EntityCondition('type', 'service_request'),
        new EntityConditionGroup('OR', $scope_conditions),
      ]),
    ]);
  }

}
