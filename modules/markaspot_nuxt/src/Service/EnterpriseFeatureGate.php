<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\group\Entity\GroupInterface;

/**
 * Gates enterprise-only features behind SaaS subscription tiers.
 *
 * Field presence no longer identifies the platform: markaspot_group attaches
 * field_tier to every jurisdiction, including self-hosted installations.
 * Platform mode is therefore authoritative. Enterprise/self-hosted stacks
 * are operator-managed and always allowed.
 *
 * On the shared self-service platform, only tiers in the allowed list pass.
 * A missing or empty tier remains fail-closed, so a pending SaaS signup does
 * not get enterprise features for free.
 *
 * Lives in markaspot_nuxt rather than markaspot_fastmap: consumers such as
 * markaspot_dashboard do not depend on markaspot_fastmap, and self-hosted
 * installs commonly run without the FastMap/billing module installed at
 * all. markaspot_nuxt is the module every jurisdiction-aware feature
 * already depends on (see FeatureFlagChecker in this namespace).
 */
class EnterpriseFeatureGate {

  public function __construct(
    private readonly FeatureScopeResolver $featureScopeResolver,
  ) {}

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
   *   TRUE for enterprise/self-hosted stacks and entitled self-service tiers,
   *   FALSE otherwise.
   */
  public function isEnterpriseFeatureAllowed(?GroupInterface $jurisdiction, string $feature): bool {
    if ($jurisdiction === NULL) {
      return FALSE;
    }

    if (!$this->featureScopeResolver->isSelfServicePlatform()) {
      return TRUE;
    }

    if (!$jurisdiction->hasField('field_tier')
      || $jurisdiction->get('field_tier')->isEmpty()) {
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
