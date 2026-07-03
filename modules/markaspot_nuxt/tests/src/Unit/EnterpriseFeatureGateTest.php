<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\EnterpriseFeatureGate;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the EnterpriseFeatureGate service.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Service\EnterpriseFeatureGate
 */
class EnterpriseFeatureGateTest extends UnitTestCase {

  /**
   * The gate under test. Final-ish, no constructor deps — real instance.
   */
  protected EnterpriseFeatureGate $gate;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->gate = new EnterpriseFeatureGate();
  }

  /**
   * Creates a group mock with a controllable field_tier state.
   *
   * @param bool $hasTierField
   *   Whether the bundle carries field_tier at all (FALSE = self-hosted:
   *   markaspot_fastmap not installed / field not attached).
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
    $this->assertFalse($this->gate->isEnterpriseFeatureAllowed(NULL, 'mail_text_editor'));
  }

  /**
   * No field_tier at all means self-hosted/on-premise: always allowed.
   *
   * @covers ::isEnterpriseFeatureAllowed
   */
  public function testSelfHostedWithNoTierFieldIsAllowed(): void {
    $group = $this->mockGroup(FALSE);
    $this->assertTrue($this->gate->isEnterpriseFeatureAllowed($group, 'mail_text_editor'));
  }

  /**
   * Attached-but-empty field_tier (pending Stripe checkout) is denied.
   *
   * This is deliberately NOT treated as self-hosted: a demo/pending SaaS
   * workspace should not get enterprise features for free.
   *
   * @covers ::isEnterpriseFeatureAllowed
   */
  public function testEmptyTierFieldIsDenied(): void {
    $group = $this->mockGroup(TRUE, NULL);
    $this->assertFalse($this->gate->isEnterpriseFeatureAllowed($group, 'mail_text_editor'));
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
    $this->assertSame($expected, $this->gate->isEnterpriseFeatureAllowed($group, 'mail_text_editor'));
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
