<?php

namespace Drupal\markaspot_search_meilisearch;

use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;

/**
 * Carries a query's search restriction to the Meilisearch API call.
 *
 * The contributed backend ignores a query's full-text fields and lets
 * documents match only some of the words. The pre-execute subscriber records
 * the restriction here; the API service applies it and refuses any search on
 * the index for which no matching restriction was recorded. The backend runs
 * its Meilisearch requests between pre-execute and the result processing
 * event, which clears the entry again.
 */
class SearchRestriction {

  /**
   * Recorded restrictions per Search API index ID.
   *
   * @var array<string, array{keys: string, options: array}>
   */
  protected array $entries = [];

  /**
   * Records the restriction of a query that is about to run.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query, its full-text fields already resolved.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   When the query would search no field or every field.
   */
  public function record(QueryInterface $query): void {
    $index_id = $query->getIndex()->id();
    // A failed build must not leave an earlier query's restriction behind.
    unset($this->entries[$index_id]);
    $this->entries[$index_id] = [
      'keys' => (string) ($query->getOriginalKeys() ?? ''),
      'options' => self::buildOptions($query),
    ];
  }

  /**
   * Returns the options recorded for the running search.
   *
   * @param string $index_id
   *   The Search API index ID, which is also the Meilisearch index name.
   * @param string $keys
   *   The search keys the backend sends.
   *
   * @return array
   *   Meilisearch search options.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   When no restriction was recorded for this index and these keys: the
   *   search bypassed the pre-execute event and would run unrestricted.
   */
  public function getOptions(string $index_id, string $keys): array {
    $entry = $this->entries[$index_id] ?? NULL;
    if ($entry === NULL || !hash_equals($entry['keys'], $keys)) {
      throw new SearchApiException(sprintf('No search restriction recorded for index %s.', $index_id));
    }
    return $entry['options'];
  }

  /**
   * Drops the restriction recorded for an index.
   *
   * @param string $index_id
   *   The Search API index ID.
   */
  public function clear(string $index_id): void {
    unset($this->entries[$index_id]);
  }

  /**
   * Builds the Meilisearch options that restrict what a query may match.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   *
   * @return array
   *   Meilisearch search options.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public static function buildOptions(QueryInterface $query): array {
    $requested = $query->getFulltextFields();
    if ($requested === NULL) {
      // NULL means every text field, contact fields included.
      throw new SearchApiException('Search fields were not resolved for this query.');
    }

    // The contributed backend makes exactly the text fields searchable.
    $searchable = [];
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      if ($field->getType() === 'text') {
        $searchable[] = $field_id;
      }
    }
    $allowed = array_values(array_intersect($requested, $searchable));
    if ($allowed === []) {
      throw new SearchApiException('None of the requested full-text fields is searchable.');
    }

    return [
      // Every word must match, as with the database backend.
      'matchingStrategy' => 'all',
      'attributesToSearchOn' => $allowed,
      // The backend reads the item ID only; leave the rest of each document,
      // contact data included, in Meilisearch.
      'attributesToRetrieve' => ['search_api_id'],
    ];
  }

}
