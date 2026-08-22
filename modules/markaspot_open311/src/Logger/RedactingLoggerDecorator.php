<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\Logger;

use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Redacts credential-bearing context before forwarding a log entry.
 */
final class RedactingLoggerDecorator implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * Context keys that contain URL strings.
   */
  private const URL_CONTEXT_KEYS = [
    'request_uri',
    'referer',
  ];

  /**
   * Constructs a redacting logger decorator.
   */
  public function __construct(
    private readonly LoggerInterface $inner,
    private readonly LogRedactor $redactor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    foreach (self::URL_CONTEXT_KEYS as $key) {
      if (!isset($context[$key])
        || (!is_string($context[$key]) && !$context[$key] instanceof \Stringable)) {
        continue;
      }

      $value = (string) $context[$key];
      $redactedValue = $this->redactor->redactUrl($value);
      if (is_string($context[$key]) || $redactedValue !== $value) {
        $context[$key] = $redactedValue;
      }
    }

    foreach ($context as $key => $contextValue) {
      if (in_array($key, self::URL_CONTEXT_KEYS, TRUE)
        || (!is_string($contextValue) && !$contextValue instanceof \Stringable)) {
        continue;
      }

      $value = (string) $contextValue;
      $redactedValue = $this->redactor->redactMessage($value);
      if (is_string($contextValue) || $redactedValue !== $value) {
        $context[$key] = $redactedValue;
      }
    }

    if (is_string($message)) {
      $message = $this->redactor->redactMessage($message);
    }
    else {
      $stringMessage = (string) $message;
      $redactedMessage = $this->redactor->redactMessage($stringMessage);
      if ($redactedMessage !== $stringMessage) {
        $message = $redactedMessage;
      }
    }

    $this->inner->log($level, $message, $context);
  }

}
