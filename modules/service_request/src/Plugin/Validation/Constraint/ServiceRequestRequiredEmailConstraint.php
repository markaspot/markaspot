<?php

declare(strict_types=1);

namespace Drupal\service_request\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Requires email without blocking unchanged historical empty values.
 *
 * @Constraint(
 *   id = "ServiceRequestRequiredEmail",
 *   label = @Translation("Service request required email", context = "Validation"),
 *   type = "field"
 * )
 */
final class ServiceRequestRequiredEmailConstraint extends Constraint {

  /**
   * Message shown when a required email is missing.
   */
  public string $message = 'This value should not be empty.';

}
