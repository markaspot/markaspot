<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\markaspot_mail\Mail\MailAttachment;
use Drupal\markaspot_mail\Enum\MailType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Render\Markup;
use Drupal\markaspot_mail\Hook\MailAlterHook;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailBuilderRegistry;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailHtmlRenderer;
use Drupal\Tests\markaspot_mail\Unit\Stub\RecordingStubBuilder;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Hook/MailAlterHook.php';

/**
 * Tests the central mail-alter dispatcher.
 */
#[CoversClass(\Drupal\markaspot_mail\Hook\MailAlterHook::class)]
#[Group('markaspot_mail')]
final class MailAlterHookTest extends UnitTestCase {

  /**
   * Prevents header injection through a builder-generated subject.
   *
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
   * Rendered HTML entities are decoded before the subject reaches the header.
   */
  public function testAlterDecodesEntitiesInSubjectOnly(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'Assigned to Roofing &amp; Siding &quot;Contractor&quot; &lt;HQ&gt;',
      variant: 'card_transactional',
      content: ['headline' => 'Roofing &amp; Siding'],
    ));
    $renderer = $this->createMock(MailHtmlRenderer::class);
    $renderer->method('render')->willReturn([
      'html' => '<p>Roofing &amp; Siding</p>',
      'plain' => 'Roofing & Siding',
    ]);

    $message = $this->buildMessage();
    $this->buildHook($builder, renderer: $renderer)->alter($message);

    $this->assertSame(
      'Assigned to Roofing & Siding "Contractor" <HQ>',
      $message['subject'],
    );
    $this->assertSame(['<p>Roofing &amp; Siding</p>'], $message['body']);
  }

  /**
   * Entity decoding happens once when a builder preserves the input subject.
   */
  public function testAlterDecodesPreservedSubjectExactlyOnce(): void {
    $builder = $this->createMock(MailBuilderInterface::class);
    $builder->method('supports')->willReturn(TRUE);
    $builder->method('build')->willReturnCallback(
      static fn (MailContext $ctx): MailMessage => new MailMessage(
        subject: $ctx->subject,
        variant: 'card_transactional',
        content: ['headline' => 'Hi'],
      ),
    );

    $message = $this->buildMessage(subject: 'Roofing &amp;amp; Siding');
    $this->buildHook($builder)->alter($message);

    $this->assertSame('Roofing &amp; Siding', $message['subject']);
  }

  /**
   * Reply-To is decoded once before decoded control bytes are stripped.
   */
  public function testAlterDecodesAndStripsReplyToExactlyOnce(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'Branded subject',
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
    ));

    $message = $this->buildMessage();
    $this->buildHook(
      $builder,
      replyTo: "Support &amp;amp; Team&#13;&#10;\0 <support@example.com>",
    )->alter($message);

    $this->assertSame(
      'Support &amp; Team <support@example.com>',
      $message['headers']['Reply-To'],
    );
  }

  /**
   * A throwing builder leaves the original mail unbranded, but not unsafe.
   */
  public function testAlterSanitizesOriginalSubjectWhenBuilderThrows(): void {
    $builder = $this->createMock(MailBuilderInterface::class);
    $builder->method('supports')->willReturn(TRUE);
    $builder->method('getType')->willReturn(MailType::ECA_ESCALATION);
    $builder->method('build')->willThrowException(new \RuntimeException('Builder failed.'));

    $message = $this->buildMessage(subject: "Original\r\nBcc: attacker@example.com\0");
    $this->buildHook($builder)->alter($message);

    $this->assertSame('OriginalBcc: attacker@example.com', $message['subject']);
    $this->assertStringNotContainsString("\r", $message['subject']);
    $this->assertStringNotContainsString("\n", $message['subject']);
    $this->assertStringNotContainsString("\0", $message['subject']);
  }

  /**
   * A renderer failure preserves the unbranded mail with a safe subject.
   */
  public function testAlterSanitizesOriginalSubjectWhenRendererThrows(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'Branded subject',
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
    ));
    $renderer = $this->createMock(MailHtmlRenderer::class);
    $renderer->method('render')->willThrowException(new \RuntimeException('Renderer failed.'));

    $message = $this->buildMessage(subject: "Original\r\nBcc: attacker@example.com\0");
    $this->buildHook($builder, renderer: $renderer)->alter($message);

    $this->assertSame('OriginalBcc: attacker@example.com', $message['subject']);
    $this->assertStringNotContainsString("\r", $message['subject']);
    $this->assertStringNotContainsString("\n", $message['subject']);
    $this->assertStringNotContainsString("\0", $message['subject']);
  }

  /**
   * Normalizes CRLF characters in the plaintext alternative.
   *
   * _plain_alt ends up as a MIME text body, not a header, but we still
   * normalize \r\n to \n as belt-and-suspenders defense against future
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

  /**
   * Normalizes generated internal URLs in HTML and plaintext output.
   */
  public function testAlterNormalizesGeneratedUrlsInBothMailParts(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'URL test',
      variant: 'card_transactional',
      content: ['headline' => 'URL test'],
    ));
    $renderer = $this->createMock(MailHtmlRenderer::class);
    $renderer->method('render')->willReturn([
      'html' => '<a href="http://127.0.0.1:8080/node/42">Open</a>',
      'plain' => 'Open: http://127.0.0.1:8080/node/42',
    ]);
    $normalizer = static fn(string $value): string => str_replace(
      'http://127.0.0.1:8080',
      'https://management.example.com',
      $value,
    );

    $message = $this->buildMessage();
    $this->buildHook($builder, renderer: $renderer, urlNormalizer: $normalizer)
      ->alter($message);

    $this->assertStringContainsString('https://management.example.com/node/42', $message['body'][0]);
    $this->assertStringContainsString('https://management.example.com/node/42', $message['params']['_plain_alt']);
    $this->assertStringNotContainsString('127.0.0.1', $message['body'][0]);
    $this->assertStringNotContainsString('127.0.0.1', $message['params']['_plain_alt']);
  }

  /**
   * Token-generated CTA URLs are normalized before renderer validation.
   */
  public function testAlterNormalizesCtaBeforeRendering(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'CTA test',
      variant: 'card_transactional',
      content: [
        'headline' => 'CTA test',
        'cta_url' => 'http://127.0.0.1:8080/node/42?token=secret',
      ],
    ));
    $renderer = $this->createMock(MailHtmlRenderer::class);
    $renderer->expects($this->once())
      ->method('render')
      ->with(
        'card_transactional',
        $this->anything(),
        $this->callback(static fn(array $content): bool => ($content['cta_url'] ?? '') === 'https://management.example.com/node/42?token=secret'),
        'en',
        NULL,
      )
      ->willReturn([
        'html' => '<a href="https://management.example.com/node/42?token=secret">Open</a>',
        'plain' => 'Open: https://management.example.com/node/42?token=secret',
      ]);
    $normalizer = static fn(string $value): string => str_replace(
      'http://127.0.0.1:8080',
      'https://management.example.com',
      $value,
    );

    $message = $this->buildMessage();
    $this->buildHook($builder, renderer: $renderer, urlNormalizer: $normalizer)
      ->alter($message);

    $this->assertStringContainsString('https://management.example.com/node/42?token=secret', $message['body'][0]);
  }

  /**
   * Unbranded and blocklisted mail bodies still receive URL normalization.
   */
  public function testAlterNormalizesTokenUrlsInBlocklistedMail(): void {
    $normalizer = static fn(string $value): string => str_replace(
      'http://127.0.0.1:8080',
      'https://management.example.com',
      $value,
    );
    $message = $this->buildMessage(module: 'user', key: 'password_reset');
    $message['body'] = ['Reset: http://127.0.0.1:8080/user/reset/42'];

    $this->buildHook(NULL, urlNormalizer: $normalizer)->alter($message);

    $this->assertSame(
      ['Reset: https://management.example.com/user/reset/42'],
      $message['body'],
    );
  }

  /**
   * Skips hard-blocked modules.
   */
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
   * Allows system mail only when a builder claims the key.
   *
   * System is no longer hard-blocklisted. Individual keys are gated by
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

  /**
   * Leaves unmatched mail untouched.
   */
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
   * Forwards builder-supplied attachments to the Drupal mail message.
   *
   * Attachments on the MailMessage DTO reach $message['params'] in the
   * shape phpmailer_smtp::addAttachments() consumes (filename / filemime
   * / filepath keys). Without this wiring, MailMessage::$attachments
   * would be a silent no-op at the hook seam.
   */
  public function testAlterForwardsAttachmentsToMessageParams(): void {
    $attachment = new MailAttachment(
      filename: 'evidence.jpg',
      filemime: 'image/jpeg',
      filepath: 'private://reports/evidence.jpg',
    );
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'Escalation',
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
      attachments: [$attachment],
    ));

    $message = $this->buildMessage();
    $this->buildHook($builder)->alter($message);

    $this->assertArrayHasKey('attachments', $message['params']);
    $this->assertCount(1, $message['params']['attachments']);
    $this->assertSame(
      [
        'filename' => 'evidence.jpg',
        'filemime' => 'image/jpeg',
        'filepath' => 'private://reports/evidence.jpg',
      ],
      $message['params']['attachments'][0],
    );
  }

  /**
   * Preserves upstream attachments when adding builder-supplied ones.
   *
   * If a module upstream of markaspot_mail already populated
   * $message['params']['attachments'], the hook appends — it doesn't
   * replace. Prevents regressions where a dual-attach module's payload
   * silently vanishes after this hook runs.
   */
  public function testAlterMergesWithUpstreamAttachments(): void {
    $preExisting = [
      'filename' => 'upstream.txt',
      'filemime' => 'text/plain',
      'filecontent' => 'injected by some other module',
    ];

    $attachment = new MailAttachment(
      filename: 'photo.jpg',
      filemime: 'image/jpeg',
      filepath: 'public://photo.jpg',
    );
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'Escalation',
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
      attachments: [$attachment],
    ));

    $message = $this->buildMessage();
    $message['params']['attachments'] = [$preExisting];
    $this->buildHook($builder)->alter($message);

    $this->assertCount(2, $message['params']['attachments']);
    $this->assertSame($preExisting, $message['params']['attachments'][0]);
    $this->assertSame('photo.jpg', $message['params']['attachments'][1]['filename']);
  }

  /**
   * Avoids creating an empty attachment slot.
   *
   * Empty attachments list leaves params.attachments untouched. A
   * builder that opts out of attachments shouldn't implicitly create
   * an empty key that downstream logic might check with
   * array_key_exists() instead of !empty().
   */
  public function testAlterDoesNotCreateEmptyAttachmentsKey(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'No attachments here',
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
    ));

    $message = $this->buildMessage();
    $this->buildHook($builder)->alter($message);

    $this->assertArrayNotHasKey('attachments', $message['params']);
  }

  /**
   * Replaces a pre-existing case-insensitive Reply-To header.
   *
   * Symfony Mailer rejects duplicate Reply-To headers ("must be unique").
   * If MailManager or an upstream alter-hook seeded a lowercase reply-to,
   * our case-insensitive cleanup must remove it before we set our branded
   * Reply-To, so the message ends up with exactly one header for that name.
   */
  public function testAlterReplacesPreExistingCaseInsensitiveReplyTo(): void {
    $builder = new RecordingStubBuilder(new MailMessage(
      subject: 'Branded subject',
      variant: 'card_transactional',
      content: ['headline' => 'Hi'],
    ));

    $message = $this->buildMessage();
    $message['headers']['reply-to'] = 'old-upstream@example.com';
    $this->buildHook($builder)->alter($message);

    $replyToKeys = array_filter(
      array_keys($message['headers']),
      static fn ($key) => strcasecmp((string) $key, 'Reply-To') === 0,
    );
    $this->assertCount(1, $replyToKeys, 'Exactly one Reply-To header must remain.');
    $this->assertSame('Reply-To', array_values($replyToKeys)[0], 'Surviving header must use canonical casing.');
    $this->assertSame('support@civic-patches.com', $message['headers']['Reply-To']);
  }

  /**
   * Builds a MailAlterHook with mocked dependencies and a stub builder.
   */
  private function buildHook(
    ?MailBuilderInterface $builder,
    ?string $plainOverride = NULL,
    ?MailHtmlRenderer $renderer = NULL,
    ?callable $urlNormalizer = NULL,
    string $replyTo = 'support@civic-patches.com',
  ): MailAlterHook {
    $registry = new MailBuilderRegistry($builder === NULL ? [] : [$builder]);

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('normalizeMailUrls')
      ->willReturnCallback($urlNormalizer ?? static fn(string $value): string => $value);
    $branding->method('normalizeMailContent')
      ->willReturnCallback(function (array $content) use ($urlNormalizer): array {
        $normalizer = $urlNormalizer ?? static fn(string $value): string => $value;
        array_walk_recursive($content, static function (&$value) use ($normalizer): void {
          if (is_string($value)) {
            $value = $normalizer($value);
          }
        });
        return $content;
      });
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
      'reply_to' => $replyTo,
      'frontend_base_url' => 'https://mark-a-spot.com',
      'jurisdiction_slug' => NULL,
      'jurisdiction_label' => NULL,
      'platform_footer' => [],
    ]);

    if ($renderer === NULL) {
      $renderer = $this->createMock(MailHtmlRenderer::class);
      $renderer->method('render')->willReturn([
        'html' => '<p>rendered</p>',
        'plain' => $plainOverride ?? "first line\r\nsecond line\rstill second",
      ]);
    }

    $logger = $this->createMock(LoggerInterface::class);

    return new MailAlterHook($registry, $branding, $renderer, $logger);
  }

}
