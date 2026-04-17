<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Hook;

use Drupal\markaspot_mail\Mail\MailBuilderRegistry;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailHtmlRenderer;
use Psr\Log\LoggerInterface;

/**
 * Central hook_mail_alter dispatcher.
 *
 * For every inbound $message the hook asks MailBuilderRegistry whether any
 * registered builder claims the ($module, $key) pair. If one does, it
 * produces an abstract MailMessage which is then branded + rendered and
 * written back onto the Drupal $message array.
 *
 * No whitelist config. The set of tagged "markaspot_mail.builder" services
 * IS the whitelist. Consumer modules migrate by providing a builder, not
 * by editing config.
 *
 * System mails from "user", "system" and "update" are hard-blocked so an
 * accidental registration can never hijack password-reset or status mails.
 */
final class MailAlterHook {

  private const SYSTEM_MODULE_BLOCKLIST = ['user', 'system', 'update'];

  public function __construct(
    private readonly MailBuilderRegistry $registry,
    private readonly MailBrandingService $branding,
    private readonly MailHtmlRenderer $renderer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Alter entry point, called from markaspot_mail_mail_alter().
   */
  public function alter(array &$message): void {
    $module = (string) ($message['module'] ?? '');
    $key = (string) ($message['key'] ?? '');

    if ($module === '' || $key === '') {
      return;
    }
    if (in_array($module, self::SYSTEM_MODULE_BLOCKLIST, TRUE)) {
      return;
    }

    $builder = $this->registry->findForMessage($module, $key);
    if ($builder === NULL) {
      return;
    }

    $langcode = (string) ($message['langcode'] ?? 'en');
    $ctx = new MailContext(
      module: $module,
      key: $key,
      langcode: $langcode,
      params: (array) ($message['params'] ?? []),
      to: $message['to'] ?? NULL,
    );

    try {
      $msg = $builder->build($ctx);
    }
    catch (\Throwable $e) {
      $this->logger->error('Builder @type threw for @module:@key: @err', [
        '@type' => $builder->getType()->value,
        '@module' => $module,
        '@key' => $key,
        '@err' => $e->getMessage(),
      ]);
      return;
    }
    if ($msg === NULL) {
      return;
    }

    try {
      $brandingPackage = $this->branding->getBranding($msg->jurisdictionId, $msg->mode, $langcode);
      $rendered = $this->renderer->render(
        $msg->variant,
        $brandingPackage,
        $msg->content,
        $langcode,
        $msg->plainText,
      );
    }
    catch (\Throwable $e) {
      // Silent-fail on render error is intentional: returning here leaves
      // the original unbranded $message intact so Drupal still delivers
      // the mail. hook_mail_alter may run under cron / queue / API-callback
      // context where \Drupal::messenger() has no UI session to reach.
      $this->logger->error('Failed to render branded mail for @module:@key (type @type): @err', [
        '@module' => $module,
        '@key' => $key,
        '@type' => $builder->getType()->value,
        '@err' => $e->getMessage(),
      ]);
      return;
    }

    // Subject may come from user-controlled data via the builder (report
    // title, display name, etc.) and ends up verbatim in an RFC 5322 header.
    // Defense-in-depth: strip CR/LF/NUL even though builders SHOULD already
    // sanitize. Mail header injection (CWE-93) would otherwise let an
    // attacker add Bcc: / To: headers and turn the pipeline into a relay.
    $message['subject'] = $this->sanitizeHeaderValue($msg->subject);
    $message['body'] = [$rendered['html']];
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8; format=flowed; delsp=no';
    if (!empty($brandingPackage['reply_to'])) {
      $message['headers']['Reply-To'] = $this->sanitizeHeaderValue((string) $brandingPackage['reply_to']);
    }

    // Strip Mail Plugin routing keys so SwiftMailer / Symfony Mailer
    // implementations don't forcibly downgrade our message to plaintext and
    // re-render the HTML twig output as-is. See markaspot_contact.module.
    unset($message['params']['formatter']);
    unset($message['params']['plaintext']);

    // Stash the plain-text alternative so a downstream mailer plugin can
    // promote it into a proper multipart/alternative body. Normalize CR
    // to LF as belt-and-suspenders for plugins that might naively splice
    // the text next to headers without re-encoding.
    $message['params']['_plain_alt'] = str_replace(["\r\n", "\r"], "\n", $rendered['plain']);
  }

  /**
   * Strips CR / LF / NUL bytes from a header value.
   */
  private function sanitizeHeaderValue(string $value): string {
    return str_replace(["\r", "\n", "\0"], '', $value);
  }

}
