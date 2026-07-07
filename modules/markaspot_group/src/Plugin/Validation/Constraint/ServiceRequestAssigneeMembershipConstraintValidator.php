<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates service request assignee membership.
 */
class ServiceRequestAssigneeMembershipConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ServiceRequestAssigneeMembershipConstraint) {
      return;
    }

    if (!$value instanceof EntityReferenceFieldItemListInterface || $value->isEmpty()) {
      return;
    }

    $node = $value->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
      return;
    }

    $assignees = $value->referencedEntities();
    $assignee = reset($assignees);
    if (!$assignee instanceof UserInterface) {
      return;
    }

    if (\_markaspot_group_service_request_assignee_is_valid($node, $assignee)) {
      return;
    }

    $this->context->buildViolation($constraint->message)
      ->addViolation();
  }

}
