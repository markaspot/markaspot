<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves the tenant a batch of uploaded media belongs to.
 *
 * Analysis policy, provider context and budget charging must follow the
 * media, not the jurisdiction a caller names. The trusted scope is, in this
 * order: the jurisdiction of a report already referencing the media, the
 * jurisdiction the media was bound to by its first analysis, or, for a fresh
 * upload, the validated jurisdiction of that first request. A request may
 * only name the trusted scope; conflicting, mixed or missing scope is
 * rejected before any provider call or budget check.
 */
class MediaTenantScopeResolver {

  use JurisdictionIdResolverTrait;

  /**
   * Expirable key/value collection binding media UUIDs to a jurisdiction.
   */
  public const STORE = 'markaspot_vision_media_tenant';

  /**
   * Constructs the media tenant scope resolver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Resolves the trusted tenant scope for a batch of media.
   *
   * @param \Drupal\media\MediaInterface[] $media_entities
   *   The media the caller is allowed to analyze.
   * @param mixed $requested
   *   The jurisdiction named by the request (ID or slug), if any.
   * @param bool $require_jurisdiction
   *   TRUE when the install cannot run without a tenant (budgeted SaaS).
   *
   * @return \Drupal\markaspot_vision\Service\MediaTenantScope
   *   The resolved scope or the reason it was refused.
   */
  public function resolve(array $media_entities, mixed $requested, bool $require_jurisdiction = FALSE): MediaTenantScope {
    $requested_id = NULL;
    if ($requested !== NULL && $requested !== '') {
      $requested_id = is_int($requested) || is_string($requested)
        ? $this->resolveJurisdictionId($requested)
        : NULL;
      if ($requested_id === NULL) {
        return MediaTenantScope::refuse('Invalid jurisdiction.', 400);
      }
    }

    $parents = $this->parentJurisdictions($media_entities);
    $bindings = $this->keyValueExpirable->get(self::STORE);

    $trusted = [];
    foreach ($media_entities as $media) {
      $media_id = (int) $media->id();
      $parent_ids = $parents[$media_id] ?? [];
      if (count($parent_ids) > 1) {
        return MediaTenantScope::refuse('One or more media items belong to reports of different jurisdictions.', 409);
      }
      if ($parent_ids) {
        $trusted[] = reset($parent_ids);
        continue;
      }
      $bound = $bindings->get($media->uuid());
      if (is_int($bound) || (is_string($bound) && ctype_digit($bound))) {
        $trusted[] = (int) $bound;
      }
    }

    $trusted = array_values(array_unique($trusted));
    if (count($trusted) > 1) {
      return MediaTenantScope::refuse('Media from different jurisdictions cannot be analyzed together.', 409);
    }

    if ($trusted) {
      $jurisdiction_id = $trusted[0];
      if ($requested_id !== NULL && $requested_id !== $jurisdiction_id) {
        return MediaTenantScope::refuse('The uploaded media do not belong to this jurisdiction.', 403);
      }
    }
    elseif ($requested_id !== NULL) {
      // A fresh upload: the first validated request binds it.
      $jurisdiction_id = $requested_id;
    }
    else {
      $jurisdiction_ids = $this->publishedJurisdictionIds();
      if (count($jurisdiction_ids) === 1) {
        // Explicitly validated single-tenant installation.
        $jurisdiction_id = (int) reset($jurisdiction_ids);
      }
      elseif (!$jurisdiction_ids && !$require_jurisdiction) {
        // No tenants at all: nothing to bind, no tenant policy to apply.
        return MediaTenantScope::unscoped();
      }
      else {
        return MediaTenantScope::refuse('jurisdiction_id is required.', 400);
      }
    }

    $jurisdiction = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
    if (!$jurisdiction instanceof GroupInterface
      || !$this->isJurisdictionGroup($jurisdiction)
      || !$jurisdiction->isPublished()) {
      return MediaTenantScope::refuse('Invalid jurisdiction.', 400);
    }

    return MediaTenantScope::resolved($jurisdiction);
  }

  /**
   * Binds media without a parent report to the jurisdiction they ran under.
   *
   * Re-analysis of the same upload, and a later report built from it, can
   * then only use this jurisdiction. The binding lives as long as the
   * upload session may analyze the media; a report takes over afterwards.
   *
   * @param \Drupal\media\MediaInterface[] $media_entities
   *   The analyzed media.
   * @param \Drupal\group\Entity\GroupInterface $jurisdiction
   *   The trusted jurisdiction.
   */
  public function bind(array $media_entities, GroupInterface $jurisdiction): void {
    $bindings = $this->keyValueExpirable->get(self::STORE);
    foreach ($media_entities as $media) {
      if (!$bindings->has($media->uuid())) {
        $bindings->setWithExpire($media->uuid(), (int) $jurisdiction->id(), MediaAnalysisAccessGuard::TTL);
      }
    }
  }

  /**
   * Returns the jurisdictions of reports that reference each media item.
   *
   * @param \Drupal\media\MediaInterface[] $media_entities
   *   The media entities.
   *
   * @return array<int, int[]>
   *   Distinct jurisdiction IDs keyed by media ID.
   */
  protected function parentJurisdictions(array $media_entities): array {
    $media_ids = array_map(static fn (MediaInterface $media): int => (int) $media->id(), $media_entities);
    if (!$media_ids) {
      return [];
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_request_media', $media_ids, 'IN')
      ->execute();

    $parents = [];
    foreach ($node_storage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface || !$node->hasField('field_request_media')) {
        continue;
      }
      $jurisdiction_id = $this->nodeJurisdictionId($node);
      if ($jurisdiction_id === NULL) {
        continue;
      }
      foreach ($node->get('field_request_media') as $item) {
        $media_id = (int) $item->target_id;
        if (in_array($media_id, $media_ids, TRUE)) {
          $parents[$media_id][$jurisdiction_id] = $jurisdiction_id;
        }
      }
    }
    return $parents;
  }

  /**
   * Resolves the jurisdiction of a report.
   */
  protected function nodeJurisdictionId(NodeInterface $node): ?int {
    if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
      $target = $node->get('field_jurisdiction')->target_id;
      return is_numeric($target) ? (int) $target : NULL;
    }
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $category = $node->get('field_category')->entity;
      if ($category && $category->hasField('field_jurisdiction') && !$category->get('field_jurisdiction')->isEmpty()) {
        $target = $category->get('field_jurisdiction')->target_id;
        return is_numeric($target) ? (int) $target : NULL;
      }
    }
    return NULL;
  }

  /**
   * Returns the IDs of all published jurisdictions.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  protected function publishedJurisdictionIds(): array {
    return array_map('intval', array_values($this->entityTypeManager
      ->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->getJurisdictionGroupType())
      ->condition('status', 1)
      ->range(0, 2)
      ->execute()));
  }

}
