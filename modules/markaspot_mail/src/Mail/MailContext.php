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
   * @param string $subject
   *   The $message['subject'] value as it stands when the hook runs.
   *   hook_mail_alter executes AFTER hook_mail, so callers like
   *   system_mail() (for action_send_email) have already token-replaced
   *   their subject template into this field. Builders that want to
   *   preserve the caller's subject (e.g. EcaActionEmailBuilder) read
   *   from here instead of re-tokenizing the template themselves.
   * @param array $body
   *   The $message['body'] array as it stands when the hook runs. Each
   *   entry is typically a string (or Markup) and corresponds to one
   *   paragraph or render-array output appended by the module's
   *   hook_mail. Empty array when the mail was never touched by a
   *   previous hook.
   */
  public function __construct(
    public string $module,
    public string $key,
    public string $langcode,
    public array $params,
    public mixed $to,
    public string $subject = '',
    public array $body = [],
  ) {}

}
