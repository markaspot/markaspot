<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai_pii_test;

use Drupal\group\Entity\GroupInterface;

/**
 * Feature resolver stub: every tenant feature is enabled.
 */
final class AllFeaturesResolver {

  /**
   * Every feature is on for every jurisdiction.
   */
  public function isEnabledEffective(string $dotPath, GroupInterface $jur, ?bool $default = NULL): bool {
    return TRUE;
  }

}
