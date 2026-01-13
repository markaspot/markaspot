<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for detecting and managing duplicate service requests.
 *
 * This service uses vector embeddings and geographic proximity to identify
 * potential duplicate service requests. Matches can be reviewed and confirmed
 * or rejected by administrators.
 */
class DuplicateDetectionService {

  /**
   * The embedding service.
   *
   * @var \Drupal\markaspot_ai\Service\EmbeddingService
   */
  protected EmbeddingService $embeddingService;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new DuplicateDetectionService.
   *
   * @param \Drupal\markaspot_ai\Service\EmbeddingService $embedding_service
   *   The embedding service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EmbeddingService $embedding_service,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->embeddingService = $embedding_service;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('markaspot_ai');
  }

  /**
   * Finds potential duplicate service requests for a given node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The source node to find duplicates for.
   * @param array $options
   *   Optional parameters:
   *   - 'similarity_threshold': Override default threshold (0-1).
   *   - 'radius_meters': Override default radius in meters.
   *   - 'time_window_days': Override default time window in days.
   *   - 'limit': Maximum number of matches to return (default: 10).
   *   - 'exclude_nids': Array of node IDs to exclude from results.
   *
   * @return array
   *   Array of potential duplicates, each containing:
   *   - 'nid': Node ID of the match.
   *   - 'title': Title of the matching node.
   *   - 'similarity': Cosine similarity score.
   *   - 'distance_meters': Geographic distance in meters (if available).
   *   - 'created': Creation timestamp of the matching node.
   */
  public function findDuplicates(NodeInterface $node, array $options = []): array {
    $config = $this->configFactory->get('markaspot_ai.settings');

    // Get configuration values with option overrides.
    $threshold = $options['similarity_threshold']
      ?? (float) $config->get('duplicate_detection.similarity_threshold')
      ?? 0.85;
    $radius = $options['radius_meters']
      ?? (int) $config->get('duplicate_detection.radius_meters')
      ?? 500;
    $timeWindowDays = $options['time_window_days']
      ?? (int) $config->get('duplicate_detection.time_window_days')
      ?? 30;
    $limit = $options['limit'] ?? 10;
    $excludeNids = $options['exclude_nids'] ?? [];

    // Always exclude the source node itself.
    $excludeNids[] = (int) $node->id();

    try {
      // Get the source node's embedding.
      $sourceEmbedding = $this->embeddingService->getEmbedding(
        (int) $node->id(),
        'node',
        'content'
      );

      if ($sourceEmbedding === NULL) {
        $this->logger->warning('No embedding found for node @nid. Cannot find duplicates.', [
          '@nid' => $node->id(),
        ]);
        return [];
      }

      // Get source node's geolocation.
      $sourceLat = NULL;
      $sourceLng = NULL;
      if ($node->hasField('field_geolocation') && !$node->get('field_geolocation')->isEmpty()) {
        $sourceLat = (float) $node->get('field_geolocation')->lat;
        $sourceLng = (float) $node->get('field_geolocation')->lng;
      }

      // Calculate time cutoff.
      $timeCutoff = \Drupal::time()->getRequestTime() - ($timeWindowDays * 86400);

      // Build query for candidate nodes.
      $query = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid', 'title', 'created'])
        ->condition('n.type', 'service_request')
        ->condition('n.status', 1)
        ->condition('n.created', $timeCutoff, '>=');

      // Exclude specified nodes.
      if (!empty($excludeNids)) {
        $query->condition('n.nid', $excludeNids, 'NOT IN');
      }

      // Apply geographic bounding box filter if coordinates available.
      if ($sourceLat !== NULL && $sourceLng !== NULL && $radius > 0) {
        $bbox = $this->calculateBoundingBox($sourceLat, $sourceLng, $radius);

        $query->join('node__field_geolocation', 'g', 'n.nid = g.entity_id');
        $query->condition('g.field_geolocation_lat', $bbox['minLat'], '>=')
          ->condition('g.field_geolocation_lat', $bbox['maxLat'], '<=')
          ->condition('g.field_geolocation_lng', $bbox['minLon'], '>=')
          ->condition('g.field_geolocation_lng', $bbox['maxLon'], '<=');

        $query->addField('g', 'field_geolocation_lat', 'lat');
        $query->addField('g', 'field_geolocation_lng', 'lng');
      }

      // Join with embeddings table to get only nodes with embeddings.
      $query->join('markaspot_ai_embeddings', 'e',
        "n.nid = e.entity_id AND e.entity_type = 'node' AND e.embedding_type = 'content'"
      );
      $query->addField('e', 'embedding');

      $candidates = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      if (empty($candidates)) {
        return [];
      }

      // Calculate similarity scores for each candidate.
      $matches = [];
      foreach ($candidates as $candidate) {
        $candidateVector = json_decode($candidate['embedding'], TRUE);

        if (json_last_error() !== JSON_ERROR_NONE || empty($candidateVector)) {
          continue;
        }

        try {
          $similarity = $this->embeddingService->cosineSimilarity(
            $sourceEmbedding['vector'],
            $candidateVector
          );
        }
        catch (\InvalidArgumentException $e) {
          // Dimension mismatch - skip this candidate.
          $this->logger->warning('Dimension mismatch for node @nid: @message', [
            '@nid' => $candidate['nid'],
            '@message' => $e->getMessage(),
          ]);
          continue;
        }

        // Only include matches above threshold.
        if ($similarity >= $threshold) {
          $match = [
            'nid' => (int) $candidate['nid'],
            'title' => $candidate['title'],
            'similarity' => round($similarity, 4),
            'created' => (int) $candidate['created'],
            'distance_meters' => NULL,
          ];

          // Calculate geographic distance if coordinates available.
          if ($sourceLat !== NULL && $sourceLng !== NULL &&
              isset($candidate['lat']) && isset($candidate['lng'])) {
            $match['distance_meters'] = round($this->haversineDistance(
              $sourceLat,
              $sourceLng,
              (float) $candidate['lat'],
              (float) $candidate['lng']
            ), 1);

            // Skip matches outside the configured radius.
            // This is a safety check in case bounding box wasn't applied.
            if ($radius > 0 && $match['distance_meters'] > $radius) {
              $this->logger->debug('Skipping match @nid - distance @dist m exceeds radius @radius m', [
                '@nid' => $candidate['nid'],
                '@dist' => $match['distance_meters'],
                '@radius' => $radius,
              ]);
              continue;
            }
          }
          elseif ($radius > 0) {
            // If radius is configured but we can't calculate distance,
            // skip this match to avoid false positives.
            $this->logger->debug('Skipping match @nid - cannot calculate distance (missing coordinates)', [
              '@nid' => $candidate['nid'],
            ]);
            continue;
          }

          $matches[] = $match;
        }
      }

      // Sort by similarity score (descending).
      usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

      // Limit results.
      $matches = array_slice($matches, 0, $limit);

      $this->logger->debug('Found @count potential duplicates for node @nid', [
        '@count' => count($matches),
        '@nid' => $node->id(),
      ]);

      return $matches;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to find duplicates for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Stores a duplicate match in the database.
   *
   * @param int $sourceNid
   *   Node ID of the source service request.
   * @param int $matchNid
   *   Node ID of the matching service request.
   * @param float $similarity
   *   Cosine similarity score (0-1).
   * @param float|null $distance
   *   Geographic distance in meters, or NULL if not applicable.
   */
  public function storeDuplicateMatch(
    int $sourceNid,
    int $matchNid,
    float $similarity,
    ?float $distance = NULL,
  ): void {
    try {
      // Ensure consistent ordering (lower nid first) to avoid duplicate pairs.
      if ($sourceNid > $matchNid) {
        [$sourceNid, $matchNid] = [$matchNid, $sourceNid];
      }

      // Check if this pair already exists.
      $existing = $this->database->select('markaspot_ai_duplicate_matches', 'd')
        ->fields('d', ['id', 'status'])
        ->condition('d.source_nid', $sourceNid)
        ->condition('d.match_nid', $matchNid)
        ->execute()
        ->fetchAssoc();

      if ($existing) {
        // Update if the match was previously rejected but is found again.
        // Don't update confirmed matches.
        if ($existing['status'] === 'rejected') {
          $this->database->update('markaspot_ai_duplicate_matches')
            ->fields([
              'similarity_score' => $similarity,
              'distance_meters' => $distance,
              'status' => 'pending',
              'reviewed_by' => NULL,
              'reviewed_at' => NULL,
              'created' => \Drupal::time()->getRequestTime(),
            ])
            ->condition('id', $existing['id'])
            ->execute();

          $this->logger->info('Re-detected duplicate match @source -> @match (similarity: @sim)', [
            '@source' => $sourceNid,
            '@match' => $matchNid,
            '@sim' => round($similarity, 3),
          ]);
        }
        return;
      }

      // Insert new match.
      $this->database->insert('markaspot_ai_duplicate_matches')
        ->fields([
          'source_nid' => $sourceNid,
          'match_nid' => $matchNid,
          'similarity_score' => $similarity,
          'distance_meters' => $distance,
          'status' => 'pending',
          'created' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();

      $this->logger->info('Stored new duplicate match @source -> @match (similarity: @sim, distance: @dist m)', [
        '@source' => $sourceNid,
        '@match' => $matchNid,
        '@sim' => round($similarity, 3),
        '@dist' => $distance ? round($distance, 1) : 'N/A',
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store duplicate match: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets duplicate matches for a given node.
   *
   * @param int $nid
   *   The node ID to get matches for.
   * @param string|null $status
   *   Optional status filter: 'pending', 'confirmed', 'rejected', or NULL for all.
   *
   * @return array
   *   Array of match records containing:
   *   - 'id': Match record ID.
   *   - 'source_nid': Source node ID.
   *   - 'match_nid': Matching node ID.
   *   - 'other_nid': The node ID that is not the requested nid.
   *   - 'similarity_score': Cosine similarity score.
   *   - 'distance_meters': Geographic distance.
   *   - 'status': Match status.
   *   - 'reviewed_by': Reviewer user ID.
   *   - 'reviewed_at': Review timestamp.
   *   - 'created': Creation timestamp.
   */
  public function getDuplicateMatches(int $nid, ?string $status = NULL): array {
    try {
      $query = $this->database->select('markaspot_ai_duplicate_matches', 'd')
        ->fields('d');

      // Match where the node is either source or match.
      $or_group = $query->orConditionGroup()
        ->condition('d.source_nid', $nid)
        ->condition('d.match_nid', $nid);
      $query->condition($or_group);

      if ($status !== NULL) {
        $query->condition('d.status', $status);
      }

      $query->orderBy('d.similarity_score', 'DESC');

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // Add 'other_nid' field for convenience.
      foreach ($results as &$result) {
        $result['other_nid'] = ((int) $result['source_nid'] === $nid)
          ? (int) $result['match_nid']
          : (int) $result['source_nid'];
      }

      return $results;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get duplicate matches for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Reviews (confirms or rejects) a duplicate match.
   *
   * @param int $matchId
   *   The match record ID.
   * @param string $status
   *   The new status: 'confirmed' or 'rejected'.
   * @param int $reviewerUid
   *   The user ID of the reviewer.
   *
   * @return bool
   *   TRUE if the review was successful, FALSE otherwise.
   */
  public function reviewMatch(int $matchId, string $status, int $reviewerUid): bool {
    // Validate status.
    if (!in_array($status, ['confirmed', 'rejected'], TRUE)) {
      $this->logger->warning('Invalid match status: @status', ['@status' => $status]);
      return FALSE;
    }

    try {
      $updated = $this->database->update('markaspot_ai_duplicate_matches')
        ->fields([
          'status' => $status,
          'reviewed_by' => $reviewerUid,
          'reviewed_at' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('id', $matchId)
        ->execute();

      if ($updated > 0) {
        $this->logger->info('Match @id reviewed as @status by user @uid', [
          '@id' => $matchId,
          '@status' => $status,
          '@uid' => $reviewerUid,
        ]);
        return TRUE;
      }

      $this->logger->warning('Match @id not found for review', ['@id' => $matchId]);
      return FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to review match @id: @message', [
        '@id' => $matchId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets a single match record by ID.
   *
   * @param int $matchId
   *   The match record ID.
   *
   * @return array|null
   *   The match record, or NULL if not found.
   */
  public function getMatch(int $matchId): ?array {
    try {
      $result = $this->database->select('markaspot_ai_duplicate_matches', 'd')
        ->fields('d')
        ->condition('d.id', $matchId)
        ->execute()
        ->fetchAssoc();

      return $result ?: NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get match @id: @message', [
        '@id' => $matchId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Calculates the Haversine distance between two points.
   *
   * @param float $lat1
   *   Latitude of the first point in degrees.
   * @param float $lon1
   *   Longitude of the first point in degrees.
   * @param float $lat2
   *   Latitude of the second point in degrees.
   * @param float $lon2
   *   Longitude of the second point in degrees.
   *
   * @return float
   *   Distance in meters.
   */
  public function haversineDistance(
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2,
  ): float {
    // Earth's radius in meters.
    $earthRadius = 6371000;

    // Convert degrees to radians.
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);

    // Haversine formula.
    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
         cos($lat1Rad) * cos($lat2Rad) *
         sin($deltaLon / 2) * sin($deltaLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
  }

  /**
   * Calculates a bounding box around a point.
   *
   * @param float $lat
   *   Center latitude in degrees.
   * @param float $lng
   *   Center longitude in degrees.
   * @param float $radiusMeters
   *   Radius in meters.
   *
   * @return array
   *   Array with keys: 'minLat', 'maxLat', 'minLon', 'maxLon'.
   */
  public function calculateBoundingBox(
    float $lat,
    float $lng,
    float $radiusMeters,
  ): array {
    // Earth's radius in meters.
    $earthRadius = 6371000;

    // Angular distance in radians.
    $angularDistance = $radiusMeters / $earthRadius;

    $latRad = deg2rad($lat);
    $lngRad = deg2rad($lng);

    // Calculate latitude bounds.
    $minLatRad = $latRad - $angularDistance;
    $maxLatRad = $latRad + $angularDistance;

    // Calculate longitude bounds (accounting for latitude).
    $deltaLng = asin(sin($angularDistance) / cos($latRad));
    $minLngRad = $lngRad - $deltaLng;
    $maxLngRad = $lngRad + $deltaLng;

    return [
      'minLat' => rad2deg($minLatRad),
      'maxLat' => rad2deg($maxLatRad),
      'minLon' => rad2deg($minLngRad),
      'maxLon' => rad2deg($maxLngRad),
    ];
  }

  /**
   * Gets pending duplicate matches for review.
   *
   * @param int $limit
   *   Maximum number of matches to return.
   * @param int $offset
   *   Offset for pagination.
   *
   * @return array
   *   Array of pending match records with node titles.
   */
  public function getPendingMatches(int $limit = 50, int $offset = 0): array {
    try {
      $query = $this->database->select('markaspot_ai_duplicate_matches', 'd')
        ->fields('d')
        ->condition('d.status', 'pending')
        ->orderBy('d.similarity_score', 'DESC')
        ->orderBy('d.created', 'DESC')
        ->range($offset, $limit);

      // Join with node table to get titles.
      $query->leftJoin('node_field_data', 'ns', 'd.source_nid = ns.nid');
      $query->leftJoin('node_field_data', 'nm', 'd.match_nid = nm.nid');
      $query->addField('ns', 'title', 'source_title');
      $query->addField('nm', 'title', 'match_title');

      return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get pending matches: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Counts duplicate matches by status.
   *
   * @return array
   *   Array with status counts: 'pending', 'confirmed', 'rejected', 'total'.
   */
  public function getMatchCounts(): array {
    try {
      $query = $this->database->select('markaspot_ai_duplicate_matches', 'd')
        ->fields('d', ['status']);
      $query->addExpression('COUNT(*)', 'count');
      $query->groupBy('d.status');

      $results = $query->execute()->fetchAllKeyed();

      return [
        'pending' => (int) ($results['pending'] ?? 0),
        'confirmed' => (int) ($results['confirmed'] ?? 0),
        'rejected' => (int) ($results['rejected'] ?? 0),
        'total' => array_sum(array_map('intval', $results)),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get match counts: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'pending' => 0,
        'confirmed' => 0,
        'rejected' => 0,
        'total' => 0,
      ];
    }
  }

  /**
   * Deletes matches involving a specific node (when node is deleted).
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int
   *   Number of matches deleted.
   */
  public function deleteMatchesForNode(int $nid): int {
    try {
      $or_group = $this->database->condition('OR')
        ->condition('source_nid', $nid)
        ->condition('match_nid', $nid);

      $deleted = $this->database->delete('markaspot_ai_duplicate_matches')
        ->condition($or_group)
        ->execute();

      if ($deleted > 0) {
        $this->logger->info('Deleted @count duplicate matches for node @nid', [
          '@count' => $deleted,
          '@nid' => $nid,
        ]);
      }

      return $deleted;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete matches for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

}
