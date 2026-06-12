<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Entity\Query\Sql\Query;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\jsonapi\Controller\EntityResource;
use Drupal\jsonapi\ResourceType\ResourceType;

/**
 * Performance-optimised JSON:API collection handler for service_request.
 *
 * Overrides two @internal EntityResource methods:
 *
 * 1. getCollectionQuery() — wraps the query in a DeferredAccessQueryWrapper
 *    which runs a fast Phase-1 candidate fetch (no access joins, no aggregate
 *    sort) and then a narrow Phase-2 access-checked query. This reduces
 *    collection latency from 932-1439ms to ~2ms on a 155k-node tenant.
 *
 * 2. getCollectionCountQuery() — wraps the count query in a
 *    CountCacheQueryWrapper that caches the result per user+filter so the
 *    expensive count query is not re-run on every page navigation.
 *
 * INTENTIONAL USE OF @internal API:
 * Drupal\jsonapi\Controller\EntityResource is marked @internal. We accept
 * this consciously because:
 * - The profile pins Core versions via the markaspot-cloud base image; any
 *   Core update goes through image CI and DDEV smoke tests before prod.
 * - The method signatures are extremely stable (unchanged since JSON:API was
 *   added to Core in 8.7).
 * - The performance gains (getCollection: ~1s → ~2ms; count: 4-6s cached)
 *   far outweigh the maintenance risk.
 *
 * When updating Core, verify these parent signatures remain unchanged:
 *   protected function getCollectionQuery(
 *     ResourceType $resource_type,
 *     array $params,
 *     CacheableMetadata $query_cacheability
 *   ): QueryInterface
 *   protected function getCollectionCountQuery(
 *     ResourceType $resource_type,
 *     array $params,
 *     CacheableMetadata $query_cacheability
 *   ): QueryInterface
 *
 * @internal This class is part of the markaspot_nuxt module internals.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class CachedCountEntityResource extends EntityResource {

  /**
   * {@inheritdoc}
   *
   * Wraps the collection query in a DeferredAccessQueryWrapper for
   * node--service_request resources. Other resource types pass through
   * unchanged.
   *
   * NO user-scope guard (unlike getCollectionCountQuery): the two-phase wrapper
   * does not cache anything, so there is no cache-flooding risk for anonymous
   * traffic. Phase 2 enforces the full access-checked query for every user,
   * making this safe for all roles including anonymous.
   *
   * IMPORTANT: getCollectionCountQuery() calls
   *   $this->getCollectionQuery(...)->range()->count()
   * so this method is also invoked from the count path. The wrapper handles
   * count() correctly by setting an isCount flag that causes execute() to
   * pass straight through to the inner query.
   */
  protected function getCollectionQuery(ResourceType $resource_type, array $params, CacheableMetadata $query_cacheability) {
    $inner = parent::getCollectionQuery($resource_type, $params, $query_cacheability);

    if ($resource_type->getTypeName() !== 'node--service_request') {
      return $inner;
    }

    // The inner query must be a Sql\Query for CandidateEntityQuery::fromQuery()
    // to work. On alternative storage backends (e.g. search_api) the inner
    // query is not a Sql\Query, so fall back to the unmodified query.
    if (!($inner instanceof Query)) {
      return $inner;
    }

    return new DeferredAccessQueryWrapper($inner);
  }

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
   *
   * Authenticated users only. The dashboard (the consumer this cache exists
   * for) is session-gated behind the Nuxt proxy. Anonymous traffic gains
   * nothing from cached counts but, on deployments where /jsonapi is directly
   * reachable, attacker-controlled filter combinations would mint unbounded
   * permanent cache entries (security review findings 1+3).
   */
  protected function getCollectionCountQuery(ResourceType $resource_type, array $params, CacheableMetadata $query_cacheability) {
    $inner = parent::getCollectionCountQuery($resource_type, $params, $query_cacheability);

    if ($resource_type->getTypeName() !== 'node--service_request') {
      return $inner;
    }

    // Authenticated users only. The dashboard (the consumer this cache
    // exists for) is session-gated behind the Nuxt proxy. Anonymous traffic
    // gains nothing from cached counts but, on deployments where /jsonapi is
    // directly reachable, attacker-controlled filter combinations would mint
    // unbounded permanent cache entries (security review findings 1+3).
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection (intentional, see method docblock)
    if (!\Drupal::currentUser()->isAuthenticated()) {
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
