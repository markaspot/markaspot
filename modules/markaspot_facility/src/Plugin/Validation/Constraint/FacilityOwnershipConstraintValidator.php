<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
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
 * Resolves the node's owning jurisdiction from field_jurisdiction (the same
 * resolution FacilityManager::applyToServiceRequest() performs), loads that
 * jurisdiction's facility catalogue via FacilityManager, and rejects any
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

    // A facility tag requires a resolvable jurisdiction. Mirrors the load path
    // in FacilityManager::applyToServiceRequest(): field_jurisdiction ->
    // target_id -> group storage. Fail secure: if the jurisdiction cannot be
    // resolved, a public-writable facility id has no owning tenant to validate
    // against, so it is rejected.
    if (!$value->hasField('field_jurisdiction') || $value->get('field_jurisdiction')->isEmpty()) {
      $this->context->buildViolation($this->violationMessage($constraint))
        ->atPath('field_facility')
        ->addViolation();
      return;
    }

    $jurisdiction_item = $value->get('field_jurisdiction')->first();
    $jurisdiction_id = (int) ($jurisdiction_item->target_id ?? 0);
    if ($jurisdiction_id <= 0) {
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

    if ($this->facilityBelongsToGroup($facility_id, $group)) {
      return;
    }

    $root_jurisdiction_id = $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id);
    if ($root_jurisdiction_id !== NULL && $root_jurisdiction_id !== $jurisdiction_id) {
      $root_group = $this->entityTypeManager->getStorage('group')->load($root_jurisdiction_id);
      if ($root_group instanceof GroupInterface && $this->facilityBelongsToGroup($facility_id, $root_group)) {
        return;
      }
    }

    $this->context->buildViolation($this->violationMessage($constraint))
      ->atPath('field_facility')
      ->addViolation();
  }

  /**
   * Checks whether a facility id belongs to a jurisdiction catalogue.
   */
  private function facilityBelongsToGroup(string $facility_id, GroupInterface $group): bool {
    // Use the dashboard (full) catalogue, not the public one: an admin may
    // have deactivated a facility that an existing report legitimately
    // references. The gap we close is cross-tenant ownership, not active state,
    // so any id owned by THIS jurisdiction or its root jurisdiction passes.
    $settings = $this->facilityManager->getDashboardSettings($group);
    foreach ($settings['items'] ?? [] as $facility) {
      if (($facility['id'] ?? '') === $facility_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns the untranslated source string for the violation builder.
   */
  protected function violationMessage(FacilityOwnershipConstraint $constraint): string {
    $message = $constraint->message;
    return is_string($message) ? $message : $message->getUntranslatedString();
  }

}
