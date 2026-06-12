<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Wraps an entity count query and caches the result per user context + filter.
 *
 * The cache key includes:
 * - resource type name (scopes to node--service_request)
 * - current user ID + sorted role IDs (access-checked counts vary per user
 *   context; uid alone is almost always sufficient, but roles are cheap to
 *   include and protect against edge cases where a user's roles change between
 *   requests within a single session)
 * - xxh64 hash of the serialized filter params (sort/page are irrelevant for
 *   a count query and MUST be excluded to avoid destroying the hit-rate across
 *   pagination navigation)
 *
 * Cache lifetime: Cache::PERMANENT with 'node_list' and
 * 'group_relationship_list' tags, no TTL needed:
 * - 'node_list': Core invalidates it on every node save/delete, so content
 *   changes never leave a stale count.
 * - 'group_relationship_list': the access-checked count depends on the
 *   user's group memberships (group module query alter). A membership
 *   change does NOT fire 'node_list'; without this tag the count would
 *   stay stale for that user until some node happens to be saved. Core
 *   invalidates the list tag on every group_relationship CRUD
 *   automatically.
 *
 * All QueryInterface methods other than execute() delegate to the inner
 * query. Fluent methods return $this (the wrapper) when the inner query
 * returns itself, so callers can keep chaining on the wrapper.
 */
final class CountCacheQueryWrapper implements QueryInterface {

  /**
   * Count sentinel returned for skip-count cache misses.
   *
   * @var int
   */
  public const SKIPPED_COUNT_SENTINEL = -1;

  /**
   * The inner count query built by EntityResource.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface
   */
  private QueryInterface $inner;

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private CacheBackendInterface $cache;

  /**
   * Current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private AccountInterface $account;

  /**
   * Cache ID for this query.
   *
   * @var string
   */
  private string $cid;

  /**
   * Whether a cache miss should skip the expensive count query.
   *
   * @var bool
   */
  private bool $skipOnCacheMiss;

  /**
   * Constructs a CountCacheQueryWrapper.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $inner
   *   The wrapped count query.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache backend to use.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param string $resource_type_name
   *   The JSON:API resource type name (e.g. 'node--service_request').
   * @param string $filter_hash
   *   Pre-computed hash of the JSON:API filter portion (the caller hashes,
   *   because core passes filters as \Drupal\jsonapi\Query\Filter value
   *   objects, not arrays). Sort and page MUST NOT be part of the hash — they
   *   are irrelevant for the count and would fragment the cache by page
   *   position, eliminating all benefit.
   * @param bool $skip_on_cache_miss
   *   TRUE to return SKIPPED_COUNT_SENTINEL on cache miss without executing
   *   or caching the inner count query. Cache hits still return the exact
   *   cached count.
   */
  public function __construct(
    QueryInterface $inner,
    CacheBackendInterface $cache,
    AccountInterface $account,
    string $resource_type_name,
    string $filter_hash,
    bool $skip_on_cache_miss = FALSE,
  ) {
    $this->inner = $inner;
    $this->cache = $cache;
    $this->account = $account;
    $this->skipOnCacheMiss = $skip_on_cache_miss;

    $roles = $account->getRoles();
    sort($roles);
    $context = $account->id() . ':' . implode(',', $roles);
    $this->cid = 'markaspot_nuxt:jsonapi_count:' . $resource_type_name . ':' . $context . ':' . $filter_hash;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $cached = $this->cache->get($this->cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    if ($this->skipOnCacheMiss) {
      return self::SKIPPED_COUNT_SENTINEL;
    }

    $result = $this->inner->execute();
    $this->cache->set($this->cid, $result, Cache::PERMANENT, ['node_list', 'group_relationship_list']);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return $this->inner->getEntityTypeId();
  }

  /**
   * {@inheritdoc}
   */
  public function condition($field, $value = NULL, $operator = NULL, $langcode = NULL) {
    $result = $this->inner->condition($field, $value, $operator, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function exists($field, $langcode = NULL) {
    $result = $this->inner->exists($field, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function notExists($field, $langcode = NULL) {
    $result = $this->inner->notExists($field, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function pager($limit = 10, $element = NULL) {
    $result = $this->inner->pager($limit, $element);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function range($start = NULL, $length = NULL) {
    $result = $this->inner->range($start, $length);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function sort($field, $direction = 'ASC', $langcode = NULL) {
    $result = $this->inner->sort($field, $direction, $langcode);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function count() {
    $result = $this->inner->count();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tableSort(&$headers) {
    $result = $this->inner->tableSort($headers);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function accessCheck($access_check = TRUE) {
    $result = $this->inner->accessCheck($access_check);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function andConditionGroup() {
    return $this->inner->andConditionGroup();
  }

  /**
   * {@inheritdoc}
   */
  public function orConditionGroup() {
    return $this->inner->orConditionGroup();
  }

  /**
   * {@inheritdoc}
   */
  public function currentRevision() {
    $result = $this->inner->currentRevision();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function latestRevision() {
    $result = $this->inner->latestRevision();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function allRevisions() {
    $result = $this->inner->allRevisions();
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function addTag($tag) {
    $result = $this->inner->addTag($tag);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function hasTag($tag) {
    return $this->inner->hasTag($tag);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAllTags(/* string ...$tags*/) {
    return $this->inner->hasAllTags(...func_get_args());
  }

  /**
   * {@inheritdoc}
   */
  public function hasAnyTag(/* string ...$tags*/) {
    return $this->inner->hasAnyTag(...func_get_args());
  }

  /**
   * {@inheritdoc}
   */
  public function addMetaData($key, $object) {
    $result = $this->inner->addMetaData($key, $object);
    return $result === $this->inner ? $this : $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetaData($key) {
    return $this->inner->getMetaData($key);
  }

}
