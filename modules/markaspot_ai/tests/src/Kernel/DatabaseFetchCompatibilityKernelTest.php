<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_ai\Controller\DuplicateController;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\DuplicateDetectionService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\markaspot_ai\Service\TokenTrackingService;
use Drupal\markaspot_dashboard\Controller\AdminStatsController;
use Drupal\markaspot_group\Controller\GroupInvitationController;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Executes the twelve converted associative fetches against a real database.
 */
#[Group('markaspot_ai')]
#[RunTestsInSeparateProcesses]
final class DatabaseFetchCompatibilityKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Isolated kernel database, never the configured runtime database.
   */
  private Connection $database;

  /**
   * Only the service configuration is mocked; all queries use the real driver.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->database = $this->container->get('database');
    require_once dirname(__DIR__, 3) . '/markaspot_ai.install';
    require_once dirname(__DIR__, 4) . '/markaspot_group/markaspot_group.install';
    foreach (markaspot_ai_schema() as $name => $schema) {
      $this->database->schema()->createTable($name, $schema);
    }
    $this->database->schema()->createTable('markaspot_group_invitations', markaspot_group_schema()['markaspot_group_invitations']);
    $this->createRowsTable('node_field_data', [
      'nid' => 'int', 'type' => 'varchar', 'title' => 'varchar',
      'status' => 'int', 'created' => 'int',
    ]);
    $this->createRowsTable('groups_field_data', [
      'id' => 'int', 'type' => 'varchar', 'label' => 'varchar',
      'default_langcode' => 'int', 'created' => 'int',
    ]);
    $this->createRowsTable('group__field_slug', [
      'entity_id' => 'int', 'field_slug_value' => 'varchar',
    ]);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['token_tracking.enabled', TRUE],
      ['token_tracking.daily_limit', 500],
    ]);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->with('markaspot_ai.settings')->willReturn($config);
  }

  /**
   * Creates minimal joined fixture tables without full entity installation.
   */
  private function createRowsTable(string $name, array $columns): void {
    $fields = [];
    foreach ($columns as $column => $type) {
      $fields[$column] = ['type' => $type, 'not null' => TRUE];
      if ($type === 'varchar') {
        $fields[$column]['length'] = 255;
      }
    }
    $this->database->schema()->createTable($name, ['fields' => $fields]);
  }

  /**
   * Five real token fetches preserve keyed/list rows and budget decisions.
   */
  public function testTokenUsageBreakdownsAndBudgetUseRealAssociativeRows(): void {
    $service = new TokenTrackingService(
      $this->database,
      $this->configFactory,
      $this->container->get('logger.factory'),
    );
    $service->logUsage('provider-a', 'model-a', 'chat', 100, 50);
    $service->logUsage('provider-b', 'model-b', 'embed', 200, 0);
    $daily = $service->getDailyUsage();
    $this->assertSame(350, $daily['total_tokens']);
    $this->assertSame(100, (int) $daily['by_model']['model-a']['input_tokens']);
    $this->assertSame(200, (int) $daily['by_operation']['embed']['input_tokens']);
    $this->assertSame(2, $daily['request_count']);
    $provider = $service->getDailyUsage('provider-a');
    $this->assertSame(150, $provider['total_tokens']);
    $this->assertSame(['model-a'], array_keys($provider['by_model']));
    $this->assertSame(['chat'], array_keys($provider['by_operation']));
    $total = $service->getTotalUsage(30);
    $this->assertSame(350, $total['total_tokens']);
    $this->assertCount(1, $total['daily_breakdown']);
    $this->assertSame(300, reset($total['daily_breakdown'])['input_tokens']);
    $this->assertSame(200, (int) $total['by_provider']['provider-b']['input_tokens']);
    $this->assertCount(2, $total['by_model']);
    $this->assertIsArray($total['by_model'][0]);
    $this->assertTrue($service->checkLimit());
    $this->assertSame(150, $service->getRemainingTokens());
    $service->logUsage('provider-a', 'model-a', 'chat', 200, 0);
    $this->assertFalse($service->checkLimit());
    $this->assertSame(0, $service->getRemainingTokens());
  }

  /**
   * Embedding and duplicate fetches preserve keys, filters and row shapes.
   */
  public function testEmbeddingAndDuplicateQueriesReturnAssociativeRows(): void {
    $client = $this->createMock(AiClientService::class);
    $client->expects($this->never())->method('embed');
    $client->method('resolveEmbeddingModel')->willReturn('local-test');
    $entityManager = $this->createMock(EntityTypeManagerInterface::class);
    $embedding = new EmbeddingService($client, $this->database, $entityManager, $this->container->get('logger.factory'));
    $now = $this->container->get('datetime.time')->getRequestTime();
    foreach ([1, 2, 3] as $nid) {
      $this->database->insert('node_field_data')->fields([
        'nid' => $nid, 'type' => 'service_request', 'title' => 'Request ' . $nid,
        'status' => 1, 'created' => $now,
      ])->execute();
      $this->database->insert('markaspot_ai_embeddings')->fields([
        'entity_id' => $nid, 'embedding' => '[1,0]', 'dimensions' => 2,
        'model' => 'local-test', 'created' => $now,
      ])->execute();
    }
    $vectors = $embedding->getAllEmbeddings(excludeEntityId: 1);
    $this->assertSame([2, 3], array_map('intval', array_keys($vectors)));
    $this->assertSame([1, 0], $vectors[2]['vector']);
    $this->assertArrayNotHasKey('embedding', $vectors[2]);
    foreach ([2, 3] as $match) {
      $this->database->insert('markaspot_ai_duplicate_matches')->fields([
        'source_nid' => 1, 'match_nid' => $match, 'similarity_score' => 0.9,
        'created' => $now,
      ])->execute();
    }
    $duplicates = new DuplicateDetectionService(
      $embedding, $this->database, $entityManager,
      $this->configFactory, $this->container->get('logger.factory'),
    );
    $matches = $duplicates->getDuplicateMatches(2, 'pending');
    $this->assertCount(1, $matches);
    $this->assertSame(1, $matches[0]['other_nid']);
    $pending = $duplicates->getPendingMatches();
    $this->assertCount(2, $pending);
    $this->assertSame('Request 1', $pending[0]['source_title']);
    $source = $this->createMock(NodeInterface::class);
    $source->method('id')->willReturn(1);
    $source->method('hasField')->willReturn(FALSE);
    $found = $duplicates->findDuplicates($source, [
      'similarity_threshold' => 0.5, 'radius_meters' => 0,
      'time_window_days' => 30, 'exclude_nids' => [3],
    ]);
    $this->assertCount(1, $found);
    $this->assertSame(2, (int) $found[0]['nid']);
    $controller = new class($duplicates, $entityManager, $this->database) extends DuplicateController {

      /**
       * Exercises the actual query with an already authorized node-ID set.
       */
      public function scopedMatches(array $ids): array {
        return $this->getPendingDuplicateMatches($ids, 50, 0);
      }

    };
    $scoped = $controller->scopedMatches([1, 2]);
    $this->assertCount(1, $scoped);
    $this->assertSame(2, (int) $scoped[0]['match_nid']);
    $this->assertSame('Request 2', $scoped[0]['match_title']);
    $this->assertSame([], $controller->scopedMatches([1]));
  }

  /**
   * Signup and invitation queries preserve typed responses and group filters.
   */
  public function testControllerQueriesUseRealAssociativeRows(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    foreach ([[10, 'jur', 1], [11, 'org', 1], [12, 'jur', 0]] as [$id, $type, $default]) {
      $this->database->insert('groups_field_data')->fields([
        'id' => $id, 'type' => $type, 'label' => 'Group ' . $id,
        'default_langcode' => $default, 'created' => $now,
      ])->execute();
      $this->database->insert('group__field_slug')->fields([
        'entity_id' => $id, 'field_slug_value' => 'group-' . $id,
      ])->execute();
    }
    $stats = new AdminStatsController($this->database, $this->container->get('datetime.time'), $this->container->get('config.factory'));
    $response = json_decode($stats->signups()->getContent(), TRUE);
    $this->assertSame([
      ['id' => '10', 'label' => 'Group 10', 'slug' => 'group-10', 'created' => $now],
    ], $response['signups']);
    $invitations = [
      [10, NULL, $now + 3600],
      [11, NULL, $now + 3600],
      [10, $now, $now + 3600],
      [10, NULL, $now - 10],
    ];
    foreach ($invitations as $index => [$gid, $claimed, $expires]) {
      $this->database->insert('markaspot_group_invitations')->fields([
        'token' => hash('sha256', 'invitation-' . $index),
        'email' => 'invite-' . $index . '@example.test', 'group_id' => $gid,
        'roles' => '["jur-member"]', 'invited_by' => 1,
        'created' => $now, 'expires' => $expires, 'claimed' => $claimed,
      ])->execute();
    }
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(10);
    $group->method('bundle')->willReturn('jur');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(10)->willReturn($group);
    $entityManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityManager->method('getStorage')->with('group')->willReturn($storage);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $account->method('getRoles')->willReturn(['authenticated']);
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');
    // GroupInvitationController still requires the legacy loader interface.
    // @phpstan-ignore classConstant.deprecatedInterface
    $memberships = $this->createMock(GroupMembershipLoaderInterface::class);
    $controller = new GroupInvitationController(
      $this->database, $entityManager, $mail, $this->container->get('module_handler'),
      $this->container->get('logger.factory')->get('markaspot_group'),
      $memberships, $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $account, $this->createMock(FloodInterface::class), $this->createMock(LockBackendInterface::class),
    );
    $response = json_decode($controller->listInvitations(Request::create('/api/group-members/invitations?group_id=10'))->getContent(), TRUE);
    $this->assertCount(1, $response['invitations']);
    $this->assertSame('invite-0@example.test', $response['invitations'][0]['email']);
    $this->assertSame(10, $response['invitations'][0]['group_id']);
    $this->assertSame(['jur-member'], $response['invitations'][0]['roles']);
  }

}
