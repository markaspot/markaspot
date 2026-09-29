<?php

namespace Drupal\markaspot_search_meilisearch\Parser;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_meilisearch\Parser\ConditionParserInterface;

/**
 * Rejects conditions that no Meilisearch condition parser can translate.
 *
 * The contributed filter parser drops such conditions silently (an empty IN
 * list, a non-scalar value), which widens the result set: an access or scope
 * condition that disappears returns documents the query meant to exclude.
 * Registered at the lowest priority, this parser is only asked when every
 * other parser declined, and aborts the search instead.
 */
class FailClosedConditionParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    throw new SearchApiException(sprintf(
      'Search condition on "%s" with operator "%s" cannot be translated for Meilisearch.',
      $condition->getField(),
      $condition->getOperator(),
    ));
  }

}
