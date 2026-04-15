<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\markaspot_nuxt\Service\FeatureFlagChecker
 * @group markaspot_nuxt
 */
final class FeatureFlagCheckerTest extends UnitTestCase {

  /**
   * Builds a group mock with the given field_nuxt_config JSON.
   *
   * Passing NULL simulates a group without the field entirely.
   */
  private function buildGroup(?string $json): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => $name === 'field_nuxt_config' && $json !== NULL);

    $group->method('get')
      ->willReturnCallback(function (string $name) use ($json) {
        // @phpcs:disable Drupal.Commenting.DocComment
        return new class ($json) {

          /**
           * Raw field value (JSON string or NULL).
           *
           * @var mixed
           */
          public $value;

          /**
           * Whether the field is considered empty.
           *
           * @var bool
           */
          private bool $empty;

          public function __construct($value) {
            $this->value = $value;
            $this->empty = ($value === NULL);
          }

          /**
           *
           */
          public function isEmpty(): bool {
            return $this->empty;
          }

        };
        // @phpcs:enable
      });

    return $group;
  }

  /**
   * @covers ::isEnabled
   */
  public function testNullJurisdictionReturnsDefault(): void {
    $checker = new FeatureFlagChecker();
    $this->assertTrue($checker->isEnabled('features.privacyNotice.enabled', NULL, TRUE));
    $this->assertFalse($checker->isEnabled('features.aiAnalysis', NULL, FALSE));
  }

  /**
   * @covers ::isEnabled
   */
  public function testMissingDotPathReturnsDefault(): void {
    $group = $this->buildGroup(json_encode(['features' => ['other' => TRUE]]));
    $checker = new FeatureFlagChecker();

    $this->assertTrue($checker->isEnabled('features.privacyNotice.enabled', $group, TRUE));
    $this->assertFalse($checker->isEnabled('features.privacyNotice.enabled', $group, FALSE));
  }

  /**
   * @covers ::isEnabled
   */
  public function testBooleanShapeIsRespected(): void {
    $group = $this->buildGroup(json_encode([
      'features' => ['aiAnalysis' => FALSE, 'passwordless' => TRUE],
    ]));
    $checker = new FeatureFlagChecker();

    $this->assertFalse($checker->isEnabled('features.aiAnalysis', $group, TRUE));
    $this->assertTrue($checker->isEnabled('features.passwordless', $group, FALSE));
  }

  /**
   * @covers ::isEnabled
   */
  public function testNestedEnabledShapeIsRespected(): void {
    $group = $this->buildGroup(json_encode([
      'features' => [
        'privacyNotice' => ['enabled' => FALSE, 'modal' => TRUE],
        'emergency' => ['enabled' => TRUE],
      ],
    ]));
    $checker = new FeatureFlagChecker();

    // Direct nested path.
    $this->assertFalse($checker->isEnabled('features.privacyNotice.enabled', $group, TRUE));
    $this->assertTrue($checker->isEnabled('features.emergency.enabled', $group, FALSE));

    // Parent object — the helper unwraps {enabled: X}.
    $this->assertFalse($checker->isEnabled('features.privacyNotice', $group, TRUE));
    $this->assertTrue($checker->isEnabled('features.emergency', $group, FALSE));
  }

  /**
   * @covers ::isEnabled
   */
  public function testCorruptJsonReturnsDefault(): void {
    $group = $this->buildGroup('not-valid-json{');
    $checker = new FeatureFlagChecker();

    $this->assertTrue($checker->isEnabled('features.privacyNotice.enabled', $group, TRUE));
    $this->assertFalse($checker->isEnabled('features.privacyNotice.enabled', $group, FALSE));
  }

  /**
   * @covers ::isEnabled
   */
  public function testMissingFieldReturnsDefault(): void {
    $group = $this->buildGroup(NULL);
    $checker = new FeatureFlagChecker();

    $this->assertTrue($checker->isEnabled('features.privacyNotice.enabled', $group, TRUE));
    $this->assertFalse($checker->isEnabled('features.privacyNotice.enabled', $group, FALSE));
  }

}
