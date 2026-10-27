<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Kernel;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\markaspot_ai\Controller\ProcessingController;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Scopes the AI processing status to one jurisdiction and published reports.
 *
 * @group markaspot_ai
 */
final class ProcessingStatusScopeKernelTest extends DuplicateAccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $schema = markaspot_ai_schema();
    foreach (['markaspot_ai_embeddings', 'markaspot_ai_sentiment'] as $table) {
      $this->container->get('database')->schema()->createTable($table, $schema[$table]);
    }
    // Slugs resolve the jurisdiction_id parameter like in the dashboard.
    FieldStorageConfig::create([
      'field_name' => 'field_slug',
      'entity_type' => 'group',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_slug',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Slug',
    ])->save();
  }

  /**
   * Counts only published reports of the requested jurisdiction.
   */
  public function testStatusCountsPublishedReportsOfOneJurisdiction(): void {
    $embedded = $this->request($this->tenantA, 'A published, embedded');
    $this->request($this->tenantA, 'A published, missing');
    $this->request($this->tenantA, 'A unpublished', FALSE);
    $this->request($this->tenantB, 'B published');
    $this->request($this->tenantB, 'B unpublished', FALSE);
    $this->embed($embedded);

    $status = $this->fetchStatus((string) $this->tenantA->id());
    $this->assertSame(2, $status['total_requests']);
    $this->assertSame(1, $status['awaiting_publication']);
    $this->assertSame(1, $status['embeddings']['count']);
    $this->assertSame(1, $status['embeddings']['missing']);

    $status = $this->fetchStatus((string) $this->tenantB->id());
    $this->assertSame(1, $status['total_requests']);
    $this->assertSame(1, $status['awaiting_publication']);
    $this->assertSame(0, $status['embeddings']['count']);
  }

  /**
   * A jurisdiction that does not resolve never widens to every tenant.
   */
  public function testUnresolvedJurisdictionReturnsNothing(): void {
    $this->request($this->tenantA, 'A published');
    $this->request($this->tenantB, 'B published');
    $org = Group::create(['type' => 'org', 'label' => 'Organisation']);
    $org->save();

    foreach (['no-such-slug', '999999', (string) $org->id()] as $value) {
      $status = $this->fetchStatus($value);
      $this->assertSame(0, $status['total_requests'], $value);
      $this->assertSame(0, $status['awaiting_publication'], $value);

      $request = Request::create('/api/ai/processing/queue', 'POST', [], [], [], [], (string) json_encode([
        'jurisdiction_id' => $value,
        'limit' => 10,
      ]));
      $result = json_decode((string) $this->controller()->queueMissing($request)->getContent(), TRUE);
      $this->assertSame(0, $result['queued'], $value);
    }
  }

  /**
   * The scope subquery can be reused, and unpublished reports never show up.
   */
  public function testMissingEmbeddingsUseScopeAndPublishState(): void {
    $missing = $this->request($this->tenantA, 'A published, missing');
    $this->request($this->tenantA, 'A unpublished', FALSE);
    $this->request($this->tenantB, 'B published');

    $controller = $this->controller();
    $scope = (fn(int $id): ?SelectInterface => $this->buildJurisdictionScope($id))
      ->call($controller, (int) $this->tenantA->id());
    $this->assertInstanceOf(SelectInterface::class, $scope);

    $service = $this->embeddingService();
    // Twice with the same subquery: each outer query gets its own copy.
    for ($i = 0; $i < 2; $i++) {
      $ids = $service->findMissingEmbeddings(10, 'node', 'service_request', 'content', $scope);
      $this->assertSame([(int) $missing->id()], array_map('intval', $ids));
    }
  }

  /**
   * Returns the decoded status for a jurisdiction parameter.
   */
  private function fetchStatus(string $jurisdiction): array {
    $query = ['jurisdiction_id' => $jurisdiction];
    $stack = new RequestStack();
    $stack->push(Request::create('/api/ai/processing/status', 'GET', $query));
    return json_decode((string) $this->controller($stack)->getStatus()->getContent(), TRUE);
  }

  /**
   * Builds the controller with real storage and a stub embedding model.
   */
  private function controller(?RequestStack $stack = NULL): ProcessingController {
    $controller = new ProcessingController(
      $this->container->get('database'),
      $this->container->get('queue'),
      $this->container->get('plugin.manager.queue_worker'),
      $this->embeddingService(),
      $stack ?? new RequestStack(),
      $this->container->get('markaspot_group.hierarchy_resolver'),
    );
    $controller->setStringTranslation($this->container->get('string_translation'));
    return $controller;
  }

  /**
   * Builds the embedding service with a fixed embedding model.
   */
  private function embeddingService(): EmbeddingService {
    $client = $this->createMock(AiClientService::class);
    $client->method('resolveEmbeddingModel')->willReturn('test-model');
    return new EmbeddingService(
      $client,
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
    );
  }

  /**
   * Stores an embedding row for a report.
   */
  private function embed(NodeInterface $node): void {
    $this->container->get('database')->insert('markaspot_ai_embeddings')->fields([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'embedding_type' => 'content',
      'embedding' => '[]',
      'model' => 'test-model',
      'dimensions' => 0,
      'text_hash' => 'hash',
      'created' => 0,
    ])->execute();
  }

}
