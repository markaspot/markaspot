<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Utility\Token;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_mail\Service\MailTextResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the override-then-default-then-empty merge for mail text templates.
 */
#[CoversClass(MailTextResolver::class)]
#[Group('markaspot_mail')]
final class MailTextResolverTest extends UnitTestCase {

  /**
   *
   */
  public function testResolveFieldPrefersLanguageOverride(): void {
    $resolver = $this->buildResolver(
      override: ['subject' => 'DE subject'],
      default: ['subject' => 'EN subject'],
    );
    $this->assertSame('DE subject', $resolver->resolveField('markaspot_fastmap.mail', 'workspace_welcome', 'subject', 'de'));
  }

  /**
   *
   */
  public function testResolveFieldFallsBackToDefaultWhenOverrideMissingField(): void {
    // Override present for the top-level key, but the specific field is
    // absent from it: default must backfill just that field.
    $resolver = $this->buildResolver(
      override: ['headline' => 'DE headline only'],
      default: ['subject' => 'EN subject', 'headline' => 'EN headline'],
    );
    $this->assertSame('EN subject', $resolver->resolveField('markaspot_mail.texts', 'report_confirmation', 'subject', 'de'));
    $this->assertSame('DE headline only', $resolver->resolveField('markaspot_mail.texts', 'report_confirmation', 'headline', 'de'));
  }

  /**
   *
   */
  public function testResolveFieldReturnsEmptyStringWhenNeitherSourceHasIt(): void {
    $resolver = $this->buildResolver(override: [], default: []);
    $this->assertSame('', $resolver->resolveField('markaspot_mail.texts', 'report_confirmation', 'subject', 'de'));
  }

  /**
   *
   */
  public function testResolveFieldTreatsExplicitEmptyOverrideAsAuthoritative(): void {
    // A field present in the override, even as an explicit empty string,
    // wins over a non-empty default. Mirrors _markaspot_fastmap_mail_
    // template()'s array-union semantics exactly.
    $resolver = $this->buildResolver(
      override: ['subject' => ''],
      default: ['subject' => 'EN subject'],
    );
    $this->assertSame('', $resolver->resolveField('markaspot_mail.texts', 'report_confirmation', 'subject', 'de'));
  }

  /**
   *
   */
  public function testResolveReturnsAllSixSlotsWithPerFieldFallback(): void {
    $resolver = $this->buildResolver(
      override: ['subject' => 'DE subject', 'body_blocks' => ['DE block 1']],
      default: [
        'subject' => 'EN subject',
        'headline' => 'EN headline',
        'intro' => 'EN intro',
        'body_blocks' => ['EN block 1', 'EN block 2'],
        'cta_label' => 'EN cta',
        'preheader' => 'EN preheader',
      ],
    );
    $slots = $resolver->resolve('markaspot_mail.texts', 'report_confirmation', 'de');

    $this->assertSame('DE subject', $slots['subject']);
    $this->assertSame('EN headline', $slots['headline']);
    $this->assertSame('EN intro', $slots['intro']);
    $this->assertSame(['DE block 1'], $slots['body_blocks']);
    $this->assertSame('EN cta', $slots['cta_label']);
    $this->assertSame('EN preheader', $slots['preheader']);
  }

  /**
   *
   */
  public function testResolveReturnsEmptyListForMissingBodyBlocks(): void {
    $resolver = $this->buildResolver(override: [], default: ['subject' => 'EN subject']);
    $slots = $resolver->resolve('markaspot_mail.texts', 'report_confirmation', 'en');
    $this->assertSame([], $slots['body_blocks']);
  }

  /**
   *
   */
  public function testReplaceTokensReplacesScalarSlotsAndEachBodyBlock(): void {
    $resolver = $this->buildResolver(override: [], default: []);
    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(
      fn (string $tmpl): string => str_replace('[node:request_id]', '42-2026', $tmpl),
    );
    $resolver = $this->buildResolverWithToken($token);

    $slots = [
      'subject' => 'Report #[node:request_id]',
      'headline' => '',
      'intro' => 'Your report #[node:request_id]',
      'body_blocks' => ['Block one #[node:request_id]', 'Block two'],
      'cta_label' => 'View #[node:request_id]',
      'preheader' => '',
    ];
    $replaced = $resolver->replaceTokens($slots, ['node' => new \stdClass()], 'en');

    $this->assertSame('Report #42-2026', $replaced['subject']);
    $this->assertSame('', $replaced['headline']);
    $this->assertSame('Your report #42-2026', $replaced['intro']);
    $this->assertSame(['Block one #42-2026', 'Block two'], $replaced['body_blocks']);
    $this->assertSame('View #42-2026', $replaced['cta_label']);
    $this->assertSame('', $replaced['preheader']);
  }

  /**
   * Builds a resolver with a fixed override + default template pair.
   */
  private function buildResolver(array $override, array $default): MailTextResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn($default);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $languageOverride = $this->createMock(LanguageConfigOverride::class);
    $languageOverride->method('get')->willReturn($override);
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')->willReturn($languageOverride);

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(fn (string $t): string => $t);

    return new MailTextResolver($configFactory, $languageManager, $token);
  }

  /**
   * Builds a resolver with an empty override/default and a fixed token mock.
   */
  private function buildResolverWithToken(Token $token): MailTextResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $languageOverride = $this->createMock(LanguageConfigOverride::class);
    $languageOverride->method('get')->willReturn([]);
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')->willReturn($languageOverride);

    return new MailTextResolver($configFactory, $languageManager, $token);
  }

}
