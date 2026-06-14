<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Regression net for AI attribute dashboard discovery scope.
 *
 * @group markaspot_ai
 */
final class AttributeControllerSourceTest extends UnitTestCase {

  /**
   * Attribute queueing must be jurisdiction-gated for tenant users.
   */
  public function testQueueBatchRequiresJurisdictionAccess(): void {
    $source = $this->loadControllerSource();

    $queueBatch = $this->methodSource($source, 'queueBatch', 'countNodesWithDefinitions');

    $this->assertStringContainsString('currentUserCanAccessJurisdiction($jurisdiction_id)', $queueBatch);
    $this->assertStringContainsString("'Access denied for the requested jurisdiction.'", $queueBatch);
    $this->assertStringContainsString('], 403);', $queueBatch);
  }

  /**
   * Tenant admin membership checks must use the member entity ID.
   */
  public function testTenantAdminMembershipUsesMemberEntityId(): void {
    $source = $this->loadControllerSource();

    $membershipLookup = $this->methodSource($source, 'getTenantAdminJurisdictionIds', 'filterAiEnabledNodeIds');

    $this->assertStringContainsString("\$query->condition('gr.entity_id', \$uid);", $membershipLookup);
    $this->assertStringNotContainsString("\$query->condition('gr.uid', \$uid);", $membershipLookup);
    $this->assertStringContainsString("'group_relationship__group_roles'", $membershipLookup);
    $this->assertStringContainsString("'roles.group_roles_target_id'", $membershipLookup);
  }

  /**
   * Discovery must include canonical field_jurisdiction assignments.
   */
  public function testDiscoveryUsesJurisdictionFieldFallback(): void {
    $source = $this->loadControllerSource();

    $jurisdictionLoader = $this->methodSource($source, 'getNodeIdsForJurisdiction', 'getNodeIdsByJurisdictionField');
    $fieldFallback = $this->methodSource($source, 'getNodeIdsByJurisdictionField', 'buildEmptyStatus');

    $this->assertStringContainsString('getNodeIdsByJurisdictionField($group_id)', $jurisdictionLoader);
    $this->assertStringContainsString("'node__field_jurisdiction'", $fieldFallback);
    $this->assertStringContainsString("'fj.field_jurisdiction_target_id'", $fieldFallback);
  }

  /**
   * Scoped users must not receive global queue volume.
   */
  public function testScopedStatusHidesGlobalQueueVolume(): void {
    $source = $this->loadControllerSource();

    $status = $this->methodSource($source, 'getStatus', 'fillSingle');

    $this->assertStringContainsString('!$this->currentUserCanSeeAllJurisdictions()', $status);
    $this->assertStringContainsString('? 0', $status);
    $this->assertStringContainsString("'queue' => \$queue_count", $status);
  }

  /**
   * Loads the controller source from disk.
   */
  private function loadControllerSource(): string {
    $path = dirname(__DIR__, 3) . '/src/Controller/AttributeController.php';
    $source = file_get_contents($path);
    $this->assertIsString($source);
    return $source;
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
