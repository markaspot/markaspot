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
   * Only published reports count as missing, in every path that queues them.
   */
  public function testOnlyPublishedReportsAreMissing(): void {
    $root = dirname(__DIR__, 3) . '/src/';
    $controller = file_get_contents($root . 'Controller/ProcessingController.php');
    $service = file_get_contents($root . 'Service/EmbeddingService.php');
    $admin = file_get_contents($root . 'Controller/AiAdminController.php');
    $worker = file_get_contents($root . 'Plugin/QueueWorker/EmbeddingQueueWorker.php');
    $this->assertIsString($controller);
    $this->assertIsString($service);
    $this->assertIsString($admin);
    $this->assertIsString($worker);

    $status = $this->methodSource($controller, 'getStatus', 'queueMissing');
    $this->assertStringContainsString("->condition('n.status', \$status)", $status);
    $this->assertStringContainsString("'awaiting_publication' => \$awaitingPublication", $status);
    $this->assertStringNotContainsString('getNodeIdsForJurisdiction(', $controller);

    $sentiment = $this->methodSource($controller, 'findMissingSentiment', 'filterAiEnabledNodeIds');
    $this->assertStringContainsString('n.status = 1', $sentiment);
    $this->assertStringContainsString('n.status = 1', $admin);

    $missing = $this->methodSource($service, 'findMissingEmbeddings', 'deleteEmbedding');
    $this->assertStringContainsString("->condition('n.status', 1)", $missing);

    $this->assertStringContainsString('if (!$node->isPublished()) {', $worker);

    $module = file_get_contents(dirname(__DIR__, 3) . '/markaspot_ai.module');
    $this->assertIsString($module);
    $this->assertStringContainsString('$changed = !$original->isPublished();', $module);
  }

  /**
   * An unknown jurisdiction never widens the queue to every tenant.
   */
  public function testUnknownJurisdictionQueuesNothing(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProcessingController.php');
    $this->assertIsString($source);
    $guard = 'if ($this->isRequested($requested) && $scope === NULL) {';
    $this->assertStringContainsString($guard, $this->methodSource($source, 'getStatus', 'queueMissing'));
    $this->assertStringContainsString($guard, $this->methodSource($source, 'queueMissing', 'processQueue'));
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
