<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;

/**
 * Resolves mailbox definitions from markaspot_mail_inbound.settings.
 *
 * A mailbox maps an IMAP account (or just a set of recipient addresses)
 * to one jurisdiction group and a default category.
 *
 * IMAP passwords are exportable config. Production deployments should leave
 * the config password empty and supply it out-of-band so no secret is ever
 * written to config/sync or baked into an image. Two supported overrides:
 *
 * - Environment variable (preferred), keyed by mailbox id:
 *   @code
 *   MARKASPOT_MAIL_INBOUND_PASSWORD_CITY_MAIN=...   # per mailbox
 *   MARKASPOT_MAIL_INBOUND_PASSWORD=...             # single-mailbox shortcut
 *   @endcode
 * - settings.php config override (classic):
 *   @code
 *   $config['markaspot_mail_inbound.settings']['mailboxes'][0]['imap']['password'] = getenv('MAIL_INBOUND_PASSWORD');
 *   @endcode
 */
class MailboxResolver {

  public const CONFIG_NAME = 'markaspot_mail_inbound.settings';

  /**
   * Constructs a MailboxResolver.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Returns all mailbox definitions, normalized with defaults.
   *
   * @param bool $enabledOnly
   *   When TRUE, disabled mailboxes are filtered out.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized mailbox definitions.
   */
  public function getMailboxes(bool $enabledOnly = TRUE): array {
    $raw = $this->configFactory->get(self::CONFIG_NAME)->get('mailboxes');
    if (!is_array($raw)) {
      return [];
    }
    $mailboxes = [];
    foreach ($raw as $mailbox) {
      if (!is_array($mailbox)) {
        continue;
      }
      $mailbox = $this->normalize($mailbox);
      if ($mailbox['id'] === '') {
        continue;
      }
      if ($enabledOnly && !$mailbox['enabled']) {
        continue;
      }
      $mailboxes[] = $mailbox;
    }
    return $mailboxes;
  }

  /**
   * Returns one mailbox by its id.
   *
   * @param string $id
   *   The mailbox id.
   * @param bool $enabledOnly
   *   When TRUE, a disabled mailbox resolves to NULL.
   *
   * @return array<string, mixed>|null
   *   The normalized mailbox or NULL.
   */
  public function getMailbox(string $id, bool $enabledOnly = FALSE): ?array {
    foreach ($this->getMailboxes($enabledOnly) as $mailbox) {
      if ($mailbox['id'] === $id) {
        return $mailbox;
      }
    }
    return NULL;
  }

  /**
   * Resolves the mailbox responsible for a message by recipient match.
   *
   * Matches the message recipients (To, Cc, Delivered-To) against each
   * enabled mailbox's recipient_addresses.
   *
   * @param \Drupal\markaspot_mail_inbound\Dto\InboundMessage $message
   *   The parsed message.
   *
   * @return array<string, mixed>|null
   *   The first matching mailbox or NULL.
   */
  public function resolveForMessage(InboundMessage $message): ?array {
    foreach ($this->getMailboxes() as $mailbox) {
      $recipients = array_map(
        static fn($address): string => mb_strtolower(trim((string) $address)),
        $mailbox['recipient_addresses']
      );
      foreach ($message->toAddresses as $address) {
        if (in_array($address, $recipients, TRUE)) {
          return $mailbox;
        }
      }
    }
    return NULL;
  }

  /**
   * Returns the global pipeline settings with defaults applied.
   *
   * @return array<string, mixed>
   *   The settings.
   */
  public function getGlobalSettings(): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $mimeTypes = $config->get('allowed_mime_types');
    return [
      'max_attachments' => (int) ($config->get('max_attachments') ?? 5),
      'max_attachment_size_mb' => (int) ($config->get('max_attachment_size_mb') ?? 8),
      'allowed_mime_types' => is_array($mimeTypes) && $mimeTypes !== []
        ? array_map(static fn($type): string => mb_strtolower(trim((string) $type)), $mimeTypes)
        : ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
      'max_body_length' => (int) ($config->get('max_body_length') ?? 10000),
      'flood_limit' => (int) ($config->get('flood_limit') ?? 10),
      'flood_window' => (int) ($config->get('flood_window') ?? 3600),
    ];
  }

  /**
   * Applies defaults to a raw mailbox definition.
   *
   * @param array<string, mixed> $mailbox
   *   The raw definition from config.
   *
   * @return array<string, mixed>
   *   The normalized definition.
   */
  protected function normalize(array $mailbox): array {
    $imap = is_array($mailbox['imap'] ?? NULL) ? $mailbox['imap'] : [];
    $id = trim((string) ($mailbox['id'] ?? ''));
    return [
      'id' => $id,
      'label' => (string) ($mailbox['label'] ?? ''),
      'enabled' => (bool) ($mailbox['enabled'] ?? FALSE),
      'imap' => [
        'host' => (string) ($imap['host'] ?? ''),
        'port' => (int) ($imap['port'] ?? 993),
        'encryption' => (string) ($imap['encryption'] ?? 'ssl'),
        'username' => (string) ($imap['username'] ?? ''),
        'password' => $this->resolvePassword($id, (string) ($imap['password'] ?? '')),
        'folder' => (string) ($imap['folder'] ?? 'INBOX'),
        'processed_folder' => (string) ($imap['processed_folder'] ?? ''),
      ],
      'recipient_addresses' => array_values(array_filter(array_map(
        static fn($address): string => mb_strtolower(trim((string) $address)),
        is_array($mailbox['recipient_addresses'] ?? NULL) ? $mailbox['recipient_addresses'] : []
      ))),
      'jurisdiction_gid' => (int) ($mailbox['jurisdiction_gid'] ?? 0),
      'default_category_tid' => (int) ($mailbox['default_category_tid'] ?? 0),
      'sender_allowlist' => is_array($mailbox['sender_allowlist'] ?? NULL) ? $mailbox['sender_allowlist'] : [],
      'sender_blocklist' => is_array($mailbox['sender_blocklist'] ?? NULL) ? $mailbox['sender_blocklist'] : [],
    ];
  }

  /**
   * Resolves the IMAP password, preferring an out-of-band override.
   *
   * Production deployments should leave the config password empty (so no
   * secret is ever written to config/sync or baked into images) and instead
   * provide it out-of-band, in order of precedence:
   *   1. An environment variable named
   *      MARKASPOT_MAIL_INBOUND_PASSWORD_<UPPERCASED_MAILBOX_ID>, e.g.
   *      MARKASPOT_MAIL_INBOUND_PASSWORD_CITY_MAIN.
   *   2. The generic MARKASPOT_MAIL_INBOUND_PASSWORD env var (single mailbox).
   *   3. The value from config (settings.php may still override the config
   *      value the classic way; that path is unaffected here).
   *
   * @param string $id
   *   The mailbox machine id.
   * @param string $configPassword
   *   The password as stored in config (may be empty).
   *
   * @return string
   *   The effective password.
   */
  protected function resolvePassword(string $id, string $configPassword): string {
    if ($configPassword !== '') {
      return $configPassword;
    }
    if ($id !== '') {
      $key = 'MARKASPOT_MAIL_INBOUND_PASSWORD_' . strtoupper(preg_replace('/[^a-z0-9_]/', '_', $id) ?? '');
      $value = getenv($key);
      if ($value !== FALSE && $value !== '') {
        return $value;
      }
    }
    $generic = getenv('MARKASPOT_MAIL_INBOUND_PASSWORD');
    return $generic !== FALSE ? $generic : '';
  }

}
