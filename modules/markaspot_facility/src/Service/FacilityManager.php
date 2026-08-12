<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Service;

use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages jurisdiction facility settings and service request derivation.
 */
class FacilityManager {
  /**
   * Maximum number of facilities allowed in the MVP blob.
   */
  private const MAX_ITEMS = 500;

  /**
   * Maximum number of facility categories per jurisdiction.
   */
  private const MAX_CATEGORIES = 100;

  /**
   * Canonical facility modes accepted by the client runtime.
   *
   * Kept in sync with FacilityMode in types/clientConfig.ts. Anything outside
   * this set resolves to `exclusive` via the legacy fallback when
   * `enabled: true`, so storing arbitrary strings here creates a silent
   * drift between admin intent and citizen-facing behaviour.
   */
  public const ALLOWED_MODES = ['exclusive', 'optional', 'disabled'];

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Country repository for ISO 3166-1 alpha-2 validation.
   *
   * @var \CommerceGuys\Addressing\Country\CountryRepositoryInterface
   */
  private CountryRepositoryInterface $countryRepository;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * Jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  private JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Tracks nodes whose address was locked from a selected facility.
   *
   * @var \SplObjectStorage<\Drupal\node\NodeInterface, bool>
   */
  private \SplObjectStorage $addressLocks;

  /**
   * Constructs the facility manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    CountryRepositoryInterface $country_repository,
    Connection $database,
    JurisdictionHierarchyResolverInterface $hierarchy_resolver,
    private readonly FeatureScopeResolver $featureScopeResolver,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('markaspot_facility');
    $this->countryRepository = $country_repository;
    $this->database = $database;
    $this->hierarchyResolver = $hierarchy_resolver;
    $this->addressLocks = new \SplObjectStorage();
  }

  /**
   * Returns the normalized facilities object for dashboard responses.
   */
  public function getDashboardSettings(?GroupInterface $group): array {
    $entitled = $this->hasEntitlement($group);
    $settings = $this->decodeFacilitiesField($group);
    $category_catalogue = $this->loadFacilityCategoryCatalogue($group);
    if ($category_catalogue !== NULL) {
      $settings['categories'] = $category_catalogue['items'];
    }
    $entity_items = $this->loadFacilityEntityItems(
      $group,
      FALSE,
      $category_catalogue['keysByEntityId'] ?? [],
    );
    if ($entity_items !== NULL) {
      $settings['items'] = $entity_items;
    }
    return $this->enforceEntitlement(
      $this->normalizeStoredSettings($settings, FALSE),
      $entitled,
      FALSE,
    );
  }

  /**
   * Returns the normalized facilities object for public settings.
   */
  public function getPublicSettings(?GroupInterface $group): array {
    $entitled = $this->hasEntitlement($group);
    $settings = $this->decodeFacilitiesField($group);
    if ($entitled) {
      $category_catalogue = $this->loadFacilityCategoryCatalogue($group);
      if ($category_catalogue !== NULL) {
        $settings['categories'] = $category_catalogue['items'];
      }
      $entity_items = $this->loadFacilityEntityItems(
        $group,
        TRUE,
        $category_catalogue['keysByEntityId'] ?? [],
      );
      if ($entity_items !== NULL) {
        $settings['items'] = $entity_items;
      }
    }
    return $this->enforceEntitlement(
      $this->normalizeStoredSettings($settings, TRUE),
      $entitled,
      TRUE,
    );
  }

  /**
   * Returns whether a jurisdiction may use facility management.
   */
  public function hasEntitlement(?GroupInterface $group): bool {
    return $group instanceof GroupInterface
      && $this->featureScopeResolver->isEnabledEffective('features.facilities', $group);
  }

  /**
   * Validates and stores the facilities blob on a jurisdiction group.
   */
  public function saveDashboardSettings(GroupInterface $group, array $payload): array {
    $normalized = $this->normalizeSubmittedSettings($payload);
    $clear_items = ($payload['clearItems'] ?? FALSE) === TRUE;
    $manages_categories = array_key_exists('categories', $payload);
    $source = $this->getSourceGroup($group);

    if (!$source->hasField('field_facilities')) {
      throw new \RuntimeException('field_facilities is missing on the jurisdiction group.');
    }

    if ($normalized['items'] === [] && !$clear_items && $this->hasLegacyFacilityItems($source)) {
      throw new \InvalidArgumentException('Refusing to clear the facility catalogue without clearItems=true.');
    }

    $transaction = $this->database->startTransaction();
    try {
      $category_entity_ids = NULL;
      $stale_categories = [];
      if ($manages_categories) {
        [$category_entity_ids, $stale_categories] = $this->syncFacilityCategoryEntities(
          $source,
          $normalized['categories'],
        );
      }
      $this->syncFacilityEntities(
        $source,
        $normalized['items'],
        $clear_items,
        $category_entity_ids,
      );
      if ($stale_categories !== []) {
        $this->entityTypeManager
          ->getStorage('markaspot_facility_category')
          ->delete($stale_categories);
      }

      // Store only the catalogue-level settings in the legacy JSON field. The
      // normalized item catalogue now lives in markaspot_facility entities.
      // This keeps the response contract stable while avoiding a second
      // writable copy of the facility list.
      $stored_settings = $normalized;
      $stored_settings['items'] = [];
      if ($manages_categories) {
        $stored_settings['categories'] = [];
      }
      $source->set(
            'field_facilities',
            json_encode($stored_settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
      $source->save();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    return $normalized;
  }

  /**
   * Applies facility-derived geodata and address to a service request node.
   */
  public function applyToServiceRequest(NodeInterface $node): void {
    if (
          $node->bundle() !== 'service_request'
          || !$node->hasField('field_facility')
          || $node->get('field_facility')->isEmpty()
          || !$node->hasField('field_jurisdiction')
          || $node->get('field_jurisdiction')->isEmpty()
      ) {
      return;
    }

    $facility_id = (string) $node->get('field_facility')->value;
    $jurisdiction_item = $node->get('field_jurisdiction')->first();
    $jurisdiction_id = (int) ($jurisdiction_item->target_id ?? 0);
    if ($jurisdiction_id <= 0) {
      return;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
    if (!$group instanceof GroupInterface) {
      return;
    }

    // Entitlement loss must also remove a newly submitted facility tag itself.
    // Keep an unchanged historical association so unrelated edits do not
    // destroy existing data, but never derive location or organisation from it.
    if (!$this->hasEntitlement($group)) {
      $original = $node->getOriginal();
      if (
        $original instanceof NodeInterface
        && $original->hasField('field_facility')
        && !$original->get('field_facility')->isEmpty()
        && (string) $original->get('field_facility')->value === $facility_id
        && $original->hasField('field_jurisdiction')
        && !$original->get('field_jurisdiction')->isEmpty()
        && (int) ($original->get('field_jurisdiction')->first()?->target_id ?? 0) === $jurisdiction_id
      ) {
        return;
      }
      $node->set('field_facility', NULL);
      return;
    }

    $settings = $this->getDashboardSettings($group);

    // In optional mode the citizen chose the position; the facility tag is
    // auto-derived from it and must not override the picked coordinates or
    // address. Preserves the `tag = f(position)` invariant documented for
    // the feature. Disabled mode should not reach this code with a facility
    // set, but we guard defensively.
    if (($settings['mode'] ?? 'disabled') !== 'exclusive') {
      return;
    }

    foreach ($settings['items'] as $facility) {
      if (($facility['id'] ?? '') !== $facility_id) {
        continue;
      }

      if ($node->hasField('field_geolocation')) {
        $node->set('field_geolocation', [
          'lat' => $facility['lat'],
          'lng' => $facility['lng'],
        ]);
      }

      if (!empty($facility['address']) && $node->hasField('field_address')) {
        $address = $this->buildFieldAddressFromFacility($facility['address'], $node, $group);
        if ($address !== NULL) {
          $node->set('field_address', $address);
          $this->addressLocks[$node] = TRUE;
        }
      }

      $this->applyFacilityOrganisation($node, $facility, $jurisdiction_id);

      return;
    }

    // The facility id is not in this jurisdiction's catalogue. On validated
    // write paths (Open311, JSON:API) the FacilityOwnership constraint already
    // rejected this before save; reaching here means a programmatic path that
    // skipped validate() (ECA action, import script, bulk update). Fail secure:
    // drop the foreign tag rather than persisting a cross-tenant value with no
    // resolvable geodata (#367 defence in depth).
    $node->set('field_facility', NULL);
    $this->logger->warning(
          'Facility "@facility" was not found for jurisdiction @jurisdiction while saving service request @node; the foreign facility tag was cleared.',
          [
            '@facility' => $facility_id,
            '@jurisdiction' => $jurisdiction_id,
            '@node' => $node->id() ?? 'new',
          ]
      );
  }

  /**
   * Applies a facility's default organisation assignment when it is resolvable.
   *
   * The catalogue stores organisationId as a string for backwards-compatible
   * settings transport. Only a numeric id that resolves to an org group scoped
   * to the same jurisdiction is safe to stamp onto node.field_organisation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Service request node being saved.
   * @param array<string, mixed> $facility
   *   Normalized facility item selected on the request.
   * @param int $jurisdiction_id
   *   The request's jurisdiction group id.
   */
  private function applyFacilityOrganisation(NodeInterface $node, array $facility, int $jurisdiction_id): void {
    if (!$node->hasField('field_organisation')) {
      return;
    }

    if (!$node->get('field_organisation')->isEmpty()) {
      return;
    }

    if (empty($facility['organisationId']) || !is_string($facility['organisationId'])) {
      return;
    }

    $raw_organisation_id = trim($facility['organisationId']);
    if ($raw_organisation_id === '') {
      return;
    }

    if (!ctype_digit($raw_organisation_id)) {
      $this->logger->warning(
        'Facility "@facility" declares non-numeric organisationId "@organisation" for jurisdiction @jurisdiction; organisation assignment was skipped.',
        [
          '@facility' => (string) ($facility['id'] ?? 'unknown'),
          '@organisation' => $raw_organisation_id,
          '@jurisdiction' => $jurisdiction_id,
        ]
      );
      return;
    }

    $organisation_id = (int) $raw_organisation_id;
    if ($organisation_id <= 0) {
      return;
    }

    $organisation = $this->entityTypeManager->getStorage('group')->load($organisation_id);
    if (!$organisation instanceof GroupInterface || $organisation->bundle() !== 'org') {
      $this->logger->warning(
        'Facility "@facility" declares missing or non-org organisationId @organisation for jurisdiction @jurisdiction; organisation assignment was skipped.',
        [
          '@facility' => (string) ($facility['id'] ?? 'unknown'),
          '@organisation' => $organisation_id,
          '@jurisdiction' => $jurisdiction_id,
        ]
      );
      return;
    }

    if (!$this->organisationBelongsToJurisdiction($organisation, $jurisdiction_id)) {
      $this->logger->warning(
        'Facility "@facility" declares organisationId @organisation outside jurisdiction @jurisdiction; organisation assignment was skipped.',
        [
          '@facility' => (string) ($facility['id'] ?? 'unknown'),
          '@organisation' => $organisation_id,
          '@jurisdiction' => $jurisdiction_id,
        ]
      );
      return;
    }

    $node->set('field_organisation', [['target_id' => $organisation_id]]);
  }

  /**
   * Returns whether an organisation group is scoped to a jurisdiction.
   */
  private function organisationBelongsToJurisdiction(GroupInterface $organisation, int $jurisdiction_id): bool {
    if (!$organisation->hasField('field_jurisdiction') || $organisation->get('field_jurisdiction')->isEmpty()) {
      return FALSE;
    }

    $target_id = (int) ($organisation->get('field_jurisdiction')->target_id ?? 0);
    if ($target_id <= 0) {
      $target_id = (int) ($organisation->get('field_jurisdiction')->first()?->target_id ?? 0);
    }

    if ($target_id === $jurisdiction_id) {
      return TRUE;
    }

    $root_jurisdiction_id = $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id);
    return $root_jurisdiction_id !== NULL && $target_id === $root_jurisdiction_id;
  }

  /**
   * Returns whether the facility flow locked the node address for this save.
   */
  public function isAddressLocked(NodeInterface $node): bool {
    if (
          $node->bundle() !== 'service_request'
          || !$node->hasField('field_facility')
          || $node->get('field_facility')->isEmpty()
      ) {
      return FALSE;
    }

    // Resolve the effective mode before consulting the per-save lock. A tenant
    // losing its entitlement must stop locking addresses immediately, even
    // when this object already recorded a lock earlier in the same request.
    if ($this->resolveEffectiveMode($node) !== 'exclusive') {
      return FALSE;
    }

    if ($this->addressLocks->contains($node)) {
      return TRUE;
    }

    if (!$node->hasField('field_address') || $node->get('field_address')->isEmpty()) {
      return FALSE;
    }

    $address_line1 = $node->get('field_address')->first()?->address_line1 ?? NULL;
    return is_string($address_line1) && trim($address_line1) !== '';
  }

  /**
   * Resolves the effective facility mode for a node's jurisdiction.
   *
   * Returns NULL when the node is not a service_request, is missing a
   * jurisdiction reference, or points at a jurisdiction whose group cannot
   * be loaded. Callers should treat NULL as "mode-agnostic" and make their
   * own decision (typically: fail secure).
   */
  private function resolveEffectiveMode(NodeInterface $node): ?string {
    if (
          $node->bundle() !== 'service_request'
          || !$node->hasField('field_jurisdiction')
          || $node->get('field_jurisdiction')->isEmpty()
      ) {
      return NULL;
    }

    $jurisdiction_item = $node->get('field_jurisdiction')->first();
    $jurisdiction_id = (int) ($jurisdiction_item->target_id ?? 0);
    if ($jurisdiction_id <= 0) {
      return NULL;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    $settings = $this->getDashboardSettings($group);
    return $settings['mode'] ?? 'disabled';
  }

  /**
   * Decodes the raw JSON blob from the jurisdiction field.
   */
  private function decodeFacilitiesField(?GroupInterface $group): array {
    if (!$group instanceof GroupInterface) {
      return [];
    }

    $source = $this->getSourceGroup($group);
    if (!$source->hasField('field_facilities') || $source->get('field_facilities')->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $source->get('field_facilities')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Loads the normalized facility category catalogue for a jurisdiction.
   *
   * @return array{items: array<int, array<string, mixed>>, keysByEntityId: array<int, string>}|null
   *   Category response items and a reference lookup, or NULL when no
   *   normalized category catalogue exists for this jurisdiction yet.
   */
  private function loadFacilityCategoryCatalogue(?GroupInterface $group): ?array {
    if (!$group instanceof GroupInterface
      || !$this->entityTypeManager->hasDefinition('markaspot_facility_category')) {
      return NULL;
    }

    $source = $this->getSourceGroup($group);
    try {
      $storage = $this->entityTypeManager->getStorage('markaspot_facility_category');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('jurisdiction_id', (int) $source->id())
        ->sort('weight')
        ->sort('label')
        ->sort('machine_name')
        ->execute();
      if ($ids === []) {
        return NULL;
      }

      $items = [];
      $keys_by_entity_id = [];
      foreach ($storage->loadMultiple($ids) as $entity) {
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        $key = $this->facilityEntityString($entity, 'machine_name');
        $label = $this->facilityEntityString($entity, 'label');
        $icon = $this->facilityEntityString($entity, 'icon');
        if ($key === '' || $label === '' || $icon === '') {
          continue;
        }
        $items[] = [
          'id' => $key,
          'label' => $label,
          'icon' => $icon,
          'weight' => (int) $entity->get('weight')->getString(),
        ];
        $keys_by_entity_id[(int) $entity->id()] = $key;
      }

      return [
        'items' => $items,
        'keysByEntityId' => $keys_by_entity_id,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->warning(
        'Facility category catalogue could not be loaded for jurisdiction @jurisdiction; falling back to legacy field_facilities. Error: @message',
        [
          '@jurisdiction' => $source->id() ?? 'unknown',
          '@message' => $e->getMessage(),
        ]
      );
      return NULL;
    }
  }

  /**
   * Loads normalized facility catalogue items from the entity store.
   *
   * @return array<int, array<string, mixed>>|null
   *   Facility items, an empty array when the entity catalogue exists but all
   *   rows are inactive/invalid for the current view, or NULL when no entity
   *   catalogue has been created for this jurisdiction yet. NULL deliberately
   *   triggers the legacy field_facilities fallback so existing tenants do not
   *   need an automatic migration.
   */
  private function loadFacilityEntityItems(
    ?GroupInterface $group,
    bool $public,
    array $category_keys_by_entity_id = [],
  ): ?array {
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    $source = $this->getSourceGroup($group);
    if (!$this->entityTypeManager->hasDefinition('markaspot_facility')) {
      return NULL;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('markaspot_facility');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('jurisdiction_id', (int) $source->id())
        ->sort('weight')
        ->sort('label')
        ->sort('machine_name')
        ->execute();

      if ($ids === []) {
        return NULL;
      }

      $items = [];
      foreach ($storage->loadMultiple($ids) as $entity) {
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        if ($public && !$this->facilityEntityBool($entity, 'active', TRUE)) {
          continue;
        }
        $item = $this->facilityEntityToItem($entity, $category_keys_by_entity_id);
        if ($item !== NULL) {
          $items[] = $item;
        }
      }

      return $items;
    }
    catch (\Throwable $e) {
      $this->logger->warning(
        'Facility entity catalogue could not be loaded for jurisdiction @jurisdiction; falling back to legacy field_facilities. Error: @message',
        [
          '@jurisdiction' => $source->id() ?? 'unknown',
          '@message' => $e->getMessage(),
        ]
      );
      return NULL;
    }
  }

  /**
   * Returns whether the legacy group JSON still contains catalogue rows.
   */
  private function hasLegacyFacilityItems(GroupInterface $group): bool {
    $settings = $this->decodeFacilitiesField($group);
    return !empty($settings['items']) && is_array($settings['items']);
  }

  /**
   * Reconciles submitted facility categories with tenant-owned entities.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Source jurisdiction group.
   * @param array<int, array<string, mixed>> $categories
   *   Normalized category items.
   *
   * @return array{0: array<string, int>, 1: array<int, \Drupal\Core\Entity\ContentEntityInterface>}
   *   Category entity IDs keyed by machine key and stale entities to delete
   *   after facility references have been reconciled.
   */
  private function syncFacilityCategoryEntities(GroupInterface $group, array $categories): array {
    if (!$this->entityTypeManager->hasDefinition('markaspot_facility_category')) {
      throw new \RuntimeException('markaspot_facility_category storage is not installed.');
    }

    $storage = $this->entityTypeManager->getStorage('markaspot_facility_category');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('jurisdiction_id', (int) $group->id())
      ->execute();
    $existing_by_key = [];
    $stale_entities = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      $key = $this->facilityEntityString($entity, 'machine_name');
      if ($key === '' || isset($existing_by_key[$key])) {
        $stale_entities[] = $entity;
        continue;
      }
      $existing_by_key[$key] = $entity;
    }

    $entity_ids_by_key = [];
    foreach (array_values($categories) as $category) {
      $key = (string) $category['id'];
      $entity = $existing_by_key[$key] ?? $storage->create([
        'jurisdiction_id' => (int) $group->id(),
        'machine_name' => $key,
      ]);
      if (!$entity instanceof ContentEntityInterface) {
        throw new \RuntimeException('markaspot_facility_category storage returned a non-content entity.');
      }
      $entity->set('jurisdiction_id', (int) $group->id());
      $entity->set('machine_name', $key);
      $entity->set('label', (string) $category['label']);
      $entity->set('icon', (string) $category['icon']);
      $entity->set('weight', (int) $category['weight']);
      $entity->save();
      $entity_ids_by_key[$key] = (int) $entity->id();
      unset($existing_by_key[$key]);
    }

    return [$entity_ids_by_key, [...$stale_entities, ...array_values($existing_by_key)]];
  }

  /**
   * Synchronizes normalized submitted facility items into content entities.
   *
   * Dashboard saves are explicit operator actions, so this is the conversion
   * point from legacy JSON items to normalized rows. Update hooks do not
   * backfill existing tenant data.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Source jurisdiction group.
   * @param array<int, array<string, mixed>> $items
   *   Normalized facility items.
   * @param bool $clear_items
   *   Whether an empty submitted catalogue is an explicit operator clear.
   * @param array<string, int>|null $category_entity_ids
   *   Submitted category entity IDs keyed by machine key. NULL preserves
   *   existing references for legacy clients that do not manage categories.
   */
  private function syncFacilityEntities(
    GroupInterface $group,
    array $items,
    bool $clear_items,
    ?array $category_entity_ids = NULL,
  ): void {
    if (!$this->entityTypeManager->hasDefinition('markaspot_facility')) {
      throw new \RuntimeException('markaspot_facility entity storage is not installed.');
    }

    $storage = $this->entityTypeManager->getStorage('markaspot_facility');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('jurisdiction_id', (int) $group->id())
      ->execute();

    $existing_by_key = [];
    $duplicate_entities = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      $key = $this->facilityEntityString($entity, 'machine_name');
      if ($key === '') {
        $duplicate_entities[] = $entity;
        continue;
      }
      if (isset($existing_by_key[$key])) {
        $duplicate_entities[] = $entity;
        continue;
      }
      $existing_by_key[$key] = $entity;
    }

    if ($items === [] && !$clear_items && ($existing_by_key !== [] || $duplicate_entities !== [])) {
      throw new \InvalidArgumentException('Refusing to clear the facility catalogue without clearItems=true.');
    }

    if ($duplicate_entities !== []) {
      $storage->delete($duplicate_entities);
    }

    foreach (array_values($items) as $weight => $item) {
      $key = (string) $item['id'];
      $entity = $existing_by_key[$key] ?? $storage->create([
        'jurisdiction_id' => (int) $group->id(),
        'machine_name' => $key,
      ]);

      if (!$entity instanceof ContentEntityInterface) {
        throw new \RuntimeException('markaspot_facility storage returned a non-content entity.');
      }

      $this->applyItemToFacilityEntity(
        $entity,
        $item,
        $group,
        $weight,
        $category_entity_ids,
      );
      $entity->save();
      unset($existing_by_key[$key]);
    }

    if ($existing_by_key !== []) {
      $storage->delete(array_values($existing_by_key));
    }
  }

  /**
   * Applies one normalized facility item to a content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Facility content entity.
   * @param array<string, mixed> $item
   *   Normalized facility item.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Source jurisdiction group.
   * @param int $weight
   *   Facility sort weight.
   * @param array<string, int>|null $category_entity_ids
   *   Submitted category entity IDs keyed by machine key.
   */
  private function applyItemToFacilityEntity(
    ContentEntityInterface $entity,
    array $item,
    GroupInterface $group,
    int $weight,
    ?array $category_entity_ids,
  ): void {
    $entity->set('jurisdiction_id', (int) $group->id());
    $entity->set('machine_name', (string) $item['id']);
    $entity->set('label', (string) $item['label']);
    $entity->set('lat', (float) $item['lat']);
    $entity->set('lng', (float) $item['lng']);
    $entity->set('active', (bool) ($item['active'] ?? TRUE));
    $entity->set('weight', $weight);
    $entity->set(
      'address',
      array_key_exists('address', $item)
        ? json_encode($item['address'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : ''
    );
    $entity->set('organisation_id', (string) ($item['organisationId'] ?? ''));
    if ($category_entity_ids !== NULL) {
      $category_key = (string) ($item['categoryId'] ?? '');
      $entity->set(
        'category_id',
        $category_key !== '' ? ($category_entity_ids[$category_key] ?? NULL) : NULL,
      );
    }
    $entity->set('icon', (string) ($item['icon'] ?? ''));
    $entity->set('description', (string) ($item['description'] ?? ''));
    $entity->set('url', (string) ($item['url'] ?? ''));
  }

  /**
   * Converts a facility content entity into the public/dashboard item shape.
   *
   * @return array<string, mixed>|null
   *   Normalized facility item, or NULL when required fields are incomplete.
   */
  private function facilityEntityToItem(
    ContentEntityInterface $entity,
    array $category_keys_by_entity_id = [],
  ): ?array {
    $id = $this->facilityEntityString($entity, 'machine_name');
    $label = $this->facilityEntityString($entity, 'label');
    $lat = $this->facilityEntityFloat($entity, 'lat');
    $lng = $this->facilityEntityFloat($entity, 'lng');
    if ($id === '' || $label === '' || $lat === NULL || $lng === NULL) {
      return NULL;
    }

    $item = [
      'id' => $id,
      'label' => $label,
      'lat' => $lat,
      'lng' => $lng,
      'active' => $this->facilityEntityBool($entity, 'active', TRUE),
    ];

    $address = $this->decodeFacilityEntityAddress($entity);
    if ($address !== NULL) {
      $item['address'] = $address;
    }

    $organisation_id = $this->facilityEntityString($entity, 'organisation_id');
    if ($organisation_id !== '') {
      $item['organisationId'] = $organisation_id;
    }

    if ($entity->hasField('category_id') && !$entity->get('category_id')->isEmpty()) {
      $category_entity_id = (int) ($entity->get('category_id')->target_id ?? 0);
      if (isset($category_keys_by_entity_id[$category_entity_id])) {
        $item['categoryId'] = $category_keys_by_entity_id[$category_entity_id];
      }
    }

    foreach (['icon', 'description', 'url'] as $field_name) {
      $value = $this->facilityEntityString($entity, $field_name);
      if ($value !== '') {
        $item[$field_name] = $value;
      }
    }

    return $item;
  }

  /**
   * Decodes the JSON address stored on a facility entity.
   */
  private function decodeFacilityEntityAddress(ContentEntityInterface $entity): array|string|null {
    $raw = $this->facilityEntityString($entity, 'address');
    if ($raw === '') {
      return NULL;
    }

    $decoded = json_decode($raw, TRUE);
    if (json_last_error() === JSON_ERROR_NONE) {
      return $this->normalizeStoredAddress($decoded);
    }

    return $this->normalizeStoredAddress($raw);
  }

  /**
   * Reads a string value from a facility entity field.
   */
  private function facilityEntityString(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }
    return trim($entity->get($field_name)->getString());
  }

  /**
   * Reads a float value from a facility entity field.
   */
  private function facilityEntityFloat(ContentEntityInterface $entity, string $field_name): ?float {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $entity->get($field_name)->getString();
    return is_numeric($value) ? (float) $value : NULL;
  }

  /**
   * Reads a boolean value from a facility entity field.
   */
  private function facilityEntityBool(ContentEntityInterface $entity, string $field_name, bool $default): bool {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return $default;
    }
    return (bool) $entity->get($field_name)->getString();
  }

  /**
   * Normalizes raw stored settings into the public/dashboard response shape.
   */
  private function normalizeStoredSettings(array $settings, bool $public): array {
    $normalized = [
      'enabled' => !empty($settings['enabled']),
      'hideMapPicker' => !empty($settings['hideMapPicker']),
      'categories' => [],
      'items' => [],
    ];

    if (!empty($settings['label']) && is_array($settings['label'])) {
      $label = [];
      if (!empty($settings['label']['singular']) && is_string($settings['label']['singular'])) {
        $label['singular'] = trim($settings['label']['singular']);
      }
      if (!empty($settings['label']['plural']) && is_string($settings['label']['plural'])) {
        $label['plural'] = trim($settings['label']['plural']);
      }
      if ($label !== []) {
        $normalized['label'] = $label;
      }
    }

    $normalized['mode'] = $this->resolveStoredMode($settings, $normalized['enabled']);

    if (!empty($settings['categories']) && is_array($settings['categories'])) {
      foreach ($settings['categories'] as $weight => $category) {
        if (!is_array($category)
          || empty($category['id'])
          || empty($category['label'])
          || empty($category['icon'])) {
          continue;
        }
        $normalized['categories'][] = [
          'id' => (string) $category['id'],
          'label' => (string) $category['label'],
          'icon' => (string) $category['icon'],
          'weight' => isset($category['weight']) ? (int) $category['weight'] : $weight,
        ];
      }
    }

    if (!empty($settings['items']) && is_array($settings['items'])) {
      foreach ($settings['items'] as $item) {
        if (
              !is_array($item) || empty($item['id']) || empty($item['label'])
              || !isset($item['lat'], $item['lng'])
          ) {
          continue;
        }

        $active = array_key_exists('active', $item) ? (bool) $item['active'] : TRUE;
        if ($public && !$active) {
          continue;
        }

        $normalized_item = [
          'id' => (string) $item['id'],
          'label' => (string) $item['label'],
          'lat' => (float) $item['lat'],
          'lng' => (float) $item['lng'],
          'active' => $active,
        ];

        $stored_address = $this->normalizeStoredAddress($item['address'] ?? NULL);
        if ($stored_address !== NULL) {
          $normalized_item['address'] = $stored_address;
        }
        if (!empty($item['organisationId']) && is_string($item['organisationId'])) {
          $normalized_item['organisationId'] = $item['organisationId'];
        }
        if (!empty($item['categoryId']) && is_string($item['categoryId'])) {
          $normalized_item['categoryId'] = $item['categoryId'];
        }
        // Display metadata (#368), re-emitted as stored. Values were
        // validated on write (HTML-stripped text; http(s)-only url).
        foreach (['icon', 'description', 'url'] as $display_field) {
          if (!empty($item[$display_field]) && is_string($item[$display_field])) {
            $normalized_item[$display_field] = $item[$display_field];
          }
        }

        $normalized['items'][] = $normalized_item;
      }
    }

    return $normalized;
  }

  /**
   * Makes entitlement authoritative over a tenant's facility configuration.
   *
   * Stored settings remain intact so an operator can restore access without
   * reconstructing the catalogue. Public responses must additionally suppress
   * that catalogue because it drives QR snapping in the citizen frontend.
   */
  private function enforceEntitlement(
    array $settings,
    bool $entitled,
    bool $public,
  ): array {
    if ($entitled) {
      return $settings;
    }

    $settings['enabled'] = FALSE;
    $settings['mode'] = 'disabled';
    if ($public) {
      $settings['categories'] = [];
      $settings['items'] = [];
    }
    return $settings;
  }

  /**
   * Resolves a stored mode value to one of the canonical modes.
   *
   * Tolerates legacy rows where `mode` is absent, empty, or an obsolete slug
   * like `facility_required`. Unknown values collapse to `exclusive` when
   * the feature is enabled (matches the client runtime legacy fallback in
   * `useFacilityReporting.ts`) and to `disabled` otherwise. This keeps the
   * dashboard form from hitting a 400 on the first Save after the allowlist
   * was tightened.
   */
  private function resolveStoredMode(array $settings, bool $enabled): string {
    $raw = isset($settings['mode']) && is_string($settings['mode'])
      ? trim($settings['mode'])
      : '';
    if (in_array($raw, self::ALLOWED_MODES, TRUE)) {
      return $raw;
    }
    return $enabled ? 'exclusive' : 'disabled';
  }

  /**
   * Validates dashboard payloads and returns canonical storage data.
   */
  public function normalizeSubmittedSettings(array $payload): array {
    $allowed_keys = ['enabled', 'label', 'mode', 'hideMapPicker', 'categories', 'items', 'clearItems'];
    $unknown = array_diff(array_keys($payload), $allowed_keys);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Unknown facilities settings keys: ' . implode(', ', $unknown) . '.');
    }

    if (!array_key_exists('enabled', $payload) || !is_bool($payload['enabled'])) {
      throw new \InvalidArgumentException('enabled is required and must be a boolean.');
    }
    if (!array_key_exists('hideMapPicker', $payload) || !is_bool($payload['hideMapPicker'])) {
      throw new \InvalidArgumentException('hideMapPicker is required and must be a boolean.');
    }
    if (!array_key_exists('items', $payload) || !is_array($payload['items'])) {
      throw new \InvalidArgumentException('items is required and must be an array.');
    }
    if (array_key_exists('clearItems', $payload) && !is_bool($payload['clearItems'])) {
      throw new \InvalidArgumentException('clearItems must be a boolean when provided.');
    }
    if (count($payload['items']) > self::MAX_ITEMS) {
      throw new \InvalidArgumentException('items exceeds the maximum allowed number of facilities.');
    }
    if (array_key_exists('categories', $payload) && !is_array($payload['categories'])) {
      throw new \InvalidArgumentException('categories must be an array when provided.');
    }
    if (count($payload['categories'] ?? []) > self::MAX_CATEGORIES) {
      throw new \InvalidArgumentException('categories exceeds the maximum allowed number of facility categories.');
    }

    $normalized = [
      'enabled' => $payload['enabled'],
      'hideMapPicker' => $payload['hideMapPicker'],
      'categories' => [],
      'items' => [],
    ];

    if (array_key_exists('label', $payload)) {
      if (!is_array($payload['label'])) {
        throw new \InvalidArgumentException('label must be an object when provided.');
      }
      $label_unknown = array_diff(array_keys($payload['label']), ['singular', 'plural']);
      if ($label_unknown !== []) {
        throw new \InvalidArgumentException('Unknown label keys: ' . implode(', ', $label_unknown) . '.');
      }
      $label = [];
      foreach (['singular', 'plural'] as $key) {
        if (array_key_exists($key, $payload['label'])) {
          $label[$key] = $this->validateTextValue(
                $payload['label'][$key],
                "label.$key",
                120,
                TRUE
            );
        }
      }
      if ($label !== []) {
        $normalized['label'] = $label;
      }
    }

    if (array_key_exists('mode', $payload)) {
      if (!is_string($payload['mode'])) {
        throw new \InvalidArgumentException('mode must be a string when provided.');
      }
      $mode = trim($payload['mode']);
      if (!in_array($mode, self::ALLOWED_MODES, TRUE)) {
        throw new \InvalidArgumentException(sprintf(
              'mode must be one of %s.',
              implode(', ', self::ALLOWED_MODES)
          ));
      }
      $normalized['mode'] = $mode;
    }

    $seen_category_ids = [];
    foreach (array_values($payload['categories'] ?? []) as $index => $category) {
      if (!is_array($category)) {
        throw new \InvalidArgumentException("categories[$index] must be an object.");
      }
      $category_unknown = array_diff(array_keys($category), ['id', 'label', 'icon', 'weight']);
      if ($category_unknown !== []) {
        throw new \InvalidArgumentException("categories[$index] contains unknown keys: " . implode(', ', $category_unknown) . '.');
      }
      $id = $this->validateCategoryKey($category['id'] ?? NULL, "categories[$index].id");
      if (isset($seen_category_ids[$id])) {
        throw new \InvalidArgumentException("categories[$index].id must be unique.");
      }
      $seen_category_ids[$id] = TRUE;
      $normalized['categories'][] = [
        'id' => $id,
        'label' => $this->validateTextValue($category['label'] ?? NULL, "categories[$index].label", 255),
        'icon' => $this->validateLucideIcon($category['icon'] ?? NULL, "categories[$index].icon", FALSE),
        'weight' => $this->validateIntegerValue(
          $category['weight'] ?? $index,
          "categories[$index].weight",
          -10000,
          10000,
        ),
      ];
    }

    $seen_ids = [];
    foreach (array_values($payload['items']) as $index => $item) {
      if (!is_array($item)) {
        throw new \InvalidArgumentException("items[$index] must be an object.");
      }

      $item_unknown = array_diff(array_keys($item), [
        'id',
        'label',
        'lat',
        'lng',
        'address',
        'organisationId',
        'categoryId',
        'active',
        // Display metadata added for #381 (FacilityRow icon/description/url),
        // sent by the Vue admin (facilities.vue). Validated and stored below
        // (#368): icon/description are HTML-stripped text, url is restricted to
        // an absolute http(s) URL so a hostile scheme cannot be persisted and
        // handed to a non-Nuxt consumer (Open311 export, admin table, email).
        'icon',
        'description',
        'url',
      ]);
      if ($item_unknown !== []) {
        throw new \InvalidArgumentException("items[$index] contains unknown keys: " . implode(', ', $item_unknown) . '.');
      }

      $id = $this->validateMachineKey($item['id'] ?? NULL, "items[$index].id");
      if (isset($seen_ids[$id])) {
        throw new \InvalidArgumentException("items[$index].id must be unique.");
      }
      $seen_ids[$id] = TRUE;

      $normalized_item = [
        'id' => $id,
        'label' => $this->validateTextValue($item['label'] ?? NULL, "items[$index].label", 255),
        'lat' => $this->validateCoordinate($item['lat'] ?? NULL, "items[$index].lat", -90.0, 90.0),
        'lng' => $this->validateCoordinate($item['lng'] ?? NULL, "items[$index].lng", -180.0, 180.0),
        'active' => array_key_exists('active', $item) ? $this->validateBoolean($item['active'], "items[$index].active") : TRUE,
      ];

      if (array_key_exists('address', $item)) {
        $normalized_item['address'] = $this->validateFacilityAddress(
          $item['address'],
          "items[$index].address"
          );
      }

      if (array_key_exists('organisationId', $item)) {
        $normalized_item['organisationId'] = $this->validateTextValue(
          $item['organisationId'],
          "items[$index].organisationId",
          255
          );
      }

      if (array_key_exists('categoryId', $item)) {
        $category_id = $this->validateCategoryKey($item['categoryId'], "items[$index].categoryId");
        if (!isset($seen_category_ids[$category_id])) {
          throw new \InvalidArgumentException("items[$index].categoryId must reference a submitted facility category.");
        }
        $normalized_item['categoryId'] = $category_id;
      }

      // Display metadata (#368). icon/description are HTML-stripped plain text
      // (validateTextValue rejects any markup); empty values are dropped so the
      // write/read paths agree (normalizeStoredSettings only re-emits truthy
      // values). url is scheme-restricted to absolute http(s).
      foreach (['icon' => 255, 'description' => 1024] as $display_field => $max_length) {
        if (array_key_exists($display_field, $item)) {
          $value = $this->validateTextValue(
            $item[$display_field],
            "items[$index].$display_field",
            $max_length,
            TRUE
            );
          if ($value !== '') {
            // Facility-specific icons predate category icons and may still use
            // legacy FontAwesome identifiers. Keep that existing plain-text
            // contract so adding categories does not make an unchanged tenant
            // catalogue impossible to save. Category icons remain Lucide-only.
            $normalized_item[$display_field] = $value;
          }
        }
      }
      if (array_key_exists('url', $item)) {
        $url = $this->validateUrlValue($item['url'], "items[$index].url");
        if ($url !== '') {
          $normalized_item['url'] = $url;
        }
      }

      $normalized['items'][] = $normalized_item;
    }

    return $normalized;
  }

  /**
   * Resolves the untranslated jurisdiction entity for non-translatable fields.
   */
  private function getSourceGroup(GroupInterface $group): GroupInterface {
    return $group->isDefaultTranslation() ? $group : $group->getUntranslated();
  }

  /**
   * Validates a machine key string.
   */
  private function validateMachineKey(mixed $value, string $path): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException("$path must be a string.");
    }
    $value = trim($value);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $value)) {
      throw new \InvalidArgumentException("$path must match /^[a-z0-9][a-z0-9_-]{0,127}$/.");
    }
    return $value;
  }

  /**
   * Validates stable facility category keys used by the dashboard and API.
   */
  private function validateCategoryKey(mixed $value, string $path): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException("$path must be a string.");
    }
    $value = trim($value);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) || strlen($value) > 128) {
      throw new \InvalidArgumentException("$path must contain only lowercase letters, numbers, and hyphens.");
    }
    return $value;
  }

  /**
   * Validates a bounded integer value without coercing strings or floats.
   */
  private function validateIntegerValue(mixed $value, string $path, int $minimum, int $maximum): int {
    if (!is_int($value) || $value < $minimum || $value > $maximum) {
      throw new \InvalidArgumentException("$path must be an integer between $minimum and $maximum.");
    }
    return $value;
  }

  /**
   * Validates and normalizes a Lucide Iconify identifier.
   */
  private function validateLucideIcon(mixed $value, string $path, bool $allow_plain = TRUE): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException("$path must be a string.");
    }
    $trimmed = strtolower(trim($value));
    if (preg_match('/^i-lucide-[a-z0-9][a-z0-9-]*$/', $trimmed)) {
      return $trimmed;
    }
    if (preg_match('/^lucide:[a-z0-9][a-z0-9-]*$/', $trimmed)) {
      return 'i-lucide-' . substr($trimmed, 7);
    }
    if ($allow_plain && preg_match('/^[a-z0-9][a-z0-9-]*$/', $trimmed)) {
      return 'i-lucide-' . $trimmed;
    }
    $expected = $allow_plain
      ? 'an i-lucide-*, lucide:*, or Lucide icon name'
      : 'an i-lucide-* or lucide:* icon name';
    throw new \InvalidArgumentException("$path must be $expected.");
  }

  /**
   * Validates a general text value.
   */
  private function validateTextValue(mixed $value, string $path, int $max_length, bool $allow_empty = FALSE): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException("$path must be a string.");
    }
    $trimmed = trim($value);
    if ($trimmed === '' && !$allow_empty) {
      throw new \InvalidArgumentException("$path must be a non-empty string.");
    }
    if (mb_strlen($trimmed) > $max_length) {
      throw new \InvalidArgumentException("$path exceeds the maximum length of $max_length characters.");
    }
    if (strip_tags($trimmed) !== $trimmed) {
      throw new \InvalidArgumentException("$path must not contain HTML.");
    }
    return $trimmed;
  }

  /**
   * Validates an optional facility display URL (#368).
   *
   * Returns an empty string for an unset/blank value (the caller drops it).
   * A non-empty value must be an absolute http(s) URL: this rejects
   * `javascript:`, `data:`, and scheme-relative links so a hostile URL cannot
   * be persisted in config and served to a consumer that does not re-sanitize
   * on render (Open311 export, a future admin table, a notification email).
   * The Nuxt frontend re-validates on render as defence in depth, but the
   * server must not be a knowing pass-through for dangerous schemes.
   */
  private function validateUrlValue(mixed $value, string $path): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException("$path must be a string.");
    }
    // Strip control characters (NUL/tab/CR/LF) anywhere in the value, not just
    // the ends: a mid-string newline could split the URL for a consumer that
    // prints it raw (plain-text email, a header context) rather than as an
    // attribute the way Nuxt does.
    $trimmed = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', trim($value));
    if ($trimmed === '') {
      return '';
    }
    if (mb_strlen($trimmed) > 512) {
      throw new \InvalidArgumentException("$path exceeds the maximum length of 512 characters.");
    }
    // Require an absolute http(s) URL with a non-empty host. The trailing
    // [^\s/] rejects javascript:/data:/file: schemes, scheme-relative //host,
    // and the empty-host edge (https://) that carries no usable destination.
    if (!preg_match('#^https?://[^\s/]#i', $trimmed)) {
      throw new \InvalidArgumentException("$path must be an absolute http:// or https:// URL.");
    }
    return $trimmed;
  }

  /**
   * Validates a facility address payload (legacy string or structured object).
   *
   * Accepts either:
   * - a non-empty string up to 512 chars (legacy form, mirrors what tenants
   *   stored before the admin UI gained reverse geocoding), or
   * - an associative array `{address_line1, country_code?, locality?,
   *   postal_code?}` written by the new admin UI after reverse geocoding.
   *
   * Anything else is rejected. The structured form requires `address_line1`
   * and silently drops empty optional sub-keys so a `country_code: ''` from
   * a JSON null-coercion does not pollute storage. The legacy string branch
   * rejects empty/whitespace-only input so the write/read paths agree:
   * `normalizeStoredAddress()` would trim a whitespace string back to NULL,
   * which would silently lose the value on the next GET.
   *
   * @return array<string, string>|string
   *   The canonical legacy string or the canonical structured array.
   */
  private function validateFacilityAddress(mixed $value, string $path): array|string {
    if (is_string($value)) {
      return $this->validateTextValue($value, $path, 512, FALSE);
    }
    if (is_array($value)) {
      return $this->validateStructuredAddress($value, $path);
    }
    throw new \InvalidArgumentException("$path must be a string or an object.");
  }

  /**
   * Validates a structured FacilityAddress object.
   *
   * Mirrors the four sub-fields required by Drupal's Address module so the
   * admin UI's reverse-geocoded payload can be persisted as-is. Optional
   * sub-keys are skipped when not a non-empty string after trimming.
   *
   * @param array<int|string, mixed> $value
   *   The structured address candidate.
   * @param string $path
   *   The dotted path for error messages (e.g. `items[0].address`).
   *
   * @return array<string, string>
   *   The validated structured address with only present, non-empty keys.
   */
  private function validateStructuredAddress(array $value, string $path): array {
    $allowed = ['address_line1', 'country_code', 'locality', 'postal_code'];
    $unknown = array_diff(array_keys($value), $allowed);
    if ($unknown !== []) {
      throw new \InvalidArgumentException("$path contains unknown keys: " . implode(', ', $unknown) . '.');
    }

    if (!array_key_exists('address_line1', $value)) {
      throw new \InvalidArgumentException("$path.address_line1 is required.");
    }

    $structured = [
      'address_line1' => $this->validateTextValue($value['address_line1'], "$path.address_line1", 255),
    ];

    if (array_key_exists('country_code', $value) && $this->isNonEmptyString($value['country_code'])) {
      $country = strtoupper(trim((string) $value['country_code']));
      // The regex is a cheap pre-check for the shape; the actual ISO 3166-1
      // alpha-2 list lookup catches non-existent codes (XX, ZZ, EU, XK) that
      // would otherwise pass here and crash Drupal's downstream Address
      // module (commerceguys/addressing) with a field-constraint violation
      // on every subsequent service request submission.
      if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new \InvalidArgumentException("$path.country_code must be a 2-letter ISO 3166-1 alpha-2 code.");
      }
      if (!array_key_exists($country, $this->countryRepository->getList())) {
        throw new \InvalidArgumentException("$path.country_code must be a valid ISO 3166-1 alpha-2 country code.");
      }
      $structured['country_code'] = $country;
    }

    if (array_key_exists('locality', $value) && $this->isNonEmptyString($value['locality'])) {
      $structured['locality'] = $this->validateTextValue($value['locality'], "$path.locality", 255);
    }

    if (array_key_exists('postal_code', $value) && $this->isNonEmptyString($value['postal_code'])) {
      $structured['postal_code'] = $this->validateTextValue($value['postal_code'], "$path.postal_code", 32);
    }

    return $structured;
  }

  /**
   * Returns TRUE for a string with at least one non-whitespace character.
   *
   * Used to skip empty optional address sub-keys (e.g. an undefined that
   * coerced to JSON null and surfaced as a missing/empty value).
   */
  private function isNonEmptyString(mixed $value): bool {
    return is_string($value) && trim($value) !== '';
  }

  /**
   * Normalizes a stored address value for the dashboard/public response.
   *
   * Accepts the legacy string form or a structured array previously written
   * by the admin UI. Unknown keys in the structured form are dropped; an
   * incomplete structured form (missing `address_line1`) is treated as
   * absent so a corrupted blob doesn't poison every dashboard load.
   *
   * @return array<string, string>|string|null
   *   The normalized stored address, or NULL when nothing usable is present.
   */
  private function normalizeStoredAddress(mixed $value): array|string|null {
    if (is_string($value)) {
      $trimmed = trim($value);
      return $trimmed === '' ? NULL : $trimmed;
    }
    if (!is_array($value) || !$this->isNonEmptyString($value['address_line1'] ?? NULL)) {
      return NULL;
    }
    $normalized = [
      'address_line1' => trim((string) $value['address_line1']),
    ];
    foreach (['country_code', 'locality', 'postal_code'] as $key) {
      if ($this->isNonEmptyString($value[$key] ?? NULL)) {
        $normalized[$key] = $key === 'country_code'
          ? strtoupper(trim((string) $value[$key]))
          : trim((string) $value[$key]);
      }
    }
    return $normalized;
  }

  /**
   * Builds the field_address payload for a service request from a facility.
   *
   * Returns the structured array directly when the stored facility carries
   * one; falls back to `{address_line1, country_code?}` derived from a legacy
   * string + jurisdiction country code otherwise. Returns NULL when the
   * source value yields no usable address line.
   *
   * @return array<string, string>|null
   *   The address payload for `$node->set('field_address', ...)`.
   */
  private function buildFieldAddressFromFacility(
    mixed $stored_address,
    NodeInterface $node,
    GroupInterface $group,
  ): ?array {
    if (is_array($stored_address) && $this->isNonEmptyString($stored_address['address_line1'] ?? NULL)) {
      $address = ['address_line1' => trim((string) $stored_address['address_line1'])];
      foreach (['country_code', 'locality', 'postal_code'] as $key) {
        if ($this->isNonEmptyString($stored_address[$key] ?? NULL)) {
          $address[$key] = $key === 'country_code'
            ? strtoupper(trim((string) $stored_address[$key]))
            : trim((string) $stored_address[$key]);
        }
      }
      // Only consult the jurisdiction fallback when the structured payload
      // didn't already supply a country code. Preserves the admin's intent
      // when reverse geocoding produced one.
      if (!isset($address['country_code'])) {
        $country_code = $this->resolveCountryCode($node, $group);
        if ($country_code !== NULL) {
          $address['country_code'] = $country_code;
        }
      }
      return $address;
    }

    if (is_string($stored_address) && trim($stored_address) !== '') {
      $address = ['address_line1' => trim($stored_address)];
      $country_code = $this->resolveCountryCode($node, $group);
      if ($country_code !== NULL) {
        $address['country_code'] = $country_code;
      }
      return $address;
    }

    return NULL;
  }

  /**
   * Validates a boolean value.
   */
  private function validateBoolean(mixed $value, string $path): bool {
    if (!is_bool($value)) {
      throw new \InvalidArgumentException("$path must be a boolean.");
    }
    return $value;
  }

  /**
   * Validates a latitude or longitude.
   */
  private function validateCoordinate(mixed $value, string $path, float $min, float $max): float {
    if (!is_numeric($value)) {
      throw new \InvalidArgumentException("$path must be numeric.");
    }
    $float = (float) $value;
    if ($float < $min || $float > $max) {
      throw new \InvalidArgumentException("$path must be between $min and $max.");
    }
    return $float;
  }

  /**
   * Resolves a country code from node or jurisdiction data.
   */
  private function resolveCountryCode(NodeInterface $node, GroupInterface $group): ?string {
    if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
      $country = $node->get('field_address')->first()?->country_code;
      if (is_string($country) && $country !== '') {
        return $country;
      }
    }

    if ($group->hasField('field_jurisdiction_address') && !$group->get('field_jurisdiction_address')->isEmpty()) {
      $country = $group->get('field_jurisdiction_address')->first()?->country_code;
      if (is_string($country) && $country !== '') {
        return $country;
      }
    }

    return NULL;
  }

}
