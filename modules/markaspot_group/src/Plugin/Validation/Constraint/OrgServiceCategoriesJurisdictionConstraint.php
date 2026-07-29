<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures organisation service categories belong to its jurisdiction.
 *
 * @Constraint(
 *   id = "OrgServiceCategoriesJurisdiction",
 *   label = @Translation("Organisation service category jurisdiction", context = "Validation"),
 *   type = "entity:group"
 * )
 */
class OrgServiceCategoriesJurisdictionConstraint extends Constraint {

  /**
   * Message shown when categories belong to another jurisdiction.
   */
  public string $message = 'Service categories must belong to the organisation jurisdiction. Invalid term IDs: @ids.';

}
