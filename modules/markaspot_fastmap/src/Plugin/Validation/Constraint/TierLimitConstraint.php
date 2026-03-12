<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Enforces workspace tier limits on service request creation.
 *
 * @Constraint(
 *   id = "TierLimit",
 *   label = @Translation("Tier Limit", context = "Validation"),
 *   type = "entity:node"
 * )
 */
class TierLimitConstraint extends Constraint {

  /**
   * Violation message when total limit is reached.
   */
  public string $totalLimitMessage = 'This workspace has reached its limit of @limit reports. Please upgrade your plan to continue reporting.';

  /**
   * Violation message when monthly limit is reached.
   */
  public string $monthlyLimitMessage = 'This workspace has reached its monthly limit of @limit reports. Please upgrade your plan or wait until next month.';

}
