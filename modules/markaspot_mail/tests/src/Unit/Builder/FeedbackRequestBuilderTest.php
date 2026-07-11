<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Utility\Token;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\FeedbackRequestBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailBrandingService;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests feedback-request mail rendering.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\FeedbackRequestBuilder::class)]
#[Group('markaspot_mail')]
final class FeedbackRequestBuilderTest extends UnitTestCase {

  /**
   * Reports the feedback mail type.
   */
  public function testGetTypeReturnsEcaFeedback(): void {
    $builder = $this->buildBuilder();
    $this->assertSame(MailType::ECA_FEEDBACK, $builder->getType());
  }

  /**
   * Claims only the feedback-request mail key.
   */
  public function testSupportsOnlyFeedbackRequestKey(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_feedback', 'feedback_request'));
    $this->assertFalse($builder->supports('markaspot_feedback', 'other_key'));
    $this->assertFalse($builder->supports('other_module', 'feedback_request'));
    $this->assertFalse($builder->supports('markaspot_escalation', 'escalation_notice'));
  }

  /**
   * Rejects an absent service-request node.
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(NULL, $logger);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: [],
      to: 'user@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Rejects a non-node value in the mail context.
   */
  public function testBuildReturnsNullWhenParamIsNotNode(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(NULL, $logger);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: ['node' => 'not-a-node'],
      to: 'user@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Renders a feedback mail in its referenced jurisdiction mode.
   */
  public function testBuildProducesJurisdictionScopedMailMessage(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(7);

    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
      ['request_id', $this->singleValueField('2026-0042')],
    ]);
    $node->method('label')->willReturn('Broken streetlight');
    $node->method('uuid')->willReturn('abc-123-uuid');

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'mode' => 'jurisdiction',
      'frontend_base_url' => 'https://utrecht.civicspot.io',
      'jurisdiction_slug' => 'utrecht',
      'email_footer_html' => Markup::create(''),
    ]);

    $builder = $this->buildBuilder($branding);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'user@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(7, $msg->jurisdictionId);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertStringContainsString('2026-0042', $msg->subject);
    $this->assertSame(
      'https://utrecht.civicspot.io/utrecht/feedback/abc-123-uuid',
      $msg->content['cta_url']
    );
    $this->assertStringContainsString('Broken streetlight', $msg->content['intro']);
    $this->assertSame('Give feedback', $msg->content['cta_label']);
    $this->assertArrayHasKey('features_block', $msg->content);
  }

  /**
   * Keeps platform mode and legacy sources without a jurisdiction.
   */
  public function testBuildFallsBackToPlatformModeWhenJurisdictionMissing(): void {
    $emptyField = $this->createMock(FieldItemListInterface::class);
    $emptyField->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', FALSE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $emptyField],
    ]);
    $node->method('label')->willReturn('Pothole');
    $node->method('id')->willReturn(101);
    $node->method('uuid')->willReturn('xyz-999-uuid');

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'mode' => 'platform',
      'frontend_base_url' => 'https://mark-a-spot.com',
      'jurisdiction_slug' => NULL,
      'email_footer_html' => Markup::create(''),
    ]);

    $translatedSources = [];
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static function (TranslatableMarkup $markup) use (&$translatedSources): string {
        $source = $markup->getUntranslatedString();
        $translatedSources[] = $source;
        return $source;
      },
    );
    $builder = $this->buildBuilder($branding, translation: $translation);

    $ctx = new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'user@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
    $this->assertStringContainsString('101', $msg->subject);
    $this->assertSame(
      'https://mark-a-spot.com/feedback/xyz-999-uuid',
      $msg->content['cta_url']
    );
    $this->assertContains('Your report was resolved', $translatedSources);
    $this->assertContains('Feedback welcome on your report @id', $translatedSources);
    $this->assertNotContains('@wording_singular_title was resolved', $translatedSources);
  }

  /**
   * Uses grammar-safe German custom wording in every generated fallback slot.
   */
  public function testBuildUsesCustomGermanWordingWithoutLegacyReportSources(): void {
    $group = $this->buildWordingJurisdiction('entry');
    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
      ['request_id', $this->singleValueField('2026-0042')],
    ]);
    $node->method('label')->willReturn('Defekte Laterne');
    $node->method('uuid')->willReturn('entry-feedback-uuid');

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'frontend_base_url' => 'https://civicspot.example',
      'jurisdiction_slug' => 'eintraege',
    ]);

    $translatedSources = [];
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static function (TranslatableMarkup $markup) use (&$translatedSources): string {
        $source = $markup->getUntranslatedString();
        $translatedSources[] = $source;
        return match ($source) {
          '@wording_singular_title was resolved' => '@wording_singular_title wurde bearbeitet',
          'Thank you for using the issue tracker. @wording_singular_title "@title" has been processed and is now marked as completed.' => 'Vielen Dank für Ihre Mitwirkung. @wording_singular_title „@title“ wurde bearbeitet und ist abgeschlossen.',
          'Feedback welcome for @wording_singular_title @id' => 'Rückmeldung zu @wording_singular_title @id',
          '@wording_singular_title @id: feedback welcome' => '@wording_singular_title @id: Rückmeldung erbeten',
          default => $source,
        };
      },
    );

    $msg = $this->buildBuilder(
      $branding,
      citizenWordingResolver: new CitizenWordingResolver(),
      translation: $translation,
    )->build(new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'de',
      params: ['node' => $node],
      to: 'citizen@example.com',
    ));

    $this->assertNotNull($msg);
    $this->assertSame('Eintrag 2026-0042: Rückmeldung erbeten', $msg->subject);
    $this->assertSame('Eintrag wurde bearbeitet', $msg->content['headline']);
    $this->assertSame('Vielen Dank für Ihre Mitwirkung. Eintrag „Defekte Laterne“ wurde bearbeitet und ist abgeschlossen.', $msg->content['intro']);
    $this->assertSame('Rückmeldung zu Eintrag 2026-0042', $msg->content['preheader']);
    $this->assertArrayHasKey('Eintrag', $msg->content['features_block']);
    $this->assertNotContains('Your report was resolved', $translatedSources);
    $this->assertNotContains('Thank you for using the issue tracker. Your report "@title" has been processed and is now marked as completed.', $translatedSources);
  }

  /**
   * Replaces the explicit subject placeholder in an active config template.
   */
  public function testBuildReplacesCustomTermInConfiguredSubjectBeforeNodeTokens(): void {
    $group = $this->buildWordingJurisdiction('entry');
    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
      ['request_id', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_jurisdiction', $jurisdictionField],
      ['request_id', $this->singleValueField('2026-0042')],
    ]);
    $node->method('label')->willReturn('Defekte Laterne');
    $node->method('uuid')->willReturn('configured-subject-uuid');

    $branding = $this->createMock(MailBrandingService::class);
    $branding->method('getBranding')->willReturn([
      'frontend_base_url' => 'https://civicspot.example',
      'jurisdiction_slug' => 'eintraege',
    ]);
    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(
      static fn (string $template): string => str_replace('[node:request_id]', '2026-0042', $template),
    );

    $msg = $this->buildBuilder(
      $branding,
      citizenWordingResolver: new CitizenWordingResolver(),
      configTemplate: [
        'subject' => '{{ citizen_term_singular_title }} [node:request_id]: Rückmeldung erbeten',
      ],
      token: $token,
    )->build(new MailContext(
      module: 'markaspot_feedback',
      key: 'feedback_request',
      langcode: 'de',
      params: ['node' => $node],
      to: 'citizen@example.com',
    ));

    $this->assertNotNull($msg);
    $this->assertSame('Eintrag 2026-0042: Rückmeldung erbeten', $msg->subject);
    $this->assertStringNotContainsString('{{ citizen_term_', $msg->subject);
  }

  /**
   * Builds the subject with mocked deps + string-translation stub.
   *
   * ConfigFactory + LanguageManager are stubbed to return empty config so
   * the builder falls back to the hardcoded t() subject template. Token
   * service is stubbed to a no-op replace() that returns its input. Tests
   * that want the config-driven path assert against that explicitly.
   */
  private function buildBuilder(
    ?MailBrandingService $branding = NULL,
    ?LoggerInterface $logger = NULL,
    ?CitizenWordingResolver $citizenWordingResolver = NULL,
    ?TranslationInterface $translation = NULL,
    ?array $configTemplate = NULL,
    ?array $overrideTemplate = NULL,
    ?Token $token = NULL,
  ): FeedbackRequestBuilder {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $immutableConfig = $this->createMock(ImmutableConfig::class);
    $immutableConfig->method('get')->willReturn($configTemplate);
    $configFactory->method('get')->willReturn($immutableConfig);

    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $override = $this->createMock(LanguageConfigOverride::class);
    $override->method('get')->willReturn($overrideTemplate);
    $languageManager->method('getLanguageConfigOverride')->willReturn($override);

    if ($token === NULL) {
      $token = $this->createMock(Token::class);
      $token->method('replace')->willReturnCallback(
        fn (string $template): string => $template,
      );
    }

    $builder = new FeedbackRequestBuilder(
      $branding ?? $this->createMock(MailBrandingService::class),
      $configFactory,
      $languageManager,
      $token,
      $citizenWordingResolver ?? new CitizenWordingResolver(),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($translation ?? $this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Builds a valid jurisdiction with a selected wording preset.
   */
  private function buildWordingJurisdiction(string $preset): GroupInterface {
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
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(7);
    return $group;
  }

  /**
   * Builds a single-value FieldItemListInterface stub via getString().
   *
   * We intentionally avoid the magic ->value accessor; the builder uses
   * getString() so the mock stays typed and works under PHP 8.2+
   * dynamic-property deprecations.
   */
  private function singleValueField(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('getString')->willReturn($value);
    return $field;
  }

}
