<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests tenant wording-preset resolution for backend copy.
 */
#[CoversClass(CitizenWordingResolver::class)]
#[Group('markaspot_nuxt')]
final class CitizenWordingResolverTest extends UnitTestCase {

  /**
   * Builds a jurisdiction mock with an optional Nuxt config value.
   */
  private function buildJurisdiction(?string $json, bool $fieldExists = FALSE, bool $fieldEmpty = FALSE): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_nuxt_config' && ($json !== NULL || $fieldExists));
    $group->method('get')
      ->willReturnCallback(static function (string $name) use ($json, $fieldEmpty): object {
        return new class ($json, $fieldEmpty) {

          /**
           * Raw JSON field value.
           */
          public mixed $value;

          /**
           * Whether the field item list is empty.
           */
          private bool $empty;

          public function __construct(mixed $value, bool $empty) {
            $this->value = $value;
            $this->empty = $empty;
          }

          /**
           * Reports whether this mock field has no stored value.
           */
          public function isEmpty(): bool {
            return $this->empty;
          }

        };
      });

    return $group;
  }

  /**
   * Resolves a configured wording preset using German term data.
   */
  public function testResolvesConfiguredPresetWithGermanTerms(): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => 'suggestion'],
    ]));

    $result = (new CitizenWordingResolver())->resolve($jurisdiction, 'de-DE');

    $this->assertSame([
      'preset' => 'suggestion',
      'locale' => 'de',
      'singular' => 'Vorschlag',
      'plural' => 'Vorschläge',
    ], $result);
  }

  /**
   * Replaces the explicit mail placeholders with selected tenant terms.
   */
  public function testReplacesRuntimeMailPlaceholdersWithSentenceStartTerms(): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => 'entry'],
    ]));

    $result = (new CitizenWordingResolver())->replaceMailPlaceholders(
      'Your {{ citizen_term_singular }}. {{ citizen_term_singular_title }} / {{ citizen_term_plural }} / {{ citizen_term_plural_title }}',
      $jurisdiction,
      'en',
    );

    $this->assertSame('Your entry. Entry / entries / Entries', $result);
  }

  /**
   * Leaves ordinary authored text alone when it contains no opt-in token.
   */
  public function testLeavesMailTextWithoutRuntimePlaceholderUntouched(): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => 'suggestion'],
    ]));
    $text = 'A tenant-authored note about a report stays as written.';

    $this->assertSame(
      $text,
      (new CitizenWordingResolver())->replaceMailPlaceholders($text, $jurisdiction, 'en'),
    );
  }

  /**
   * Resolves a configured wording preset using another supported locale.
   */
  public function testResolvesConfiguredPresetWithSupportedLocaleTerms(): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => 'contribution'],
    ]));

    $result = (new CitizenWordingResolver())->resolve($jurisdiction, 'nl');

    $this->assertSame([
      'preset' => 'contribution',
      'locale' => 'nl',
      'singular' => 'bijdrage',
      'plural' => 'bijdragen',
    ], $result);
  }

  /**
   * Reads tenant-wide wording configuration from the untranslated group.
   */
  public function testReadsPresetFromUntranslatedJurisdiction(): void {
    $source = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => 'suggestion'],
    ]));
    $translated = $this->createMock(GroupInterface::class);
    $translated->expects($this->once())
      ->method('getUntranslated')
      ->willReturn($source);

    $result = (new CitizenWordingResolver())->resolve($translated, 'en');

    $this->assertSame([
      'preset' => 'suggestion',
      'locale' => 'en',
      'singular' => 'suggestion',
      'plural' => 'suggestions',
    ], $result);
  }

  /**
   * Resolves every public locale and preset to non-empty basic noun data.
   */
  #[DataProvider('supportedLocalePresetProvider')]
  public function testResolvesAllSupportedLocalePresetCombinations(string $locale, string $preset): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => $preset],
    ]));

    $result = (new CitizenWordingResolver())->resolve($jurisdiction, $locale);

    $this->assertSame($preset, $result['preset']);
    $this->assertSame($locale, $result['locale']);
    $this->assertNotSame('', $result['singular']);
    $this->assertNotSame('', $result['plural']);
  }

  /**
   * Formats each visible validation detail in the effective response locale.
   *
   * @covers ::formatValidationMessage
   */
  #[DataProvider('validationMessageProvider')]
  public function testFormatsAllLocalePresetValidationMessages(string $locale, string $preset, string $messageKey, array $placeholders): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => $preset],
    ]));
    $resolver = new CitizenWordingResolver();

    $formatted = $resolver->formatValidationMessage($messageKey, $jurisdiction, $locale);
    $terms = $resolver->resolve($jurisdiction, $locale);

    $termKey = str_starts_with($messageKey, 'duplicate_') ? 'singular' : 'plural';
    $containsSelectedTerm = $messageKey !== CitizenWordingResolver::VALIDATION_DUPLICATE_BLOCK;
    // The hard-block suffix deliberately contains no selected noun, but it
    // is still a shipped, locale-specific response template. Keep concrete
    // assertions here so PHPUnit does not classify those matrix cases as
    // risky and so an unresolved internal placeholder cannot slip through.
    $this->assertNotSame('', $formatted);
    $this->assertStringNotContainsString('@wording_', $formatted);
    if ($containsSelectedTerm) {
      $this->assertStringContainsString($terms[$termKey], $formatted);
    }
    foreach ($placeholders as $placeholder) {
      $this->assertStringContainsString($placeholder, $formatted);
    }

    foreach ($containsSelectedTerm ? CitizenWordingResolver::PRESETS : [] as $otherPreset) {
      if ($otherPreset === $preset) {
        continue;
      }
      $otherJurisdiction = $this->buildJurisdiction(json_encode([
        'i18n' => ['wording' => $otherPreset],
      ]));
      $otherTerm = $resolver->resolve($otherJurisdiction, $locale)[$termKey];
      $this->assertDoesNotMatchRegularExpression(
        '/(?<![\\p{L}\\p{N}])' . preg_quote($otherTerm, '/') . '(?![\\p{L}\\p{N}])/u',
        $formatted,
        sprintf('Validation template for %s/%s must not leak the %s label.', $locale, $preset, $otherPreset),
      );
    }
  }

  /**
   * Provides every locale/preset pair supported by the public resolver.
   */
  public static function supportedLocalePresetProvider(): iterable {
    $locales = [
      'ar', 'cs', 'da', 'de', 'de-ls', 'en', 'es', 'fi', 'fr', 'hu',
      'it', 'nb', 'nl', 'pl', 'pt', 'sv', 'tr', 'uk',
    ];
    $presets = ['report', 'suggestion', 'entry', 'contribution'];

    foreach ($locales as $locale) {
      foreach ($presets as $preset) {
        yield "$locale/$preset" => [$locale, $preset];
      }
    }
  }

  /**
   * Provides every public locale, preset, and validation message type.
   */
  public static function validationMessageProvider(): iterable {
    $messages = [
      CitizenWordingResolver::VALIDATION_TIER_TOTAL => ['@limit'],
      CitizenWordingResolver::VALIDATION_TIER_MONTHLY => ['@limit'],
      CitizenWordingResolver::VALIDATION_TIER_PUBLISHED => ['@limit'],
      CitizenWordingResolver::VALIDATION_EMAIL_DAILY_LIMIT => ['@count'],
      CitizenWordingResolver::VALIDATION_DUPLICATE_VISIBLE => ['@id', '@radius', '@unit'],
      CitizenWordingResolver::VALIDATION_DUPLICATE_HIDDEN => ['@radius', '@unit'],
      CitizenWordingResolver::VALIDATION_DUPLICATE_HINT => [],
      CitizenWordingResolver::VALIDATION_DUPLICATE_BLOCK => [],
    ];

    foreach (self::supportedLocalePresetProvider() as $name => [$locale, $preset]) {
      foreach ($messages as $messageKey => $placeholders) {
        yield "$name/$messageKey" => [$locale, $preset, $messageKey, $placeholders];
      }
    }
  }

  /**
   * Keeps the explicit simple-German locale instead of collapsing it.
   */
  public function testSimpleGermanKeepsItsOwnSupportedLocale(): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => 'entry'],
    ]));

    $result = (new CitizenWordingResolver())->resolve($jurisdiction, 'de-ls');

    $this->assertSame([
      'preset' => 'entry',
      'locale' => 'de-ls',
      'singular' => 'Eintrag',
      'plural' => 'Einträge',
    ], $result);
  }

  /**
   * Falls back safely when tenant configuration is absent or malformed.
   */
  #[DataProvider('invalidConfigProvider')]
  public function testInvalidOrMissingConfigFallsBackToReport(?string $json, bool $withoutJurisdiction): void {
    $jurisdiction = $withoutJurisdiction ? NULL : $this->buildJurisdiction($json);
    $result = (new CitizenWordingResolver())->resolve($jurisdiction, 'en');

    $this->assertSame([
      'preset' => CitizenWordingResolver::DEFAULT_PRESET,
      'locale' => 'en',
      'singular' => 'report',
      'plural' => 'reports',
    ], $result);
  }

  /**
   * Provides invalid or absent tenant config cases.
   */
  public static function invalidConfigProvider(): array {
    return [
      'no jurisdiction' => [NULL, TRUE],
      'missing field' => [NULL, FALSE],
      'malformed json' => ['{not json', FALSE],
      'missing wording' => [json_encode(['i18n' => []]), FALSE],
      'invalid preset' => [json_encode(['i18n' => ['wording' => 'idea']]), FALSE],
      'wrong casing' => [json_encode(['i18n' => ['wording' => 'Suggestion']]), FALSE],
      'wrong wording shape' => [json_encode(['i18n' => ['wording' => ['suggestion']]]), FALSE],
      'wrong i18n shape' => [json_encode(['i18n' => 'suggestion']), FALSE],
    ];
  }

  /**
   * Falls back from an existing but empty tenant config field.
   */
  public function testEmptyNuxtConfigFieldFallsBackToReport(): void {
    $jurisdiction = $this->buildJurisdiction(NULL, TRUE, TRUE);

    $result = (new CitizenWordingResolver())->resolve($jurisdiction, 'en');

    $this->assertSame(CitizenWordingResolver::DEFAULT_PRESET, $result['preset']);
    $this->assertSame('report', $result['singular']);
  }

  /**
   * Falls back from an existing config field whose stored value is empty.
   */
  public function testEmptyNuxtConfigValueFallsBackToReport(): void {
    $jurisdiction = $this->buildJurisdiction('', TRUE);

    $result = (new CitizenWordingResolver())->resolve($jurisdiction, 'en');

    $this->assertSame(CitizenWordingResolver::DEFAULT_PRESET, $result['preset']);
    $this->assertSame('report', $result['singular']);
  }

  /**
   * Falls back to English term data when the requested locale is unknown.
   */
  public function testUnknownLocaleFallsBackToEnglishTerms(): void {
    $jurisdiction = $this->buildJurisdiction(json_encode([
      'i18n' => ['wording' => 'entry'],
    ]));

    $result = (new CitizenWordingResolver())->resolve($jurisdiction, 'xx-YY');

    $this->assertSame([
      'preset' => 'entry',
      'locale' => 'en',
      'singular' => 'entry',
      'plural' => 'entries',
    ], $result);
  }

}
