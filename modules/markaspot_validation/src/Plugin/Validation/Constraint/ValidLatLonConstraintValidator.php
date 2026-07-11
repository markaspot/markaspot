<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary;
use Drupal\markaspot_validation\Plugin\Validation\Geo\Polygon;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that coordinates fall within the configured boundary.
 *
 * Resolution order:
 * 1. Jurisdiction-specific GeoJSON boundary (from the jurisdiction group's
 *    field_boundary, resolved via group membership, the submitted entity
 *    field, or the category term).
 * 2. Global WKT polygon from markaspot_validation.settings.wkt.
 * 3. No boundary configured: validation passes.
 */
class ValidLatLonConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ValidLatLonConstraintValidator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint): void {
    $lng = (float) $value->lng;
    $lat = (float) $value->lat;

    if (!$this->isWithinBoundary($lng, $lat)) {
      $this->context->addViolation($constraint->noValidViewboxMessage);
    }
  }

  /**
   * Checks whether coordinates fall within the applicable boundary.
   *
   * @param float $lng
   *   Longitude.
   * @param float $lat
   *   Latitude.
   *
   * @return bool
   *   TRUE if inside boundary or no boundary is configured.
   */
  private function isWithinBoundary(float $lng, float $lat): bool {
    // 1. Try each jurisdiction boundary from most to least specific. A child
    // without usable geometry falls back to its canonical root boundary.
    foreach ($this->resolveJurisdictions() as $jurisdictionId) {
      $boundary = $this->loadJurisdictionBoundary($jurisdictionId);
      if ($boundary) {
        return $boundary->contains($lng, $lat);
      }
    }

    // 2. Fallback to global WKT polygon.
    return $this->checkWktBoundary($lng, $lat);
  }

  /**
   * Resolves the jurisdiction ID from the validated entity.
   *
   * Primary: entity -> field_jurisdiction from the current payload.
   * Existing fallback: entity -> group_relationship.
   * Last fallback: entity -> field_category -> term -> field_jurisdiction.
   *
   * Once an entity jurisdiction is known, its canonical root is the only
   * fallback. Emergency categories are shared across that complete root tree,
   * so a term owned by a sibling child must never select the sibling boundary.
   *
   * @return int[]
   *   Ordered unique jurisdiction group IDs, most specific first.
   */
  private function resolveJurisdictions(): array {
    $root = $this->context->getRoot();
    if (!method_exists($root, 'getEntity')) {
      return [];
    }

    $entity = $root->getEntity();
    if (!$entity) {
      return [];
    }

    $jurisdictionIds = [];
    $primaryJurisdictionId = NULL;
    // The current entity field is authoritative during creates and edits. A
    // stored relationship can still point at the old child until after save.
    $fieldGroupId = $this->resolveJurisdictionFromEntityField($entity);
    if ($fieldGroupId !== NULL) {
      $primaryJurisdictionId = $fieldGroupId;
    }
    else {
      // Existing entities without the denormalized field can use membership.
      $groupId = $this->resolveJurisdictionFromGroupRelationship($entity);
      if ($groupId !== NULL) {
        $primaryJurisdictionId = $groupId;
      }
    }

    if ($primaryJurisdictionId !== NULL) {
      $jurisdictionIds[] = $primaryJurisdictionId;
      $rootJurisdictionId = $this->resolveRootJurisdictionId($primaryJurisdictionId);
      if ($rootJurisdictionId !== NULL) {
        $jurisdictionIds[] = $rootJurisdictionId;
      }
    }
    else {
      // Legacy entities without any direct or relationship jurisdiction can
      // still derive their scope from the category and then its root.
      $categoryJurisdictionId = $this->resolveJurisdictionFromCategory($entity);
      if ($categoryJurisdictionId !== NULL) {
        $jurisdictionIds[] = $categoryJurisdictionId;
        $rootJurisdictionId = $this->resolveRootJurisdictionId($categoryJurisdictionId);
        if ($rootJurisdictionId !== NULL) {
          $jurisdictionIds[] = $rootJurisdictionId;
        }
      }
    }

    return array_values(array_unique($jurisdictionIds));
  }

  /**
   * Resolves the jurisdiction directly from the submitted entity field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being validated.
   *
   * @return int|null
   *   The submitted jurisdiction group ID, or NULL when absent.
   */
  private function resolveJurisdictionFromEntityField(ContentEntityInterface $entity): ?int {
    if (!$entity->hasField('field_jurisdiction')) {
      return NULL;
    }

    $jurisdiction = $entity->get('field_jurisdiction');
    if ($jurisdiction->isEmpty()) {
      return NULL;
    }

    $groupId = (int) $jurisdiction->target_id;
    return $groupId > 0 ? $groupId : NULL;
  }

  /**
   * Resolves jurisdiction ID from the entity's group relationship.
   *
   * Queries group_relationship storage for an existing service request
   * relationship that directly links the node to its assigned jurisdiction.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being validated.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if no relationship exists.
   */
  private function resolveJurisdictionFromGroupRelationship(ContentEntityInterface $entity): ?int {
    // New entities have no ID yet, so no group relationship can exist.
    if ($entity->isNew()) {
      return NULL;
    }

    $relationships = $this->entityTypeManager
      ->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $entity->id(),
        'type' => $this->jurisdictionGroupType() . '-group_node-service_request',
      ]);

    if (empty($relationships)) {
      return NULL;
    }

    $relationship = reset($relationships);
    $group = $relationship->getGroup();
    if (!$group) {
      return NULL;
    }
    return (int) $group->id();
  }

  /**
   * Gets the configured jurisdiction group type.
   */
  private function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Resolves jurisdiction ID from the entity's category term.
   *
   * Traverses: entity -> field_category -> term -> field_jurisdiction.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being validated.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if not determinable.
   */
  private function resolveJurisdictionFromCategory(ContentEntityInterface $entity): ?int {
    if (!$entity->hasField('field_category')) {
      return NULL;
    }

    $category = $entity->get('field_category');
    if ($category->isEmpty()) {
      return NULL;
    }

    $term = $category->entity;
    if (!$term || !$term->hasField('field_jurisdiction') || $term->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    return (int) $term->get('field_jurisdiction')->target_id;
  }

  /**
   * Resolves a jurisdiction's canonical root without depending on Group APIs.
   *
   * The markaspot_group module depends on this validation module, so injecting
   * hierarchy service here would create a module dependency cycle. Traverse
   * the same parent field defensively with a cycle guard instead.
   */
  private function resolveRootJurisdictionId(int $jurisdictionId): ?int {
    $storage = $this->entityTypeManager->getStorage('group');
    $visited = [];
    $currentId = $jurisdictionId;

    while ($currentId > 0) {
      if (isset($visited[$currentId])) {
        return NULL;
      }
      $visited[$currentId] = TRUE;

      $group = $storage->load($currentId);
      if (!$group) {
        return NULL;
      }
      if (!$group->hasField('field_parent_jurisdiction')) {
        return $currentId;
      }

      $parent = $group->get('field_parent_jurisdiction');
      if ($parent->isEmpty()) {
        return $currentId;
      }

      $parentId = (int) $parent->target_id;
      if ($parentId <= 0) {
        return NULL;
      }
      $currentId = $parentId;
    }

    return NULL;
  }

  /**
   * Loads the GeoJSON boundary from a jurisdiction group entity.
   *
   * @param int $jurisdictionId
   *   The group entity ID.
   *
   * @return \Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary|null
   *   The boundary, or NULL if not available.
   */
  private function loadJurisdictionBoundary(int $jurisdictionId): ?GeoJsonBoundary {
    $group = $this->entityTypeManager
      ->getStorage('group')
      ->load($jurisdictionId);

    if (!$group
      || !$group->hasField('field_boundary')
      || $group->get('field_boundary')->isEmpty()) {
      return NULL;
    }

    return GeoJsonBoundary::fromJson($group->get('field_boundary')->value);
  }

  /**
   * Checks coordinates against the global WKT polygon config.
   *
   * @param float $lng
   *   Longitude.
   * @param float $lat
   *   Latitude.
   *
   * @return bool
   *   TRUE if inside the WKT polygon, or TRUE if no WKT is configured.
   */
  private function checkWktBoundary(float $lng, float $lat): bool {
    $wkt = $this->configFactory->get('markaspot_validation.settings')->get('wkt');
    if (empty($wkt)) {
      return TRUE;
    }

    $coordinates = self::parseWkt($wkt);
    $polygon = new Polygon($coordinates);
    return $polygon->contain($lng, $lat);
  }

  /**
   * Parses a WKT POLYGON string into coordinate pairs.
   *
   * @param string $wkt
   *   WKT string, e.g. "POLYGON ((lng lat, lng lat, ...))".
   *
   * @return array
   *   Array of [lng, lat] coordinate pairs.
   */
  private static function parseWkt(string $wkt): array {
    // Strip "POLYGON ((" prefix and "))" suffix.
    $inner = substr($wkt, 9, -2);
    return array_map(
      fn(string $point) => array_map('floatval', explode(' ', trim($point))),
      explode(',', $inner)
    );
  }

}
