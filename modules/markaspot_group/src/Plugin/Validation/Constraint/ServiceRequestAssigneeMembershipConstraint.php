<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures service request assignees belong to the request scope.
 *
 * @Constraint(
 *   id = "ServiceRequestAssigneeMembership",
 *   label = @Translation("Service request assignee membership", context = "Validation"),
 *   type = "field"
 * )
 */
class ServiceRequestAssigneeMembershipConstraint extends Constraint {

  /**
   * Message shown when the assignee is outside the allowed scope.
   */
  public string $message = 'The selected assignee must be a member of the assigned organisation or jurisdiction.';

}
