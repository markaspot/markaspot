<?php

declare(strict_types=1);

namespace Drupal\markaspot_health;

/**
 * Value object describing the outcome of a single smoke check.
 *
 * Four-state status (vs. HealthCheckResult's binary passed flag) is required
 * because smoke checks have a third + fourth meaningful outcome: skip (the
 * check was excluded by --mode or --category) and warning (e.g. mailpit
 * unreachable on cp1 prod is expected, not a failure). Carrying mode and
 * evidence at the top level lets external consumers (release-deploy, CI)
 * reason about the run without re-deriving them.
 */
final readonly class SmokeCheckResult {

  public const STATUS_PASS = 'pass';
  public const STATUS_FAIL = 'fail';
  public const STATUS_SKIP = 'skip';
  public const STATUS_WARNING = 'warning';

  public const MODE_READ_ONLY = 'read-only';
  public const MODE_FULL = 'full';

  /**
   * Constructs a SmokeCheckResult.
   *
   * @param string $pluginId
   *   The plugin ID that produced the result.
   * @param string $label
   *   The human-readable plugin label.
   * @param string $status
   *   One of pass, fail, skip, warning.
   * @param string $severity
   *   Severity of a non-pass outcome: error, warning, info.
   * @param string $category
   *   Catalog category from the plugin annotation.
   * @param bool $mutates
   *   Whether the plugin declared itself as mutating.
   * @param string $mode
   *   Run mode used: read-only or full.
   * @param int $count
   *   Number of affected items detected by the check.
   * @param string $message
   *   Short human-readable message summarising the outcome.
   * @param array<string, mixed> $evidence
   *   Free-form structured payload (HTTP status, exit code, response excerpt).
   *   Smoke checks use this to capture the network/process trace for triage.
   * @param string|null $fixHint
   *   Optional remediation hint.
   * @param string|null $fixUrl
   *   Optional remediation URL.
   * @param array<int, array<string, mixed>> $details
   *   Optional per-row examples for failed items. Empty when not applicable.
   * @param int $detailsTruncatedCount
   *   Number of additional detail rows the plugin chose not to include.
   * @param int|null $tenantId
   *   Optional jurisdiction ID this finding scopes to.
   * @param int|null $lastRunDurationMs
   *   Optional duration in milliseconds, set by the plugin manager.
   */
  public function __construct(
    public string $pluginId,
    public string $label,
    public string $status,
    public string $severity,
    public string $category,
    public bool $mutates,
    public string $mode,
    public int $count,
    public string $message,
    public array $evidence = [],
    public ?string $fixHint = NULL,
    public ?string $fixUrl = NULL,
    public array $details = [],
    public int $detailsTruncatedCount = 0,
    public ?int $tenantId = NULL,
    public ?int $lastRunDurationMs = NULL,
  ) {}

  /**
   * Returns TRUE when the status indicates a successful pass.
   */
  public function passed(): bool {
    return $this->status === self::STATUS_PASS;
  }

  /**
   * Returns TRUE when the status is fail (counts toward exit-non-zero).
   */
  public function failed(): bool {
    return $this->status === self::STATUS_FAIL;
  }

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
      $this->status,
      $this->severity,
      $this->category,
      $this->mutates,
      $this->mode,
      $this->count,
      $this->message,
      $this->evidence,
      $this->fixHint,
      $this->fixUrl,
      $this->details,
      $this->detailsTruncatedCount,
      $this->tenantId,
      $milliseconds,
    );
  }

  /**
   * Converts the result to a serialisable array using the public API shape.
   *
   * @return array<string, mixed>
   *   Associative array suitable for JSON output.
   */
  public function toArray(): array {
    return [
      'id' => $this->id(),
      'label' => $this->label,
      'status' => $this->status,
      'severity' => $this->severity,
      'category' => $this->category,
      'mutates' => $this->mutates,
      'mode' => $this->mode,
      'count' => $this->count,
      'message' => $this->message,
      'evidence' => $this->evidence,
      'fix_hint' => $this->fixHint,
      'fix_url' => $this->fixUrl,
      'details' => $this->details,
      'details_truncated_count' => $this->detailsTruncatedCount,
      'tenant_id' => $this->tenantId,
      'last_run_duration_ms' => $this->lastRunDurationMs,
    ];
  }

}
