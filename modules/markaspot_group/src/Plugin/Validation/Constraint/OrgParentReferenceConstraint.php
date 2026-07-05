<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures organisation parent references stay inside one jurisdiction tree.
 *
 * @Constraint(
 *   id = "OrgParentReference",
 *   label = @Translation("Organisation parent reference", context = "Validation"),
 *   type = "entity:group"
 * )
 */
class OrgParentReferenceConstraint extends Constraint {

  /**
   * Message shown when an organisation points at itself as parent.
   */
  public string $selfReferenceMessage = 'An organisation cannot reference itself as parent organisation.';

  /**
   * Message shown when the parent is not an organisation group.
   */
  public string $parentTypeMessage = 'A parent organisation reference must point to an organisation group.';

  /**
   * Message shown when the parent belongs to another jurisdiction.
   */
  public string $jurisdictionMismatchMessage = 'A parent organisation must belong to the same jurisdiction as the child organisation.';

  /**
   * Message shown when the parent reference creates a cycle.
   */
  public string $circularReferenceMessage = 'An organisation parent reference cannot create a circular hierarchy.';

}
