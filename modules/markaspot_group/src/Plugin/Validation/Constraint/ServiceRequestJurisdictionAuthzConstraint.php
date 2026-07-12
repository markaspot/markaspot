<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures a report is only re-homed into a jurisdiction the user manages.
 *
 * Complements the markaspot_group_node_presave() guard: this constraint runs
 * on validated write paths (JSON:API, REST, forms) and yields a clean 422,
 * while the presave guard remains the universal backstop for programmatic
 * saves that skip validation.
 *
 * @Constraint(
 *   id = "ServiceRequestJurisdictionAuthz",
 *   label = @Translation("Service request jurisdiction authorization", context = "Validation"),
 *   type = "field"
 * )
 */
class ServiceRequestJurisdictionAuthzConstraint extends Constraint {

  /**
   * Message shown when the user may not move the report into the jurisdiction.
   */
  public string $message = 'You are not authorized to move this report into the selected jurisdiction.';

}
