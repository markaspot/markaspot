<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Restricts service requests to one organisation when configured.
 *
 * @Constraint(
 *   id = "ServiceRequestSingleOrganisation",
 *   label = @Translation("Service request single organisation", context = "Validation"),
 *   type = "field"
 * )
 */
class ServiceRequestSingleOrganisationConstraint extends Constraint {

  /**
   * Message shown when more than one organisation is assigned.
   */
  public string $message = 'Only one organisation can be assigned to a service request.';

}
