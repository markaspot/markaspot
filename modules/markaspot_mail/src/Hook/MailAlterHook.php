<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailHtmlRenderer;
use Psr\Log\LoggerInterface;

/**
 * Central hook_mail_alter implementation.
 *
 * Consumer mails opt in via the markaspot_mail.settings "whitelist.entries"
 * config: each entry is keyed by "<module>.<key>" and its value is a mapping
 * { mode: platform|jurisdiction, variant: hero_code|card_transactional }.
 * Anything not in the whitelist is passed through unchanged (no behavior
 * change for modules that haven't been migrated yet).
 *
 * System mails from "user", "system" and "update" are never touched, even
 * if a consumer accidentally whitelists them.
 */
final class MailAlterHook {

  private const SYSTEM_MODULE_BLOCKLIST = ['user', 'system', 'update'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailBrandingService $branding,
    private readonly MailHtmlRenderer $renderer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Alter entry point, called from markaspot_mail_mail_alter().
   *
   * @param array $message
   *   The mail message array, modified in place.
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

    $whitelist = (array) $this->configFactory
      ->get('markaspot_mail.settings')
      ->get('whitelist.entries');
    $lookup = $module . '.' . $key;
    if (!isset($whitelist[$lookup])) {
      return;
    }

    // Resolve mode + variant from the whitelist entry. The canonical shape is
    // a mapping ['mode' => ..., 'variant' => ...]; for backward compatibility
    // we still accept a plain string (legacy "platform" / "jurisdiction").
    $entry = $whitelist[$lookup];
    if (is_string($entry)) {
      $mode = $entry === 'jurisdiction' ? 'jurisdiction' : 'platform';
      $whitelistVariant = '';
    }
    else {
      $entryArr = (array) $entry;
      $mode = ($entryArr['mode'] ?? 'platform') === 'jurisdiction' ? 'jurisdiction' : 'platform';
      $whitelistVariant = (string) ($entryArr['variant'] ?? '');
    }

    $langcode = (string) ($message['langcode'] ?? 'en');
    $params = (array) ($message['params'] ?? []);

    $jurisdictionId = NULL;
    if ($mode === 'jurisdiction' && isset($params['jurisdiction_id'])) {
      $jurisdictionId = (int) $params['jurisdiction_id'];
    }

    // Consumers must provide the variant + content payload via params.
    // Stage 1 only wires the alter pipeline; Stages 2-4 populate these keys
    // from the respective consumer modules. The whitelist entry may also
    // pin a variant so consumers can't accidentally downgrade; if both are
    // set the params value wins but must equal the whitelist variant.
    $variant = (string) ($params['_mail_variant'] ?? $whitelistVariant);
    $content = (array) ($params['_mail_content'] ?? []);
    if ($variant === '' || $content === []) {
      $this->logger->notice('markaspot_mail whitelisted @lookup but no _mail_variant / _mail_content was provided; leaving message untouched.', [
        '@lookup' => $lookup,
      ]);
      return;
    }

    try {
      $brandingPackage = $this->branding->getBranding($jurisdictionId, $mode, $langcode);
      $rendered = $this->renderer->render($variant, $brandingPackage, $content, $langcode);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to render branded mail for @lookup: @msg', [
        '@lookup' => $lookup,
        '@msg' => $e->getMessage(),
      ]);
      return;
    }

    $message['body'] = [$rendered['html']];
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8; format=flowed; delsp=no';
    if (!empty($brandingPackage['reply_to'])) {
      $message['headers']['Reply-To'] = $this->sanitizeHeaderValue((string) $brandingPackage['reply_to']);
    }

    // Strip Mail Plugin routing keys so SwiftMailer / Symfony Mailer
    // implementations don't forcibly downgrade our message to plaintext and
    // re-render the HTML twig output as-is. See markaspot_contact.module
    // for the same pattern.
    unset($message['params']['formatter']);
    unset($message['params']['plaintext']);

    // A future MailerPlugin (see Stages 3-4) will turn this into a proper
    // multipart/alternative body. For now we stash plaintext in params so
    // plugins down the line can pick it up.
    $message['params']['_plain_alt'] = $rendered['plain'];
  }

  /**
   * Strips CR / LF / NUL bytes from a header value.
   *
   * Any data that flows into RFC 5322 headers (Reply-To, From, Subject etc.)
   * must never contain \r, \n or \0 or an attacker controlling the upstream
   * string (e.g. field_jurisdiction_e_mail) could inject additional headers
   * or terminate the header block early.
   */
  private function sanitizeHeaderValue(string $value): string {
    return str_replace(["\r", "\n", "\0"], '', $value);
  }

}
