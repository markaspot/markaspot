<?php

namespace Drupal\markaspot_open311\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
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
   * Maximum search input in characters, the cap the UI proxy applies too.
   */
  public const MAX_QUERY_LENGTH = 100;

  /**
   * Upper bound for the Search API database statements, in seconds.
   *
   * The database server matches parts of words (LIKE '%word%'), which scans
   * the whole word table. On the largest tenant a common word took 18 s with
   * full visibility, while the public path stayed under 5 s. The bound keeps
   * one search from holding a PHP worker for half a minute.
   */
  protected const STATEMENT_TIMEOUT_SECONDS = 10;

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
   * @param \Drupal\Core\Database\Connection|null $database
   *   The database connection the Search API database backend uses; without
   *   it searches run without a statement timeout.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
    protected ?Connection $database = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Normalizes search input: one line, collapsed spaces, capped length.
   *
   * @param string $query_string
   *   The raw search input.
   *
   * @return string
   *   The normalized input; empty for invalid UTF-8.
   */
  public static function normalizeQuery(string $query_string): string {
    $collapsed = trim(preg_replace('/\s+/u', ' ', $query_string) ?? '');
    return trim(mb_substr($collapsed, 0, self::MAX_QUERY_LENGTH));
  }

  /**
   * Checks whether the database backend can match the input at all.
   *
   * The server indexes words of three or more characters. Input without such
   * a run of letters or digits ("a", "%", "_") matched nothing useful and cost
   * more than no search at all. The UI proxy applies the same rule; this
   * covers direct API-key clients.
   *
   * @param string $query_string
   *   The normalized search input.
   *
   * @return bool
   *   TRUE when the input holds at least three letters or digits in a row.
   */
  public static function isSearchableQuery(string $query_string): bool {
    return preg_match('/[\p{L}\p{N}]{3,}/u', $query_string) === 1;
  }

  /**
   * Returns the input as a request ID candidate for an exact lookup.
   *
   * Request IDs are configurable (prefix, delimiter, date format), so any
   * single token with a digit counts; the caller checks that the ID exists.
   *
   * @param string $query_string
   *   The normalized search input.
   *
   * @return string|null
   *   The request ID candidate, or NULL for general text.
   */
  public function getExactRequestIdCandidate(string $query_string): ?string {
    $request_id = $this->normalizeRequestIdQuery($query_string);
    return $request_id !== NULL && preg_match('/\d/', $request_id) === 1 ? $request_id : NULL;
  }

  /**
   * Counts the requests with this exact ID that the list query can return.
   *
   * The probe is a copy of the list query, so it carries the same access,
   * jurisdiction and workspace scope: an ID the caller cannot list looks
   * exactly like a missing one.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The list query.
   * @param string $request_id
   *   The request ID to look up.
   *
   * @return int
   *   The number of visible requests with that ID.
   */
  public function countVisibleRequestId(QueryInterface $query, string $request_id): int {
    $probe = clone $query;
    // The list query already carries the pager range, which a count keeps.
    $probe->range();
    $probe->condition('request_id', $request_id);
    $probe->count();
    try {
      return (int) $probe->execute();
    }
    catch (\Exception $e) {
      // The exception message carries the SQL with the searched ID.
      $this->logger->error('Request ID lookup failed: @class.', ['@class' => get_class($e)]);
      return 0;
    }
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
    if ($index === NULL || !$index->status()) {
      return FALSE;
    }

    $server = $index->getServerInstanceIfAvailable();
    if ($server?->getBackendId() === 'search_api_meilisearch') {
      // The contributed Meilisearch backend searches every text field for
      // every account; only markaspot_search_meilisearch restricts it.
      // Without it an anonymous search would find reports by e-mail address.
      if (!$this->moduleHandler->moduleExists('markaspot_search_meilisearch')) {
        $this->logger->error('Search index @index runs on Meilisearch without markaspot_search_meilisearch; full-text search is disabled.', [
          '@index' => self::INDEX_ID,
        ]);
        return FALSE;
      }
      // An unreachable server would otherwise fail inside the backend, which
      // reports errors through the messenger and so opens anonymous sessions.
      if (!$server->isAvailable()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns the full-text fields an account may search.
   *
   * Contact fields are searchable only for accounts that may see them, so a
   * search cannot tell whether an address or e-mail occurs in a report.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The searching account.
   *
   * @return string[]
   *   Search API field IDs of the service_requests index.
   */
  public static function fulltextFieldsFor(AccountInterface $account): array {
    $fields = self::PUBLIC_FULLTEXT_FIELDS;
    if ($account->hasPermission('view field_e_mail')) {
      $fields[] = 'field_e_mail';
    }
    if ($account->hasPermission('view field_address')) {
      $fields[] = 'address_line1';
      $fields[] = 'postal_code';
    }
    return $fields;
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

    $query_string = self::normalizeQuery($query_string);
    if (!self::isSearchableQuery($query_string)) {
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

      $query->setFulltextFields(self::fulltextFieldsFor($user));

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
      // No caller renders excerpts, and building them loads every hit: on the
      // largest tenant 1,000 hits cost 124 MB and doubled the search time.
      $query->addTag('search_api_skip_processor_highlight');
      // Only the IDs are used; the count would run the same scan a second time.
      $query->setOption('skip result count', TRUE);

      $results = $this->executeWithStatementTimeout(static fn () => $query->execute());

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

      return $nids;
    }
    catch (\Exception $e) {
      $this->lastSearchFailed = TRUE;
      // Database exception messages carry the SQL with the search words.
      $this->logger->error('Search API query failed: @class (code @code).', [
        '@class' => get_class($e),
        '@code' => $e->getCode(),
      ]);
      return [];
    }
  }

  /**
   * Runs a Search API query under the statement timeout.
   *
   * A statement over the limit is aborted by the server and surfaces as an
   * exception, which search() treats as a failed search.
   *
   * @param callable $execute
   *   Runs the query and returns its result set.
   *
   * @return mixed
   *   The result of $execute.
   */
  protected function executeWithStatementTimeout(callable $execute): mixed {
    $restore = $this->applyStatementTimeout();
    try {
      return $execute();
    }
    finally {
      if ($restore !== NULL) {
        try {
          $this->database?->query($restore);
        }
        catch (\Exception $e) {
          $this->logger->warning('Could not restore the database statement timeout: @class.', [
            '@class' => get_class($e),
          ]);
        }
      }
    }
  }

  /**
   * Sets the session statement timeout for MariaDB or MySQL.
   *
   * @return string|null
   *   The statement that restores the previous timeout, or NULL when no
   *   timeout was set (no connection, other database, or an error).
   */
  protected function applyStatementTimeout(): ?string {
    if ($this->database === NULL || $this->database->databaseType() !== 'mysql') {
      return NULL;
    }
    try {
      $is_mariadb = method_exists($this->database, 'isMariaDb') && $this->database->isMariaDb();
      // MariaDB counts seconds for every statement, MySQL milliseconds for
      // SELECT statements.
      [$variable, $value] = $is_mariadb
        ? ['max_statement_time', (float) self::STATEMENT_TIMEOUT_SECONDS]
        : ['max_execution_time', self::STATEMENT_TIMEOUT_SECONDS * 1000];
      $previous = $this->database->query('SELECT @@SESSION.' . $variable)->fetchField();
      $this->database->query('SET SESSION ' . $variable . ' = ' . $value);
      return 'SET SESSION ' . $variable . ' = ' . ($is_mariadb ? (float) $previous : (int) $previous);
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not set the database statement timeout: @class.', [
        '@class' => get_class($e),
      ]);
      return NULL;
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
