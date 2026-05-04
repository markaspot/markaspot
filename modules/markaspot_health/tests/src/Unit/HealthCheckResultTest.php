<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit;

use Drupal\markaspot_health\HealthCheckResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the HealthCheckResult value object.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\HealthCheckResult
 */
class HealthCheckResultTest extends UnitTestCase {

  /**
   * Tests array conversion for a passed result.
   *
   * @covers ::toArray
   */
  public function testToArrayWhenPassed(): void {
    $result = new HealthCheckResult(
      'sample_check',
      'Sample',
      TRUE,
      'warning',
      0,
      'All good.',
    );

    $this->assertSame([
      'id' => 'sample-check',
      'label' => 'Sample',
      'passed' => TRUE,
      'severity' => 'warning',
      'count' => 0,
      'message' => 'All good.',
      'fix_hint' => NULL,
      'fix_url' => NULL,
      'details' => [],
      'details_truncated_count' => 0,
      'tenant_id' => NULL,
      'plugin_version' => NULL,
      'last_run_duration_ms' => NULL,
    ], $result->toArray());
  }

  /**
   * Tests that withDuration returns a new result with the duration set.
   *
   * @covers ::withDuration
   */
  public function testWithDurationProducesCopy(): void {
    $original = new HealthCheckResult('sample', 'Sample', TRUE, 'info', 0, 'ok');
    $copy = $original->withDuration(42);

    $this->assertNull($original->lastRunDurationMs);
    $this->assertSame(42, $copy->lastRunDurationMs);
    $this->assertSame($original->pluginId, $copy->pluginId);
  }

  /**
   * Tests array conversion for a failed result with hint and URL.
   *
   * @covers ::toArray
   */
  public function testToArrayWhenFailed(): void {
    $result = new HealthCheckResult(
      'sample',
      'Sample',
      FALSE,
      'error',
      3,
      'Three orphans.',
      'Run drush sample:repair.',
      'https://example.com/runbook',
    );

    $array = $result->toArray();
    $this->assertFalse($array['passed']);
    $this->assertSame(3, $array['count']);
    $this->assertSame('Run drush sample:repair.', $array['fix_hint']);
    $this->assertSame('https://example.com/runbook', $array['fix_url']);
  }

  /**
   * Tests that the value object is readonly.
   */
  public function testReadonly(): void {
    $result = new HealthCheckResult('a', 'A', TRUE, 'info', 0, 'ok');
    $this->expectException(\Error::class);
    // @phpstan-ignore-next-line Intentional readonly assignment.
    $result->count = 5;
  }

}
