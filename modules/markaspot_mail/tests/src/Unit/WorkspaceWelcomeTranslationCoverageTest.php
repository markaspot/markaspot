<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use PHPUnit\Framework\Attributes\Group;

use Drupal\Tests\UnitTestCase;

/**
 * Tests translation coverage for customized workspace welcome copy.
 */
#[Group('markaspot_mail')]
final class WorkspaceWelcomeTranslationCoverageTest extends UnitTestCase {

  /**
   * New source strings used only by non-default wording presets.
   */
  private const REQUIRED_MSGIDS = [
    'Try it out: create your first test @wording_singular directly on the map.',
    'A few demo @wording_plural are already in place. Edit or delete them anytime.',
    'Manage incoming @wording_plural in your dashboard:',
    'Manage incoming reports in your dashboard:',
    'Log in anytime:',
  ];

  /**
   * Placeholder occurrences that must be preserved in each translation.
   */
  private const REQUIRED_PLACEHOLDER_COUNTS = [
    'Try it out: create your first test @wording_singular directly on the map.' => [
      '@wording_singular' => 1,
    ],
    'A few demo @wording_plural are already in place. Edit or delete them anytime.' => [
      '@wording_plural' => 1,
    ],
    'Manage incoming @wording_plural in your dashboard:' => [
      '@wording_plural' => 1,
    ],
    'Manage incoming reports in your dashboard:' => [],
    'Log in anytime:' => [],
  ];

  /**
   * Tests all shipped translations retain every welcome placeholder.
   */
  public function testAllShippedTranslationsRetainWelcomePlaceholders(): void {
    foreach (WordingOverrideTranslationCoverageTest::SHIPPED_LOCALES as $langcode) {
      $source = file_get_contents($this->translationDir() . '/' . $langcode . '.po');
      $this->assertIsString($source);

      foreach (self::REQUIRED_MSGIDS as $msgid) {
        $translation = $this->translatedPoString($source, $msgid, $langcode . '.po');
        foreach (self::REQUIRED_PLACEHOLDER_COUNTS[$msgid] as $placeholder => $expectedCount) {
          $this->assertSame(
            $expectedCount,
            substr_count($translation, $placeholder),
            sprintf('Expected %d occurrences of "%s" in "%s" translation in %s.', $expectedCount, $placeholder, $msgid, $langcode . '.po'),
          );
        }
      }
    }
  }

  /**
   * Tests the template catalog contains the customized welcome source copy.
   */
  public function testPotContainsCustomizedWelcomeSources(): void {
    $source = file_get_contents($this->translationDir() . '/markaspot_mail.pot');
    $this->assertIsString($source);

    foreach (self::REQUIRED_MSGIDS as $msgid) {
      $this->assertStringContainsString(
        'msgid "' . addcslashes($msgid, "\\\"") . '"',
        $source,
        sprintf('Missing source string "%s" in markaspot_mail.pot.', $msgid),
      );
    }
  }

  /**
   * Gets a non-empty translation from a PO file.
   */
  private function translatedPoString(string $source, string $msgid, string $filename): string {
    $encodedMsgid = preg_quote(addcslashes($msgid, "\\\""), '/');
    $pattern = '/msgid "' . $encodedMsgid . "\"\\Rmsgstr \"(?<translation>(?!\")(?:[^\"\\\\]|\\\\.)*)\"/u";
    $this->assertMatchesRegularExpression(
      $pattern,
      $source,
      sprintf('Missing non-empty "%s" translation in %s.', $msgid, $filename),
    );
    preg_match($pattern, $source, $matches);
    return (string) ($matches['translation'] ?? '');
  }

  /**
   * Gets the module translation directory.
   */
  private function translationDir(): string {
    return dirname(__DIR__, 3) . '/translations';
  }

}
