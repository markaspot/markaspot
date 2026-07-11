<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\NotificationTextBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests config-driven notification-mail rendering.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\NotificationTextBuilder::class)]
#[Group('markaspot_mail')]
final class NotificationTextBuilderTest extends UnitTestCase {

  /**
   * Reports the config-driven notification mail type.
   */
  public function testGetTypeReturnsNotificationConfig(): void {
    $this->assertSame(MailType::NOTIFICATION_CONFIG, $this->buildBuilder()->getType());
  }

  /**
   * Claims only the notification mail-key namespace.
   */
  public function testSupportsOnlyNotificationPrefixedKeys(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_mail', 'notification_report_confirmation'));
    $this->assertTrue($builder->supports('markaspot_mail', 'notification_status_open'));
    $this->assertFalse($builder->supports('markaspot_mail', 'other'));
    $this->assertFalse($builder->supports('other', 'notification_report_confirmation'));
  }

  /**
   * Rejects mail contexts without a service-request node.
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: [],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Rejects templates without a usable subject.
   */
  public function testBuildReturnsNullWhenResolvedSubjectIsEmpty(): void {
    $node = $this->buildPlainNode();

    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn([
      'subject' => '',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ]);
    $textResolver->method('replaceTokens')->willReturnArgument(0);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $builder = $this->buildBuilder(textResolver: $textResolver, logger: $logger);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Produces a platform mail when no jurisdiction is referenced.
   */
  public function testBuildProducesPlatformMailWithoutJurisdiction(): void {
    $node = $this->buildPlainNode();

    $slots = [
      'subject' => 'Your report #42 has been received',
      'headline' => 'We received your report',
      'intro' => 'Your report #42 has been received.',
      'body_blocks' => ['Category: Pothole'],
      'cta_label' => 'View your report',
      'preheader' => 'We received your report.',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturn($slots);

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('Your report #42 has been received', $msg->subject);
    $this->assertSame('We received your report', $msg->content['headline']);
    $this->assertSame(['Category: Pothole'], $msg->content['body_blocks']);
    $this->assertSame('View your report', $msg->content['cta_label']);
    $this->assertSame('https://tenant.example.org/amsterdam/requests/42', $msg->content['cta_url']);
  }

  /**
   * Selects jurisdiction mode from the referenced group.
   */
  public function testBuildResolvesJurisdictionModeFromNode(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(7);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
    ]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);

    $slots = [
      'subject' => 'Update on your report #42',
      'headline' => '',
      'intro' => '',
      'body_blocks' => [],
      'cta_label' => '',
      'preheader' => '',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturn($slots);

    $builder = $this->buildBuilder(textResolver: $textResolver);
    $ctx = new MailContext(
      module: 'markaspot_mail',
      key: 'notification_status_open',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'status_open'],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(7, $msg->jurisdictionId);
    $this->assertArrayNotHasKey('cta_label', $msg->content);
    $this->assertArrayNotHasKey('cta_url', $msg->content);
  }

  /**
   * Shipped templates resolve their opt-in runtime wording before node tokens.
   */
  public function testBuildReplacesRuntimeWordingPlaceholdersForJurisdiction(): void {
    $group = $this->buildWordingJurisdiction('entry');
    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $requestIdField = $this->createMock(FieldItemListInterface::class);
    $requestIdField->method('isEmpty')->willReturn(FALSE);
    $requestIdField->method('getString')->willReturn('42');

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
      ['request_id', $requestIdField],
    ]);

    $slots = [
      'subject' => 'Your {{ citizen_term_singular }} #[node:request_id] has been received',
      'headline' => 'We received your {{ citizen_term_singular }}',
      'intro' => 'Your {{ citizen_term_singular }} is ready.',
      'body_blocks' => ['View {{ citizen_term_plural }}.'],
      'cta_label' => 'View your {{ citizen_term_singular }}',
      'preheader' => '{{ citizen_term_singular_title }} ready.',
    ];
    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolve')->willReturn($slots);
    $textResolver->method('replaceTokens')->willReturnArgument(0);

    $msg = $this->buildBuilder(
      textResolver: $textResolver,
      citizenWordingResolver: new CitizenWordingResolver(),
    )->build(new MailContext(
      module: 'markaspot_mail',
      key: 'notification_report_confirmation',
      langcode: 'en',
      params: ['node' => $node, 'notification_key' => 'report_confirmation'],
      to: 'citizen@example.com',
    ));

    $this->assertNotNull($msg);
    $this->assertSame('Your entry #[node:request_id] has been received', $msg->subject);
    $this->assertSame('We received your entry', $msg->content['headline']);
    $this->assertSame(['View entries.'], $msg->content['body_blocks']);
    $this->assertSame('Entry ready.', $msg->content['preheader']);
    $this->assertStringNotContainsString('{{ citizen_term_', $msg->subject);
  }

  /**
   * Builds a mock node without jurisdiction but with a request_id of 42.
   *
   * No toUrl() stub on purpose: the builder must derive the CTA from the
   * branding package, never from the node's canonical (backend) URL.
   */
  private function buildPlainNode(): NodeInterface&MockObject {
    $requestIdField = $this->createMock(FieldItemListInterface::class);
    $requestIdField->method('isEmpty')->willReturn(FALSE);
    $requestIdField->method('getString')->willReturn('42');

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', FALSE],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['request_id', $requestIdField],
    ]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);

    return $node;
  }

  /**
   * Builds a valid jurisdiction with a selected i18n wording preset.
   */
  private function buildWordingJurisdiction(string $preset): GroupInterface&MockObject {
    $configField = new class ((string) json_encode(['i18n' => ['wording' => $preset]])) {

      /**
       * Raw JSON config value.
       */
      public string $value;

      /**
       * Stores the raw config value.
       */
      public function __construct(string $value) {
        $this->value = $value;
      }

      /**
       * Reports the mock field as populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_nuxt_config',
    );
    $group->method('get')->with('field_nuxt_config')->willReturn($configField);
    $group->method('id')->willReturn(7);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');
    return $group;
  }

  /**
   * Builds the builder with default or injected mocks.
   */
  private function buildBuilder(
    ?MailTextResolver $textResolver = NULL,
    ?LoggerInterface $logger = NULL,
    ?MailBrandingService $branding = NULL,
    ?CitizenWordingResolver $citizenWordingResolver = NULL,
  ): NotificationTextBuilder {
    if ($textResolver === NULL) {
      $textResolver = $this->createMock(MailTextResolver::class);
      $textResolver->method('resolve')->willReturn([
        'subject' => '',
        'headline' => '',
        'intro' => '',
        'body_blocks' => [],
        'cta_label' => '',
        'preheader' => '',
      ]);
      $textResolver->method('replaceTokens')->willReturnArgument(0);
    }
    if ($branding === NULL) {
      $branding = $this->createMock(MailBrandingService::class);
      $branding->method('getBranding')->willReturn([
        'frontend_base_url' => 'https://tenant.example.org',
        'jurisdiction_slug' => 'amsterdam',
      ]);
    }
    return new NotificationTextBuilder(
      $textResolver,
      $branding,
      $citizenWordingResolver ?? new CitizenWordingResolver(),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
