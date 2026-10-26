<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Guards the processing status against loading every report of a tenant.
 *
 * @group markaspot_ai
 */
final class ProcessingControllerSourceTest extends UnitTestCase {

  /**
   * Scoped status counts decide AI eligibility once per jurisdiction.
   */
  public function testScopedStatusCountsWithoutLoadingReports(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProcessingController.php');
    $this->assertIsString($source);

    $status = $this->methodSource($source, 'getStatus', 'queueMissing');
    $this->assertSame(2, substr_count($status, '$this->countEligibleMissing('));
    $this->assertStringNotContainsString('collectAiEnabledNodeIds(', $status);

    $count = $this->methodSource($source, 'countEligibleMissing', 'collectAiEnabledNodeIds');
    $this->assertStringContainsString("isEnabledEffective('aiProcessing', \$group, FALSE)", $count);
    $this->assertStringNotContainsString('loadMultiple(', $count);
    $this->assertStringNotContainsString('filterAiEnabledNodeIds(', $count);
  }

  /**
   * Extracts a method block from source.
   */
  private function methodSource(string $source, string $method, string $nextMethod): string {
    $start = strpos($source, 'function ' . $method . '(');
    $this->assertIsInt($start, sprintf('Method %s must exist.', $method));
    $end = strpos($source, 'function ' . $nextMethod . '(', $start);
    $this->assertIsInt($end, sprintf('Method %s must follow %s.', $nextMethod, $method));
    return substr($source, $start, $end - $start);
  }

}
