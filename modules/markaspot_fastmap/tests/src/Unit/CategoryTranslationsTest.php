<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\markaspot_fastmap\Validation\CategoryTranslations;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Validation/CategoryTranslations.php';

/**
 * Tests the index-preserving category translation contract.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Validation\CategoryTranslations
 * @group markaspot_fastmap
 */
class CategoryTranslationsTest extends UnitTestCase {

  /**
   * Complete labels preserve their language and category pairing.
   */
  public function testCompleteTranslationsAndLegacyInput(): void {
    $labels = ['en' => [' Pothole ', 'Graffiti', 'Lighting'], 'fr' => ['Nid de poule', 'Graffiti', 'Éclairage']];
    $expected = $labels;
    $expected['en'][0] = 'Pothole';
    $this->assertSame($expected, CategoryTranslations::normalize($labels, 'en', ['en', 'fr']));
    $this->assertSame(['fr' => ['Éclairage']], CategoryTranslations::normalize(['Éclairage'], 'fr', ['en', 'fr']));
    $this->assertSame(['en' => ['Lighting']], CategoryTranslations::normalize(['Lighting'], '', ['en', 'fr']));
  }

  /**
   * Invalid payloads cannot compact or relabel a translation array.
   *
   * @dataProvider invalidCategories
   */
  public function testRejectsIncompleteTranslations(array $categories, string $language = 'en'): void {
    $this->expectException(\RuntimeException::class);
    CategoryTranslations::normalize($categories, $language, ['en', 'fr']);
  }

  /**
   * Provides malformed language arrays and missing primary languages.
   */
  public static function invalidCategories(): array {
    return [
      'blank middle' => [['en' => ['Pothole', 'Graffiti', 'Lighting'], 'fr' => ['Nid de poule', '', 'Éclairage']]],
      'short secondary' => [['en' => ['Pothole', 'Lighting'], 'fr' => ['Nid de poule']]],
      'long secondary' => [['en' => ['Lighting'], 'fr' => ['Éclairage', 'Autre']]],
      'whitespace secondary' => [['en' => ['Lighting'], 'fr' => ['  ']]],
      'non-string secondary' => [['en' => ['Lighting'], 'fr' => [42]]],
      'sparse secondary' => [['en' => ['Pothole', 'Lighting'], 'fr' => [0 => 'Nid de poule', 2 => 'Éclairage']]],
      'empty locale' => [['en' => ['Lighting'], 'fr' => []]],
      'missing primary' => [['fr' => ['Éclairage']], 'en'],
      'unsupported locale' => [['en' => ['Lighting'], 'xx' => ['Other']]],
      'malformed legacy' => [['Lighting', NULL]],
    ];
  }

}
