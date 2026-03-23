<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\markaspot_ai\Service\RiskScoreCalculator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the deterministic risk score calculator.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\RiskScoreCalculator
 */
class RiskScoreCalculatorTest extends UnitTestCase {

  /**
   * The calculator under test.
   *
   * @var \Drupal\markaspot_ai\Service\RiskScoreCalculator
   */
  protected RiskScoreCalculator $calculator;

  /**
   * The mocked config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->config->method('get')
      ->willReturnMap([
        ['risk_score.category_weights', NULL],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($this->config);

    $this->calculator = new RiskScoreCalculator($configFactory);
  }

  /**
   * Tests that score is always between 0.0 and 1.0.
   *
   * @covers ::calculate
   */
  public function testScoreIsNormalized(): void {
    // Maximum possible values.
    $score = $this->calculator->calculate(4, 'Gas Leak', 'frustrated', TRUE, 10);
    $this->assertGreaterThanOrEqual(0.0, $score);
    $this->assertLessThanOrEqual(1.0, $score);

    // Minimum possible values.
    $score = $this->calculator->calculate(0, 'Graffiti', 'positive', FALSE, 0);
    $this->assertGreaterThanOrEqual(0.0, $score);
    $this->assertLessThanOrEqual(1.0, $score);
  }

  /**
   * Tests the maximum risk scenario produces score close to 1.0.
   *
   * @covers ::calculate
   */
  public function testMaximumRiskScore(): void {
    // All factors at maximum: hazard=4, Gas Leak, frustrated,
    // media=TRUE, 5+ dupes = 0.40+0.25+0.15+0.10+0.10 = 1.0.
    $score = $this->calculator->calculate(4, 'Gas Leak', 'frustrated', TRUE, 5);
    $this->assertEquals(1.0, $score);
  }

  /**
   * Tests the minimum risk scenario produces score close to 0.0.
   *
   * @covers ::calculate
   */
  public function testMinimumRiskScore(): void {
    // All factors at minimum: hazard=0, Graffiti(0.1),
    // positive, no media, 0 dupes = 0+0.025+0+0+0 = 0.025.
    $score = $this->calculator->calculate(0, 'Graffiti', 'positive', FALSE, 0);
    $this->assertEquals(0.025, $score);
  }

  /**
   * Tests hazard level contribution.
   *
   * @covers ::calculate
   */
  public function testHazardLevelContribution(): void {
    $base = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 0);
    $high = $this->calculator->calculate(4, 'unknown_cat', 'neutral', FALSE, 0);

    // Hazard weight is 0.40, so difference should be 0.40 (4/4 * 0.40).
    $this->assertEqualsWithDelta(0.40, $high - $base, 0.001);
  }

  /**
   * Tests hazard level is clamped to maximum of 4.
   *
   * @covers ::calculate
   */
  public function testHazardLevelClampedAt4(): void {
    $at4 = $this->calculator->calculate(4, 'Graffiti', 'neutral', FALSE, 0);
    $at10 = $this->calculator->calculate(10, 'Graffiti', 'neutral', FALSE, 0);

    $this->assertEquals($at4, $at10);
  }

  /**
   * Tests sentiment urgency mapping.
   *
   * @covers ::calculate
   */
  public function testSentimentUrgencyMapping(): void {
    $frustrated = $this->calculator->calculate(0, 'unknown_cat', 'frustrated', FALSE, 0);
    $neutral = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 0);
    $positive = $this->calculator->calculate(0, 'unknown_cat', 'positive', FALSE, 0);

    $this->assertGreaterThan($neutral, $frustrated);
    $this->assertGreaterThan($positive, $neutral);
  }

  /**
   * Tests unknown sentiment defaults to 0.3 (same as neutral).
   *
   * @covers ::calculate
   */
  public function testUnknownSentimentDefaultsToNeutral(): void {
    $neutral = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 0);
    $unknown = $this->calculator->calculate(0, 'unknown_cat', 'bogus_sentiment', FALSE, 0);

    $this->assertEquals($neutral, $unknown);
  }

  /**
   * Tests media presence contribution.
   *
   * @covers ::calculate
   */
  public function testMediaContribution(): void {
    $noMedia = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 0);
    $withMedia = $this->calculator->calculate(0, 'unknown_cat', 'neutral', TRUE, 0);

    // Media weight is 0.10.
    $this->assertEqualsWithDelta(0.10, $withMedia - $noMedia, 0.001);
  }

  /**
   * Tests duplicate count saturation at 5.
   *
   * @covers ::calculate
   */
  public function testDuplicateCountSaturation(): void {
    $at5 = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 5);
    $at10 = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 10);
    $at100 = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 100);

    $this->assertEquals($at5, $at10);
    $this->assertEquals($at5, $at100);
  }

  /**
   * Tests duplicate factor scales linearly up to 5.
   *
   * @covers ::calculate
   */
  public function testDuplicateFactorLinearScaling(): void {
    $base = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 0);
    $one = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 1);
    $three = $this->calculator->calculate(0, 'unknown_cat', 'neutral', FALSE, 3);

    // 1 duplicate: 1/5 * 0.10 = 0.02.
    $this->assertEqualsWithDelta(0.02, $one - $base, 0.001);
    // 3 duplicates: 3/5 * 0.10 = 0.06.
    $this->assertEqualsWithDelta(0.06, $three - $base, 0.001);
  }

  /**
   * Tests known category weights from defaults.
   *
   * @covers ::calculate
   *
   * @dataProvider knownCategoryWeightsProvider
   */
  public function testKnownCategoryWeights(string $category, float $expectedWeight): void {
    // All other factors zeroed out except category.
    $base = $this->calculator->calculate(0, 'unknown_for_this_test_xyz', 'positive', FALSE, 0);
    $score = $this->calculator->calculate(0, $category, 'positive', FALSE, 0);

    // Category weight * 0.25, unknown weight is 0.3 * 0.25 = 0.075.
    $expectedDiff = ($expectedWeight - 0.3) * 0.25;
    $this->assertEqualsWithDelta($expectedDiff, $score - $base, 0.001);
  }

  /**
   * Data provider for known category weights.
   *
   * @return array
   *   Test cases with category name and expected weight.
   */
  public static function knownCategoryWeightsProvider(): array {
    return [
      'Gas Leak' => ['Gas Leak', 1.0],
      'Gasleck' => ['Gasleck', 1.0],
      'Pothole' => ['Pothole', 0.5],
      'Graffiti' => ['Graffiti', 0.1],
      'Fallen Tree' => ['Fallen Tree', 0.7],
      'Street Light' => ['Street Light', 0.4],
    ];
  }

  /**
   * Tests unknown category gets default weight of 0.3.
   *
   * @covers ::calculate
   */
  public function testUnknownCategoryDefaultWeight(): void {
    // Two unknown categories should produce the same score.
    $a = $this->calculator->calculate(2, 'NonExistentCategoryA', 'neutral', FALSE, 0);
    $b = $this->calculator->calculate(2, 'NonExistentCategoryB', 'neutral', FALSE, 0);

    $this->assertEquals($a, $b);
  }

  /**
   * Tests config overrides for category weights.
   *
   * @covers ::calculate
   */
  public function testConfigOverrideCategoryWeight(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['risk_score.category_weights', ['Custom Category' => 0.8]],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $calculator = new RiskScoreCalculator($configFactory);

    // With override weight 0.8: 0 + 0.8*0.25 + 0.3*0.15 + 0 + 0 = 0.245.
    $score = $calculator->calculate(0, 'Custom Category', 'neutral', FALSE, 0);
    $expected = (0.8 * 0.25) + (0.3 * 0.15);
    $this->assertEqualsWithDelta($expected, $score, 0.001);
  }

  /**
   * Tests config override weight is clamped to 0.0-1.0.
   *
   * @covers ::calculate
   */
  public function testConfigOverrideWeightClamped(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['risk_score.category_weights', ['Over' => 5.0, 'Under' => -2.0]],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_ai.settings')
      ->willReturn($config);

    $calculator = new RiskScoreCalculator($configFactory);

    // 5.0 clamps to 1.0; -2.0 clamps to 0.0.
    $overScore = $calculator->calculate(0, 'Over', 'positive', FALSE, 0);
    $underScore = $calculator->calculate(0, 'Under', 'positive', FALSE, 0);

    // Over: 1.0 * 0.25 = 0.25; Under: 0.0 * 0.25 = 0.0.
    $this->assertEqualsWithDelta(0.25, $overScore, 0.001);
    $this->assertEqualsWithDelta(0.0, $underScore, 0.001);
  }

}
