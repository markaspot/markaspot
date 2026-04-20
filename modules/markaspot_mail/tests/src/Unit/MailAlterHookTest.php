<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Render\Markup;
use Drupal\markaspot_mail\Hook\MailAlterHook;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailBuilderRegistry;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailHtmlRenderer;
use Drupal\Tests\markaspot_mail\Unit\Stub\RecordingStubBuilder;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(\Drupal\markaspot_mail\Hook\MailAlterHook::class)]
#[Group('markaspot_mail')]
final class MailAlterHookTest extends UnitTestCase {

  /**
   * Regression guard for mail header injection (CWE-93) via Subject:. A
   * builder that naively interpolates user input (report title, display
   * name) into its subject could otherwise inject Bcc: / To: headers.
   */
  public function testAlterStripsCrLfFromSubject(): void {
    $evilSubject = "Report #42 updated\r\nBcc: attacker@example.com";

    $builder = new RecordingStubBuilder(new MailMessage(
      subject: $evilSubject,
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
    ));

    $message = $this->buildMessage();
    $this->buildHook($builder)->alter($message);

    // What matters for CWE-93 is CR/LF absence — without a newline the
    // literal "Bcc:" substring is harmless text. The joined result proves
    // the delimiters were stripped before the string hit the header sink.
    $this->assertStringNotContainsString("\r", $message['subject']);
    $this->assertStringNotContainsString("\n", $message['subject']);
    $this->assertSame('Report #42 updatedBcc: attacker@example.com', $message['subject']);
  }

  /**
   * _plain_alt ends up as a MIME text body, not a header, but we still
   * normalize \r\n → \n as belt-and-suspenders defense against future
   * mailer plugins that might naively splice it next to headers.
   */
  public function testAlterNormalizesCrLfInPlainAlt(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'Clean subject',
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
    ));

    $message = $this->buildMessage();
    $hook = $this->buildHook($builder, plainOverride: "first line\r\nsecond line\rstill second");
    $hook->alter($message);

    $this->assertArrayHasKey('_plain_alt', $message['params']);
    $this->assertStringNotContainsString("\r", $message['params']['_plain_alt']);
  }

  public function testAlterSkipsBlocklistedModules(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'should-not-happen',
      variant: 'card_transactional',
      content: [],
    ));

    $hook = $this->buildHook($builder);
    foreach (['user', 'update'] as $module) {
      $message = $this->buildMessage(module: $module, key: 'password_reset');
      $originalSubject = $message['subject'];
      $hook->alter($message);
      $this->assertSame($originalSubject, $message['subject'], "Blocklisted module '$module' must not be branded.");
    }
    $this->assertFalse($builder->wasCalled, 'Builder must never run for blocklisted modules.');
  }

  /**
   * system is no longer hard-blocklisted — individual keys are gated by
   * builder supports(). A builder that does not claim a system:* key must
   * not be invoked, but a builder that does (EcaActionEmailBuilder for
   * system:action_send_email) gets the chance to brand.
   */
  public function testAlterAllowsSystemModuleThroughWhenNoBuilderClaims(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'should-not-happen',
      variant: 'card_transactional',
      content: [],
    ));

    $hook = $this->buildHook($builder);
    $message = $this->buildMessage(module: 'system', key: 'mail');
    $originalSubject = $message['subject'];
    $hook->alter($message);
    $this->assertSame($originalSubject, $message['subject']);
    $this->assertFalse($builder->wasCalled, 'Builder must not be invoked when supports() returns FALSE.');
  }

  public function testAlterSkipsWhenRegistryHasNoMatch(): void {
    $hook = $this->buildHook(NULL);
    $message = $this->buildMessage();
    $originalSubject = $message['subject'];
    $hook->alter($message);
    $this->assertSame($originalSubject, $message['subject']);
  }

  /**
   * Builds a minimal Drupal $message array for the test subject.
   */
  private function buildMessage(
    string $module = 'markaspot_escalation',
    string $key = 'escalation_notice',
    string $subject = 'Original subject',
  ): array {
    return [
      'module' => $module,
      'key' => $key,
      'to' => 'user@example.com',
      'subject' => $subject,
      'body' => [],
      'langcode' => 'en',
      'params' => [],
      'headers' => [],
    ];
  }

  /**
   * Builds a MailAlterHook with mocked dependencies + a stub builder.
   */
  private function buildHook(?MailBuilderInterface $builder, ?string $plainOverride = NULL): MailAlterHook {
    $registry = new MailBuilderRegistry($builder === NULL ? [] : [$builder]);

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'mode' => 'jurisdiction',
      'platform_name' => 'Mark-a-Spot',
      'logo_url' => '',
      'primary_color' => '#004ced',
      'background_color' => '#EEF3FF',
      'support_email' => 'support@civic-patches.com',
      'legal_notice_url' => 'https://civicpatches.de/impressum',
      'privacy_url' => 'https://civicpatches.de/datenschutz',
      'email_footer_html' => Markup::create(''),
      'reply_to' => 'support@civic-patches.com',
      'frontend_base_url' => 'https://mark-a-spot.com',
      'jurisdiction_slug' => NULL,
      'jurisdiction_label' => NULL,
      'platform_footer' => [],
    ]);

    $renderer = $this->createMock(MailHtmlRenderer::class);
    $renderer->method('render')->willReturn([
      'html' => '<p>rendered</p>',
      'plain' => $plainOverride ?? "first line\r\nsecond line\rstill second",
    ]);

    $logger = $this->createMock(LoggerInterface::class);

    return new MailAlterHook($registry, $branding, $renderer, $logger);
  }

}
