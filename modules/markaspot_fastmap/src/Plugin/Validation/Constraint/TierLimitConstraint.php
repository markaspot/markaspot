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

  public const JURISDICTION_MISMATCH_MESSAGE = 'The selected jurisdiction does not match the selected category.';

  /**
   * Violation message when total limit is reached.
   */
  public string $totalLimitMessage = 'This workspace has reached its limit of @limit reports. Please upgrade your plan to continue reporting.';

  /**
   * Violation message when monthly limit is reached.
   */
  public string $monthlyLimitMessage = 'This workspace has reached its monthly limit of @limit reports. Please upgrade your plan or wait until next month.';

  /**
   * Violation message when published-reports limit is reached.
   */
  public string $publishedLimitMessage = 'This workspace has reached its limit of @limit published reports. Unpublish existing reports or upgrade your plan.';

  /**
   * Violation message when the submitted jurisdiction conflicts with category.
   */
  public string $jurisdictionMismatchMessage = self::JURISDICTION_MISMATCH_MESSAGE;

}
