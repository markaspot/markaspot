<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\user\Entity\User;
use Drupal\Core\Utility\UpdateException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/markaspot_group.install';
require_once dirname(__DIR__, 5) . '/markaspot.install';

/**
 * Tests deferred Search API work while grant update hooks run.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class DeferredSearchIndexingUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'search_api',
    'search_api_test',
  ];

  /**
   * The index whose content-access tracker is updated by node grants.
   */
  private IndexInterface $index;

  /**
   * The node used to trigger a node-access record alteration.
   */
  private Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('search_api', ['search_api_item']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['search_api', 'system', 'user', 'node']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    $this->node = Node::create([
      'type' => 'service_request',
      'title' => 'Queued after grant rebuild',
      'uid' => 1,
      'status' => 1,
    ]);
    $this->node->save();

    Server::create([
      'name' => 'Test server',
      'id' => 'test_server',
      'status' => TRUE,
      'backend' => 'search_api_test',
    ])->save();
    $this->index = Index::create([
      'name' => 'Service requests',
      'id' => 'service_requests',
      'status' => TRUE,
      'server' => 'test_server',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'processor_settings' => ['content_access' => []],
      'field_settings' => [
        'node_grants' => [
          'label' => 'Node access information',
          'type' => 'string',
          'property_path' => 'search_api_node_grants',
          'indexed_locked' => TRUE,
          'type_locked' => TRUE,
          'hidden' => TRUE,
        ],
        'status' => [
          'label' => 'Publishing status',
          'type' => 'boolean',
          'datasource_id' => 'entity:node',
          'property_path' => 'status',
          'indexed_locked' => TRUE,
          'type_locked' => TRUE,
        ],
      ],
      'options' => ['index_directly' => TRUE],
    ]);
    $this->index->save();
    \Drupal::service('search_api.index_task_manager')->addItemsAll($this->index);
    \Drupal::service('search_api.server_task_manager')
      ->execute($this->index->getServerInstance());
  }

  /**
   * Grant rebuilds keep direct-indexing work queued across retry batches.
   */
  public function testGrantUpdatesDeferAndPreserveSearchIndexWork(): void {
    $tracker = $this->index->getTrackerInstance();
    $remaining = $tracker->getRemainingItemsCount();
    $this->assertGreaterThan(0, $remaining);

    $this->rebuildGrantsWithDeferredIndexing();
    $this->assertFalse($this->index->isBatchTracking());
    $this->assertSame($remaining, $tracker->getRemainingItemsCount());
    $this->triggerPostRequestIndexing();
    $this->assertSame($remaining, $tracker->getRemainingItemsCount());

    // A repeated update batch does not drain or duplicate the pending item.
    $this->rebuildGrantsWithDeferredIndexing();
    $this->triggerPostRequestIndexing();
    $this->assertSame($remaining, $tracker->getRemainingItemsCount());
  }

  /**
   * Grant rewrites remove stale access-filtered results before they can leak.
   */
  public function testGrantUpdatesClearAccessIndexesBeforeQueuingWork(): void {
    $tracker = $this->index->getTrackerInstance();
    $backend_key = 'search_api_test.backend.indexed.' . $this->index->id();
    $this->container->get('state')->set($backend_key, ['stale' => TRUE]);
    $this->assertNotEmpty($this->container->get('state')->get($backend_key, []));

    $secondary = $this->createContentAccessIndex('secondary');
    $secondary_tracker = $secondary->getTrackerInstance();
    $secondary_key = 'search_api_test.backend.indexed.' . $secondary->id();
    $this->container->get('state')->set($secondary_key, ['stale' => TRUE]);
    $this->assertNotEmpty($this->container->get('state')->get($secondary_key, []));

    $cleared = _markaspot_group_clear_content_access_indexes();
    sort($cleared);
    $this->assertSame(['secondary', 'service_requests'], $cleared);
    $this->assertSame([], $this->container->get('state')->get($backend_key, []));
    $this->assertSame([], $this->container->get('state')->get($secondary_key, []));
    $this->assertSame(1, $tracker->getRemainingItemsCount());
    $this->assertSame(1, $secondary_tracker->getRemainingItemsCount());
    $this->triggerPostRequestIndexing();
    $this->assertSame(1, $tracker->getRemainingItemsCount());
    $this->assertSame(1, $secondary_tracker->getRemainingItemsCount());

    // A retry leaves the cleared backend and its queued replacement intact.
    $cleared = _markaspot_group_clear_content_access_indexes();
    sort($cleared);
    $this->assertSame(['secondary', 'service_requests'], $cleared);
    $this->assertSame([], $this->container->get('state')->get($backend_key, []));
    $this->assertSame([], $this->container->get('state')->get($secondary_key, []));
    $this->assertSame(1, $tracker->getRemainingItemsCount());
    $this->assertSame(1, $secondary_tracker->getRemainingItemsCount());
    $this->assertSame(0, $this->pendingDeleteTasks($this->index));
    $this->assertSame(0, $this->pendingDeleteTasks($secondary));
  }

  /**
   * Access grant rewrites fail before changing grants when clearing is unsafe.
   */
  public function testGrantUpdatesRejectReadOnlyOrFailingSearchIndexes(): void {
    $backend_key = 'search_api_test.backend.indexed.' . $this->index->id();
    $this->container->get('state')->set($backend_key, ['stale' => TRUE]);
    $this->assertNotEmpty($this->container->get('state')->get($backend_key, []));
    $this->index->set('read_only', TRUE)->save();
    try {
      _markaspot_group_clear_content_access_indexes();
      $this->fail('A read-only index must stop the grant update.');
    }
    catch (UpdateException $exception) {
      $this->assertStringContainsString('read-only', $exception->getMessage());
    }
    $this->assertNotEmpty($this->container->get('state')->get($backend_key, []));
    $this->index->set('read_only', FALSE)->save();

    $this->container->get('state')->set(
      'search_api_test.backend.exception.deleteAllIndexItems',
      TRUE,
    );
    try {
      _markaspot_group_clear_content_access_indexes();
      $this->fail('A failed backend clear must stop the grant update.');
    }
    catch (SearchApiException $exception) {
      $this->assertStringContainsString('deleteAllIndexItems', $exception->getMessage());
    }
    finally {
      $this->container->get('state')->set(
        'search_api_test.backend.exception.deleteAllIndexItems',
        FALSE,
      );
    }
    $this->assertNotEmpty($this->container->get('state')->get($backend_key, []));
    $this->assertSame(0, $this->pendingDeleteTasks($this->index));
  }

  /**
   * Interrupted tracker preparation is resumed without indexing in updatedb.
   */
  public function testDeferredMessageResolvesPartialTrackerPreparation(): void {
    $this->index->rebuildTracker();
    $tracker = $this->index->getTrackerInstance();
    $this->assertSame(0, $tracker->getTotalItemsCount());
    $this->assertFalse(
      $this->container->get('search_api.index_task_manager')
        ->isTrackingComplete($this->index),
    );

    _markaspot_management_index_deferred_message();
    $this->assertSame(1, $tracker->getTotalItemsCount());
    $this->assertSame(1, $tracker->getRemainingItemsCount());
    $this->triggerPostRequestIndexing();
    $this->assertSame(1, $tracker->getRemainingItemsCount());

    // Retrying the compatibility update does not replace or consume the work.
    _markaspot_management_index_deferred_message();
    $this->assertSame(1, $tracker->getRemainingItemsCount());
  }

  /**
   * Rebuilds this node's grants as the update hooks do.
   */
  private function rebuildGrantsWithDeferredIndexing(): void {
    $indexes = _markaspot_group_start_search_api_batch_tracking();
    try {
      $handler = $this->container->get('entity_type.manager')
        ->getAccessControlHandler('node');
      $handler->acquireGrants($this->node);
    }
    finally {
      _markaspot_group_stop_search_api_batch_tracking($indexes);
    }
  }

  /**
   * Runs Search API's post-request destructor in the test process.
   */
  private function triggerPostRequestIndexing(): void {
    $this->container->get('search_api.post_request_indexing')->destruct();
  }

  /**
   * Counts deletion tasks that could otherwise retain stale index rows.
   */
  private function pendingDeleteTasks(IndexInterface $index): int {
    return (int) $this->container->get('search_api.task_manager')->getTasksCount([
      'server_id' => 'test_server',
      'index_id' => $index->id(),
      'type' => ['deleteItems', 'deleteAllIndexItems'],
    ]);
  }

  /**
   * Creates an enabled node index with the Content access processor.
   */
  private function createContentAccessIndex(string $id): IndexInterface {
    $index = Index::create([
      'name' => $id,
      'id' => $id,
      'status' => TRUE,
      'server' => 'test_server',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'processor_settings' => ['content_access' => []],
      'field_settings' => [
        'node_grants' => [
          'label' => 'Node access information',
          'type' => 'string',
          'property_path' => 'search_api_node_grants',
          'indexed_locked' => TRUE,
          'type_locked' => TRUE,
          'hidden' => TRUE,
        ],
        'status' => [
          'label' => 'Publishing status',
          'type' => 'boolean',
          'datasource_id' => 'entity:node',
          'property_path' => 'status',
          'indexed_locked' => TRUE,
          'type_locked' => TRUE,
        ],
      ],
      'options' => ['index_directly' => TRUE],
    ]);
    $index->save();
    \Drupal::service('search_api.index_task_manager')->addItemsAll($index);
    \Drupal::service('search_api.server_task_manager')
      ->execute($index->getServerInstance());
    return $index;
  }

}
