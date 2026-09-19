<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\markaspot_fastmap\Validation\WorkspaceClaim;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the approved, compact workspace claim contract.
 */
#[CoversClass(WorkspaceClaim::class)]
#[Group('markaspot_fastmap')]
class WorkspaceClaimTest extends UnitTestCase {

  /**
   * Complete translations and Unicode survive; absent claims stay absent.
   */
  public function testClaimsPreserveSelectedLanguages(): void {
    $claims = ['de' => ' Bäume vor Ort erfassen. ', 'fr' => 'Observer les arbres du quartier'];
    $expected = $claims;
    $expected['de'] = trim($claims['de']);
    $this->assertSame($expected, WorkspaceClaim::normalize($claims, ['de', 'fr']));
    $this->assertSame([], WorkspaceClaim::normalize(NULL, ['en']));
    $this->assertSame([], WorkspaceClaim::normalize([], ['en']));
    $this->assertSame(['en' => str_repeat('🌳', 40)], WorkspaceClaim::normalize(['en' => str_repeat('🌳', 40)], ['en']));
  }

  /**
   * Invalid or incomplete approved copy is never silently rewritten.
   */
  #[DataProvider('invalidClaims')]
  public function testRejectsInvalidClaims(mixed $claims): void {
    $this->expectException(\RuntimeException::class);
    WorkspaceClaim::normalize($claims, ['en', 'fr']);
  }

  /**
   * Malformed claims include unsupported locales, controls and HTML.
   */
  public static function invalidClaims(): array {
    return [
      'not a map' => ['A claim'],
      'missing translation' => [['en' => 'Local trees']],
      'wrong language' => [['en' => 'Local trees', 'de' => 'Bäume']],
      'empty translation' => [['en' => 'Trees', 'fr' => '  ']],
      'non-string' => [['en' => ['Trees'], 'fr' => 'Arbres']],
      'too long' => [['en' => str_repeat('a', 41), 'fr' => 'Arbres']],
      'HTML' => [['en' => '<b>Trees</b>', 'fr' => 'Arbres']],
      'newline' => [['en' => "Local\ntrees", 'fr' => 'Arbres']],
      'NUL' => [['en' => "Trees\0", 'fr' => 'Arbres']],
      'Unicode line break' => [['en' => "Local\u{2028}trees", 'fr' => 'Arbres']],
    ];
  }

}
