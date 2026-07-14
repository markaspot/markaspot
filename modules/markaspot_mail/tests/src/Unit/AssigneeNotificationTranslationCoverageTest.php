<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use PHPUnit\Framework\Attributes\Group;

use Drupal\Tests\UnitTestCase;

/**
 * Tests translation coverage for assignee assignment mails.
 */
#[Group('markaspot_mail')]
final class AssigneeNotificationTranslationCoverageTest extends UnitTestCase {

  private const REQUIRED_MSGIDS = [
    'Request #@request_id assigned to you',
    'This request has been assigned to you for processing.',
    'Request #@request_id was assigned to you',
  ];

  private const REQUIRED_PLACEHOLDERS = [
    'Request #@request_id assigned to you' => ['@request_id'],
    'Request #@request_id was assigned to you' => ['@request_id'],
  ];

  /**
   * Tests every installed translation file contains non-empty assignee strings.
   */
  public function testEveryLanguageFileContainsAssigneeNotificationStrings(): void {
    $translationDir = $this->translationDir();
    $files = glob($translationDir . '/*.po') ?: [];
    sort($files);

    $this->assertCount(17, $files, 'Expected 17 markaspot_mail language files.');
    foreach ($files as $file) {
      $source = file_get_contents($file);
      $this->assertIsString($source);

      foreach (self::REQUIRED_MSGIDS as $msgid) {
        $translation = $this->translatedPoString($source, $msgid, basename($file));
        foreach (self::REQUIRED_PLACEHOLDERS[$msgid] ?? [] as $placeholder) {
          $this->assertStringContainsString(
            $placeholder,
            $translation,
            sprintf('Missing placeholder "%s" in "%s" translation in %s.', $placeholder, $msgid, basename($file)),
          );
        }
      }
    }
  }

  /**
   * Tests the template catalog contains the assignee mail source strings.
   */
  public function testPotContainsAssigneeNotificationStrings(): void {
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
    $this->assertMatchesRegularExpression(
      '/msgid "' . $encodedMsgid . "\"\\Rmsgstr \"(?<translation>(?!\")(?:[^\"\\\\]|\\\\.)*)\"/u",
      $source,
      sprintf('Missing non-empty "%s" translation in %s.', $msgid, $filename),
    );
    preg_match('/msgid "' . $encodedMsgid . "\"\\Rmsgstr \"(?<translation>(?!\")(?:[^\"\\\\]|\\\\.)*)\"/u", $source, $matches);
    return (string) ($matches['translation'] ?? '');
  }

  /**
   * Gets the module translation directory.
   */
  private function translationDir(): string {
    return dirname(__DIR__, 3) . '/translations';
  }

}
