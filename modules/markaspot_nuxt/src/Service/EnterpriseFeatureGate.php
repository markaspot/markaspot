<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\group\Entity\GroupInterface;

/**
 * Gates enterprise-only features behind SaaS subscription tiers.
 *
 * Self-hosted / on-premise jurisdictions carry no field_tier field at all
 * (the field is only attached to the 'jur' bundle when markaspot_fastmap is
 * installed) and are always allowed: they are operator-managed installs,
 * not tier-limited SaaS workspaces. This mirrors the "no field_tier means
 * no fastmap ... (on-premise)" convention already established by
 * \Drupal\markaspot_fastmap\Plugin\Validation\Constraint\TierLimitConstraintValidator
 * and \Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController::canUseOperationsDashboard().
 *
 * SaaS workspaces (field_tier present, values free/starter/pro/heart per
 * markaspot_fastmap/config/install/field.storage.group.field_tier.yml) only
 * pass for tiers in the allowed list. An attached-but-empty field_tier
 * (workspace has not completed Stripe checkout) is treated as NOT allowed,
 * matching canUseOperationsDashboard's fail-closed behavior — a pending
 * SaaS signup does not get enterprise features for free.
 *
 * Lives in markaspot_nuxt rather than markaspot_fastmap: consumers such as
 * markaspot_dashboard do not depend on markaspot_fastmap, and self-hosted
 * installs commonly run without the FastMap/billing module installed at
 * all. markaspot_nuxt is the module every jurisdiction-aware feature
 * already depends on (see FeatureFlagChecker in this namespace).
 */
class EnterpriseFeatureGate {

  /**
   * SaaS tiers that unlock enterprise-gated features.
   *
   * 'heart' is the current top tier (free/starter/pro/heart, see
   * field.storage.group.field_tier.yml). Add a value here (e.g. a future
   * 'enterprise' tier) when a new top tier is introduced — no call site
   * needs to change.
   */
  private const ENTERPRISE_TIERS = ['heart'];

  /**
   * Checks whether a jurisdiction may use an enterprise-gated feature.
   *
   * @param \Drupal\group\Entity\GroupInterface|null $jurisdiction
   *   The jurisdiction group to check, or NULL when no jurisdiction context
   *   could be resolved. Fails closed (denied).
   * @param string $feature
   *   Machine name of the gated feature (e.g. 'mail_text_editor'). All
   *   enterprise features currently share the same tier list; the
   *   parameter is threaded through getAllowedTiers() so a future
   *   per-feature tier map can be introduced without changing this
   *   method's signature or any call site.
   *
   * @return bool
   *   TRUE for self-hosted jurisdictions (no field_tier field) and SaaS
   *   jurisdictions on an allowed tier, FALSE otherwise.
   */
  public function isEnterpriseFeatureAllowed(?GroupInterface $jurisdiction, string $feature): bool {
    if ($jurisdiction === NULL) {
      return FALSE;
    }

    if (!$jurisdiction->hasField('field_tier')) {
      return TRUE;
    }

    if ($jurisdiction->get('field_tier')->isEmpty()) {
      return FALSE;
    }

    $tier = (string) $jurisdiction->get('field_tier')->value;
    return in_array($tier, $this->getAllowedTiers($feature), TRUE);
  }

  /**
   * Returns the SaaS tiers that unlock the given enterprise feature.
   *
   * A single shared list today. Kept as its own method (rather than
   * inlining self::ENTERPRISE_TIERS in isEnterpriseFeatureAllowed()) so a
   * future feature requiring a different tier cutoff only needs a match
   * arm here.
   *
   * @param string $feature
   *   Machine name of the gated feature.
   *
   * @return string[]
   *   Allowed tier machine names.
   */
  protected function getAllowedTiers(string $feature): array {
    return self::ENTERPRISE_TIERS;
  }

}
