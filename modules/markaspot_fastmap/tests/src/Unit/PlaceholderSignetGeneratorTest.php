<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\markaspot_fastmap\Service\PlaceholderSignetGenerator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests deterministic placeholder signet generation.
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\PlaceholderSignetGenerator
 * @group markaspot_fastmap
 */
final class PlaceholderSignetGeneratorTest extends UnitTestCase {

  /**
   * Golden-vector colors from the normative JavaScript fixture.
   */
  private const GOLDEN_COLORS = [
    'blue' => '#2563eb',
    'red' => '#dc2626',
    'amber' => '#f59e0b',
    'violet' => '#7c3aed',
    'emerald' => '#059669',
  ];

  /**
   * Tests every golden SVG with a byte-for-byte string comparison.
   *
   * @covers ::generate
   */
  public function testGoldenVectorsMatchExactly(): void {
    $fixturePath = dirname(__DIR__, 2) . '/fixtures/golden.json';
    $fixtureContents = file_get_contents($fixturePath);
    $this->assertNotFalse($fixtureContents);
    $vectors = json_decode($fixtureContents, TRUE, flags: JSON_THROW_ON_ERROR);

    $generator = new PlaceholderSignetGenerator();
    $seenOrnaments = [];
    $seenMirrored = [];

    foreach ($vectors as $seed => $vector) {
      $this->assertCount(5, $vector['svg']);
      $seenOrnaments[$vector['info']['ornament']] = TRUE;
      $seenMirrored[$vector['info']['mirrored'] ? 'yes' : 'no'] = TRUE;
      foreach ($vector['svg'] as $color => $expectedSvg) {
        $this->assertSame(
          $expectedSvg,
          $generator->generate($seed, self::GOLDEN_COLORS[$color]),
          $seed . ' with ' . $color,
        );
      }
    }

    // A fixture that misses part of the vocabulary passes while leaving that
    // part unported. Assert the coverage, not the number of entries: the
    // mirrored variant exists for exactly one ornament and is the easiest to
    // lose.
    ksort($seenOrnaments);
    ksort($seenMirrored);
    $this->assertSame(
      ['bend', 'chevron', 'pale', 'pile'],
      array_keys($seenOrnaments),
      'Golden vectors must cover every ornament',
    );
    $this->assertSame(
      ['no', 'yes'],
      array_keys($seenMirrored),
      'Golden vectors must cover both mirrored states',
    );
  }

  /**
   * Tests that output is stable and depends on the seed.
   *
   * @covers ::generate
   */
  public function testOutputIsDeterministicAndSeedSpecific(): void {
    $generator = new PlaceholderSignetGenerator();
    $first = $generator->generate('bleckede', '#3b82f6');

    $this->assertSame($first, $generator->generate('bleckede', '#3b82f6'));
    $this->assertNotSame($first, $generator->generate('dorsten', '#3b82f6'));
  }

}
