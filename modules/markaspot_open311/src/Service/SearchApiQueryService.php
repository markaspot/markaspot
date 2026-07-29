<?php

namespace Drupal\markaspot_open311\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Drupal\search_api\Entity\Index;

/**
 * Service for executing Search API queries for service requests.
 *
 * This service provides full-text search capabilities using the Search API
 * module. It is designed to work alongside entity queries, where Search API
 * handles free-text search and entity queries handle structured filters.
 */
class SearchApiQueryService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Whether the last Search API query failed at backend/runtime level.
   *
   * @var bool
   */
  protected bool $lastSearchFailed = FALSE;

  /**
   * The Search API index ID for service requests.
   */
  protected const INDEX_ID = 'service_requests';

  /**
   * Minimum query length for Search API to be used.
   *
   * Queries shorter than this will fall back to basic LIKE search.
   */
  protected const MIN_QUERY_LENGTH = 2;

  /**
   * Non-PII fields available to every full-text search account.
   */
  protected const PUBLIC_FULLTEXT_FIELDS = [
    'title',
    'body',
    'request_id',
  ];

  /**
   * Constructs a SearchApiQueryService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Checks if Search API is available and the index exists.
   *
   * @return bool
   *   TRUE if Search API can be used, FALSE otherwise.
   */
  public function isAvailable(): bool {
    if (!$this->moduleHandler->moduleExists('search_api')) {
      return FALSE;
    }

    $index = $this->getIndex();
    return $index !== NULL && $index->status();
  }

  /**
   * Gets the Search API index for service requests.
   *
   * @return \Drupal\search_api\Entity\Index|null
   *   The Search API index or NULL if not found.
   */
  protected function getIndex(): ?Index {
    try {
      $index = Index::load(self::INDEX_ID);
      return $index;
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to load Search API index: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Executes a full-text search query.
   *
   * @param string $query_string
   *   The search query string.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user.
   * @param array $options
   *   Optional query options:
   *   - limit: Maximum number of results (default: 100)
   *   - offset: Offset for pagination (default: 0)
   *   - langcode: Language code to filter by (optional)
   *   - jurisdiction_nids: Array of node IDs to restrict search to (optional)
   *
   * @return array
   *   Array of node IDs matching the search query.
   */
  public function search(string $query_string, AccountInterface $user, array $options = []): array {
    $this->lastSearchFailed = FALSE;

    // Check minimum query length.
    if (strlen(trim($query_string)) < self::MIN_QUERY_LENGTH) {
      return [];
    }

    // Verify Search API availability.
    if (!$this->isAvailable()) {
      $this->logger->notice('Search API not available, falling back to basic search.');
      return [];
    }

    $index = $this->getIndex();
    if (!$index) {
      return [];
    }

    try {
      // Create the Search API query.
      $query = $index->query();

      $fulltext_fields = self::PUBLIC_FULLTEXT_FIELDS;
      if ($user->hasPermission('view field_e_mail')) {
        $fulltext_fields[] = 'field_e_mail';
      }
      $query->setFulltextFields($fulltext_fields);

      // Set the search keys (the search text).
      $query->keys($query_string);

      // Configure the query for partial matching.
      // The tokenizer processor handles word splitting.
      $query->setOption('search_api_partial_match', TRUE);

      // Set pagination.
      $limit = $options['limit'] ?? 100;
      $offset = $options['offset'] ?? 0;
      $query->range($offset, $limit);

      // Restrict to specific node IDs if provided (for jurisdiction filtering).
      if (!empty($options['jurisdiction_nids'])) {
        $query->addCondition('nid', $options['jurisdiction_nids'], 'IN');
      }

      // Ensure only published content for anonymous users.
      if ($user->isAnonymous()) {
        $query->addCondition('status', TRUE);
      }

      // Language filtering is intentionally NOT applied to Search API queries.
      // The search should find content across all languages, and the entity
      // query / result processing returns the correct translation.
      // If strict language filtering is needed in the future, it can be enabled
      // via an option like 'filter_by_language' => TRUE.
      // Execute the query.
      $results = $query->execute();

      // Extract node IDs from results.
      $nids = [];
      foreach ($results->getResultItems() as $item) {
        // Item ID format is "entity:node/NID:LANGCODE".
        $item_id = $item->getId();
        if (preg_match('/entity:node\/(\d+)/', $item_id, $matches)) {
          $nids[] = (int) $matches[1];
        }
      }

      // Remove duplicates (can occur with multi-language content).
      $nids = array_unique($nids);

      $this->logger->debug('Search API query "@query" returned @count results.', [
        '@query' => $query_string,
        '@count' => count($nids),
      ]);

      return $nids;
    }
    catch (\Exception $e) {
      $this->lastSearchFailed = TRUE;
      $this->logger->error('Search API query failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Checks whether the last Search API query failed.
   *
   * Empty Search API results are not failures. This flag is only TRUE when the
   * backend threw while Search API was otherwise enabled and selected.
   *
   * @return bool
   *   TRUE if the last search threw an exception.
   */
  public function didLastSearchFail(): bool {
    return $this->lastSearchFailed;
  }

  /**
   * Applies the bounded fallback search used when Search API is unavailable.
   *
   * The fallback intentionally does not provide full-text semantics. It only
   * supports exact, ID-shaped request ID lookup so the API never falls back to
   * LIKE scans over request, title, body, or address tables.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query to constrain.
   * @param string $query_string
   *   The incoming search query string.
   *
   * @return bool
   *   TRUE when a safe fallback condition was applied, FALSE otherwise.
   */
  public function applySafeFallbackSearch(QueryInterface $query, string $query_string): bool {
    $request_id = $this->normalizeRequestIdQuery($query_string);
    if ($request_id === NULL) {
      return FALSE;
    }

    $query->condition('request_id', $request_id);
    return TRUE;
  }

  /**
   * Normalizes search input to an exact request ID.
   *
   * @param string $query_string
   *   The incoming search query string.
   *
   * @return string|null
   *   Normalized request ID, or NULL when unsupported.
   */
  protected function normalizeRequestIdQuery(string $query_string): ?string {
    $query_string = trim($query_string);
    if (str_starts_with($query_string, '#')) {
      $query_string = substr($query_string, 1);
    }

    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,63}$/', $query_string) !== 1) {
      return NULL;
    }

    return $query_string;
  }

  /**
   * Gets searchable fields from the index.
   *
   * This can be used to inform users which fields are searchable.
   *
   * @return array
   *   Array of field names that are full-text searchable.
   */
  public function getSearchableFields(): array {
    $index = $this->getIndex();
    if (!$index) {
      return [];
    }

    $fields = [];
    foreach ($index->getFields() as $field_id => $field) {
      // Text fields are the searchable ones.
      if ($field->getType() === 'text') {
        $fields[] = $field_id;
      }
    }

    return $fields;
  }

  /**
   * Reindexes a specific node.
   *
   * Call this after creating or updating a service request to ensure
   * the search index is up to date.
   *
   * @param int $nid
   *   The node ID to reindex.
   */
  public function reindexNode(int $nid): void {
    if (!$this->isAvailable()) {
      return;
    }

    $index = $this->getIndex();
    if (!$index) {
      return;
    }

    try {
      // Track the item for reindexing.
      $index->trackItemsUpdated('entity:node', [$nid]);

      // If immediate indexing is enabled, index now.
      if ($index->getOption('index_directly')) {
        $index->indexItems();
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to reindex node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
