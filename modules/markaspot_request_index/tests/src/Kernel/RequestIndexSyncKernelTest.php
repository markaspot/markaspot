<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_index\Kernel;

use Drupal\group\Entity\Group;
use Drupal\markaspot_request_index\RequestIndexRebuilder;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that entity writes keep the request index in sync.
 *
 * @group markaspot_request_index
 */
#[RunTestsInSeparateProcesses]
final class RequestIndexSyncKernelTest extends RequestIndexKernelTestBase {

  /**
   * Insert, update and delete keep the row and its child rows in sync.
   */
  public function testInsertUpdateDeleteKeepRowInSync(): void {
    $schema = $this->database()->schema();
    $this->assertTrue($schema->indexExists('markaspot_request_index', 'jur_created'));
    $this->assertTrue($schema->indexExists('markaspot_request_index', 'jur_address'));

    $node = $this->createRequest(['field_facility' => 'playground-7']);
    $nid = (int) $node->id();

    $row = $this->row($nid);
    $this->assertNotNull($row);
    $this->assertSame((string) $this->childJurisdiction->id(), $row['jur_gid']);
    $this->assertSame((string) $this->rootJurisdiction->id(), $row['root_gid']);
    $this->assertSame((string) $this->organisationA->id(), $row['org_gid']);
    $this->assertSame('0', $row['org_multi']);
    $this->assertSame('0', $row['jur_multi']);
    $this->assertSame('1', $row['published']);
    $this->assertSame('1', $row['uid']);
    $this->assertSame((string) $this->statusOpen->id(), $row['status_tid']);
    $this->assertSame((string) $this->category->id(), $row['category_tid']);
    $this->assertSame('BENCH-1', $row['service_code']);
    $this->assertSame('open', $row['open311_mapping']);
    $this->assertSame('Beispielweg 12', $row['address_line1']);
    $this->assertSame('00099', $row['postal_code']);
    $this->assertSame('Musterstadt', $row['locality']);
    $this->assertSame('playground-7', $row['facility_id']);
    // The fixture bundle has no field_district: the request still projects.
    $this->assertNull($row['district_tid']);
    $this->assertNull($row['assignee_uid']);
    $this->assertSame((string) $node->getCreatedTime(), $row['created']);
    $this->assertSame([(int) $this->organisationA->id()], $this->children('org', $nid));
    $this->assertSame([(int) $this->childJurisdiction->id()], $this->children('jur', $nid));

    $node = Node::load($nid);
    $node->set('field_organisation', [$this->organisationB->id()]);
    $node->setUnpublished();
    $node->set('field_address', [
      'country_code' => 'DE',
      'address_line1' => 'Probeallee 3',
      'postal_code' => '00098',
      'locality' => 'Musterstadt',
    ]);
    $node->save();

    $row = $this->row($nid);
    $this->assertSame((string) $this->organisationB->id(), $row['org_gid']);
    $this->assertSame('0', $row['published']);
    $this->assertSame('Probeallee 3', $row['address_line1']);
    $this->assertStringContainsString('Probeallee 3', $row['search_text']);
    $this->assertStringNotContainsString('Beispielweg', $row['search_text']);
    $this->assertSame([(int) $this->organisationB->id()], $this->children('org', $nid));

    $node->delete();
    $this->assertNull($this->row($nid));
    $this->assertSame([], $this->children('org', $nid));
    $this->assertSame([], $this->children('jur', $nid));
  }

  /**
   * The jurisdiction derived and saved again in node_insert is projected.
   *
   * The insert hook of markaspot_group sets field_jurisdiction to the only
   * root and resaves the node; the row must reflect that final state. Every
   * projection reads the stored request, so this holds in any hook order.
   */
  public function testJurisdictionDerivedOnInsertIsProjected(): void {
    $node = $this->createRequest(['field_jurisdiction' => [], 'field_organisation' => []]);
    $nid = (int) $node->id();
    $node = Node::load($nid);
    $this->assertSame((int) $this->rootJurisdiction->id(), (int) $node->get('field_jurisdiction')->target_id);

    $row = $this->row($nid);
    $this->assertSame((string) $this->rootJurisdiction->id(), $row['jur_gid']);
    $this->assertSame((string) $this->rootJurisdiction->id(), $row['root_gid']);
    $this->assertSame('0', $row['org_gid']);
    $this->assertSame([(int) $this->rootJurisdiction->id()], $this->children('jur', $nid));
    $this->assertSame([], $this->children('org', $nid));
  }

  /**
   * Multi-value organisations and jurisdictions fill child tables and flags.
   */
  public function testMultiValueReferencesFillChildTables(): void {
    $node = $this->createRequest([
      'field_jurisdiction' => [$this->rootJurisdiction->id(), $this->childJurisdiction->id()],
      'field_organisation' => [$this->organisationB->id(), $this->organisationA->id()],
    ]);
    $nid = (int) $node->id();
    $stored = Node::load($nid);
    $this->assertCount(2, $stored->get('field_organisation'), 'markaspot_group kept both organisations.');
    $this->assertCount(2, $stored->get('field_jurisdiction'), 'markaspot_group kept both jurisdictions.');

    $row = $this->row($nid);
    // The first delta, not the lowest ID, is the row's organisation.
    $this->assertSame((string) $this->organisationB->id(), $row['org_gid']);
    $this->assertSame('1', $row['org_multi']);
    // The child is more specific than the root listed before it.
    $this->assertSame((string) $this->childJurisdiction->id(), $row['jur_gid']);
    $this->assertSame((string) $this->rootJurisdiction->id(), $row['root_gid']);
    $this->assertSame('1', $row['jur_multi']);
    $organisations = [(int) $this->organisationA->id(), (int) $this->organisationB->id()];
    sort($organisations);
    $this->assertSame($organisations, $this->children('org', $nid));
    $this->assertSame([(int) $this->rootJurisdiction->id(), (int) $this->childJurisdiction->id()], $this->children('jur', $nid));
  }

  /**
   * Relationship writes re-project through Group's node resave.
   *
   * This module has no relationship hooks: Group 3 saves the grouped node
   * again on every relationship insert and delete, and that save's
   * node_update projects field_organisation, the authority, not the
   * relation.
   */
  public function testGroupRelationshipChangesReprojectThroughNodeResave(): void {
    $node = $this->createRequest();
    $nid = (int) $node->id();
    $orgA = (string) $this->organisationA->id();

    // A stale index row comes back on the next relationship insert. The
    // markaspot_group field sync is suspended; Group's node resave remains.
    $this->database()->delete('markaspot_request_index')->condition('nid', $nid)->execute();
    _markaspot_group_set_syncing(TRUE);
    try {
      $relationship = $this->organisationB->addRelationship($node, 'group_node:service_request');
    }
    finally {
      _markaspot_group_set_syncing(FALSE);
    }
    $row = $this->row($nid);
    $this->assertNotNull($row);
    // field_organisation is the authority, not the new relation.
    $this->assertSame($orgA, $row['org_gid']);
    $this->assertSame([(int) $orgA], $this->children('org', $nid));

    $this->database()->update('markaspot_request_index')
      ->fields(['org_gid' => 999999])
      ->condition('nid', $nid)
      ->execute();
    _markaspot_group_set_syncing(TRUE);
    try {
      $relationship->delete();
    }
    finally {
      _markaspot_group_set_syncing(FALSE);
    }
    $this->assertSame($orgA, $this->row($nid)['org_gid']);
  }

  /**
   * Contact fields never reach search_text or any other column.
   */
  public function testContactFieldsNeverProjected(): void {
    $contact = [
      'field_e_mail' => 'fictional.reporter@example.test',
      'field_phone' => '+00 555 0100',
      'field_first_name' => 'Fictiona',
      'field_last_name' => 'Placeholderova',
    ];
    $node = $this->createRequest($contact);
    $row = $this->row((int) $node->id());

    $this->assertStringContainsString('Bench with a loose slat', $row['search_text']);
    $this->assertStringContainsString('Beispielweg 12', $row['search_text']);
    $this->assertStringContainsString('00099', $row['search_text']);
    $this->assertStringContainsString('Musterstadt', $row['search_text']);
    foreach ($contact as $value) {
      foreach ($row as $column => $stored) {
        $this->assertStringNotContainsString($value, (string) $stored, $column);
      }
    }
    $this->assertStringNotContainsString('example.test', $row['search_text']);
    $this->assertStringNotContainsString('555', $row['search_text']);
  }

  /**
   * A failing index write never fails the node save; it is queued instead.
   */
  public function testFailingIndexWriteIsQueuedNotFatal(): void {
    $schema = $this->database()->schema();
    $this->container->get('module_handler')->loadInclude('markaspot_request_index', 'install');
    $definition = markaspot_request_index_schema()['markaspot_request_index_jur'];
    $schema->dropTable('markaspot_request_index_jur');
    // hook_install() creates the queue table on live sites. Created lazily
    // inside the save transaction, it would be DDL with an implicit commit.
    $queue = $this->container->get('queue')->get(RequestIndexRebuilder::QUEUE, TRUE);
    $queue->createItem(['op' => 'nids', 'nids' => []]);
    $queue->deleteQueue();

    $node = $this->createRequest();
    $nid = (int) $node->id();
    $this->assertNotNull(Node::load($nid), 'The request was saved.');
    $this->assertNull($this->row($nid), 'The partial write was rolled back.');
    $rebuilder = $this->container->get('markaspot_request_index.rebuilder');
    $this->assertGreaterThan(0, $rebuilder->status()['queued']);

    $schema->createTable('markaspot_request_index_jur', $definition);
    $rebuilder->drain();
    $this->assertSame((string) $this->organisationA->id(), $this->row($nid)['org_gid'] ?? NULL);
    $this->assertSame([(int) $this->childJurisdiction->id()], $this->children('jur', $nid));
  }

  /**
   * Copied term values and a reparented jurisdiction re-project via queue.
   */
  public function testTermAndHierarchyChangesQueueReprojection(): void {
    $node = $this->createRequest();
    $nid = (int) $node->id();
    $rebuilder = $this->container->get('markaspot_request_index.rebuilder');
    $this->assertInstanceOf(RequestIndexRebuilder::class, $rebuilder);

    $this->category->set('field_service_code', 'BENCH-2')->save();
    $this->assertSame('BENCH-1', $this->row($nid)['service_code'], 'Term saves do not write rows synchronously.');
    $rebuilder->drain();
    $this->assertSame('BENCH-2', $this->row($nid)['service_code']);

    $newRoot = Group::create(['type' => 'jur', 'label' => 'Fictional second root']);
    $newRoot->save();
    $child = Group::load($this->childJurisdiction->id());
    $child->set('field_parent_jurisdiction', $newRoot->id())->save();
    $this->assertSame((string) $this->rootJurisdiction->id(), $this->row($nid)['root_gid']);
    $this->assertGreaterThan(0, $rebuilder->status()['queued']);
    $rebuilder->drain();
    $this->assertSame((string) $newRoot->id(), $this->row($nid)['root_gid']);
    $this->assertSame((string) $this->childJurisdiction->id(), $this->row($nid)['jur_gid']);
  }

}
