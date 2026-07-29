<?php

declare(strict_types=1);

namespace Drupal\markaspot_open311\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves service status terms to their Open311 open or closed semantics.
 */
class StatusClassifier {

  private const CLOSED_TIDS_CACHE_ID = 'markaspot_open311:closed_status_tids';

  /**
   * Constructs the status classifier.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Determines whether a status term is closed.
   *
   * The legacy fallback deliberately checks status_open and defaults unknown
   * terms to closed because that is the existing GeoReport API contract.
   */
  public function isClosed(TermInterface|int $term): bool {
    $term_id = $term instanceof TermInterface ? (int) $term->id() : $term;
    $status_term = $term instanceof TermInterface
      ? $term
      : $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);

    if ($status_term instanceof TermInterface && $status_term->hasField('field_open311_mapping')) {
      $mapping_field = $status_term->get('field_open311_mapping');
      if (!$mapping_field->isEmpty()) {
        $mapping = (string) ($mapping_field->getValue()[0]['value'] ?? '');
        // Every non-closed mapping, including "initial", is Open311-open.
        return $mapping === 'closed';
      }
    }

    $status_open = array_values(
      $this->configFactory->get('markaspot_open311.settings')->get('status_open') ?? [],
    );
    return !in_array($term_id, $status_open);
  }

  /**
   * Returns every term ID that escalation consumers must treat as closed.
   *
   * The union retains legacy operator configuration while also covering
   * provisioned terms whose term-level mapping was previously ignored by
   * escalation queries.
   *
   * @return int[]
   *   Sorted unique taxonomy term IDs.
   */
  public function closedTids(): array {
    if ($cached = $this->cache->get(self::CLOSED_TIDS_CACHE_ID)) {
      return $cached->data;
    }

    $term_ids = $this->entityTypeManager->getStorage('taxonomy_term')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_status')
      ->condition('field_open311_mapping', 'closed')
      ->execute();
    $mapped_closed = array_map('intval', array_values($term_ids));

    $legacy_closed = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('status_closed') ?? [];
    $legacy_closed = array_map('intval', array_values($legacy_closed));

    $closed_tids = array_values(array_unique(array_merge(
      $mapped_closed,
      $legacy_closed,
    )));
    sort($closed_tids, SORT_NUMERIC);

    $this->cache->set(
      self::CLOSED_TIDS_CACHE_ID,
      $closed_tids,
      Cache::PERMANENT,
      [
        'taxonomy_term_list:service_status',
        'config:markaspot_open311.settings',
      ],
    );

    return $closed_tids;
  }

}
