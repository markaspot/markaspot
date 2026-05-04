<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures jurisdiction groups do not reference themselves as parent.
 *
 * @Constraint(
 *   id = "JurisdictionParentReference",
 *   label = @Translation("Jurisdiction parent reference", context = "Validation"),
 *   type = "entity:group"
 * )
 */
class JurisdictionParentReferenceConstraint extends Constraint {

  /**
   * Message shown when a jurisdiction points at itself as parent.
   */
  public string $selfReferenceMessage = 'A jurisdiction cannot reference itself as parent jurisdiction.';

}
