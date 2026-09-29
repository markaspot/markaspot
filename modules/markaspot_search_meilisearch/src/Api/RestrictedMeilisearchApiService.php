<?php

namespace Drupal\markaspot_search_meilisearch\Api;

use Drupal\Core\State\StateInterface;
use Drupal\markaspot_search_meilisearch\IndexSettings;
use Drupal\markaspot_search_meilisearch\SearchRestriction;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_meilisearch\Api\MeilisearchApiService;

/**
 * Meilisearch API service that only runs restricted searches.
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
   * The state service, which records applied index settings.
   *
   * @var \Drupal\Core\State\StateInterface|null
   */
  protected ?StateInterface $state = NULL;

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
   * Sets the state service.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function setState(StateInterface $state): void {
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api\SearchApiException
   *   When the index settings are not confirmed or no restriction was
   *   recorded for this search.
   */
  public function search(string $indexName, string $query, array $options = []) {
    if ($this->restriction === NULL || $this->state === NULL) {
      throw new SearchApiException('Meilisearch search restriction is not wired.');
    }
    if (!IndexSettings::isApplied($this->state, $indexName)) {
      // Without the settings typo tolerance is on and candidates stop at 1,000.
      throw new SearchApiException(sprintf('Meilisearch settings for index %s are not confirmed.', $indexName));
    }
    // The restriction wins over anything the backend passed.
    $options = $this->restriction->getOptions($indexName, $query) + $options;
    return parent::search($indexName, $query, $options);
  }

  /**
   * {@inheritdoc}
   *
   * Facet searches bypass the restriction and would count documents across
   * every searchable attribute, contact fields included.
   */
  public function searchFacets(string $indexId, string $facetName, ?string $facetQuery = NULL, ?array $filter = NULL, ?string $query = NULL): never {
    throw new SearchApiException('Meilisearch facet search is disabled for service requests.');
  }

}
