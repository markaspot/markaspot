<?php

namespace Drupal\markaspot_search_meilisearch;

use Drupal\Core\State\StateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_meilisearch\Api\MeilisearchApiServiceInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;

/**
 * Applies and checks the Meilisearch index settings the backend leaves out.
 */
class IndexSettings {

  /**
   * Lower bound for Meilisearch's maxTotalHits.
   *
   * GeoreportRequestIndexResource::SEARCH_API_CANDIDATE_LIMIT takes up to
   * 10,000 search candidates before access and jurisdiction filtering;
   * Meilisearch's default of 1,000 would silently cut that set to a tenth.
   */
  public const MIN_MAX_TOTAL_HITS = 10000;

  /**
   * State key: index IDs whose settings were confirmed by Meilisearch.
   */
  public const STATE_KEY = 'markaspot_search_meilisearch.settings_applied';

  /**
   * Constructs the index settings service.
   *
   * @param \Drupal\search_api_meilisearch\Api\MeilisearchApiServiceInterface $api
   *   A Meilisearch API service instance (the service is not shared).
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    protected MeilisearchApiServiceInterface $api,
    protected StateInterface $state,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Checks whether an index runs on a Meilisearch server.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index.
   *
   * @return bool
   *   TRUE for an index on a Meilisearch server.
   */
  public static function isMeilisearch(IndexInterface $index): bool {
    return $index->getServerInstanceIfAvailable()?->getBackendId() === 'search_api_meilisearch';
  }

  /**
   * Checks whether the settings of an index were confirmed.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param string $index_id
   *   The Search API index ID.
   *
   * @return bool
   *   TRUE once Meilisearch confirmed the settings for this index.
   */
  public static function isApplied(StateInterface $state, string $index_id): bool {
    return !empty($state->get(self::STATE_KEY, [])[$index_id]);
  }

  /**
   * Applies the settings to the Meilisearch index of a Search API index.
   *
   * Runs after the backend's own index setup (entity hooks follow postSave).
   * A failure is logged, not thrown, so saving the index still works; the
   * API service refuses searches until the settings are confirmed.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index.
   */
  public function apply(IndexInterface $index): void {
    if (!self::isMeilisearch($index) || !$index->status()) {
      $this->markApplied($index->id(), FALSE);
      return;
    }

    try {
      $meili_index = $this->connect($index);
      $tasks = [];
      foreach ($this->missingSettings($meili_index) as $setting) {
        $tasks[] = match ($setting) {
          'pagination' => $meili_index->updatePagination(['maxTotalHits' => self::MIN_MAX_TOTAL_HITS]),
          'typo_tolerance' => $meili_index->updateTypoTolerance(['enabled' => FALSE]),
          'displayed_attributes' => $meili_index->updateDisplayedAttributes(['search_api_id']),
        };
      }
      foreach ($tasks as $task) {
        $result = $this->api->waitForUpdate($task['taskUid']);
        if ($result['status'] !== 'succeeded') {
          throw new \RuntimeException('Meilisearch settings task ' . $task['taskUid'] . ' ended as ' . $result['status'] . '.');
        }
      }
      $this->markApplied($index->id(), TRUE);
    }
    catch (\Exception $e) {
      $this->markApplied($index->id(), FALSE);
      $this->logger->error('Meilisearch settings for index @index not applied; searches on it are refused: @message', [
        '@index' => $index->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Lists the settings that differ from what the service request search needs.
   *
   * Used by the status report: Meilisearch recreates a lost index with its
   * defaults on the next indexing run, which the state flag cannot see.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index on a Meilisearch server.
   *
   * @return string[]
   *   Names of the settings that are missing.
   *
   * @throws \Drupal\search_api_meilisearch\Api\MeilisearchApiException
   *   When Meilisearch cannot be reached.
   */
  public function check(IndexInterface $index): array {
    return $this->missingSettings($this->connect($index));
  }

  /**
   * Returns the Meilisearch index for a Search API index.
   */
  protected function connect(IndexInterface $index): Indexes {
    $config = $index->getServerInstance()->getBackend()->getConfiguration();
    $this->api->setUrl($config['meilisearch_host_address'] . ':' . $config['meilisearch_host_port']);
    $this->api->setMasterKey($config['meilisearch_master_key']);
    return $this->api->getIndex($index->id());
  }

  /**
   * Compares the live settings with the required ones.
   *
   * @return string[]
   *   Names of the settings that are missing.
   */
  protected function missingSettings(Indexes $meili_index): array {
    $missing = [];
    if (($meili_index->getPagination()['maxTotalHits'] ?? 0) < self::MIN_MAX_TOTAL_HITS) {
      $missing[] = 'pagination';
    }
    // Match exact words and prefixes like the database backend's word index:
    // "shows" must not find "showing", and a mistyped digit is another request
    // or house number. API clients rely on predictable results.
    if (($meili_index->getTypoTolerance()['enabled'] ?? TRUE) !== FALSE) {
      $missing[] = 'typo_tolerance';
    }
    // The backend reads the item ID only; a leaked search key then yields IDs,
    // not contact data.
    if ($meili_index->getDisplayedAttributes() !== ['search_api_id']) {
      $missing[] = 'displayed_attributes';
    }
    return $missing;
  }

  /**
   * Records whether the settings of an index are confirmed.
   */
  protected function markApplied(string $index_id, bool $applied): void {
    $applied_indexes = $this->state->get(self::STATE_KEY, []);
    $applied_indexes[$index_id] = $applied;
    $this->state->set(self::STATE_KEY, $applied_indexes);
  }

}
