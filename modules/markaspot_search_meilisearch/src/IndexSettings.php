<?php

namespace Drupal\markaspot_search_meilisearch;

use Drupal\search_api\IndexInterface;
use Drupal\search_api_meilisearch\Api\MeilisearchApiServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the Meilisearch index settings the contributed backend leaves out.
 */
class IndexSettings {

  /**
   * Lower bound for Meilisearch's maxTotalHits.
   *
   * The GeoReport API takes up to 10,000 search candidates before access and
   * jurisdiction filtering; Meilisearch's default of 1,000 would silently cut
   * that set to a tenth.
   */
  public const MIN_MAX_TOTAL_HITS = 10000;

  /**
   * Constructs the index settings service.
   *
   * @param \Drupal\search_api_meilisearch\Api\MeilisearchApiServiceInterface $api
   *   A Meilisearch API service instance (the service is not shared).
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    protected MeilisearchApiServiceInterface $api,
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
   * Applies the settings to the Meilisearch index of a Search API index.
   *
   * Runs after the backend's own index setup (entity hooks follow postSave).
   * A failure is logged, not thrown, so saving the index still works.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index.
   */
  public function apply(IndexInterface $index): void {
    if (!self::isMeilisearch($index)) {
      return;
    }
    $config = $index->getServerInstance()->getBackendConfig();
    $this->api->setUrl($config['meilisearch_host_address'] . ':' . $config['meilisearch_host_port']);
    $this->api->setMasterKey($config['meilisearch_master_key']);

    try {
      $meili_index = $this->api->getIndex($index->id());
      $tasks = [];
      if (($meili_index->getPagination()['maxTotalHits'] ?? 0) < self::MIN_MAX_TOTAL_HITS) {
        $tasks[] = $meili_index->updatePagination(['maxTotalHits' => self::MIN_MAX_TOTAL_HITS]);
      }
      // Match like the database backend: "shows" must not find "showing", and
      // a mistyped digit is another request or house number. API clients rely
      // on predictable results.
      if (($meili_index->getTypoTolerance()['enabled'] ?? TRUE) !== FALSE) {
        $tasks[] = $meili_index->updateTypoTolerance(['enabled' => FALSE]);
      }
      foreach ($tasks as $task) {
        $result = $this->api->waitForUpdate($task['taskUid']);
        if ($result['status'] !== 'succeeded') {
          throw new \RuntimeException('Meilisearch settings task ' . $task['taskUid'] . ' ended as ' . $result['status'] . '.');
        }
      }
    }
    catch (\Exception $e) {
      // Without these settings the GeoReport API sees at most 1,000 candidates.
      $this->logger->error('Meilisearch settings for index @index not applied: @message', [
        '@index' => $index->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
