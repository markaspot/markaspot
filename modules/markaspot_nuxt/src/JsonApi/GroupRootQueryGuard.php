<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\jsonapi\Access\TemporaryQueryGuard;
use Drupal\jsonapi\JsonApiFilter;

/**
 * Defers root organisation filtering to Group's access-checked query.
 *
 * Core does not know Group's query access implementation and otherwise adds
 * an impossible subset condition to every filtered organisation collection.
 * Referenced entities retain Core's guards, including referenced groups.
 *
 * @internal Only used for group--org collections.
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class GroupRootQueryGuard extends TemporaryQueryGuard {

  /**
   * {@inheritdoc}
   */
  protected static function applyAccessConditions(QueryInterface $query, $entity_type_id, $field_prefix, CacheableMetadata $cacheability) {
    if ($entity_type_id === 'group' && $field_prefix === NULL) {
      // Preserve explicit module vetoes; do not grant global filter access.
      $access = static::getAccessResultsFromEntityFilterHook(
        \Drupal::entityTypeManager()->getDefinition($entity_type_id),
        \Drupal::currentUser(),
      );
      $cacheability->addCacheableDependency($access[JsonApiFilter::AMONG_ALL]);
      if (!$access[JsonApiFilter::AMONG_ALL]->isForbidden()) {
        $cacheability->addCacheContexts(['user.group_permissions']);
        $cacheability->addCacheTags(['group_list']);
        return;
      }
    }

    parent::applyAccessConditions($query, $entity_type_id, $field_prefix, $cacheability);
  }

}
