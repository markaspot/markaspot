<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Protects custom-wording mail sources and their placeholders in every locale.
 */
#[Group('markaspot_mail')]
final class WordingOverrideTranslationCoverageTest extends UnitTestCase {

  /**
   * All locale catalogs shipped with the mail module.
   *
   * @var list<string>
   */
  public const SHIPPED_LOCALES = [
    'ar',
    'cs',
    'da',
    'de',
    'de-ls',
    'es',
    'fi',
    'fr',
    'hu',
    'it',
    'nb',
    'nl',
    'pl',
    'pt',
    'sv',
    'tr',
    'uk',
  ];

  /**
   * New grammar-neutral sources used only for non-report wording presets.
   *
   * @var array<string, array<string, int>>
   */
  private const REQUIRED_SOURCES = [
    '@wording_singular_title was resolved' => [
      '@wording_singular_title' => 1,
    ],
    'Thank you for using the issue tracker. @wording_singular_title "@title" has been processed and is now marked as completed.' => [
      '@wording_singular_title' => 1,
      '@title' => 1,
    ],
    'Feedback welcome for @wording_singular_title @id' => [
      '@wording_singular_title' => 1,
      '@id' => 1,
    ],
    '@wording_singular_title @id: feedback welcome' => [
      '@wording_singular_title' => 1,
      '@id' => 1,
    ],
    'More information needed: @wording_singular_title' => [
      '@wording_singular_title' => 1,
    ],
    'More information is needed: @wording_singular_title.' => [
      '@wording_singular_title' => 1,
    ],
    'Please clarify: @wording_singular_title' => [
      '@wording_singular_title' => 1,
    ],
    'Update: @wording_singular_title' => [
      '@wording_singular_title' => 1,
    ],
    'There is an update: @wording_singular_title. Please check the platform for details.' => [
      '@wording_singular_title' => 1,
    ],
  ];

  /**
   * Loanwords that are intentionally the same in their target locale.
   *
   * @var array<string, list<string>>
   */
  private const ALLOWED_SOURCE_EQUIVALENT_TRANSLATIONS = [
    'de' => [
      'Update: @wording_singular_title',
    ],
    'nl' => [
      'Update: @wording_singular_title',
    ],
  ];

  /**
   * Every shipped locale translates the custom-wording path and keeps tokens.
   */
  public function testAllWordingOverrideTranslationsRetainPlaceholders(): void {
    $pot = file_get_contents($this->translationsPath('markaspot_mail.pot'));
    $this->assertIsString($pot);

    foreach (self::SHIPPED_LOCALES as $locale) {
      $translationCatalog = file_get_contents($this->translationsPath($locale . '.po'));
      $this->assertIsString($translationCatalog);

      foreach (self::REQUIRED_SOURCES as $msgid => $placeholders) {
        $this->assertStringContainsString(
          'msgid "' . addcslashes($msgid, "\\\"") . '"',
          $pot,
          'Missing wording-override source in markaspot_mail.pot: ' . $msgid,
        );
        $translation = $this->translatedPoString($translationCatalog, $msgid, $locale);
        if (!in_array($msgid, self::ALLOWED_SOURCE_EQUIVALENT_TRANSLATIONS[$locale] ?? [], TRUE)) {
          $this->assertNotSame(
            $msgid,
            $translation,
            sprintf('The %s translation must not fall back to its English source: %s.', $locale, $msgid),
          );
        }
        foreach ($placeholders as $placeholder => $expectedCount) {
          $this->assertSame(
            $expectedCount,
            substr_count($translation, $placeholder),
            sprintf('Expected %d occurrences of %s in the %s translation for %s.', $expectedCount, $placeholder, $locale, $msgid),
          );
        }
      }
    }
  }

  /**
   * The German strings deliberately avoid gendered possessive forms.
   */
  public function testGermanTranslationsStayGrammarNeutralForEntry(): void {
    $german = file_get_contents($this->translationsPath('de.po'));
    $this->assertIsString($german);

    $this->assertSame(
      '@wording_singular_title wurde bearbeitet',
      $this->translatedPoString($german, '@wording_singular_title was resolved'),
    );
    $this->assertSame(
      'Weitere Angaben werden benötigt: @wording_singular_title.',
      $this->translatedPoString($german, 'More information is needed: @wording_singular_title.'),
    );
  }

  /**
   * Existing tenants reimport these new sources during the module update.
   */
  public function testExistingTenantsGetWordingTranslationReimportUpdate(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/markaspot_mail.install');
    $this->assertIsString($install);
    $this->assertStringContainsString('function markaspot_mail_update_10009(): string', $install);
    $this->assertStringContainsString('return _markaspot_mail_import_shipped_translations();', $install);
  }

  /**
   * Reads a single-line translated PO entry for a known source message.
   */
  private function translatedPoString(string $source, string $msgid, string $locale = 'de'): string {
    $encodedMsgid = preg_quote(addcslashes($msgid, "\\\""), '/');
    $pattern = '/msgid "' . $encodedMsgid . "\"\\Rmsgstr \"(?<translation>(?!\")(?:[^\"\\\\]|\\\\.)*)\"/u";
    $this->assertMatchesRegularExpression($pattern, $source, 'Missing ' . $locale . ' translation for ' . $msgid);
    preg_match($pattern, $source, $matches);
    return (string) ($matches['translation'] ?? '');
  }

  /**
   * Returns a translation file's path inside the module.
   */
  private function translationsPath(string $filename): string {
    return dirname(__DIR__, 3) . '/translations/' . $filename;
  }

}
