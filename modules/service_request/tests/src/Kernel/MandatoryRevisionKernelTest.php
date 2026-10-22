<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\service_request\RevisionLog;
use Drupal\user\Entity\User;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves revision history survives callers disabling the revision flag.
 *
 * @group service_request
 */
#[RunTestsInSeparateProcesses]
final class MandatoryRevisionKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'service_request',
    'zzz_revision_domain_test',
  ];

  /**
   * Every report save retains the old values and attributes the new revision.
   */
  public function testMandatoryRevisions(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
    $type = NodeType::create(['type' => 'service_request', 'name' => 'Report', 'new_revision' => FALSE]);
    $type->save();
    $this->assertTrue(NodeType::load('service_request')->shouldCreateNewRevision());
    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'settings' => ['handler' => 'default:node'],
    ])->save();

    User::create(['uid' => 0, 'name' => 'Anonymous', 'status' => 0])->save();
    $actor = $this->createUser();
    $this->container->get('current_user')->setAccount($actor);
    $node = Node::create(['type' => 'service_request', 'title' => 'Before', 'uid' => $actor->id()]);
    $node->save();
    $old_id = (int) $node->getRevisionId();
    $node->setTitle('After');
    $node->setNewRevision(FALSE);
    $node->save();
    $this->assertNotSame($old_id, (int) $node->getRevisionId());
    $this->assertSame((int) $actor->id(), (int) $node->getRevisionUserId());
    $this->assertSame('Before', $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($old_id)->label());
    $this->assertSame('After', $node->label());
    $node->setTitle('Automatic moderation');
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId(0);
    $node->setRevisionCreationTime(1234567890);
    RevisionLog::setMessage($node, 'Explicit system attribution.');
    $node->save();
    $this->assertSame(0, (int) $node->getRevisionUserId());
    $this->assertSame(1234567890, (int) $node->getRevisionCreationTime());
    $this->assertSame('Explicit system attribution.', RevisionLog::message((string) $node->getRevisionLogMessage()));
    $this->assertSame('civic.revision.v1', json_decode((string) $node->getRevisionLogMessage(), TRUE)['schema']);
  }

  /**
   * Config imports converge while runtime revisions remain mandatory.
   */
  public function testConfigImportConvergesAndUpdateSetsDefault(): void {
    $this->testMandatoryRevisions();
    $type = NodeType::load('service_request');
    $type->setSyncing(TRUE);
    $type->setNewRevision(FALSE);
    $type->save();
    $this->assertFalse(NodeType::load('service_request')->shouldCreateNewRevision());
    $node = Node::create(['type' => 'service_request', 'title' => 'Imported config']);
    $node->save();
    $vid = $node->getRevisionId();
    $node->setTitle('Programmatic edit')->setNewRevision(FALSE);
    $node->save();
    $this->assertNotSame($vid, $node->getRevisionId());
    $this->container->get('module_handler')->loadInclude('service_request', 'install');
    service_request_update_11023();
    $this->assertTrue(NodeType::load('service_request')->shouldCreateNewRevision());
  }

  /**
   * A late procedural domain hook runs before the explicitly last collector.
   */
  public function testFinalDomainStateIsCaptured(): void {
    $this->testMandatoryRevisions();
    $node = Node::create(['type' => 'service_request', 'title' => 'Late domain change']);
    $node->save();
    $this->container->get('state')->set('revision_domain_test.status', $node->id());
    $node->setTitle('Updated');
    $node->save();
    $entry = RevisionLog::decode((string) $node->getRevisionLogMessage());
    $status = array_values(array_filter($entry['changes'], fn(array $change): bool => $change['field'] === 'status'));
    $this->assertSame((string) $node->id(), $status[0]['after'][0]['id']);
    $this->assertSame([], $status[0]['before']);
  }

}
