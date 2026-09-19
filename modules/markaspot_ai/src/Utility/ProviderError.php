<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Utility;

/**
 * Bounded provider diagnostics and explicit parameter-rejection detection.
 */
final class ProviderError {

  /**
   * Tests decoded error metadata, never a serialized body or error type alone.
   */
  public static function rejects(array $decoded, string $parameter, bool $anthropic = FALSE): bool {
    $error = $decoded['error'] ?? [];
    if (!is_array($error)) {
      return FALSE;
    }
    $param = $error['param'] ?? NULL;
    $code = $error['code'] ?? NULL;
    if ($param !== NULL || $code !== NULL) {
      $matches = $param === $parameter || ($parameter === 'response_format' && is_string($param) && str_starts_with($param, 'response_format.'));
      return $matches && in_array($code, ['unsupported_parameter', 'unsupported_value', 'unknown_parameter'], TRUE);
    }
    if ($anthropic && ($error['type'] ?? '') !== 'invalid_request_error') {
      return FALSE;
    }
    $message = $error['message'] ?? '';
    return is_string($message)
      && preg_match('/\b' . preg_quote($parameter, '/') . '\b/i', $message)
      && preg_match('/unsupported|not supported|unknown|unrecognized|not allowed|extra fields not permitted/i', $message);
  }

  /**
   * Limits provider-derived text including any surrounding diagnostic prefix.
   */
  public static function message(string $body, int $status): string {
    $decoded = json_decode($body, TRUE);
    $error = is_array($decoded) ? ($decoded['error'] ?? NULL) : NULL;
    if ($error !== NULL) {
      $message = is_array($error) ? ($error['message'] ?? json_encode($error)) : $error;
      return mb_substr(sprintf('API error (%d): %s', $status, is_scalar($message) ? (string) $message : 'Request rejected'), 0, 300);
    }
    return mb_substr(sprintf('API returned status code %d: %s', $status, $body), 0, 300);
  }

}
