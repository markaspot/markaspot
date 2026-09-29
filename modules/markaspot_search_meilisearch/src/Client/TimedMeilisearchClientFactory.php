<?php

namespace Drupal\markaspot_search_meilisearch\Client;

use Drupal\search_api_meilisearch\Api\MeilisearchApiException;
use Drupal\search_api_meilisearch\Client\Client as HttpAdapter;
use Drupal\search_api_meilisearch\Client\MeilisearchClientFactory;
use Meilisearch\Client;

/**
 * Meilisearch client factory with bounded HTTP requests.
 *
 * The contributed factory sets no timeout: a Meilisearch that accepts
 * connections but does not answer would hold every PHP worker that searches.
 */
class TimedMeilisearchClientFactory extends MeilisearchClientFactory {

  /**
   * Seconds to wait for a response.
   */
  public const TIMEOUT = 5;

  /**
   * Seconds to wait for the connection.
   */
  public const CONNECT_TIMEOUT = 2;

  /**
   * {@inheritdoc}
   */
  public function getInstance(string $url, ?string $key = NULL): Client {
    try {
      return new Client($url, $key, new HttpAdapter([
        'timeout' => self::TIMEOUT,
        'connect_timeout' => self::CONNECT_TIMEOUT,
      ]));
    }
    catch (\Exception $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

}
