<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Core\Site\Settings;
use Drupal\markaspot_mail\Hook\RecipientOverrideHook;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the non-prod recipient fence (markaspot-ui#388, defense layer L3).
 *
 * The hook reads markaspot_mail_recipient_override via Settings::get(); the
 * Settings singleton is swapped per test via `new Settings([...])`, the same
 * pattern MailBrandingServiceTest uses for markaspot_operating_mode.
 */
#[CoversClass(RecipientOverrideHook::class)]
#[Group('markaspot_mail')]
final class RecipientOverrideHookTest extends UnitTestCase {

  private const OVERRIDE = 'devmail@civicpatches.de';

  /**
   * Empty override = strict passthrough: nothing changes, nothing is logged.
   */
  public function testEmptyOverrideIsPassthrough(): void {
    new Settings([]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');

    $message = $this->buildMessage();
    $original = $message;
    (new RecipientOverrideHook($logger))->alter($message);

    $this->assertSame($original, $message, 'Unset override must not touch the message at all.');
  }

  /**
   * Whitespace-only override counts as unset (compose ${VAR:-} injects '').
   */
  public function testWhitespaceOverrideIsPassthrough(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => '   ']);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');

    $message = $this->buildMessage();
    $original = $message;
    (new RecipientOverrideHook($logger))->alter($message);

    $this->assertSame($original, $message);
  }

  /**
   * Active override rewrites To and strips Cc/Bcc regardless of header case.
   */
  public function testOverrideRewritesToCcAndBcc(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $message = $this->buildMessage();
    $message['headers']['Cc'] = 'citizen-cc@example.org';
    $message['headers']['bcc'] = 'citizen-bcc@example.org';
    $message['headers']['To'] = 'citizen@example.org';

    (new RecipientOverrideHook($this->createMock(LoggerInterface::class)))->alter($message);

    $this->assertSame(self::OVERRIDE, $message['to']);
    $this->assertSame(self::OVERRIDE, $message['headers']['To'], 'A To header must be rewritten, not just $message[to].');
    foreach (array_keys($message['headers']) as $headerName) {
      $this->assertNotSame(0, strcasecmp((string) $headerName, 'Cc'), 'No Cc header variant may survive.');
      $this->assertNotSame(0, strcasecmp((string) $headerName, 'Bcc'), 'No Bcc header variant may survive.');
    }
    // Non-recipient headers must survive untouched.
    $this->assertSame('text/plain; charset=UTF-8', $message['headers']['Content-Type']);
  }

  /**
   * Original recipients (to + cc + bcc) land in the watchdog audit log.
   */
  public function testOriginalRecipientsAreLogged(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $captured = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(function (string $template, array $context) use (&$captured): void {
        $captured = $context;
      });

    $message = $this->buildMessage();
    $message['headers']['CC'] = 'citizen-cc@example.org';
    $message['headers']['Bcc'] = 'citizen-bcc@example.org';

    (new RecipientOverrideHook($logger))->alter($message);

    $this->assertSame('citizen@example.org', $captured['@to']);
    $this->assertSame('citizen-cc@example.org', $captured['@cc']);
    $this->assertSame('citizen-bcc@example.org', $captured['@bcc']);
    $this->assertSame(self::OVERRIDE, $captured['@override']);
    $this->assertSame('markaspot_feedback:feedback_request', $captured['@key']);
  }

  /**
   * Reply-To is stripped case-insensitively and lands in the audit log.
   *
   * A Reply-To pointing at a citizen would let a tester's reply in the dev
   * mailbox leave the fence even though the mail itself was redirected.
   */
  public function testReplyToIsStrippedAndLogged(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $captured = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(function (string $template, array $context) use (&$captured): void {
        $captured = $context;
      });

    $message = $this->buildMessage();
    $message['headers']['reply-to'] = 'citizen-reply@example.org';

    (new RecipientOverrideHook($logger))->alter($message);

    foreach (array_keys($message['headers']) as $headerName) {
      $this->assertNotSame(0, strcasecmp((string) $headerName, 'Reply-To'), 'No Reply-To header variant may survive.');
    }
    $this->assertSame('citizen-reply@example.org', $captured['@reply_to']);
  }

  /**
   * Multiple case variants of Cc are ALL captured in the audit log.
   *
   * PHP array keys make 'Cc' and 'CC' distinct headers; a last-wins capture
   * would silently drop one citizen address from the audit trail.
   */
  public function testAllCcVariantsAreAuditLogged(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $captured = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(function (string $template, array $context) use (&$captured): void {
        $captured = $context;
      });

    $message = $this->buildMessage();
    $message['headers']['Cc'] = 'first-cc@example.org';
    $message['headers']['CC'] = 'second-cc@example.org';

    (new RecipientOverrideHook($logger))->alter($message);

    $this->assertStringContainsString('first-cc@example.org', $captured['@cc']);
    $this->assertStringContainsString('second-cc@example.org', $captured['@cc']);
    foreach (array_keys($message['headers']) as $headerName) {
      $this->assertNotSame(0, strcasecmp((string) $headerName, 'Cc'), 'No Cc header variant may survive.');
    }
  }

  /**
   * Overlong original recipients are truncated in the subject prefix.
   *
   * Multi-recipient To strings must not push the [DEV→...] marker and the
   * real subject out of view; the full list still goes to watchdog.
   */
  public function testSubjectPrefixTruncatesLongOriginalTo(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $recipients = implode(', ', array_map(
      static fn (int $i): string => sprintf('citizen%02d@example.org', $i),
      range(1, 10),
    ));
    $this->assertGreaterThan(80, mb_strlen($recipients));

    $captured = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(function (string $template, array $context) use (&$captured): void {
        $captured = $context;
      });

    $message = $this->buildMessage();
    $message['to'] = $recipients;
    (new RecipientOverrideHook($logger))->alter($message);

    $this->assertStringStartsWith('[DEV→citizen01@example.org', $message['subject']);
    $this->assertStringContainsString('…] Status update for your report', $message['subject']);
    // Display part is capped at 80 chars (79 + ellipsis) inside the framing.
    $closingBracket = mb_strpos($message['subject'], ']');
    $this->assertNotFalse($closingBracket);
    $this->assertLessThanOrEqual(88, $closingBracket, 'Prefix must stay bounded.');
    // Watchdog keeps the full untruncated recipient list.
    $this->assertSame($recipients, $captured['@to']);
  }

  /**
   * The subject gets the "[DEV→original]" prefix, original subject preserved.
   */
  public function testSubjectIsPrefixedWithOriginalRecipient(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $message = $this->buildMessage();
    (new RecipientOverrideHook($this->createMock(LoggerInterface::class)))->alter($message);

    $this->assertSame('[DEV→citizen@example.org] Status update for your report', $message['subject']);
  }

  /**
   * A missing To still produces a recognizable prefix instead of breaking.
   */
  public function testSubjectPrefixWithUnsetTo(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $message = $this->buildMessage();
    unset($message['to']);
    (new RecipientOverrideHook($this->createMock(LoggerInterface::class)))->alter($message);

    $this->assertSame(self::OVERRIDE, $message['to']);
    $this->assertStringStartsWith('[DEV→<unset>] ', $message['subject']);
  }

  /**
   * CR/LF in the user-entered original To must not reach the subject.
   *
   * The prefix interpolates the original recipient; without sanitizing it
   * would become a header-injection vector (CWE-93).
   */
  public function testSubjectPrefixStripsHeaderInjectionFromOriginalTo(): void {
    new Settings([RecipientOverrideHook::SETTINGS_KEY => self::OVERRIDE]);

    $message = $this->buildMessage();
    $message['to'] = "citizen@example.org\r\nBcc: attacker@example.com";
    (new RecipientOverrideHook($this->createMock(LoggerInterface::class)))->alter($message);

    $this->assertStringNotContainsString("\r", $message['subject']);
    $this->assertStringStartsWith('[DEV→citizen@example.org', $message['subject']);
  }

  /**
   * Builds a minimal Drupal $message array for the test subject.
   */
  private function buildMessage(): array {
    return [
      'module' => 'markaspot_feedback',
      'key' => 'feedback_request',
      'to' => 'citizen@example.org',
      'subject' => 'Status update for your report',
      'body' => ['Hello'],
      'langcode' => 'en',
      'params' => [],
      'headers' => [
        'Content-Type' => 'text/plain; charset=UTF-8',
      ],
    ];
  }

}
