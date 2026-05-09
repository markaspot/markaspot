<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for SmokeCheck plugins.
 */
abstract class SmokeCheckPluginBase extends PluginBase implements SmokeCheckInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  abstract public function run(array $context = []): SmokeCheckResult;

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
   * {@inheritdoc}
   */
  public function category(): string {
    $category = $this->pluginDefinition['category'] ?? 'drupal_internal';
    return is_string($category) && $category !== '' ? $category : 'drupal_internal';
  }

  /**
   * {@inheritdoc}
   */
  public function mutates(): bool {
    return !empty($this->pluginDefinition['mutates']);
  }

  /**
   * Resolves the run mode for this invocation, defaulting to read-only.
   *
   * @param array<string, mixed> $context
   *   Run context.
   *
   * @return string
   *   One of read-only or full.
   */
  protected function mode(array $context): string {
    $mode = $context['mode'] ?? SmokeCheckResult::MODE_READ_ONLY;
    return $mode === SmokeCheckResult::MODE_FULL
      ? SmokeCheckResult::MODE_FULL
      : SmokeCheckResult::MODE_READ_ONLY;
  }

  /**
   * Gets the optional fix hint defined by the annotation.
   */
  protected function fixHint(): ?string {
    $hint = $this->pluginDefinition['fix_hint'] ?? NULL;
    return $hint === NULL ? NULL : (string) $hint;
  }

  /**
   * Gets the optional fix URL defined by the annotation.
   *
   * Same security rule as HealthCheckPluginBase::fixUrl(): only same-origin
   * relative paths beginning with "/" are accepted; everything else (absolute
   * URLs, protocol-relative, javascript:, data:, mailto:) is silently dropped.
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
   * Builds a passed result.
   */
  protected function pass(string $message, array $evidence = [], string $mode = SmokeCheckResult::MODE_READ_ONLY, ?int $tenantId = NULL): SmokeCheckResult {
    return $this->build(SmokeCheckResult::STATUS_PASS, 0, $message, $evidence, $mode, [], 0, $tenantId);
  }

  /**
   * Builds a failed result.
   *
   * @param int $count
   *   Number of affected items.
   * @param string $message
   *   Human-readable message.
   * @param array<string, mixed> $evidence
   *   Free-form evidence payload (HTTP status, exit code, response excerpt).
   * @param string $mode
   *   Run mode used.
   * @param array<int, array<string, mixed>> $details
   *   Optional per-row details.
   * @param int $detailsTruncatedCount
   *   Number of additional detail rows omitted.
   * @param int|null $tenantId
   *   Optional jurisdiction id.
   */
  protected function fail(
    int $count,
    string $message,
    array $evidence = [],
    string $mode = SmokeCheckResult::MODE_READ_ONLY,
    array $details = [],
    int $detailsTruncatedCount = 0,
    ?int $tenantId = NULL,
  ): SmokeCheckResult {
    return $this->build(SmokeCheckResult::STATUS_FAIL, $count, $message, $evidence, $mode, $details, $detailsTruncatedCount, $tenantId);
  }

  /**
   * Builds a skip result.
   *
   * Use when the check is structurally inapplicable for this invocation
   * (mutating plugin under read-only mode, missing dependency, prod safety).
   */
  protected function skip(string $message, array $evidence = [], string $mode = SmokeCheckResult::MODE_READ_ONLY, ?int $tenantId = NULL): SmokeCheckResult {
    return $this->build(SmokeCheckResult::STATUS_SKIP, 0, $message, $evidence, $mode, [], 0, $tenantId);
  }

  /**
   * Builds a warning result.
   *
   * Use when the check did not fully pass but the failure is environmental
   * (e.g. SMTP not reachable from cp1 sandbox) and not a tenant defect.
   */
  protected function warning(string $message, array $evidence = [], string $mode = SmokeCheckResult::MODE_READ_ONLY, ?int $tenantId = NULL): SmokeCheckResult {
    return $this->build(SmokeCheckResult::STATUS_WARNING, 0, $message, $evidence, $mode, [], 0, $tenantId);
  }

  /**
   * Builds a SmokeCheckResult with the plugin's annotation defaults.
   */
  private function build(
    string $status,
    int $count,
    string $message,
    array $evidence,
    string $mode,
    array $details,
    int $detailsTruncatedCount,
    ?int $tenantId,
  ): SmokeCheckResult {
    return new SmokeCheckResult(
      $this->getPluginId(),
      $this->label(),
      $status,
      $this->severity(),
      $this->category(),
      $this->mutates(),
      $mode,
      $count,
      $message,
      $evidence,
      $this->fixHint(),
      $this->fixUrl(),
      $details,
      $detailsTruncatedCount,
      $tenantId,
    );
  }

}
