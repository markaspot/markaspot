<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Privacy;

/**
 * Outcome of provider-based personal-name screening.
 *
 * Keeps a successful "no names found" apart from a screening that did not
 * happen: an unavailable service, a failed request or an unusable response
 * must never be read as a clean result, because the caller would then store
 * and publish the text as if names had been removed.
 */
final class NameScreeningResult {

  /**
   * Screening ran and found no personal names.
   */
  public const CLEAN = 'clean';

  /**
   * Screening ran and found personal names.
   */
  public const NAMES = 'names';

  /**
   * Screening did not produce a usable result.
   */
  public const FAILED = 'failed';

  /**
   * The screening service did not pass its health check.
   */
  public const REASON_UNAVAILABLE = 'unavailable';

  /**
   * The screening request failed (connection error, timeout, HTTP error).
   */
  public const REASON_REQUEST_FAILED = 'request_failed';

  /**
   * The screening service answered with an unusable payload.
   */
  public const REASON_MALFORMED = 'malformed';

  /**
   * Constructs a result; use the named constructors.
   *
   * @param string $status
   *   One of the status constants.
   * @param string[] $names
   *   Detected names (only for NAMES).
   * @param string|null $reason
   *   Failure reason constant (only for FAILED).
   */
  private function __construct(
    public readonly string $status,
    public readonly array $names = [],
    public readonly ?string $reason = NULL,
  ) {}

  /**
   * Screening ran and found nothing.
   */
  public static function clean(): self {
    return new self(self::CLEAN);
  }

  /**
   * Screening ran; an empty list is a clean result.
   *
   * @param string[] $names
   *   Validated personal names.
   */
  public static function withNames(array $names): self {
    return $names === [] ? self::clean() : new self(self::NAMES, array_values($names));
  }

  /**
   * Screening did not produce a usable result.
   */
  public static function failed(string $reason): self {
    return new self(self::FAILED, [], $reason);
  }

  /**
   * Whether the text was not screened.
   */
  public function isFailed(): bool {
    return $this->status === self::FAILED;
  }

}
