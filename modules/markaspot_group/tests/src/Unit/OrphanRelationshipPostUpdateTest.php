<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests orphan group relationship cleanup post-update wiring.
 *
 * @group markaspot_group
 */
class OrphanRelationshipPostUpdateTest extends UnitTestCase {

  /**
   * Tests the post-update invokes the safe orphan relationship repair service.
   */
  public function testCleanupPostUpdateUsesIntegrityCheckerRepair(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $source = file_get_contents($moduleRoot . '/markaspot_group.post_update.php');

    $this->assertStringContainsString('function markaspot_group_post_update_cleanup_orphaned_group_relationships', $source);
    $this->assertStringContainsString("Drupal::service('markaspot_group.integrity_checker')", $source);
    $this->assertStringContainsString('repairOrphanRelationships(FALSE)', $source);
    $this->assertStringContainsString('relationship_missing_node', $source);
    $this->assertStringContainsString('relationship_missing_user', $source);
    $this->assertStringContainsString('relationship_missing_group_skipped', $source);
  }

}
