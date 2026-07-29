<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\ParentTreeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates organisation parent references.
 */
class OrgParentReferenceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the organisation parent validator.
   */
  public function __construct(
    protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('markaspot_group.organisation_hierarchy_resolver'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof OrgParentReferenceConstraint) {
      return;
    }

    if (!$value instanceof GroupInterface || $value->bundle() !== 'org') {
      return;
    }

    if (!$value->hasField('field_parent_org')) {
      return;
    }

    $parent_field = $value->get('field_parent_org');
    if ($parent_field->isEmpty() || !$parent_field instanceof EntityReferenceFieldItemListInterface) {
      return;
    }

    $parents = $parent_field->referencedEntities();
    $parent = reset($parents);
    if (!$parent instanceof GroupInterface) {
      return;
    }

    if ($parent->bundle() !== 'org') {
      $this->context->buildViolation($constraint->parentTypeMessage)
        ->atPath('field_parent_org')
        ->addViolation();
      return;
    }

    $value_id = (int) $value->id();
    if ($value_id > 0 && (int) $parent->id() === $value_id) {
      $this->context->buildViolation($constraint->selfReferenceMessage)
        ->atPath('field_parent_org')
        ->addViolation();
      return;
    }

    if ($this->jurisdictionId($value) !== $this->jurisdictionId($parent)) {
      $this->context->buildViolation($constraint->jurisdictionMismatchMessage)
        ->atPath('field_parent_org')
        ->addViolation();
      return;
    }

    if ($this->createsCircularReference($value, $parent)) {
      $this->context->buildViolation($constraint->circularReferenceMessage)
        ->atPath('field_parent_org')
        ->addViolation();
    }
  }

  /**
   * Checks whether assigning parent creates or joins a circular hierarchy.
   */
  protected function createsCircularReference(GroupInterface $group, GroupInterface $parent): bool {
    $group_id = (int) $group->id();
    if ($group_id <= 0) {
      return FALSE;
    }

    $visited = [];
    $current = $parent;
    for ($depth = 0; $depth < ParentTreeResolver::MAX_HIERARCHY_DEPTH; $depth++) {
      $current_id = (int) $current->id();
      if ($current_id <= 0) {
        return FALSE;
      }
      if ($current_id === $group_id) {
        return TRUE;
      }
      if (isset($visited[$current_id])) {
        return TRUE;
      }
      $visited[$current_id] = TRUE;

      if (!$current->hasField('field_parent_org')) {
        return FALSE;
      }
      $parent_field = $current->get('field_parent_org');
      if ($parent_field->isEmpty() || !$parent_field instanceof EntityReferenceFieldItemListInterface) {
        return FALSE;
      }

      $parents = $parent_field->referencedEntities();
      $next = reset($parents);
      if (!$next instanceof GroupInterface || $next->bundle() !== 'org') {
        return FALSE;
      }

      $current = $next;
    }

    return TRUE;
  }

  /**
   * Returns the jurisdiction target ID for an organisation group.
   */
  protected function jurisdictionId(GroupInterface $group): int {
    if (!$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return 0;
    }

    $jurisdiction_id = (int) ($group->get('field_jurisdiction')->target_id ?? 0);
    if ($jurisdiction_id <= 0 || $this->hierarchyResolver === NULL) {
      return $jurisdiction_id;
    }

    return $this->hierarchyResolver->getRootJurisdictionId($jurisdiction_id)
      ?? 0;
  }

}
