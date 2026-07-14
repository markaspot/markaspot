<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the configured service request organisation limit.
 */
class ServiceRequestSingleOrganisationConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ServiceRequestSingleOrganisationConstraintValidator.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
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
    if (!$constraint instanceof ServiceRequestSingleOrganisationConstraint
      || !$value instanceof FieldItemListInterface) {
      return;
    }

    $node = $value->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
      return;
    }

    $enabled = $this->configFactory
      ->get('markaspot_group.settings')
      ->get('single_organisation_assignment');
    if ($enabled !== TRUE || $value->count() <= 1) {
      return;
    }

    $this->context->buildViolation($constraint->message)
      ->addViolation();
  }

}
