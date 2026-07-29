<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates organisation service category jurisdiction references.
 */
class OrgServiceCategoriesJurisdictionConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the organisation category jurisdiction validator.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('markaspot_group.organisation_hierarchy_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof OrgServiceCategoriesJurisdictionConstraint
      || !$value instanceof GroupInterface
      || $value->bundle() !== 'org'
      || !$value->hasField('field_jurisdiction')
      || $value->get('field_jurisdiction')->isEmpty()
      || !$value->hasField('field_service_categories')
      || $value->get('field_service_categories')->isEmpty()) {
      return;
    }

    $jurisdictionId = (int) $value->get('field_jurisdiction')->target_id;
    $rootId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
    if ($rootId === NULL) {
      return;
    }

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $invalidIds = [];
    foreach ($value->get('field_service_categories') as $item) {
      $termId = (int) $item->target_id;
      $term = $termStorage->load($termId);
      if (!$term instanceof TermInterface
        || !$term->hasField('field_jurisdiction')
        || $term->get('field_jurisdiction')->isEmpty()) {
        $invalidIds[] = $termId;
        continue;
      }

      $termJurisdictionId = (int) $term->get('field_jurisdiction')->target_id;
      if ($this->hierarchyResolver->getRootJurisdictionId($termJurisdictionId) !== $rootId) {
        $invalidIds[] = $termId;
      }
    }

    $invalidIds = array_values(array_unique($invalidIds));
    if ($invalidIds !== []) {
      $this->context->buildViolation($constraint->message, [
        '@ids' => implode(', ', $invalidIds),
      ])
        ->atPath('field_service_categories')
        ->addViolation();
    }
  }

}
