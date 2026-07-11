<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Utility\Token;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\ResubmissionRequestBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests resubmission-request mail rendering.
 */
#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\ResubmissionRequestBuilder::class)]
#[Group('markaspot_mail')]
final class ResubmissionRequestBuilderTest extends UnitTestCase {

  /**
   * Reports the resubmission mail type.
   */
  public function testGetTypeReturnsEcaResubmission(): void {
    $this->assertSame(MailType::ECA_RESUBMISSION, $this->buildBuilder()->getType());
  }

  /**
   * Claims only the resubmission mail key.
   */
  public function testSupportsOnlyResubmitRequest(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_resubmission', 'resubmit_request'));
    $this->assertFalse($builder->supports('markaspot_resubmission', 'other'));
    $this->assertFalse($builder->supports('other', 'resubmit_request'));
  }

  /**
   * Rejects an absent service-request node.
   */
  public function testBuildReturnsNullWhenNodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: [],
      to: 'citizen@example.com',
    );
    $this->assertNull($builder->build($ctx));
  }

  /**
   * Uses legacy hardcoded copy when the config template is absent.
   */
  public function testBuildFallsBackToHardcodedCopyWhenConfigMissing(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $translatedSources = [];
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static function (TranslatableMarkup $markup) use (&$translatedSources): string {
        $source = $markup->getUntranslatedString();
        $translatedSources[] = $source;
        return $source;
      },
    );
    $builder = $this->buildBuilder(translation: $translation);
    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('card_transactional', $msg->variant);
    $this->assertSame('platform', $msg->mode);
    $this->assertStringContainsString('needs more information', $msg->subject);
    $this->assertSame('Please clarify your report', $msg->content['headline']);
    $this->assertContains('Your report needs more information', $translatedSources);
    $this->assertContains('Please clarify your report', $translatedSources);
    $this->assertNotContains('More information needed: @wording_singular_title', $translatedSources);
  }

  /**
   * Keeps explicit config templates and performs node token replacement.
   */
  public function testBuildAppliesConfigTemplateViaTokenReplace(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolveField')->willReturnCallback(
      fn (string $configName, string $key, string $field, string $langcode): string => match ($field) {
        'subject' => 'Please clarify [node:title]',
        'body' => "Hello,\n\nPlease clarify the report [node:request_id].",
        default => '',
      },
    );

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(
      fn (string $tmpl): string => str_replace(
        ['[node:title]', '[node:request_id]'],
        ['Pothole on Main St', '42-2026'],
        $tmpl,
      ),
    );

    $builder = $this->buildBuilder(
      textResolver: $textResolver,
      token: $token,
    );
    $ctx = new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'en',
      params: ['node' => $node],
      to: 'citizen@example.com',
    );
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('Please clarify Pothole on Main St', $msg->subject);
    $this->assertSame('Hello,', (string) $msg->content['intro']);
    $this->assertSame(['Please clarify the report 42-2026.'], array_map('strval', $msg->content['body_blocks']));
  }

  /**
   * Uses an uninflected German phrase shape for a custom citizen term.
   */
  public function testBuildUsesCustomGermanWordingForHardcodedFallbacks(): void {
    $group = $this->buildWordingJurisdiction('entry');
    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
    ]);
    $node->method('get')->with('field_jurisdiction')->willReturn($jurisdictionField);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static function (TranslatableMarkup $markup): string {
        return match ($markup->getUntranslatedString()) {
          'More information needed: @wording_singular_title' => 'Weitere Angaben benötigt: @wording_singular_title',
          'More information is needed: @wording_singular_title.' => 'Weitere Angaben werden benötigt: @wording_singular_title.',
          'Please clarify: @wording_singular_title' => 'Bitte ergänzen: @wording_singular_title',
          default => $markup->getUntranslatedString(),
        };
      },
    );

    $msg = $this->buildBuilder(
      citizenWordingResolver: new CitizenWordingResolver(),
      translation: $translation,
    )->build(new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'de',
      params: ['node' => $node],
      to: 'citizen@example.com',
    ));

    $this->assertNotNull($msg);
    $this->assertSame('Weitere Angaben benötigt: Eintrag', $msg->subject);
    $this->assertSame('Weitere Angaben benötigt: Eintrag', $msg->content['preheader']);
    $this->assertSame('Bitte ergänzen: Eintrag', $msg->content['headline']);
    $this->assertSame('Weitere Angaben werden benötigt: Eintrag.', $msg->content['intro']);
  }

  /**
   * Replaces custom wording in configured subject and body before node tokens.
   */
  public function testBuildReplacesCustomTermInConfiguredSubjectAndBody(): void {
    $group = $this->buildWordingJurisdiction('entry');
    $jurisdictionField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('referencedEntities')->willReturn([$group]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_jurisdiction', TRUE],
    ]);
    $node->method('get')->with('field_jurisdiction')->willReturn($jurisdictionField);

    $textResolver = $this->createMock(MailTextResolver::class);
    $textResolver->method('resolveField')->willReturnCallback(
      static fn (string $configName, string $key, string $field): string => match ($field) {
        'subject' => 'Erinnerung: {{ citizen_term_singular_title }} [node:title]',
        'body' => "Noch offen: {{ citizen_term_singular_title }}\n\nLink: [node:url]",
        default => '',
      },
    );
    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(
      static fn (string $template): string => str_replace(
        ['[node:title]', '[node:url]'],
        ['Defekte Laterne', 'https://civicspot.example/eintrag'],
        $template,
      ),
    );

    $msg = $this->buildBuilder(
      textResolver: $textResolver,
      token: $token,
      citizenWordingResolver: new CitizenWordingResolver(),
    )->build(new MailContext(
      module: 'markaspot_resubmission',
      key: 'resubmit_request',
      langcode: 'de',
      params: ['node' => $node],
      to: 'citizen@example.com',
    ));

    $this->assertNotNull($msg);
    $this->assertSame('Erinnerung: Eintrag Defekte Laterne', $msg->subject);
    $this->assertSame('Noch offen: Eintrag', (string) $msg->content['intro']);
    $this->assertSame(['Link: https://civicspot.example/eintrag'], array_map('strval', $msg->content['body_blocks']));
    $this->assertStringNotContainsString('{{ citizen_term_', $msg->subject);
  }

  /**
   * Builds the subject with optional dep injection.
   */
  private function buildBuilder(
    ?MailTextResolver $textResolver = NULL,
    ?Token $token = NULL,
    ?LoggerInterface $logger = NULL,
    ?CitizenWordingResolver $citizenWordingResolver = NULL,
    ?TranslationInterface $translation = NULL,
  ): ResubmissionRequestBuilder {
    if ($textResolver === NULL) {
      $textResolver = $this->createMock(MailTextResolver::class);
      $textResolver->method('resolveField')->willReturn('');
    }
    if ($token === NULL) {
      $token = $this->createMock(Token::class);
      $token->method('replace')->willReturnCallback(fn (string $t): string => $t);
    }
    $builder = new ResubmissionRequestBuilder(
      $textResolver,
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

}
