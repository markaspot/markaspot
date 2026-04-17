<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail;

/**
 * Read-only view of a Drupal mail message passed to a builder.
 *
 * Captures the subset of $message a builder needs to produce a MailMessage.
 * The hook is the only place that mutates $message; a builder that needs
 * to decide whether to intercept calls supports(), and a builder that
 * wants to render reads this DTO instead of touching the array directly.
 */
final readonly class MailContext {

  /**
   * @param string $module
   *   The Drupal mail $message['module'] value.
   * @param string $key
   *   The Drupal mail $message['key'] value.
   * @param string $langcode
   *   The langcode under which the mail is being prepared.
   * @param array $params
   *   The raw $message['params'] array passed by the consumer module.
   * @param mixed $to
   *   The $message['to'] value (string or array, depending on caller).
   */
  public function __construct(
    public string $module,
    public string $key,
    public string $langcode,
    public array $params,
    public mixed $to,
  ) {}

}
