<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

/**
 * Resolves the workspace billing lifecycle state.
 *
 * Single source of truth for the billing/tier state machine used by both
 * tenant-self views (BillingController) and operator-admin views
 * (BillingAdminController). Keeps the two surfaces in lockstep — Phase 3 of
 * the compliance marathon requires they never diverge.
 *
 * The five recognised states are:
 *  - 'demo': expiry set AND no Stripe subscription. Workspace is in the
 *    onboarding grace window. Tier is typically NULL.
 *  - 'pending_checkout': stripe_customer_id present BUT no subscription
 *    (user clicked "Start checkout" but did not finish payment).
 *  - 'free_permanent': no expiry AND has subscription AND tier='free'
 *    (admin-granted permanent free plan, edge case).
 *  - 'paid': no expiry AND tier in ['starter', 'pro', 'heart'].
 *  - 'unknown': fallback when none of the above apply. Used as a signal
 *    for the frontend to surface a "needs operator attention" badge
 *    rather than silently misrender.
 */
class BillingStateResolver {

  /**
   * Tiers that imply an active paid subscription.
   *
   * @var string[]
   */
  private const PAID_TIERS = ['starter', 'pro', 'heart'];

  /**
   * Resolves the effective workspace lifecycle state.
   *
   * @param string|null $tier
   *   The current field_tier value (free|starter|pro|heart|NULL).
   * @param string|null $stripeCustomerId
   *   The current field_stripe_customer_id value.
   * @param string|null $stripeSubscriptionId
   *   The current field_stripe_subscription_id value.
   * @param int|null $expiryDate
   *   The current field_expiry_date timestamp (or NULL when not in demo).
   *
   * @return string
   *   One of: 'demo', 'pending_checkout', 'free_permanent', 'paid',
   *   'unknown'. See the class docblock for semantics.
   */
  public function resolve(
    ?string $tier,
    ?string $stripeCustomerId,
    ?string $stripeSubscriptionId,
    ?int $expiryDate,
  ): string {
    $hasExpiry = $expiryDate !== NULL && $expiryDate > 0;
    $hasCustomer = $stripeCustomerId !== NULL && trim($stripeCustomerId) !== '';
    $hasSubscription = $stripeSubscriptionId !== NULL && trim($stripeSubscriptionId) !== '';

    if ($hasExpiry && !$hasSubscription) {
      return 'demo';
    }

    if ($hasCustomer && !$hasSubscription) {
      return 'pending_checkout';
    }

    if (!$hasExpiry && in_array($tier, self::PAID_TIERS, TRUE)) {
      return 'paid';
    }

    if (!$hasExpiry && $hasSubscription && $tier === 'free') {
      return 'free_permanent';
    }

    return 'unknown';
  }

}
