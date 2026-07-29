<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\EnterpriseFeatureGate;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the EnterpriseFeatureGate service.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Service\EnterpriseFeatureGate
 */
class EnterpriseFeatureGateTest extends UnitTestCase {

  /**
   * Creates a gate for the requested platform type.
   */
  private function createGate(bool $selfService): EnterpriseFeatureGate {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->method('isSelfServicePlatform')->willReturn($selfService);
    return new EnterpriseFeatureGate($resolver);
  }

  /**
   * Creates a group mock with a controllable field_tier state.
   *
   * @param bool $hasTierField
   *   Whether the bundle carries field_tier at all.
   * @param string|null $tierValue
   *   The stored tier value. NULL means the field is attached but empty
   *   (pending Stripe checkout).
   */
  protected function mockGroup(bool $hasTierField, ?string $tierValue = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_tier' && $hasTierField);

    if ($hasTierField) {
      // @phpcs:disable Drupal.Commenting.DocComment
      $fieldItem = new class($tierValue) {

        /**
         * The field value.
         *
         * @var mixed
         */
        public $value;

        /**
         * Whether the field is empty.
         *
         * @var bool
         */
        private bool $empty;

        public function __construct($value) {
          $this->value = $value;
          $this->empty = $value === NULL;
        }

        /**
         *
         */
        public function isEmpty(): bool {
          return $this->empty;
        }

      };
      // @phpcs:enable
      $group->method('get')->with('field_tier')->willReturn($fieldItem);
    }

    return $group;
  }

  /**
   * NULL jurisdiction (no context resolvable) fails closed.
   *
   * @covers ::isEnterpriseFeatureAllowed
   */
  public function testNullJurisdictionIsDenied(): void {
    $this->assertFalse(
      $this->createGate(FALSE)
        ->isEnterpriseFeatureAllowed(NULL, 'mail_text_editor'),
    );
  }

  /**
   * Self-hosted remains allowed with the now-universal empty tier field.
   *
   * @covers ::isEnterpriseFeatureAllowed
   */
  public function testSelfHostedWithEmptyTierFieldIsAllowed(): void {
    $group = $this->mockGroup(TRUE, NULL);
    $this->assertTrue(
      $this->createGate(FALSE)
        ->isEnterpriseFeatureAllowed($group, 'mail_text_editor'),
    );
  }

  /**
   * Attached-but-empty field_tier on self-service remains denied.
   *
   * A demo/pending SaaS workspace must not get enterprise features for free.
   *
   * @covers ::isEnterpriseFeatureAllowed
   */
  public function testSelfServiceWithEmptyTierFieldIsDenied(): void {
    $group = $this->mockGroup(TRUE, NULL);
    $this->assertFalse(
      $this->createGate(TRUE)
        ->isEnterpriseFeatureAllowed($group, 'mail_text_editor'),
    );
  }

  /**
   * Missing tier field on self-service fails closed.
   *
   * @covers ::isEnterpriseFeatureAllowed
   */
  public function testSelfServiceWithMissingTierFieldIsDenied(): void {
    $group = $this->mockGroup(FALSE);
    $this->assertFalse(
      $this->createGate(TRUE)
        ->isEnterpriseFeatureAllowed($group, 'mail_text_editor'),
    );
  }

  /**
   * Only the top ('heart') tier unlocks enterprise features.
   *
   * @covers ::isEnterpriseFeatureAllowed
   *
   * @dataProvider tierProvider
   */
  public function testTierGating(string $tier, bool $expected): void {
    $group = $this->mockGroup(TRUE, $tier);
    $this->assertSame(
      $expected,
      $this->createGate(TRUE)
        ->isEnterpriseFeatureAllowed($group, 'mail_text_editor'),
    );
  }

  /**
   * Data provider for tier gating.
   *
   * @return array<string, array{string, bool}>
   *   Test cases.
   */
  public static function tierProvider(): array {
    return [
      'free' => ['free', FALSE],
      'starter' => ['starter', FALSE],
      'pro' => ['pro', FALSE],
      'heart' => ['heart', TRUE],
    ];
  }

}
