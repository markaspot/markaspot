<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that a report's jurisdiction change targets a managed jurisdiction.
 */
class ServiceRequestJurisdictionAuthzConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ServiceRequestJurisdictionAuthzConstraint) {
      return;
    }

    if (!$value instanceof EntityReferenceFieldItemListInterface || $value->isEmpty()) {
      return;
    }

    $node = $value->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
      return;
    }

    // The authorization logic touches the container (current user, storage),
    // so it lives in the module file, mirroring the assignee constraint.
    if (\_markaspot_group_service_request_jurisdiction_move_authorized($node)) {
      return;
    }

    $this->context->buildViolation($constraint->message)->addViolation();
  }

}
