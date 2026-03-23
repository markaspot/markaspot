<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_ai\Service\EmbeddingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the embedding service for vector operations and storage.
 *
 * @group markaspot_ai
 * @coversDefaultClass \Drupal\markaspot_ai\Service\EmbeddingService
 */
class EmbeddingServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_ai\Service\EmbeddingService
   */
  protected EmbeddingService $service;

  /**
   * The mocked AI client.
   *
   * @var \Drupal\markaspot_ai\Service\AiClientService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $aiClient;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->aiClient = $this->createMock(AiClientService::class);
    $this->database = $this->createMock(Connection::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_ai')
      ->willReturn($this->logger);

    $this->service = new EmbeddingService(
      $this->aiClient,
      $this->database,
      $entityTypeManager,
      $loggerFactory,
    );
  }

  /**
   * Tests identical vectors have similarity of 1.0.
   *
   * @covers ::cosineSimilarity
   */
  public function testIdenticalVectorsSimilarity(): void {
    $vector = [1.0, 2.0, 3.0];
    $similarity = $this->service->cosineSimilarity($vector, $vector);
    $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);
  }

  /**
   * Tests opposite vectors have similarity of -1.0.
   *
   * @covers ::cosineSimilarity
   */
  public function testOppositeVectorsSimilarity(): void {
    $vectorA = [1.0, 0.0, 0.0];
    $vectorB = [-1.0, 0.0, 0.0];
    $similarity = $this->service->cosineSimilarity($vectorA, $vectorB);
    $this->assertEqualsWithDelta(-1.0, $similarity, 0.0001);
  }

  /**
   * Tests orthogonal vectors have similarity of 0.0.
   *
   * @covers ::cosineSimilarity
   */
  public function testOrthogonalVectorsSimilarity(): void {
    $vectorA = [1.0, 0.0, 0.0];
    $vectorB = [0.0, 1.0, 0.0];
    $similarity = $this->service->cosineSimilarity($vectorA, $vectorB);
    $this->assertEqualsWithDelta(0.0, $similarity, 0.0001);
  }

  /**
   * Tests zero vector returns 0.0.
   *
   * @covers ::cosineSimilarity
   */
  public function testZeroVectorSimilarity(): void {
    $zero = [0.0, 0.0, 0.0];
    $other = [1.0, 2.0, 3.0];
    $similarity = $this->service->cosineSimilarity($zero, $other);
    $this->assertEquals(0.0, $similarity);
  }

  /**
   * Tests dimension mismatch throws exception.
   *
   * @covers ::cosineSimilarity
   */
  public function testDimensionMismatchThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('same dimensions');

    $this->service->cosineSimilarity([1.0, 2.0], [1.0, 2.0, 3.0]);
  }

  /**
   * Tests similarity is commutative (A,B == B,A).
   *
   * @covers ::cosineSimilarity
   */
  public function testSimilarityIsCommutative(): void {
    $a = [0.5, 0.3, 0.8, 0.1];
    $b = [0.2, 0.7, 0.4, 0.9];

    $ab = $this->service->cosineSimilarity($a, $b);
    $ba = $this->service->cosineSimilarity($b, $a);

    $this->assertEqualsWithDelta($ab, $ba, 0.0001);
  }

  /**
   * Tests similarity with real-world-like embedding vectors.
   *
   * @covers ::cosineSimilarity
   */
  public function testSimilarityWithRealisticVectors(): void {
    // Two similar vectors (high similarity expected).
    $a = [0.1, 0.8, 0.3, 0.5, 0.2];
    $b = [0.12, 0.79, 0.31, 0.48, 0.22];
    $similarity = $this->service->cosineSimilarity($a, $b);
    $this->assertGreaterThan(0.99, $similarity);

    // Two dissimilar vectors (low similarity expected).
    $c = [0.9, 0.1, 0.0, 0.0, 0.8];
    $similarity2 = $this->service->cosineSimilarity($a, $c);
    $this->assertLessThan(0.7, $similarity2);
  }

  /**
   * Tests text hash normalization.
   *
   * @covers ::generateTextHash
   */
  public function testTextHashNormalization(): void {
    $hash1 = $this->service->generateTextHash('Hello  World');
    $hash2 = $this->service->generateTextHash('hello world');
    $hash3 = $this->service->generateTextHash("  HELLO\t\nWORLD  ");

    $this->assertEquals($hash1, $hash2);
    $this->assertEquals($hash2, $hash3);
  }

  /**
   * Tests different texts produce different hashes.
   *
   * @covers ::generateTextHash
   */
  public function testDifferentTextsProduceDifferentHashes(): void {
    $hash1 = $this->service->generateTextHash('pothole on main street');
    $hash2 = $this->service->generateTextHash('graffiti on wall');

    $this->assertNotEquals($hash1, $hash2);
  }

  /**
   * Tests hash is a valid SHA-256 hex string.
   *
   * @covers ::generateTextHash
   */
  public function testHashFormat(): void {
    $hash = $this->service->generateTextHash('test');
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
  }

  /**
   * Tests successful embedding generation.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingSuccess(): void {
    $vector = [0.1, 0.2, 0.3, 0.4, 0.5];
    $this->aiClient->method('embed')
      ->willReturn([
        'data' => [['embedding' => $vector]],
        'model' => 'text-embedding-3-large',
        'usage' => ['prompt_tokens' => 5],
      ]);

    $result = $this->service->generateEmbedding('test text');

    $this->assertEquals($vector, $result['vector']);
    $this->assertEquals('text-embedding-3-large', $result['model']);
    $this->assertEquals(5, $result['dimensions']);
    $this->assertEquals(['prompt_tokens' => 5], $result['usage']);
  }

  /**
   * Tests empty text throws InvalidArgumentException.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingEmptyTextThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('empty text');

    $this->service->generateEmbedding('   ');
  }

  /**
   * Tests invalid response structure throws exception.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingInvalidResponseThrows(): void {
    $this->aiClient->method('embed')
      ->willReturn(['data' => []]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid embedding response');

    $this->service->generateEmbedding('test text');
  }

  /**
   * Tests API failure propagates exception.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingApiFailureThrows(): void {
    $this->aiClient->method('embed')
      ->willThrowException(new \Exception('API down'));

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('API down');

    $this->service->generateEmbedding('test text');
  }

  /**
   * Tests deleting embedding for a specific type.
   *
   * @covers ::deleteEmbedding
   */
  public function testDeleteEmbeddingSpecificType(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    $this->database->method('delete')
      ->with('markaspot_ai_embeddings')
      ->willReturn($delete);

    $deleted = $this->service->deleteEmbedding(42, 'node', 'content');
    $this->assertEquals(1, $deleted);
  }

  /**
   * Tests deleting all embeddings for an entity.
   *
   * @covers ::deleteEmbedding
   */
  public function testDeleteEmbeddingAllTypes(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(3);

    $this->database->method('delete')
      ->with('markaspot_ai_embeddings')
      ->willReturn($delete);

    $deleted = $this->service->deleteEmbedding(42);
    $this->assertEquals(3, $deleted);
  }

  /**
   * Tests delete handles database exception gracefully.
   *
   * @covers ::deleteEmbedding
   */
  public function testDeleteEmbeddingDatabaseError(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willThrowException(new \Exception('DB error'));

    $this->database->method('delete')
      ->willReturn($delete);

    $deleted = $this->service->deleteEmbedding(42);
    $this->assertEquals(0, $deleted);
  }

}
