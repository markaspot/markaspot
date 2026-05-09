<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for SmokeCheck plugins.
 */
interface SmokeCheckInterface extends PluginInspectionInterface {

  /**
   * Executes the smoke check.
   *
   * @param array<string, mixed> $context
   *   Optional context. Standard keys: "jurisdiction" (int), "mode" (string),
   *   "run_id" (string for self-cleanup tagging in mutating checks).
   *
   * @return \Drupal\markaspot_health\SmokeCheckResult
   *   The check result.
   */
  public function run(array $context = []): SmokeCheckResult;

  /**
   * Gets the plugin label.
   *
   * @return string
   *   The label.
   */
  public function label(): string;

  /**
   * Gets the default severity.
   *
   * @return string
   *   One of: error, warning, info.
   */
  public function severity(): string;

  /**
   * Gets the catalog category from the annotation.
   *
   * @return string
   *   Category, e.g. http_sanity, drupal_internal, auth.
   */
  public function category(): string;

  /**
   * Returns TRUE when this plugin writes to the system.
   *
   * @return bool
   *   TRUE for mutating plugins (only run under --mode=full).
   */
  public function mutates(): bool;

}
