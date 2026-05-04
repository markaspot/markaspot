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
 * Validates organisation jurisdiction references.
 */
class OrgRootJurisdictionReferenceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs an OrgRootJurisdictionReferenceConstraintValidator.
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
    if (!$constraint instanceof OrgRootJurisdictionReferenceConstraint) {
      return;
    }

    if (!$value instanceof GroupInterface || $value->bundle() !== 'org') {
      return;
    }

    if (!$value->hasField('field_jurisdiction') || $value->get('field_jurisdiction')->isEmpty()) {
      return;
    }

    $jurisdiction_field = $value->get('field_jurisdiction');
    if (!$jurisdiction_field instanceof EntityReferenceFieldItemListInterface) {
      return;
    }

    $jurisdictions = $jurisdiction_field->referencedEntities();
    $jurisdiction = reset($jurisdictions);
    if (!$jurisdiction instanceof GroupInterface || !$this->isJurisdictionGroup($jurisdiction)) {
      return;
    }

    if ($jurisdiction->hasField('field_parent_jurisdiction')
      && !$jurisdiction->get('field_parent_jurisdiction')->isEmpty()) {
      $this->context->buildViolation($constraint->message)
        ->atPath('field_jurisdiction')
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
