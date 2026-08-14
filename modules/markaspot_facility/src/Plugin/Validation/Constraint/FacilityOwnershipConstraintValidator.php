<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that a service request's facility belongs to its jurisdiction.
 *
 * Resolves the node's owning jurisdiction from field_jurisdiction or, for
 * anonymous creates, from the selected category. It then loads that
 * jurisdiction's facility catalogue via FacilityManager and rejects any
 * non-empty field_facility value whose machine key is not present in the
 * jurisdiction's items[]. This closes the cross-tenant injection gap where an
 * anonymous submitter could tag a report with another tenant's facility id.
 */
final class FacilityOwnershipConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FacilityManager $facilityManager,
    protected readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('markaspot_facility.manager'),
      $container->get('markaspot_group.hierarchy_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FacilityOwnershipConstraint) {
      return;
    }

    if (!$value instanceof NodeInterface || $value->bundle() !== 'service_request') {
      return;
    }

    // No facility tag means nothing to validate. Empty is always allowed.
    if (!$value->hasField('field_facility') || $value->get('field_facility')->isEmpty()) {
      return;
    }
    $facility_id = trim((string) $value->get('field_facility')->value);
    if ($facility_id === '') {
      return;
    }
    $public_catalogue = $this->requiresPublicCatalogue($value, $facility_id);

    // Anonymous JSON:API creates intentionally omit field_jurisdiction. Match
    // the boundary validator's safe fallback and derive the tenant from the
    // selected category's server-side field_jurisdiction reference. Fail
    // secure when neither source resolves.
    $jurisdiction_id = $this->resolveJurisdictionId(
      $value,
      $facility_id,
      $public_catalogue,
    );
    if ($jurisdiction_id === NULL) {
      $this->context->buildViolation($this->violationMessage($constraint))
        ->atPath('field_facility')
        ->addViolation();
      return;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($jurisdiction_id);
    if (!$group instanceof GroupInterface) {
      $this->context->buildViolation($this->violationMessage($constraint))
        ->atPath('field_facility')
        ->addViolation();
      return;
    }

    if ($this->facilityBelongsToGroup($facility_id, $group, $public_catalogue)) {
      return;
    }

    $root_jurisdiction_id = $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id);
    if ($root_jurisdiction_id !== NULL && $root_jurisdiction_id !== $jurisdiction_id) {
      $root_group = $this->entityTypeManager->getStorage('group')->load($root_jurisdiction_id);
      if ($root_group instanceof GroupInterface
        && $this->facilityBelongsToGroup($facility_id, $root_group, $public_catalogue)) {
        return;
      }
    }

    // A child-owned facility may be routed to a more specific nested child.
    // Resolve the unique catalogue owner again so later requests and reloaded
    // nodes do not depend on FacilityManager's request-local cache.
    $owner_id = $this->facilityManager->resolveFacilityOwnerFromCategory(
      $value,
      $facility_id,
      $public_catalogue,
    );
    if ($owner_id !== NULL
      && $root_jurisdiction_id !== NULL
      && $this->facilityManager->facilityOwnerMatchesJurisdiction(
        $owner_id,
        $jurisdiction_id,
        $root_jurisdiction_id,
      )) {
      $owner_group = $this->entityTypeManager->getStorage('group')->load($owner_id);
      if ($owner_group instanceof GroupInterface
        && $this->facilityBelongsToGroup($facility_id, $owner_group, $public_catalogue)) {
        return;
      }
    }

    $this->context->buildViolation($this->violationMessage($constraint))
      ->atPath('field_facility')
      ->addViolation();
  }

  /**
   * Resolves the jurisdiction from the node or its selected category.
   */
  private function resolveJurisdictionId(
    NodeInterface $node,
    string $facility_id,
    bool $public_catalogue,
  ): ?int {
    $category_jurisdiction_id = $this->resolveCategoryJurisdictionId($node);
    if (!$node->hasField('field_jurisdiction') || $node->get('field_jurisdiction')->isEmpty()) {
      return $category_jurisdiction_id === NULL
        ? NULL
        : $this->facilityManager->resolveFacilityOwnerFromCategory(
          $node,
          $facility_id,
          $public_catalogue,
        );
    }

    $jurisdiction_ids = [];
    foreach ($node->get('field_jurisdiction')->getValue() as $item) {
      $jurisdiction_id = (int) ($item['target_id'] ?? 0);
      if ($jurisdiction_id <= 0) {
        return NULL;
      }
      $jurisdiction_ids[] = $jurisdiction_id;
    }
    // A service request has exactly one reporting jurisdiction. Accepting
    // several same-root children would still publish the report into every
    // referenced child workspace even though only the first value drives the
    // remaining save pipeline.
    if (count($jurisdiction_ids) !== 1) {
      return NULL;
    }

    // field_jurisdiction is unlimited-cardinality. Every submitted value must
    // belong to the same canonical tenant tree, otherwise a caller could hide
    // a foreign jurisdiction behind the first valid value.
    $root_ids = [];
    foreach ($jurisdiction_ids as $jurisdiction_id) {
      $root_id = $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id);
      if ($root_id === NULL) {
        return NULL;
      }
      $root_ids[] = $root_id;
    }
    $root_ids = array_values(array_unique($root_ids));
    if (count($root_ids) !== 1) {
      return NULL;
    }

    // When a category is present, its server-side tenant ownership must agree
    // with the submitted jurisdiction tree. This prevents a direct foreign
    // jurisdiction from overriding the category-derived create scope.
    if ($category_jurisdiction_id !== NULL) {
      $category_root_id = $this->hierarchyResolver
        ->getRootJurisdictionId($category_jurisdiction_id);
      if ($category_root_id === NULL || $category_root_id !== $root_ids[0]) {
        return NULL;
      }
    }

    return $jurisdiction_ids[0];
  }

  /**
   * Resolves the selected category's owning jurisdiction.
   */
  private function resolveCategoryJurisdictionId(NodeInterface $node): ?int {
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return NULL;
    }

    $category = $node->get('field_category')->entity;
    if (!$category instanceof ContentEntityInterface
      || !$category->hasField('field_jurisdiction')
      || $category->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $jurisdiction_id = (int) $category->get('field_jurisdiction')->target_id;
    return $jurisdiction_id > 0 ? $jurisdiction_id : NULL;
  }

  /**
   * Checks whether a facility id belongs to a jurisdiction catalogue.
   */
  private function facilityBelongsToGroup(
    string $facility_id,
    GroupInterface $group,
    bool $public_catalogue,
  ): bool {
    // New citizen intake may only select active facilities exposed by the
    // public catalogue. Existing reports retain inactive historical
    // associations so unrelated edits do not destroy stored data.
    $settings = $public_catalogue
      ? $this->facilityManager->getPublicSettings($group)
      : $this->facilityManager->getDashboardSettings($group);
    if ($public_catalogue
      && (empty($settings['enabled']) || ($settings['mode'] ?? 'disabled') === 'disabled')) {
      return FALSE;
    }
    foreach ($settings['items'] ?? [] as $facility) {
      if (($facility['id'] ?? '') === $facility_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns whether this write must use the active public catalogue.
   */
  private function requiresPublicCatalogue(NodeInterface $node, string $facility_id): bool {
    if ($node->isNew()) {
      return TRUE;
    }
    $original = $this->loadPersistedOriginal($node);
    if (!$original instanceof NodeInterface
      || !$original->hasField('field_facility')
      || $original->get('field_facility')->isEmpty()
      || trim((string) $original->get('field_facility')->value) !== $facility_id) {
      return TRUE;
    }

    $current_jurisdiction = $node->hasField('field_jurisdiction')
      && !$node->get('field_jurisdiction')->isEmpty()
      ? (int) ($node->get('field_jurisdiction')->first()?->target_id ?? 0)
      : 0;
    $original_jurisdiction = $original->hasField('field_jurisdiction')
      && !$original->get('field_jurisdiction')->isEmpty()
      ? (int) ($original->get('field_jurisdiction')->first()?->target_id ?? 0)
      : 0;
    return $current_jurisdiction !== $original_jurisdiction;
  }

  /**
   * Loads the persisted entity state available during validation.
   *
   * Drupal only populates ContentEntityBase::original during presave, while
   * Open311 validates a loaded node before calling save(). Use loadUnchanged()
   * so an unrelated update can retain a historical inactive facility without
   * weakening validation for a newly assigned facility or jurisdiction.
   */
  private function loadPersistedOriginal(NodeInterface $node): ?NodeInterface {
    $original = $node->getOriginal();
    if ($original instanceof NodeInterface) {
      return $original;
    }

    $node_id = $node->id();
    if ($node_id === NULL) {
      return NULL;
    }
    $persisted = $this->entityTypeManager
      ->getStorage($node->getEntityTypeId())
      ->loadUnchanged($node_id);
    return $persisted instanceof NodeInterface ? $persisted : NULL;
  }

  /**
   * Returns the untranslated source string for the violation builder.
   */
  protected function violationMessage(FacilityOwnershipConstraint $constraint): string {
    $message = $constraint->message;
    return is_string($message) ? $message : $message->getUntranslatedString();
  }

}
