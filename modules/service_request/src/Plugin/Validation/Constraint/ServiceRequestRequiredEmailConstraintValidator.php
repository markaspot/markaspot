<?php

declare(strict_types=1);

namespace Drupal\service_request\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates required service request email across its lifecycle.
 */
final class ServiceRequestRequiredEmailConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ServiceRequestRequiredEmailConstraintValidator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ServiceRequestRequiredEmailConstraint
      || !$value instanceof FieldItemListInterface
      || !$value->isEmpty()) {
      return;
    }

    $node = $value->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
      return;
    }

    if (!$node->isNew()) {
      $original = $this->entityTypeManager
        ->getStorage('node')
        ->loadUnchanged($node->id());
      if ($original instanceof NodeInterface
        && $original->hasField('field_e_mail')
        && $original->get('field_e_mail')->isEmpty()) {
        return;
      }
    }

    $this->context->buildViolation($constraint->message)->addViolation();
  }

}
