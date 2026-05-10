<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for HealthCheck plugins.
 */
abstract class HealthCheckPluginBase extends PluginBase implements HealthCheckInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  abstract public function run(array $context = []): HealthCheckResult;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $label = $this->pluginDefinition['label'] ?? $this->getPluginId();
    return (string) $label;
  }

  /**
   * {@inheritdoc}
   */
  public function severity(): string {
    $severity = $this->pluginDefinition['severity'] ?? 'warning';
    return in_array($severity, ['error', 'warning', 'info'], TRUE) ? $severity : 'warning';
  }

  /**
   * Gets the optional fix hint defined by the annotation.
   *
   * @return string|null
   *   Hint or NULL.
   */
  protected function fixHint(): ?string {
    $hint = $this->pluginDefinition['fix_hint'] ?? NULL;
    return $hint === NULL ? NULL : (string) $hint;
  }

  /**
   * Gets the optional fix URL defined by the annotation.
   *
   * Only same-origin admin paths beginning with "/" are accepted. Anything
   * else (absolute URLs, protocol-relative, javascript:, data:, mailto:) is
   * silently dropped to prevent open-redirect or XSS payloads delivered via
   * a third-party plugin annotation.
   *
   * @return string|null
   *   Validated relative URL, or NULL.
   */
  protected function fixUrl(): ?string {
    $url = $this->pluginDefinition['fix_url'] ?? NULL;
    if ($url === NULL) {
      return NULL;
    }
    $url = (string) $url;
    if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
      return NULL;
    }
    return $url;
  }

  /**
   * Builds a passed result for this plugin.
   *
   * @param string $message
   *   Human-readable message.
   * @param int|null $tenantId
   *   Optional jurisdiction id this finding scopes to.
   *
   * @return \Drupal\markaspot_health\HealthCheckResult
   *   Result with passed = TRUE.
   */
  protected function pass(string $message, ?int $tenantId = NULL): HealthCheckResult {
    return new HealthCheckResult(
      $this->getPluginId(),
      $this->label(),
      TRUE,
      $this->severity(),
      0,
      $message,
      $this->fixHint(),
      $this->fixUrl(),
      [],
      0,
      $tenantId,
    );
  }

  /**
   * Builds a failed result for this plugin.
   *
   * @param int $count
   *   Number of affected items.
   * @param string $message
   *   Human-readable message.
   * @param array<int, array<string, mixed>> $details
   *   Optional per-row examples; pass an empty array when not applicable.
   * @param int $detailsTruncatedCount
   *   Number of additional rows omitted from $details.
   * @param int|null $tenantId
   *   Optional jurisdiction id this finding scopes to.
   *
   * @return \Drupal\markaspot_health\HealthCheckResult
   *   Result with passed = FALSE.
   */
  protected function fail(
    int $count,
    string $message,
    array $details = [],
    int $detailsTruncatedCount = 0,
    ?int $tenantId = NULL,
  ): HealthCheckResult {
    return $this->failWithSeverity(
      $this->severity(),
      $count,
      $message,
      $details,
      $detailsTruncatedCount,
      $tenantId,
    );
  }

  /**
   * Builds a failed result with an explicit severity override.
   *
   * Use when the annotation default does not capture the runtime escalation
   * — e.g. a check that is `warning` for staging tenants but `error` for
   * `field_visibility=public` ones. Going through this helper keeps the
   * fix_hint / fix_url validation that the base class performs in fixHint()
   * / fixUrl(), which a direct HealthCheckResult constructor call would
   * bypass.
   *
   * @param string $severity
   *   One of error, warning, info. Invalid values fall back to the
   *   annotation severity.
   * @param int $count
   *   Number of affected items.
   * @param string $message
   *   Human-readable message.
   * @param array<int, array<string, mixed>> $details
   *   Optional per-row examples.
   * @param int $detailsTruncatedCount
   *   Number of additional rows omitted from $details.
   * @param int|null $tenantId
   *   Optional jurisdiction id this finding scopes to.
   *
   * @return \Drupal\markaspot_health\HealthCheckResult
   *   Result with passed = FALSE and the requested severity.
   */
  protected function failWithSeverity(
    string $severity,
    int $count,
    string $message,
    array $details = [],
    int $detailsTruncatedCount = 0,
    ?int $tenantId = NULL,
  ): HealthCheckResult {
    $effective = in_array($severity, ['error', 'warning', 'info'], TRUE)
      ? $severity
      : $this->severity();
    return new HealthCheckResult(
      $this->getPluginId(),
      $this->label(),
      FALSE,
      $effective,
      $count,
      $message,
      $this->fixHint(),
      $this->fixUrl(),
      $details,
      $detailsTruncatedCount,
      $tenantId,
    );
  }

}
