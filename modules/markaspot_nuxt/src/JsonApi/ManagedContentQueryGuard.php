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
use Drupal\markaspot_tenant_admin\TenantAdminHelper;
use Drupal\markaspot_group\Service\TenantAdminHelper as GroupTenantAdminHelper;

/**
 * Mirrors scoped draft access for managed template and taxonomy collections.
 *
 * @internal Used only for the corresponding root resource collections.
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class ManagedContentQueryGuard extends TemporaryQueryGuard {

  /**
   * {@inheritdoc}
   */
  protected static function applyAccessConditions(QueryInterface $query, $entity_type_id, $field_prefix, CacheableMetadata $cacheability) {
    if ($field_prefix !== NULL) {
      // Root query grants cannot protect traversed entity references.
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
    if ($condition === NULL || !$account->isAuthenticated()) {
      return $condition;
    }
    $access = static::getAccessResultsFromEntityFilterHook($entity_type, $account);
    $cacheability->addCacheableDependency($access[JsonApiFilter::AMONG_ALL]);
    if ($access[JsonApiFilter::AMONG_ALL]->isForbidden()) {
      return $condition;
    }
    $modules = \Drupal::moduleHandler();
    $subset = NULL;
    if ($entity_type->id() === 'node'
      && $account->hasPermission('access content')
      && $modules->moduleExists('markaspot_boilerplate')) {
      // BoilerplateAccess::alterQuery applies publication, exact jurisdiction,
      // organisation and template-type restrictions before counts and paging.
      $subset = new EntityCondition('type', 'boilerplate');
    }
    elseif ($entity_type->id() === 'taxonomy_term' && $modules->moduleExists('markaspot_group')) {
      // Match markaspot_tenant_admin_taxonomy_term_access(): staff may view
      // drafts in their inherited taxonomy catalog, plus shared terms.
      $tenant_admin_enabled = $modules->moduleExists('markaspot_tenant_admin');
      $member_ids = $tenant_admin_enabled
        ? TenantAdminHelper::getUserMemberJurisdictionIds($account)
        : (in_array('tenant_admin', $account->getRoles(), TRUE)
          ? GroupTenantAdminHelper::getUserJurisdictionIds($account) : []);
      $jurisdiction_ids = $member_ids;
      $vocabularies = ['service_status', 'service_category'];
      if ($tenant_admin_enabled) {
        $jurisdiction_ids = [];
        $vocabularies[] = 'internal_status';
        $resolver = \Drupal::service('markaspot_group.hierarchy_resolver');
        foreach ($member_ids as $id) {
          $jurisdiction_ids = array_merge($jurisdiction_ids, $resolver->getTermJurisdictionIds($id));
        }
      }
      if ($jurisdiction_ids !== []) {
        $subset = new EntityConditionGroup('AND', [
          new EntityCondition('vid', $vocabularies, 'IN'),
          new EntityConditionGroup('OR', [
            new EntityCondition('field_jurisdiction.target_id', array_values(array_unique($jurisdiction_ids)), 'IN'),
            new EntityCondition('field_jurisdiction.target_id', NULL, 'IS NULL'),
          ]),
        ]);
      }
    }
    if ($subset === NULL) {
      return $condition;
    }
    $cacheability->addCacheContexts(['user', 'user.permissions']);
    // Memberships and Group permissions change independently of global roles.
    $cacheability->setCacheMaxAge(0);
    return new EntityConditionGroup('OR', [$condition, $subset]);
  }

}
