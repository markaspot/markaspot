<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and managing vector embeddings.
 *
 * This service handles the generation of text embeddings via the AI client,
 * stores them in the database, and provides retrieval functionality for
 * similarity searches and duplicate detection.
 */
class EmbeddingService {

  /**
   * The AI client service.
   *
   * @var \Drupal\markaspot_ai\Service\AiClientService
   */
  protected AiClientService $aiClient;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Whether this request has already checked model visibility by entity type.
   */
  protected array $checkedModels = [];

  /**
   * Whether a model visibility warning has been emitted this request.
   */
  protected bool $modelWarningLogged = FALSE;

  /**
   * Constructs a new EmbeddingService.
   *
   * @param \Drupal\markaspot_ai\Service\AiClientService $ai_client
   *   The AI client service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    AiClientService $ai_client,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->aiClient = $ai_client;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('markaspot_ai');
  }

  /**
   * Generates an embedding vector for the given text.
   *
   * @param string $text
   *   The text to generate an embedding for.
   * @param array $options
   *   Optional parameters to pass to the AI client.
   *
   * @return array
   *   The embedding result containing:
   *   - 'vector': The embedding vector (array of floats).
   *   - 'model': The model used.
   *   - 'dimensions': Number of dimensions.
   *   - 'usage': Token usage information.
   *
   * @throws \Exception
   *   When embedding generation fails.
   */
  public function generateEmbedding(string $text, array $options = []): array {
    if (empty(trim($text))) {
      throw new \InvalidArgumentException('Cannot generate embedding for empty text.');
    }

    try {
      $response = $this->aiClient->embed($text, $options);

      if (!isset($response['data'][0]['embedding'])) {
        throw new \Exception('Invalid embedding response structure.');
      }

      $embedding = $response['data'][0]['embedding'];

      return [
        'vector' => $embedding,
        'model' => $this->aiClient->resolveEmbeddingModel($options['model'] ?? NULL, $options['provider'] ?? NULL),
        'dimensions' => count($embedding),
        'usage' => $response['usage'] ?? [],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate embedding: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Stores an embedding vector in the database.
   *
   * @param int $entityId
   *   The entity ID the embedding belongs to.
   * @param string $entityType
   *   The entity type (e.g., 'node').
   * @param string $embeddingType
   *   The type of embedding: 'content', 'title', 'address', etc.
   * @param array $vector
   *   The embedding vector (array of floats).
   * @param string $textHash
   *   SHA-256 hash of the source text for change detection.
   * @param string $model
   *   The model used to generate the embedding.
   */
  public function storeEmbedding(
    int $entityId,
    string $entityType,
    string $embeddingType,
    array $vector,
    string $textHash,
    string $model = 'unknown',
  ): void {
    try {
      // Check if an embedding already exists for this entity/type.
      $existing = $this->database->select('markaspot_ai_embeddings', 'e')
        ->fields('e', ['id'])
        ->condition('e.entity_type', $entityType)
        ->condition('e.entity_id', $entityId)
        ->condition('e.embedding_type', $embeddingType)
        ->execute()
        ->fetchField();

      $fields = [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'embedding_type' => $embeddingType,
        'embedding' => json_encode($vector),
        'model' => $model,
        'dimensions' => count($vector),
        'text_hash' => $textHash,
        'created' => \Drupal::time()->getRequestTime(),
      ];

      if ($existing) {
        // Update existing embedding.
        $this->database->update('markaspot_ai_embeddings')
          ->fields($fields)
          ->condition('id', $existing)
          ->execute();

        $this->logger->debug('Updated embedding for @type @id (@embedding_type)', [
          '@type' => $entityType,
          '@id' => $entityId,
          '@embedding_type' => $embeddingType,
        ]);
      }
      else {
        // Insert new embedding.
        $this->database->insert('markaspot_ai_embeddings')
          ->fields($fields)
          ->execute();

        $this->logger->debug('Stored new embedding for @type @id (@embedding_type)', [
          '@type' => $entityType,
          '@id' => $entityId,
          '@embedding_type' => $embeddingType,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store embedding: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Retrieves an embedding from the database.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $entityType
   *   The entity type (default: 'node').
   * @param string $embeddingType
   *   The embedding type (default: 'content').
   *
   * @return array|null
   *   The embedding data containing:
   *   - 'id': Database record ID.
   *   - 'vector': The embedding vector.
   *   - 'model': The model used.
   *   - 'dimensions': Number of dimensions.
   *   - 'text_hash': Hash of the source text.
   *   - 'created': Timestamp when created.
   *   Returns NULL if no embedding found.
   */
  public function getEmbedding(
    int $entityId,
    string $entityType = 'node',
    string $embeddingType = 'content',
  ): ?array {
    try {
      $result = $this->database->select('markaspot_ai_embeddings', 'e')
        ->fields('e')
        ->condition('e.entity_type', $entityType)
        ->condition('e.entity_id', $entityId)
        ->condition('e.embedding_type', $embeddingType)
        ->condition('e.model', $this->aiClient->resolveEmbeddingModel())
        ->execute()
        ->fetchAssoc();

      if (!$result) {
        $this->warnIfModelUnavailable($entityType);
        return NULL;
      }

      // Decode the JSON-encoded vector.
      $vector = json_decode($result['embedding'], TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('Failed to decode embedding vector for @type @id', [
          '@type' => $entityType,
          '@id' => $entityId,
        ]);
        return NULL;
      }

      return [
        'id' => (int) $result['id'],
        'vector' => $vector,
        'model' => $result['model'],
        'dimensions' => (int) $result['dimensions'],
        'text_hash' => $result['text_hash'],
        'created' => (int) $result['created'],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve embedding: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Checks if an embedding exists and is current for the given text hash.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $textHash
   *   The SHA-256 hash of the current text.
   * @param string $entityType
   *   The entity type (default: 'node').
   * @param string $embeddingType
   *   The embedding type (default: 'content').
   *
   * @return bool
   *   TRUE if a current embedding exists, FALSE otherwise.
   */
  public function embeddingExists(
    int $entityId,
    string $textHash,
    string $entityType = 'node',
    string $embeddingType = 'content',
  ): bool {
    try {
      $result = $this->database->select('markaspot_ai_embeddings', 'e')
        ->fields('e', ['text_hash'])
        ->condition('e.entity_type', $entityType)
        ->condition('e.entity_id', $entityId)
        ->condition('e.embedding_type', $embeddingType)
        ->condition('e.model', $this->aiClient->resolveEmbeddingModel())
        ->execute()
        ->fetchField();

      // Check if exists and hash matches (content hasn't changed).
      return $result === $textHash;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check embedding existence: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Finds entities that are missing embeddings.
   *
   * @param int $limit
   *   Maximum number of entities to return (default: 100).
   * @param string $entityType
   *   The entity type to check (default: 'node').
   * @param string $bundle
   *   Optional bundle to filter by (e.g., 'service_request').
   * @param string $embeddingType
   *   The embedding type to check for (default: 'content').
   * @param array|null $nodeIds
   *   Optional array of node IDs to restrict the search to (e.g. for
   *   jurisdiction scoping). NULL means no restriction.
   * @param int $offset
   *   Result offset for paged scans.
   *
   * @return array
   *   Array of entity IDs that are missing embeddings.
   */
  public function findMissingEmbeddings(
    int $limit = 100,
    string $entityType = 'node',
    ?string $bundle = 'service_request',
    string $embeddingType = 'content',
    ?array $nodeIds = NULL,
    int $offset = 0,
  ): array {
    try {
      // Build query based on entity type.
      if ($entityType === 'node') {
        $entity_query = $this->database->select('node_field_data', 'n')
          ->fields('n', ['nid']);

        if ($bundle !== NULL) {
          $entity_query->condition('n.type', $bundle);
        }

        // Filter to specific node IDs if provided (jurisdiction scoping).
        if ($nodeIds !== NULL) {
          if (empty($nodeIds)) {
            return [];
          }
          $entity_query->condition('n.nid', $nodeIds, 'IN');
        }

        // Left join to find nodes without embeddings.
        $entity_query->leftJoin('markaspot_ai_embeddings', 'e',
          "n.nid = e.entity_id AND e.entity_type = :entity_type AND e.embedding_type = :embedding_type AND e.model = :model",
          [
            ':entity_type' => $entityType,
            ':embedding_type' => $embeddingType,
            ':model' => $this->aiClient->resolveEmbeddingModel(),
          ]
        );

        $entity_query->isNull('e.id');
        $entity_query->range($offset, $limit);
        $entity_query->orderBy('n.created', 'DESC');

        $results = $entity_query->execute()->fetchCol();
      }
      else {
        // Generic query for other entity types.
        // This is a simplified approach - specific entity types may need
        // custom handling.
        $results = [];
        $this->logger->warning('findMissingEmbeddings not fully implemented for entity type: @type', [
          '@type' => $entityType,
        ]);
      }

      return array_map('intval', $results);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to find missing embeddings: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Deletes embeddings for a given entity.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $entityType
   *   The entity type (default: 'node').
   * @param string|null $embeddingType
   *   Optional specific embedding type to delete. NULL deletes all types.
   *
   * @return int
   *   Number of embeddings deleted.
   */
  public function deleteEmbedding(
    int $entityId,
    string $entityType = 'node',
    ?string $embeddingType = NULL,
  ): int {
    try {
      $query = $this->database->delete('markaspot_ai_embeddings')
        ->condition('entity_type', $entityType)
        ->condition('entity_id', $entityId);

      if ($embeddingType !== NULL) {
        $query->condition('embedding_type', $embeddingType);
      }

      $deleted = $query->execute();

      if ($deleted > 0) {
        $this->logger->info('Deleted @count embeddings for @type @id', [
          '@count' => $deleted,
          '@type' => $entityType,
          '@id' => $entityId,
        ]);
      }

      return $deleted;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete embedding: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets all embeddings of a specific type for similarity comparisons.
   *
   * @param string $embeddingType
   *   The embedding type to retrieve.
   * @param string $entityType
   *   The entity type (default: 'node').
   * @param int|null $excludeEntityId
   *   Optional entity ID to exclude from results.
   * @param int $limit
   *   Maximum number to retrieve (default: 1000).
   *
   * @return array
   *   Array of embeddings with entity_id as key.
   */
  public function getAllEmbeddings(
    string $embeddingType = 'content',
    string $entityType = 'node',
    ?int $excludeEntityId = NULL,
    int $limit = 1000,
  ): array {
    try {
      $query = $this->database->select('markaspot_ai_embeddings', 'e')
        ->fields('e', ['entity_id', 'embedding', 'model', 'dimensions'])
        ->condition('e.entity_type', $entityType)
        ->condition('e.embedding_type', $embeddingType)
        ->condition('e.model', $this->aiClient->resolveEmbeddingModel())
        ->range(0, $limit);

      if ($excludeEntityId !== NULL) {
        $query->condition('e.entity_id', $excludeEntityId, '<>');
      }

      $results = $query->execute()->fetchAllAssoc('entity_id', FetchAs::Associative);
      if ($results === []) {
        $this->warnIfModelUnavailable($entityType);
      }

      // Decode vectors.
      foreach ($results as &$row) {
        $row['vector'] = json_decode($row['embedding'], TRUE);
        unset($row['embedding']);
      }

      return $results;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get all embeddings: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Lists persisted model identities and dimensions, without reading vectors.
   */
  public function getStoredModelSummary(?string $entityType = NULL): array {
    $query = $this->database->select('markaspot_ai_embeddings', 'e')
      ->fields('e', ['model', 'dimensions']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('e.model');
    $query->groupBy('e.dimensions');
    $query->orderBy('e.model');
    $query->orderBy('e.dimensions');
    if ($entityType !== NULL) {
      $query->condition('e.entity_type', $entityType);
    }
    return $query->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Warns once when stored vectors do not match the configured identity.
   */
  protected function warnIfModelUnavailable(string $entityType): void {
    if ($this->modelWarningLogged || isset($this->checkedModels[$entityType])) {
      return;
    }
    $this->checkedModels[$entityType] = TRUE;
    $rows = $this->getStoredModelSummary($entityType);
    $configured = $this->aiClient->resolveEmbeddingModel();
    $stored = array_unique(array_column($rows, 'model'));
    if ($stored !== [] && !in_array($configured, $stored, TRUE)) {
      $this->modelWarningLogged = TRUE;
      $this->logger->warning('No embeddings match configured identifier @model; stored identifiers: @stored. For an identity-only deployment rename, use markaspot:ai:embeddings-relabel --from=<stored> --to=<configured> --dry-run before relabelling.', [
        '@model' => $configured,
        '@stored' => implode(', ', $stored),
      ]);
    }
  }

  /**
   * Explicitly relabels compatible identities without changing vector data.
   */
  public function relabelEmbeddings(string $from, string $to, bool $dryRun = FALSE): int {
    if (trim($from) === '' || trim($to) === '' || $from === $to) {
      throw new \InvalidArgumentException('Distinct nonempty --from and --to identifiers are required.');
    }
    if ($to !== $this->aiClient->resolveEmbeddingModel()) {
      throw new \InvalidArgumentException('--to must equal the currently configured embedding identifier.');
    }
    $rows = $this->getStoredModelSummary();
    $source = array_values(array_filter($rows, static fn ($row) => $row['model'] === $from));
    $target = array_values(array_filter($rows, static fn ($row) => $row['model'] === $to));
    if ($source === []) {
      throw new \InvalidArgumentException('No embeddings match --from.');
    }
    $dimensions = array_unique(array_map('intval', array_column($source, 'dimensions')));
    if (count($dimensions) !== 1 || array_filter($target, static fn ($row) => (int) $row['dimensions'] !== $dimensions[0])) {
      throw new \InvalidArgumentException('Refusing to relabel incompatible embedding dimensions.');
    }
    if ($dryRun) {
      return (int) array_sum(array_column($source, 'count'));
    }
    return (int) $this->database->update('markaspot_ai_embeddings')
      ->fields(['model' => $to])
      ->condition('model', $from)
      ->execute();
  }

  /**
   * Calculates cosine similarity between two vectors.
   *
   * @param array $vectorA
   *   First embedding vector.
   * @param array $vectorB
   *   Second embedding vector.
   *
   * @return float
   *   Cosine similarity score between -1 and 1.
   *
   * @throws \InvalidArgumentException
   *   When vectors have different dimensions.
   */
  public function cosineSimilarity(array $vectorA, array $vectorB): float {
    if (count($vectorA) !== count($vectorB)) {
      throw new \InvalidArgumentException('Vectors must have the same dimensions.');
    }

    $dotProduct = 0;
    $normA = 0;
    $normB = 0;

    foreach ($vectorA as $i => $valueA) {
      $valueB = $vectorB[$i];
      $dotProduct += $valueA * $valueB;
      $normA += $valueA * $valueA;
      $normB += $valueB * $valueB;
    }

    $normA = sqrt($normA);
    $normB = sqrt($normB);

    if ($normA == 0 || $normB == 0) {
      return 0;
    }

    return $dotProduct / ($normA * $normB);
  }

  /**
   * Generates a text hash for change detection.
   *
   * @param string $text
   *   The text to hash.
   *
   * @return string
   *   SHA-256 hash of the normalized text.
   */
  public function generateTextHash(string $text): string {
    // Normalize whitespace and convert to lowercase for consistent hashing.
    $normalized = strtolower(preg_replace('/\s+/', ' ', trim($text)));
    return hash('sha256', $normalized);
  }

}
