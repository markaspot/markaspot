<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\markaspot_health\SmokeCheckResult;

/**
 * Tests the SmokeCheckResult value object.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\SmokeCheckResult
 */
class SmokeCheckResultTest extends UnitTestCase {

  /**
   * @covers ::toArray
   */
  public function testToArrayWhenPassed(): void {
    $result = new SmokeCheckResult(
      'sample_check',
      'Sample',
      SmokeCheckResult::STATUS_PASS,
      'warning',
      'http_sanity',
      FALSE,
      SmokeCheckResult::MODE_READ_ONLY,
      0,
      'All good.',
      ['status_code' => 200],
    );

    $array = $result->toArray();
    $this->assertSame('sample-check', $array['id']);
    $this->assertSame('pass', $array['status']);
    $this->assertSame('http_sanity', $array['category']);
    $this->assertFalse($array['mutates']);
    $this->assertSame('read-only', $array['mode']);
    $this->assertSame(['status_code' => 200], $array['evidence']);
  }

  /**
   * @covers ::passed
   * @covers ::failed
   */
  public function testStatusPredicates(): void {
    $pass = $this->build(SmokeCheckResult::STATUS_PASS);
    $fail = $this->build(SmokeCheckResult::STATUS_FAIL);
    $skip = $this->build(SmokeCheckResult::STATUS_SKIP);
    $warn = $this->build(SmokeCheckResult::STATUS_WARNING);

    $this->assertTrue($pass->passed());
    $this->assertFalse($pass->failed());

    $this->assertFalse($fail->passed());
    $this->assertTrue($fail->failed());

    $this->assertFalse($skip->passed());
    $this->assertFalse($skip->failed());

    $this->assertFalse($warn->passed());
    $this->assertFalse($warn->failed());
  }

  /**
   * @covers ::withDuration
   */
  public function testWithDurationProducesCopy(): void {
    $original = $this->build(SmokeCheckResult::STATUS_PASS);
    $copy = $original->withDuration(42);

    $this->assertNull($original->lastRunDurationMs);
    $this->assertSame(42, $copy->lastRunDurationMs);
    $this->assertSame($original->pluginId, $copy->pluginId);
    $this->assertSame($original->status, $copy->status);
    $this->assertSame($original->evidence, $copy->evidence);
  }

  /**
   * Tests that the value object is readonly.
   */
  public function testReadonly(): void {
    $result = $this->build(SmokeCheckResult::STATUS_PASS);
    $this->expectException(\Error::class);
    // @phpstan-ignore-next-line Intentional readonly assignment.
    $result->count = 5;
  }

  /**
   * Builds a fixture result for status-predicate tests.
   */
  private function build(string $status): SmokeCheckResult {
    return new SmokeCheckResult(
      'sample',
      'Sample',
      $status,
      'info',
      'drupal_internal',
      FALSE,
      SmokeCheckResult::MODE_READ_ONLY,
      0,
      'msg',
    );
  }

}
