<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\Logger;

/**
 * Redacts credentials from URLs and log message fragments.
 */
final class LogRedactor {

  /**
   * Credential-shaped query parameter names.
   */
  private const CREDENTIAL_KEY_PATTERN = 'api_key|api-key|apikey|token|access_token|access-token|key';

  /**
   * Encoded ampersands only delimit a value when another assignment follows.
   */
  private const VALUE_END_PATTERN = '&|%26(?=[A-Za-z0-9_.\~-]+(?:=|%3D))|#|\s|$';

  /**
   * Redacts credential query parameters from a URL-like string.
   */
  public function redactUrl(string $url): string {
    $redacted = preg_replace_callback(
      '~(?<prefix>[?&]|%26)(?<key>(?:' . self::CREDENTIAL_KEY_PATTERN . '))(?<separator>=|%3D)(?<value>.*?)(?=' . self::VALUE_END_PATTERN . ')~i',
      static fn(array $matches): string => $matches['prefix'] . $matches['key'] . $matches['separator'] . '[REDACTED]',
      $url,
    );

    if ($redacted === NULL) {
      return '[REDACTED]';
    }

    // Query-only and malformed URL strings may not contain a query separator.
    // The message redactor is deliberately more permissive and is the safe
    // fallback for those inputs.
    return $this->redactMessage($redacted);
  }

  /**
   * Redacts credentials embedded in an arbitrary log message.
   */
  public function redactMessage(string $message): string {
    $redacted = preg_replace_callback(
      '~(?<![A-Za-z0-9_-])(?<key>(?:' . self::CREDENTIAL_KEY_PATTERN . '))(?<separator>\s*(?:=|%3D)\s*)(?<value>.*?)(?=' . self::VALUE_END_PATTERN . ')~i',
      static fn(array $matches): string => $matches['key'] . $matches['separator'] . '[REDACTED]',
      $message,
    );

    if ($redacted === NULL) {
      return '[REDACTED]';
    }

    $redacted = preg_replace_callback(
      '~(?<prefix>\bAuthorization\s*:\s*Bearer\s+)[^\s,;]+~i',
      static fn(array $matches): string => $matches['prefix'] . '[REDACTED]',
      $redacted,
    );
    if ($redacted === NULL) {
      return '[REDACTED]';
    }

    $redacted = preg_replace_callback(
      '~(?<prefix>\bCookie\s*:\s*)[^\r\n]*~i',
      static fn(array $matches): string => $matches['prefix'] . '[REDACTED]',
      $redacted,
    );

    return $redacted ?? '[REDACTED]';
  }

}
