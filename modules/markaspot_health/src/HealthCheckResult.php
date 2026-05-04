<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

/**
 * Value object describing the outcome of a single health check.
 */
final readonly class HealthCheckResult {

  /**
   * Constructs a HealthCheckResult.
   *
   * @param string $pluginId
   *   The plugin ID that produced the result.
   * @param string $label
   *   The human-readable plugin label.
   * @param bool $passed
   *   TRUE if the check passed, FALSE on failure.
   * @param string $severity
   *   Severity if the check did not pass: error, warning, or info.
   * @param int $count
   *   Number of affected items detected by the check.
   * @param string $message
   *   Short human-readable message summarising the outcome.
   * @param string|null $fixHint
   *   Optional remediation hint.
   * @param string|null $fixUrl
   *   Optional remediation URL.
   * @param array<int, array<string, mixed>> $details
   *   Optional per-row examples for failed references. Empty when not
   *   applicable. Plugins should truncate to a reasonable limit and report
   *   the remainder via $detailsTruncatedCount.
   * @param int $detailsTruncatedCount
   *   Number of additional detail rows the plugin chose not to include.
   * @param int|null $tenantId
   *   Optional jurisdiction ID this finding scopes to.
   * @param string|null $pluginVersion
   *   Optional plugin schema version, surfaced for drift detection between
   *   frontend builds and the Drupal-side plugin manager.
   * @param int|null $lastRunDurationMs
   *   Optional duration in milliseconds the plugin took to run, set by the
   *   plugin manager during the run cycle.
   */
  public function __construct(
    public string $pluginId,
    public string $label,
    public bool $passed,
    public string $severity,
    public int $count,
    public string $message,
    public ?string $fixHint = NULL,
    public ?string $fixUrl = NULL,
    public array $details = [],
    public int $detailsTruncatedCount = 0,
    public ?int $tenantId = NULL,
    public ?string $pluginVersion = NULL,
    public ?int $lastRunDurationMs = NULL,
  ) {}

  /**
   * Returns the kebab-case form of the plugin id used in the public API.
   */
  public function id(): string {
    return str_replace('_', '-', $this->pluginId);
  }

  /**
   * Returns a copy of the result with the run duration filled in.
   */
  public function withDuration(int $milliseconds): self {
    return new self(
      $this->pluginId,
      $this->label,
      $this->passed,
      $this->severity,
      $this->count,
      $this->message,
      $this->fixHint,
      $this->fixUrl,
      $this->details,
      $this->detailsTruncatedCount,
      $this->tenantId,
      $this->pluginVersion,
      $milliseconds,
    );
  }

  /**
   * Converts the result to a serialisable array using the public API shape.
   *
   * Field names mirror the Pro-layer HealthCheckResult contract: kebab-case
   * "id" instead of the Drupal "plugin_id", and "details" / "tenant_id" /
   * "plugin_version" / "last_run_duration_ms" exposed at the top level.
   *
   * @return array<string, mixed>
   *   Associative array representation suitable for JSON output.
   */
  public function toArray(): array {
    return [
      'id' => $this->id(),
      'label' => $this->label,
      'passed' => $this->passed,
      'severity' => $this->severity,
      'count' => $this->count,
      'message' => $this->message,
      'fix_hint' => $this->fixHint,
      'fix_url' => $this->fixUrl,
      'details' => $this->details,
      'details_truncated_count' => $this->detailsTruncatedCount,
      'tenant_id' => $this->tenantId,
      'plugin_version' => $this->pluginVersion,
      'last_run_duration_ms' => $this->lastRunDurationMs,
    ];
  }

}
