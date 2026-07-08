<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates assigned service request team jurisdiction scope.
 */
class ServiceRequestAssignedTeamJurisdictionConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ServiceRequestAssignedTeamJurisdictionConstraint) {
      return;
    }

    if (!$value instanceof EntityReferenceFieldItemListInterface || $value->isEmpty()) {
      return;
    }

    $node = $value->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
      return;
    }

    $teams = $value->referencedEntities();
    $team = reset($teams);
    if (!$team instanceof GroupInterface) {
      $this->context->buildViolation($constraint->message)
        ->addViolation();
      return;
    }

    if (\_markaspot_group_service_request_team_is_valid($node, $team)) {
      return;
    }

    $this->context->buildViolation($constraint->message)
      ->addViolation();
  }

}
