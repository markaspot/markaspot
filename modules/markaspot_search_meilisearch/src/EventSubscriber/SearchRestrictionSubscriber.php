<?php

namespace Drupal\markaspot_search_meilisearch\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_open311\Service\SearchApiQueryService;
use Drupal\markaspot_search_meilisearch\IndexSettings;
use Drupal\markaspot_search_meilisearch\SearchRestriction;
use Drupal\search_api\Event\ProcessingResultsEvent;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\SearchApiException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restricts every query on a Meilisearch index to the account's fields.
 *
 * Applies to all callers (GeoReport search, the management view), so contact
 * fields are searchable only for accounts that may see them.
 */
class SearchRestrictionSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\markaspot_search_meilisearch\SearchRestriction $restriction
   *   The search restriction holder.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The searching account.
   */
  public function __construct(
    protected SearchRestriction $restriction,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Late, so field changes by other subscribers are narrowed afterwards.
      SearchApiEvents::QUERY_PRE_EXECUTE => ['onQueryPreExecute', -1000],
      SearchApiEvents::PROCESSING_RESULTS => ['onProcessingResults', 1000],
    ];
  }

  /**
   * Resolves the searchable fields and records the restriction.
   *
   * @param \Drupal\search_api\Event\QueryPreExecuteEvent $event
   *   The event.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    if (!IndexSettings::isMeilisearch($query->getIndex())) {
      return;
    }

    $allowed = SearchApiQueryService::fulltextFieldsFor($this->currentUser);
    $requested = $query->getFulltextFields();
    $fields = $requested === NULL ? $allowed : array_values(array_intersect($requested, $allowed));
    if ($fields === []) {
      throw new SearchApiException('The account may search none of the requested fields.');
    }
    $query->setFulltextFields($fields);
    $this->restriction->record($query);
  }

  /**
   * Drops the restriction once the backend has run.
   *
   * @param \Drupal\search_api\Event\ProcessingResultsEvent $event
   *   The event.
   */
  public function onProcessingResults(ProcessingResultsEvent $event): void {
    $this->restriction->clear($event->getResults()->getQuery()->getIndex()->id());
  }

}
