<?php

namespace Drupal\markaspot_search_meilisearch;

use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;

/**
 * Carries a query's search restrictions to the Meilisearch API call.
 *
 * The contributed backend ignores a query's full-text fields and lets
 * documents match only some of the words. Mark-a-Spot restricts the searched
 * fields per account (contact fields only for staff who may see them), so the
 * query alter hook records the restriction here and the API service applies
 * it as attributesToSearchOn and matchingStrategy. The backend runs the
 * Meilisearch request right after the alter hook, within the same query.
 */
class SearchRestriction {

  /**
   * Meilisearch search options per Search API index ID.
   *
   * @var array<string, array>
   */
  protected array $options = [];

  /**
   * Records the restriction of a query that is about to run.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   When the query names full-text fields of which none is searchable;
   *   searching every attribute instead would lift the restriction.
   */
  public function record(QueryInterface $query): void {
    $this->options[$query->getIndex()->id()] = self::buildOptions($query);
  }

  /**
   * Returns the options recorded for an index.
   *
   * @param string $index_id
   *   The Search API index ID, which is also the Meilisearch index name.
   *
   * @return array
   *   Meilisearch search options; empty when no query was recorded.
   */
  public function getOptions(string $index_id): array {
    return $this->options[$index_id] ?? [];
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
    // Every word must match, as with the database backend.
    $options = ['matchingStrategy' => 'all'];

    $requested = $query->getFulltextFields();
    if ($requested === NULL) {
      return $options;
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
    $options['attributesToSearchOn'] = $allowed;

    return $options;
  }

}
