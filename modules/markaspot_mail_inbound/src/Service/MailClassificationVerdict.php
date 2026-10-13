<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

/**
 * A validated text-classification verdict for an inbound mail.
 *
 * The provider is asked for a strict JSON schema, but adapters and
 * response-format modes do not all enforce it, so the result is untrusted
 * until it passes this local validation. Only a verdict built here may feed
 * the automatic-promotion gate; anything malformed is rejected as a whole
 * instead of being coerced (a cast would turn a missing verdict or the string
 * "false" into TRUE and clamp an inflated confidence to 1.0).
 *
 * Model confidence and prompt-injection resistance stay residual risks: a
 * valid verdict is a well-formed opinion of the model, not an authorization.
 */
final class MailClassificationVerdict {

  /**
   * Builds a verdict from already validated values.
   *
   * @param bool $isReport
   *   Whether the model judged the mail to be a citizen report.
   * @param int $categoryTid
   *   The suggested service_category term ID.
   * @param float $confidence
   *   The model confidence in [0, 1].
   * @param string|null $address
   *   The extracted address, if any.
   * @param string|null $summary
   *   The neutral problem summary, if any.
   */
  private function __construct(
    public readonly bool $isReport,
    public readonly int $categoryTid,
    public readonly float $confidence,
    public readonly ?string $address,
    public readonly ?string $summary,
  ) {}

  /**
   * Validates decoded provider data.
   *
   * @param mixed $data
   *   The decoded JSON content of the provider answer.
   *
   * @return self|string
   *   The verdict, or a short reason code when the data is invalid. The code
   *   never contains provider or mail text, so it is safe to log.
   */
  public static function fromProviderData(mixed $data): self|string {
    if (!is_array($data)) {
      return 'not_an_object';
    }
    // A verdict must be present and a real boolean: missing, NULL, "false",
    // 0/1 and arrays are all rejected rather than interpreted.
    if (!array_key_exists('is_report', $data) || !is_bool($data['is_report'])) {
      return 'invalid_is_report';
    }
    $tid = $data['category_tid'] ?? NULL;
    if (!is_int($tid) || $tid <= 0) {
      return 'invalid_category_tid';
    }
    $confidence = $data['confidence'] ?? NULL;
    if (!is_int($confidence) && !is_float($confidence)) {
      return 'invalid_confidence';
    }
    $confidence = (float) $confidence;
    if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
      return 'invalid_confidence';
    }
    foreach (['address', 'summary'] as $key) {
      if (array_key_exists($key, $data) && $data[$key] !== NULL && !is_string($data[$key])) {
        return 'invalid_' . $key;
      }
    }

    return new self(
      $data['is_report'],
      $tid,
      $confidence,
      $data['address'] ?? NULL,
      $data['summary'] ?? NULL,
    );
  }

  /**
   * The confidence the promotion gate may use.
   *
   * @return float|null
   *   The confidence for a report verdict; NULL for a non-report verdict, which
   *   the queue worker never promotes.
   */
  public function promotionConfidence(): ?float {
    return $this->isReport ? $this->confidence : NULL;
  }

}
