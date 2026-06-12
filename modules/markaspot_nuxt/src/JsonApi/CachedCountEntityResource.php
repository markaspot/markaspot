<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\jsonapi\Controller\EntityResource;
use Drupal\jsonapi\ResourceType\ResourceType;

/**
 * Caches the count query for service_request JSON:API collections.
 *
 * Overrides getCollectionCountQuery() from the @internal EntityResource to
 * wrap the query in a CountCacheQueryWrapper. The wrapper serves cached
 * counts on hit and populates the cache on miss, using Core's 'node_list'
 * and 'group_relationship_list' tags so counts are never stale (see the
 * wrapper's docblock for the invalidation rationale).
 *
 * INTENTIONAL USE OF @internal API:
 * Drupal\jsonapi\Controller\EntityResource is marked @internal. We accept
 * this consciously because:
 * - The profile pins Core versions via the markaspot-cloud base image; any
 *   Core update goes through image CI and DDEV smoke tests before prod.
 * - The method signature is extremely stable (has not changed since
 *   JSON:API was added to Core in 8.7).
 * - The performance gain (4-6 seconds per request on large tenants)
 *   far outweighs the maintenance risk.
 * When updating Core, verify the parent signature matches:
 *   protected function getCollectionCountQuery(
 *     ResourceType $resource_type,
 *     array $params,
 *     CacheableMetadata $query_cacheability
 *   )
 *
 * @internal This class is part of the markaspot_nuxt module internals.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class CachedCountEntityResource extends EntityResource {

  /**
   * {@inheritdoc}
   *
   * Wraps the inner count query in a cache layer for node--service_request
   * resources. Other resource types pass through unchanged.
   *
   * The filter portion of $params is the only cache key dimension that varies
   * per request for a given user context. Sort and page are irrelevant for the
   * count result and are intentionally excluded from the cache key so that
   * paginating through a result set still benefits from cached counts.
   *
   * Cache dependencies (\Drupal::cache() / \Drupal::currentUser()) are resolved
   * at call time rather than injected into the constructor. This is pragmatic:
   * adding constructor arguments to the decorated service would require
   * rebuilding its DI definition (the parent has 14 injected services), whereas
   * these two global accessors are stable and already used extensively inside
   * the @internal parent class itself.
   */
  protected function getCollectionCountQuery(ResourceType $resource_type, array $params, CacheableMetadata $query_cacheability) {
    $inner = parent::getCollectionCountQuery($resource_type, $params, $query_cacheability);

    if ($resource_type->getTypeName() !== 'node--service_request') {
      return $inner;
    }

    // $params['filter'] is a \Drupal\jsonapi\Query\Filter VALUE OBJECT (built
    // by Filter::createFromQueryParameter()), not an array. serialize() is
    // deterministic for identical query strings, so hashing it is fine — but
    // it must never meet an array type hint. Hash here, pass the string.
    $filter_hash = hash('xxh64', serialize($params['filter'] ?? []));

    return new CountCacheQueryWrapper(
      $inner,
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection (intentional, see method docblock)
      \Drupal::cache(),
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection (intentional, see method docblock)
      \Drupal::currentUser(),
      $resource_type->getTypeName(),
      $filter_hash,
    );
  }

}
