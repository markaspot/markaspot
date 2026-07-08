<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures assigned service request teams belong to the request jurisdiction.
 *
 * @Constraint(
 *   id = "ServiceRequestAssignedTeamJurisdiction",
 *   label = @Translation("Service request assigned team jurisdiction", context = "Validation"),
 *   type = "field"
 * )
 */
class ServiceRequestAssignedTeamJurisdictionConstraint extends Constraint {

  /**
   * Message shown when the team is outside the request jurisdiction.
   */
  public string $message = 'The selected team must belong to the service request jurisdiction.';

}
