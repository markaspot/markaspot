<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates jurisdiction parent references.
 */
class JurisdictionParentReferenceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a JurisdictionParentReferenceConstraintValidator.
   */
  public function __construct(
    protected ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof JurisdictionParentReferenceConstraint) {
      return;
    }

    if (!$value instanceof GroupInterface || !$this->isJurisdictionGroup($value)) {
      return;
    }

    if (!$value->id() || !$value->hasField('field_parent_jurisdiction')) {
      return;
    }

    $parent_field = $value->get('field_parent_jurisdiction');
    if ($parent_field->isEmpty() || !$parent_field instanceof EntityReferenceFieldItemListInterface) {
      return;
    }

    $parents = $parent_field->referencedEntities();
    $parent = reset($parents);
    if (!$parent instanceof GroupInterface) {
      return;
    }

    if ((int) $parent->id() === (int) $value->id()) {
      $this->context->buildViolation($constraint->selfReferenceMessage)
        ->atPath('field_parent_jurisdiction')
        ->addViolation();
    }
  }

  /**
   * Checks whether a group is the configured jurisdiction bundle.
   */
  protected function isJurisdictionGroup(GroupInterface $group): bool {
    return $group->bundle() === $this->jurisdictionGroupType();
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  protected function jurisdictionGroupType(): string {
    $config = $this->configFactory?->get('markaspot_open311.settings');
    $configured = $config ? $config->get('jurisdiction_group_type') : NULL;

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
