<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests update 11901 relationship backfill idempotency wiring.
 *
 * @group markaspot_group
 */
class PostUpdate11901IdempotencyTest extends UnitTestCase {

  /**
   * Tests the batch loop checks for existing relationships before adding.
   */
  public function testNodeBackfillChecksExistingRelationshipInBatchLoop(): void {
    $module_root = dirname(__DIR__, 3);
    $source = file_get_contents($module_root . '/markaspot_group.install');

    $this->assertStringContainsString('function markaspot_group_update_11901(array &$sandbox): void', $source);
    $this->assertStringContainsString("\$existing_nids = \$db->select('group_relationship_field_data', 'gr')", $source);
    $this->assertStringContainsString("->condition('entity_id', \$chunk, 'IN')", $source);
    $this->assertStringContainsString("->condition('gid', \$sandbox['root_jur_id'])", $source);
    $this->assertStringContainsString("\$sandbox['skipped_existing']++", $source);
    $this->assertStringContainsString("\$sandbox['added']++", $source);

    $nodes_load_pos = strpos($source, '$nodes = $etm->getStorage(\'node\')->loadMultiple($chunk);');
    $existing_check_pos = strpos($source, '$existing_nids = $db->select(\'group_relationship_field_data\', \'gr\')', $nodes_load_pos);
    $loop_skip_pos = strpos($source, 'if (isset($existing_nids[(int) $node->id()])) {');
    $add_relationship_pos = strpos($source, '$root_jur->addRelationship($node, $plugin_id);');
    $this->assertNotFalse($nodes_load_pos);
    $this->assertNotFalse($existing_check_pos);
    $this->assertNotFalse($loop_skip_pos);
    $this->assertNotFalse($add_relationship_pos);
    $this->assertLessThan($loop_skip_pos, $existing_check_pos);
    $this->assertLessThan($add_relationship_pos, $existing_check_pos);
  }

}
