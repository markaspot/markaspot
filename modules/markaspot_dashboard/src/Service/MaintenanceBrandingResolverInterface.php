<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

/**
 * Resolves the minimal public branding used during Core maintenance mode.
 */
interface MaintenanceBrandingResolverInterface {

  /**
   * Resolves branding for a jurisdiction id or slug.
   *
   * When no identifier is supplied, a single published jurisdiction is used.
   * Multi-jurisdiction installations must supply an explicit id or slug.
   *
   * @param string|null $jurisdiction
   *   Optional jurisdiction id or slug.
   *
   * @return array{tenantName: string, logoLight: string, logoDark: string, defaultLocale: string}|null
   *   Public branding data, or NULL when no unambiguous jurisdiction exists.
   */
  public function resolve(?string $jurisdiction = NULL): ?array;

}
