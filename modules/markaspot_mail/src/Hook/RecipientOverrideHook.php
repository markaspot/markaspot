<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Hook;

use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;

/**
 * Hard recipient fence for non-production environments (markaspot-ui#388).
 *
 * Defense-in-depth layer L3 against the recurring incident class "dev/test
 * deployment with a production DB pull mails real citizens": when the
 * settings key 'markaspot_mail_recipient_override' is non-empty, EVERY
 * outgoing mail has its To AND Reply-To rewritten to the override address
 * and its Cc/Bcc stripped before any mail plugin (phpmailer_smtp, smtp,
 * php_mail) fires. The original recipients are written to watchdog for
 * audit, and the subject is prefixed with "[DEV→original@example.com]" so
 * redirected mail is recognizable at a glance in the dev mailbox.
 *
 * The key is intentionally a Settings (settings.php) value and NOT config:
 * config lives in the tenant DB and is exactly the thing that drifts on a
 * prod DB pull. Settings come from the runtime environment
 * (markaspot-cloud/docker/settings.php maps MARKASPOT_MAIL_RECIPIENT_OVERRIDE
 * onto it), so the fence holds even when mailsystem.settings and SMTP
 * credentials in the imported DB point at the real relay.
 *
 * Unlike MailAlterHook (builder-gated branding with early returns), this
 * hook is unconditional: it must also catch unbranded mails, contrib mails,
 * password resets and anything a future module sends. It runs AFTER the
 * branding hook in markaspot_mail_mail_alter() so the subject prefix
 * survives the branding subject overwrite.
 *
 * Empty/unset override = strict passthrough, no behavior change.
 */
final class RecipientOverrideHook {

  /**
   * Settings key read via Settings::get(), fed from the container env.
   */
  public const SETTINGS_KEY = 'markaspot_mail_recipient_override';

  /**
   * Max display length of the original To inside the subject prefix.
   *
   * Multi-recipient To strings can be arbitrarily long; an unbounded prefix
   * would push the "[DEV→...]" marker and the real subject out of view in
   * mail clients. The full, untruncated recipients always go to watchdog.
   */
  private const SUBJECT_RECIPIENT_MAX_LENGTH = 80;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Rewrites all recipients to the override address when configured.
   *
   * @param array $message
   *   The Drupal mail message array, altered by reference.
   */
  public function alter(array &$message): void {
    $override = trim((string) Settings::get(self::SETTINGS_KEY, ''));
    if ($override === '') {
      return;
    }

    // The override feeds the To and Reply-To headers and the subject prefix
    // raw. Strip CR/LF/NUL so a malformed value — an operator typo, a copied
    // line with a trailing newline, a multi-value paste — can never become a
    // header-injection vector (CWE-93) in a mail plugin that splices headers
    // unescaped. The value is env-sourced and operator-controlled, so this is
    // belt-and-suspenders, but the fence must not be the weak link.
    $override = $this->sanitizeHeaderValue($override);

    $originalTo = trim((string) ($message['to'] ?? ''));
    $originalCc = [];
    $originalBcc = [];
    $originalReplyTo = [];

    // Collect + remove Cc/Bcc/Reply-To and rewrite To headers
    // case-insensitively. Drupal MailManager and alter hooks are not
    // consistent about header casing ('Cc' vs 'cc' vs 'CC'); a
    // case-sensitive unset would leave a live carbon-copy to a citizen
    // behind. Every case variant's value is captured for the audit log,
    // not just the last one seen.
    foreach (array_keys((array) ($message['headers'] ?? [])) as $headerName) {
      $name = (string) $headerName;
      if (strcasecmp($name, 'Cc') === 0) {
        $originalCc[] = trim((string) $message['headers'][$headerName]);
        unset($message['headers'][$headerName]);
      }
      elseif (strcasecmp($name, 'Bcc') === 0) {
        $originalBcc[] = trim((string) $message['headers'][$headerName]);
        unset($message['headers'][$headerName]);
      }
      elseif (strcasecmp($name, 'Reply-To') === 0) {
        // A Reply-To pointing at a citizen would let a tester's reply in
        // the dev mailbox leave the fence even though the mail itself was
        // redirected. Capture and drop every case variant here; a single
        // canonical Reply-To pointing at the override is re-added after the
        // loop (see below). Stripping alone would let replies fall back to
        // From, which on a prod DB pull can still be a citizen-facing
        // address — the very escape this layer exists to close.
        $originalReplyTo[] = trim((string) $message['headers'][$headerName]);
        unset($message['headers'][$headerName]);
      }
      elseif (strcasecmp($name, 'To') === 0) {
        if ($originalTo === '') {
          $originalTo = trim((string) $message['headers'][$headerName]);
        }
        $message['headers'][$headerName] = $override;
      }
    }

    // Re-add one canonical Reply-To pointing at the override. Set
    // unconditionally (even when the original carried none) so a reply from
    // the dev mailbox always lands back in the dev mailbox and can never
    // escape via the From fallback. Runs after the strip loop so it survives
    // every case-variant unset above.
    $message['headers']['Reply-To'] = $override;

    // Audit trail: the original recipients must remain reconstructable from
    // watchdog so a redirected mail can be re-sent manually if needed.
    $this->logger->notice('Mail recipient override active: to "@to", cc "@cc", bcc "@bcc", reply-to "@reply_to" redirected to "@override" (key=@key).', [
      '@to' => $originalTo === '' ? '<unset>' : $originalTo,
      '@cc' => $this->formatAddressList($originalCc),
      '@bcc' => $this->formatAddressList($originalBcc),
      '@reply_to' => $this->formatAddressList($originalReplyTo),
      '@override' => $override,
      '@key' => (string) ($message['module'] ?? '') . ':' . (string) ($message['key'] ?? ''),
    ]);

    $message['to'] = $override;

    // Prefix the subject with the original recipient so testers see who
    // WOULD have received the mail. The original To may carry user-entered
    // data; strip CR/LF/NUL so the prefix cannot become a header-injection
    // vector (CWE-93) in mail plugins that splice the subject raw. The
    // display value is truncated; the watchdog entry above always holds
    // the full recipient list.
    $displayTo = $originalTo === '' ? '<unset>' : $this->truncateForSubject($originalTo);
    $prefix = $this->sanitizeHeaderValue('[DEV→' . $displayTo . '] ');
    $message['subject'] = $prefix . (string) ($message['subject'] ?? '');
  }

  /**
   * Joins captured header values for the audit log.
   *
   * @param string[] $values
   *   Header values, possibly from multiple case variants of one header.
   *
   * @return string
   *   Comma-joined non-empty values, or '<unset>' when none were present.
   */
  private function formatAddressList(array $values): string {
    $values = array_filter($values, static fn (string $value): bool => $value !== '');
    return $values === [] ? '<unset>' : implode(', ', $values);
  }

  /**
   * Truncates a recipient string for subject display (mb-safe, ellipsis).
   */
  private function truncateForSubject(string $value): string {
    if (mb_strlen($value) <= self::SUBJECT_RECIPIENT_MAX_LENGTH) {
      return $value;
    }
    return mb_substr($value, 0, self::SUBJECT_RECIPIENT_MAX_LENGTH - 1) . '…';
  }

  /**
   * Strips CR / LF / NUL bytes from a header value.
   */
  private function sanitizeHeaderValue(string $value): string {
    return str_replace(["\r", "\n", "\0"], '', $value);
  }

}
