<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures organisation groups reference a root jurisdiction.
 *
 * @Constraint(
 *   id = "OrgRootJurisdictionReference",
 *   label = @Translation("Organisation root jurisdiction reference", context = "Validation"),
 *   type = "entity:group"
 * )
 */
class OrgRootJurisdictionReferenceConstraint extends Constraint {

  /**
   * Message shown when an org points to a child jurisdiction.
   */
  public string $message = 'Organisation groups must reference a root jurisdiction, not a child jurisdiction.';

}
