<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\SmokeCheck;

use Drupal\markaspot_health\HealthCheckPluginManager;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\markaspot_health\Plugin\SmokeCheck\HealthWrapCheck;
use Drupal\markaspot_health\SmokeCheckResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the health-wrap smoke check.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\SmokeCheck\HealthWrapCheck
 */
class HealthWrapCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'health_wrap',
    'label' => 'Health suite (wrapped)',
    'severity' => 'error',
    'category' => 'wrap',
    'mutates' => FALSE,
  ];

  /**
   * @covers ::run
   */
  public function testPassesWhenAllHealthChecksPass(): void {
    $manager = $this->mockHealthManager([
      new HealthCheckResult('a', 'A', TRUE, 'info', 0, 'ok'),
      new HealthCheckResult('b', 'B', TRUE, 'warning', 0, 'ok'),
    ]);

    $plugin = new HealthWrapCheck([], 'health_wrap', $this->definition, $manager);
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_PASS, $result->status);
    $this->assertSame(2, $result->evidence['health_total']);
  }

  /**
   * @covers ::run
   */
  public function testFailsOnAnyErrorSeverityFailure(): void {
    $manager = $this->mockHealthManager([
      new HealthCheckResult('a', 'A', TRUE, 'info', 0, 'ok'),
      new HealthCheckResult('b', 'B', FALSE, 'error', 3, 'three orphans'),
    ]);

    $plugin = new HealthWrapCheck([], 'health_wrap', $this->definition, $manager);
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_FAIL, $result->status);
    $this->assertSame(1, $result->count);
    $this->assertSame('b', $result->evidence['health_error_fails'][0]['id']);
  }

  /**
   * Non-error health failures must downgrade to WARNING, not FAIL.
   *
   * Keeps the smoke gate from going red on advisory drift.
   *
   * @covers ::run
   */
  public function testWarnsWhenOnlyNonErrorFailures(): void {
    $manager = $this->mockHealthManager([
      new HealthCheckResult('a', 'A', FALSE, 'warning', 1, 'minor'),
    ]);

    $plugin = new HealthWrapCheck([], 'health_wrap', $this->definition, $manager);
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_WARNING, $result->status);
    $this->assertSame('a', $result->evidence['health_non_error_fails'][0]['id']);
  }

  /**
   * Health manager throwing must not crash the wrap; emits WARNING instead.
   *
   * @covers ::run
   */
  public function testManagerThrowingProducesWarning(): void {
    $manager = $this->createMock(HealthCheckPluginManager::class);
    $manager->method('runAll')->willThrowException(new \RuntimeException('boom'));

    $plugin = new HealthWrapCheck([], 'health_wrap', $this->definition, $manager);
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_WARNING, $result->status);
    $this->assertStringContainsString('boom', $result->message);
  }

  /**
   * Helper: mock the HealthCheckPluginManager to return fixed results.
   *
   * @param array<int, HealthCheckResult> $results
   *   Health results to return.
   */
  private function mockHealthManager(array $results): HealthCheckPluginManager {
    $manager = $this->createMock(HealthCheckPluginManager::class);
    $manager->method('runAll')->willReturn($results);
    return $manager;
  }

}
