<?php

namespace Drupal\markaspot_search_meilisearch\Api;

use Drupal\markaspot_search_meilisearch\SearchRestriction;
use Drupal\search_api_meilisearch\Api\MeilisearchApiService;

/**
 * Meilisearch API service that applies the recorded search restriction.
 *
 * Replaces the class of search_api_meilisearch.api (see the service
 * provider), so the contributed, final backend keeps working unchanged.
 */
class RestrictedMeilisearchApiService extends MeilisearchApiService {

  /**
   * The recorded search restrictions.
   *
   * @var \Drupal\markaspot_search_meilisearch\SearchRestriction|null
   */
  protected ?SearchRestriction $restriction = NULL;

  /**
   * Sets the search restriction holder.
   *
   * @param \Drupal\markaspot_search_meilisearch\SearchRestriction $restriction
   *   The search restriction holder.
   */
  public function setRestriction(SearchRestriction $restriction): void {
    $this->restriction = $restriction;
  }

  /**
   * {@inheritdoc}
   */
  public function search(string $indexName, string $query, array $options = []) {
    if ($this->restriction !== NULL) {
      // The restriction wins over anything the backend passed.
      $options = $this->restriction->getOptions($indexName) + $options;
    }
    return parent::search($indexName, $query, $options);
  }

}
