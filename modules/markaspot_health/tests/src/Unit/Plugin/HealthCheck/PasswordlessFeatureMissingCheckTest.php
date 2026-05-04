<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\PasswordlessFeatureMissingCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the passwordless-feature-missing detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\PasswordlessFeatureMissingCheck
 */
class PasswordlessFeatureMissingCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'passwordless_feature_missing',
    'label' => 'Passwordless feature flag missing',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenGroupModuleAbsent(): void {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('group')->willReturn(FALSE);

    $plugin = new PasswordlessFeatureMissingCheck([], 'passwordless_feature_missing', $this->definition, $etm);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsForGroupsMissingFeature(): void {
    $groupOk = $this->makeGroupStub('1', json_encode(['features' => ['passwordless' => TRUE]]));
    $groupMissingFeature = $this->makeGroupStub('5', json_encode(['features' => ['voting' => TRUE]]));
    $groupMissingFeatures = $this->makeGroupStub('7', json_encode(['theme' => ['primary' => 'blue']]));

    $plugin = $this->buildPlugin([1, 5, 7], [$groupOk, $groupMissingFeature, $groupMissingFeatures]);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(2, $result->count);
    $this->assertStringContainsString('5', $result->message);
    $this->assertStringContainsString('7', $result->message);
    $this->assertStringNotContainsString(' 1,', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenAllGroupsHaveFeature(): void {
    $g = $this->makeGroupStub('1', json_encode(['features' => ['passwordless' => TRUE]]));
    $plugin = $this->buildPlugin([1], [$g]);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * Builds a plugin with mocked entity storage that returns the given groups.
   */
  private function buildPlugin(array $ids, array $groups): PasswordlessFeatureMissingCheck {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine($ids, $ids));

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($groups);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->with('group')->willReturn($storage);

    return new PasswordlessFeatureMissingCheck(
      [],
      'passwordless_feature_missing',
      $this->definition,
      $etm,
    );
  }

  /**
   * Builds a stub group with hasField + get('field_nuxt_config') accessors.
   */
  private function makeGroupStub(string $id, string $json): object {
    $list = new class($json) {

      // phpcs:ignore Drupal.Commenting.VariableComment.Missing
      public function __construct(public string $value) {}

    };

    // phpcs:disable Drupal.Commenting.FunctionComment.Missing
    return new class($id, $list) {

      public function __construct(private string $id, private object $list) {}

      public function id(): string {
        return $this->id;
      }

      public function hasField(string $name): bool {
        return $name === 'field_nuxt_config';
      }

      public function get(string $name): object {
        return $this->list;
      }

    };
    // phpcs:enable Drupal.Commenting.FunctionComment.Missing
  }

}
