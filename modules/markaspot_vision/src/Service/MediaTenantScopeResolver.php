<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Binds uploaded media to a tenant and resolves the scope of an analysis.
 *
 * Analysis policy, provider context and budget charging follow the media,
 * not the jurisdiction a caller names. The trusted scope is, in this order:
 * the jurisdiction of a report already referencing the media, or the
 * jurisdiction the media was bound to. A binding is written once, atomically,
 * and never changes afterwards; it lives as long as the media.
 *
 * Bindings are made at the earliest trusted point, the upload itself: the
 * media insert records the jurisdiction the upload request names in
 * UPLOAD_HEADER (validated as a published jurisdiction), or the only
 * jurisdiction of a single-tenant install. An anonymous citizen of tenant B
 * and an attacker choosing B are indistinguishable, so the goal is not to
 * prevent the choice but to fix it before any analysis and keep every later
 * analysis, budget check and charge on it.
 *
 * Legacy cohort: uploads made before this contract existed carry no binding.
 * The update that introduced it records the highest media ID at that moment
 * (CUTOFF_STATE). Unbound media with an ID at or below it may be bound once,
 * atomically, by their first analysis to the validated jurisdiction it
 * names. The ID is server-owned evidence: the database assigns it and
 * JSON:API cannot set it, unlike the `created` date, which a client (or a
 * date-preserving migration) can backdate. That cohort is finite and only
 * shrinks. Every media inserted later is outside it whatever its dates, so
 * an unbound one on a multi-tenant install is refused: every current client
 * names the upload's jurisdiction, and a missing cutoff means there is no
 * legacy cohort at all.
 * Single-tenant installs bind every upload to their only jurisdiction.
 */
class MediaTenantScopeResolver {

  use JurisdictionIdResolverTrait;

  /**
   * Key/value collection binding media UUIDs to a jurisdiction ID.
   */
  public const STORE = 'markaspot_vision_media_tenant';

  /**
   * Request header naming the jurisdiction of an upload.
   */
  public const UPLOAD_HEADER = 'X-Markaspot-Jurisdiction';

  /**
   * State key: the highest media ID of the legacy (pre-binding) cohort.
   */
  public const CUTOFF_STATE = 'markaspot_vision.upload_binding_cutoff_mid';

  /**
   * Constructs the media tenant scope resolver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeyValueFactoryInterface $keyValue,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
  ) {
  }

  /**
   * Binds a freshly uploaded media item to the tenant of its upload request.
   *
   * Leaves the media unbound when the request names no valid jurisdiction on
   * a multi-tenant install; analysis of such an upload is then refused.
   */
  public function bindUpload(MediaInterface $media, ?Request $request): void {
    if ($media->bundle() !== 'request_image' || $this->bindingStore()->has($media->uuid())) {
      return;
    }

    $named = $request ? trim((string) $request->headers->get(self::UPLOAD_HEADER, '')) : '';
    $jurisdiction_id = $named !== '' ? $this->resolveJurisdictionId($named) : NULL;
    if ($jurisdiction_id === NULL && $named === '') {
      $jurisdiction_id = $this->singleTenantJurisdictionId();
    }
    if ($jurisdiction_id !== NULL) {
      $this->bindingStore()->setIfNotExists($media->uuid(), $jurisdiction_id);
    }
  }

  /**
   * Removes the binding of a deleted media item.
   */
  public function forget(MediaInterface $media): void {
    $this->bindingStore()->delete($media->uuid());
  }

  /**
   * Resolves the trusted tenant scope for a batch of media.
   *
   * Unbound media are bound here, atomically, before the caller checks any
   * policy or budget; a request that loses a concurrent first binding is
   * refused.
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

    $tenant_count = count($this->jurisdictionIds(FALSE));
    $parents = $this->parentJurisdictions($media_entities);
    $bindings = $this->bindingStore();

    $trusted = [];
    $unbound = [];
    foreach ($media_entities as $media) {
      $parent = $parents[(int) $media->id()] ?? NULL;
      if ($parent !== NULL) {
        if ($parent['unresolved'] && $tenant_count > 0) {
          // A referencing report has no establishable tenant: never let the
          // caller or another report supply it.
          return MediaTenantScope::refuse('The report of one or more media items has no resolvable jurisdiction.', 409);
        }
        if (count($parent['ids']) > 1) {
          return MediaTenantScope::refuse('One or more media items belong to reports of different jurisdictions.', 409);
        }
        if ($parent['ids']) {
          $trusted[] = reset($parent['ids']);
          continue;
        }
      }
      $bound = $bindings->get($media->uuid());
      if (is_int($bound) || (is_string($bound) && ctype_digit($bound))) {
        $trusted[] = (int) $bound;
      }
      else {
        $unbound[] = $media;
      }
    }

    // Unbound members are judged before any batch scope is accepted: on a
    // multi-tenant install only the legacy cohort may still be bound here.
    if ($unbound && $tenant_count > 1) {
      $cutoff = $this->state->get(self::CUTOFF_STATE);
      foreach ($unbound as $media) {
        if ($cutoff === NULL || (int) $media->id() > (int) $cutoff) {
          return MediaTenantScope::refuse('The uploaded media are not bound to a jurisdiction.', 400);
        }
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
    elseif ($tenant_count === 0) {
      // No tenants exist at all, published or not: nothing to bind.
      return $require_jurisdiction
        ? MediaTenantScope::refuse('jurisdiction_id is required.', 400)
        : MediaTenantScope::unscoped();
    }
    elseif ($tenant_count === 1) {
      // Explicitly validated single-tenant install: its only jurisdiction,
      // and only while it is published.
      $jurisdiction_id = $this->singleTenantJurisdictionId();
      if ($jurisdiction_id === NULL || ($requested_id !== NULL && $requested_id !== $jurisdiction_id)) {
        return MediaTenantScope::refuse('Invalid jurisdiction.', 400);
      }
    }
    elseif ($requested_id !== NULL) {
      // Legacy cohort only (checked above): bound once by its first analysis.
      $jurisdiction_id = $requested_id;
    }
    else {
      return MediaTenantScope::refuse('jurisdiction_id is required.', 400);
    }

    $jurisdiction = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
    if (!$jurisdiction instanceof GroupInterface
      || !$this->isJurisdictionGroup($jurisdiction)
      || !$jurisdiction->isPublished()) {
      return MediaTenantScope::refuse('Invalid jurisdiction.', 400);
    }

    if ($unbound && !$this->claim($unbound, $jurisdiction_id)) {
      return MediaTenantScope::refuse('The uploaded media were bound to another jurisdiction by a concurrent request.', 409);
    }

    return MediaTenantScope::resolved($jurisdiction);
  }

  /**
   * Binds unbound media atomically; FALSE if another binding won.
   *
   * @param \Drupal\media\MediaInterface[] $media_entities
   *   Media that had no binding when the scope was resolved.
   * @param int $jurisdiction_id
   *   The jurisdiction to bind them to.
   */
  protected function claim(array $media_entities, int $jurisdiction_id): bool {
    $bindings = $this->bindingStore();
    foreach ($media_entities as $media) {
      $bindings->setIfNotExists($media->uuid(), $jurisdiction_id);
      $stored = $bindings->get($media->uuid());
      if ((int) $stored !== $jurisdiction_id) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns the jurisdictions of reports that reference each media item.
   *
   * @param \Drupal\media\MediaInterface[] $media_entities
   *   The media entities.
   *
   * @return array<int, array{ids: int[], unresolved: bool}>
   *   Keyed by media ID, for media referenced by at least one report: the
   *   distinct jurisdiction IDs of those reports, and whether any of them has
   *   no resolvable jurisdiction (independent of what the others resolve).
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
      $jurisdiction_ids = $this->nodeJurisdictionIds($node);
      foreach ($node->get('field_request_media') as $item) {
        $media_id = (int) $item->target_id;
        if (!in_array($media_id, $media_ids, TRUE)) {
          continue;
        }
        $parents[$media_id] ??= ['ids' => [], 'unresolved' => FALSE];
        if (!$jurisdiction_ids) {
          $parents[$media_id]['unresolved'] = TRUE;
        }
        foreach ($jurisdiction_ids as $jurisdiction_id) {
          $parents[$media_id]['ids'][$jurisdiction_id] = $jurisdiction_id;
        }
      }
    }
    return $parents;
  }

  /**
   * Resolves every jurisdiction a report is assigned to.
   *
   * Sources, first non-empty wins: all field_jurisdiction values, the
   * category's jurisdiction, then the report's Group relationships (a
   * jurisdiction directly, or an organisation's jurisdiction).
   *
   * @return int[]
   *   Distinct jurisdiction IDs; more than one is a conflict for the caller.
   */
  protected function nodeJurisdictionIds(NodeInterface $node): array {
    if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
      return $this->targetIds($node->get('field_jurisdiction'));
    }
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $ids = [];
      foreach ($node->get('field_category')->referencedEntities() as $category) {
        if ($category->hasField('field_jurisdiction') && !$category->get('field_jurisdiction')->isEmpty()) {
          $ids = array_merge($ids, $this->targetIds($category->get('field_jurisdiction')));
        }
      }
      if ($ids) {
        return array_values(array_unique($ids));
      }
    }
    if (!$this->entityTypeManager->hasDefinition('group_relationship')) {
      return [];
    }
    $ids = [];
    /** @var \Drupal\group\Entity\Storage\GroupRelationshipStorageInterface $relationship_storage */
    $relationship_storage = $this->entityTypeManager->getStorage('group_relationship');
    foreach ($relationship_storage->loadByEntity($node) as $relationship) {
      if (!str_starts_with((string) $relationship->getPluginId(), 'group_node:')) {
        continue;
      }
      $group = $relationship->getGroup();
      if ($this->isJurisdictionGroup($group)) {
        $ids[] = (int) $group->id();
      }
      elseif ($group instanceof GroupInterface && $group->hasField('field_jurisdiction') && !$group->get('field_jurisdiction')->isEmpty()) {
        $ids = array_merge($ids, $this->targetIds($group->get('field_jurisdiction')));
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Returns the distinct numeric target IDs of a reference field.
   *
   * @return int[]
   *   Target IDs.
   */
  protected function targetIds(iterable $items): array {
    $ids = [];
    foreach ($items as $item) {
      if (is_numeric($item->target_id)) {
        $ids[] = (int) $item->target_id;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Returns the only jurisdiction of a single-tenant install, if published.
   */
  protected function singleTenantJurisdictionId(): ?int {
    $all = $this->jurisdictionIds(FALSE);
    if (count($all) !== 1) {
      return NULL;
    }
    $published = $this->jurisdictionIds(TRUE);
    return $published === $all ? $all[0] : NULL;
  }

  /**
   * Returns up to two jurisdiction IDs (enough to tell 0, 1 and many apart).
   *
   * @param bool $published_only
   *   Whether to count only published jurisdictions.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  protected function jurisdictionIds(bool $published_only): array {
    $query = $this->entityTypeManager
      ->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->getJurisdictionGroupType())
      ->range(0, 2);
    if ($published_only) {
      $query->condition('status', 1);
    }
    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Returns the binding store.
   */
  protected function bindingStore(): KeyValueStoreInterface {
    return $this->keyValue->get(self::STORE);
  }

}
