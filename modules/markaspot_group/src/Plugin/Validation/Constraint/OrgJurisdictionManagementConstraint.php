<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures organisation jurisdiction writes stay in a managed tenant.
 *
 * @Constraint(
 *   id = "OrgJurisdictionManagement",
 *   label = @Translation("Organisation jurisdiction management", context = "Validation"),
 *   type = "entity:group"
 * )
 */
class OrgJurisdictionManagementConstraint extends Constraint {

  /**
   * Message shown for a jurisdiction outside the acting account's scope.
   */
  public string $message = 'You may only manage organisations in a jurisdiction where you are a jurisdiction administrator.';

}
