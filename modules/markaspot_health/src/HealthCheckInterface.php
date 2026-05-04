<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for HealthCheck plugins.
 */
interface HealthCheckInterface extends PluginInspectionInterface {

  /**
   * Executes the check.
   *
   * @param array<string, mixed> $context
   *   Optional context, for example a jurisdiction ID under the key
   *   "jurisdiction".
   *
   * @return \Drupal\markaspot_health\HealthCheckResult
   *   The check result.
   */
  public function run(array $context = []): HealthCheckResult;

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

}
