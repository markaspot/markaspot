<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

/**
 * Distinguishes untouched example geography from operator configuration.
 */
final class TenantValidationDefaults {

  /**
   * Plans a one-time removal of packaged example geography on an empty site.
   */
  public static function plan(array $active, array $shipped, bool $hasContent, bool $initialized): array {
    if ($initialized) {
      return ['action' => 'preserve_initialized', 'clear' => []];
    }
    if (empty($shipped['wkt']) || !is_array($shipped['locality'] ?? NULL)) {
      throw new \RuntimeException('Shipped validation defaults are unavailable.');
    }
    if (($active['wkt'] ?? NULL) !== $shipped['wkt'] || ($active['locality'] ?? NULL) !== $shipped['locality']) {
      return ['action' => 'preserve_configured', 'clear' => []];
    }
    if ($hasContent) {
      throw new \RuntimeException('Example geography on a populated installation requires operator review.');
    }
    return ['action' => 'clear_packaged_example', 'clear' => ['wkt', 'locality']];
  }

}
