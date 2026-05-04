<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit;

use Drupal\markaspot_health\GroupIntegrityCheckBase;
use Drupal\markaspot_health\Plugin\HealthCheck\RelationshipMissingGroupCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the shared GroupIntegrity plugin base via a concrete subclass.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\GroupIntegrityCheckBase
 */
class GroupIntegrityCheckBaseTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'relationship_missing_group',
    'label' => 'Group relationships with missing group',
    'severity' => 'warning',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    GroupIntegrityCheckBase::resetCache();
  }

  /**
   * @covers ::run
   */
  public function testPassesWhenSubKeyHasZeroRows(): void {
    $checker = $this->makeChecker([
      'relationship_missing_group' => ['description' => 'desc', 'rows' => []],
    ]);
    $plugin = new RelationshipMissingGroupCheck([], 'relationship_missing_group', $this->definition, $checker);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertSame(0, $result->count);
    $this->assertSame([], $result->details);
  }

  /**
   * @covers ::run
   */
  public function testFailsAndExposesDetailsWhenRowsPresent(): void {
    $rows = array_map(
      static fn(int $i): array => ['entity_id' => $i, 'gid' => 99],
      range(1, 60),
    );
    $checker = $this->makeChecker([
      'relationship_missing_group' => ['description' => 'sample drift', 'rows' => $rows],
    ]);
    $plugin = new RelationshipMissingGroupCheck([], 'relationship_missing_group', $this->definition, $checker);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(60, $result->count);
    $this->assertCount(50, $result->details);
    $this->assertSame(10, $result->detailsTruncatedCount);
    $this->assertSame('sample drift', $result->message);
    $this->assertSame(['entity_id' => 1, 'gid' => 99], $result->details[0]);
  }

  /**
   * @covers ::run
   * @covers ::resetCache
   */
  public function testCachesUpstreamCallAcrossInstances(): void {
    $checker = $this->makeChecker([
      'relationship_missing_group' => ['description' => 'desc', 'rows' => []],
    ], expectedCalls: 1);

    $plugin1 = new RelationshipMissingGroupCheck([], 'relationship_missing_group', $this->definition, $checker);
    $plugin2 = new RelationshipMissingGroupCheck([], 'relationship_missing_group', $this->definition, $checker);
    $plugin1->run();
    $plugin2->run();
  }

  /**
   * Builds a GroupIntegrityChecker mock returning the given check() data.
   */
  private function makeChecker(array $checkData, int $expectedCalls = 1): object {
    if (!class_exists('Drupal\markaspot_group\Service\GroupIntegrityChecker')) {
      $this->markTestSkipped('markaspot_group GroupIntegrityChecker is not available in this checkout.');
    }
    $mock = $this->getMockBuilder('Drupal\markaspot_group\Service\GroupIntegrityChecker')
      ->disableOriginalConstructor()
      ->onlyMethods(['check'])
      ->getMock();
    $mock->expects($this->exactly($expectedCalls))->method('check')->willReturn($checkData);
    return $mock;
  }

}
