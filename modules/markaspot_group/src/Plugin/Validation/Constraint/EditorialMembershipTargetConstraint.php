<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Keeps editors from adding foreign accounts or peers to organisations.
 *
 * @Constraint(
 *   id = "EditorialMembershipTarget",
 *   label = @Translation("Editorial membership target", context = "Validation"),
 *   type = "entity:group_relationship"
 * )
 */
class EditorialMembershipTargetConstraint extends Constraint {

  /**
   * Message shown for an account outside the editor's reach.
   */
  public string $message = 'Editors may only add members of their own tenant who are no editors or tenant administrators.';

}
