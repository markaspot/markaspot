<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for AI duplicate detection endpoints.
 *
 * Provides REST endpoints for:
 * - Fetching duplicates for a specific service request
 * - Reviewing duplicate matches (confirm/reject)
 * - Fetching all pending duplicates system-wide
 */
class DuplicateController extends ControllerBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * Constructs a DuplicateController object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('language_manager')
    );
  }

  /**
   * Get duplicates for a specific service request.
   *
   * @param int $nid
   *   The node ID of the source service request.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response with duplicates data.
   */
  public function getDuplicates(int $nid): CacheableJsonResponse {
    // Load the source node.
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'service_request') {
      throw new NotFoundHttpException('Service request not found');
    }

    // Query for duplicate matches.
    $query = $this->database->select('markaspot_ai_duplicate_matches', 'm')
      ->fields('m', [
        'id',
        'match_nid',
        'similarity_score',
        'distance_meters',
        'status',
        'created',
      ])
      ->condition('source_nid', $nid)
      ->orderBy('similarity_score', 'DESC');

    $results = $query->execute()->fetchAll();

    // Build duplicates array with node data.
    $duplicates = [];
    foreach ($results as $row) {
      $match_node = $this->entityTypeManager->getStorage('node')->load($row->match_nid);
      if (!$match_node) {
        continue;
      }

      $duplicates[] = $this->formatDuplicateMatch($row, $match_node);
    }

    $response = new CacheableJsonResponse([
      'node' => [
        'nid' => $node->id(),
        'title' => $node->getTitle(),
        'created' => $node->getCreatedTime(),
      ],
      'duplicates' => $duplicates,
      'count' => count($duplicates),
    ]);

    // Add cache metadata.
    $response->getCacheableMetadata()
      ->addCacheTags(['node:' . $nid])
      ->setCacheMaxAge(300);

    return $response;
  }

  /**
   * Get all pending duplicate matches system-wide.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   JSON response with pending duplicates.
   */
  public function getPendingDuplicates(Request $request): CacheableJsonResponse {
    $limit = $request->query->get('limit', 50);
    $offset = $request->query->get('offset', 0);

    // Get total counts by status.
    $counts_query = $this->database->select('markaspot_ai_duplicate_matches', 'm')
      ->fields('m', ['status'])
      ->groupBy('status');
    $counts_query->addExpression('COUNT(*)', 'count');
    $counts = $counts_query->execute()->fetchAllKeyed();

    $total_counts = [
      'pending' => (int) ($counts['pending'] ?? 0),
      'confirmed' => (int) ($counts['confirmed'] ?? 0),
      'rejected' => (int) ($counts['rejected'] ?? 0),
      'total' => array_sum($counts),
    ];

    // Query for pending matches.
    $query = $this->database->select('markaspot_ai_duplicate_matches', 'm')
      ->fields('m')
      ->condition('status', 'pending')
      ->orderBy('created', 'DESC')
      ->range((int) $offset, (int) $limit);

    $results = $query->execute()->fetchAll();

    // Build matches array with node data.
    $matches = [];
    foreach ($results as $row) {
      $source_node = $this->entityTypeManager->getStorage('node')->load($row->source_nid);
      $match_node = $this->entityTypeManager->getStorage('node')->load($row->match_nid);

      if (!$source_node || !$match_node) {
        continue;
      }

      $matches[] = [
        'id' => $row->id,
        'source_nid' => $row->source_nid,
        'source_title' => $source_node->getTitle(),
        'match_nid' => $row->match_nid,
        'match_title' => $match_node->getTitle(),
        'similarity_score' => (float) $row->similarity_score,
        'distance_meters' => $row->distance_meters ? (float) $row->distance_meters : NULL,
        'status' => $row->status,
        'created' => (int) $row->created,
      ];
    }

    $response = new CacheableJsonResponse([
      'matches' => $matches,
      'total_counts' => $total_counts,
      'limit' => (int) $limit,
      'offset' => (int) $offset,
    ]);

    $response->getCacheableMetadata()
      ->addCacheTags(['markaspot_ai_duplicates'])
      ->setCacheMaxAge(300);

    return $response;
  }

  /**
   * Review a duplicate match (confirm or reject).
   *
   * When confirmed, this will:
   * - Add a status note to the duplicate request
   * - Set the duplicate request's status to Closed
   * - Update the match status
   *
   * @param int $match_id
   *   The duplicate match ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object with JSON body containing 'status'.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success/error.
   */
  public function reviewMatch(int $match_id, Request $request): JsonResponse {
    // Parse request body.
    $data = json_decode($request->getContent(), TRUE);
    if (!isset($data['status']) || !in_array($data['status'], ['confirmed', 'rejected'])) {
      throw new BadRequestHttpException('Invalid status. Must be "confirmed" or "rejected".');
    }

    $new_status = $data['status'];

    // Load the match record.
    $match = $this->database->select('markaspot_ai_duplicate_matches', 'm')
      ->fields('m')
      ->condition('id', $match_id)
      ->execute()
      ->fetchObject();

    if (!$match) {
      throw new NotFoundHttpException('Match not found');
    }

    // Load the duplicate node (match_nid).
    $duplicate_node = $this->entityTypeManager->getStorage('node')->load($match->match_nid);
    if (!$duplicate_node || $duplicate_node->bundle() !== 'service_request') {
      throw new NotFoundHttpException('Duplicate service request not found');
    }

    // Load the source node for reference.
    $source_node = $this->entityTypeManager->getStorage('node')->load($match->source_nid);
    if (!$source_node) {
      throw new NotFoundHttpException('Source service request not found');
    }

    // Update the match status.
    $this->database->update('markaspot_ai_duplicate_matches')
      ->fields([
        'status' => $new_status,
        'reviewed_by' => $this->currentUser->id(),
        'reviewed_at' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $match_id)
      ->execute();

    // If confirmed, auto-close the duplicate and add status note.
    if ($new_status === 'confirmed') {
      $this->confirmDuplicate($duplicate_node, $source_node);
    }

    return new JsonResponse([
      'success' => TRUE,
      'match_id' => $match_id,
      'status' => $new_status,
    ]);
  }

  /**
   * Confirm a duplicate: add status note and close the request.
   *
   * @param \Drupal\node\NodeInterface $duplicate_node
   *   The duplicate service request node.
   * @param \Drupal\node\NodeInterface $source_node
   *   The original/source service request node.
   */
  protected function confirmDuplicate(NodeInterface $duplicate_node, NodeInterface $source_node): void {
    // Get the language of the duplicate request.
    $langcode = $duplicate_node->language()->getId();

    // Build the status note message based on language.
    $source_request_id = $this->getServiceRequestId($source_node);
    if ($langcode === 'de') {
      $note_text = "Als Duplikat von #{$source_request_id} markiert";
    }
    else {
      $note_text = "Marked as duplicate of #{$source_request_id}";
    }

    // Add the status note.
    $this->addStatusNote($duplicate_node, $note_text);

    // Set status to Closed (tid 5).
    $closed_status_tid = 5;
    if ($duplicate_node->hasField('field_status')) {
      $duplicate_node->set('field_status', ['target_id' => $closed_status_tid]);
    }

    // Save the node.
    $duplicate_node->save();
  }

  /**
   * Add a status note paragraph to a service request node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $note_text
   *   The status note text to add.
   */
  protected function addStatusNote(NodeInterface $node, string $note_text): void {
    // Get the current status to associate with this note.
    $current_status = $node->hasField('field_status') ? $node->get('field_status')->target_id : NULL;

    // Convert line breaks to <br> tags for proper display.
    $note_text_html = nl2br($note_text, FALSE);

    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $status_note_paragraph = $paragraph_storage->create([
      'type' => 'status',
      'field_status_note' => [
        'value' => $note_text_html,
        'format' => 'basic_html',
      ],
      'field_status_term' => [
        'target_id' => $current_status,
      ],
    ]);
    $status_note_paragraph->save();

    // Add to field_status_notes.
    $notes = $node->get('field_status_notes')->getValue();
    $notes[] = [
      'target_id' => $status_note_paragraph->id(),
      'target_revision_id' => $status_note_paragraph->getRevisionId(),
    ];
    $node->set('field_status_notes', $notes);
  }

  /**
   * Get the service_request_id from a node's title.
   *
   * Expected format: "#123-2026 Some Title"
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return string
   *   The service request ID (e.g., "123-2026") or the nid as fallback.
   */
  protected function getServiceRequestId(NodeInterface $node): string {
    $title = $node->getTitle();
    if (preg_match('/#(\d+-\d+)/', $title, $matches)) {
      return $matches[1];
    }
    return (string) $node->id();
  }

  /**
   * Format a duplicate match record for API response.
   *
   * @param object $row
   *   The database row from markaspot_ai_duplicate_matches.
   * @param \Drupal\node\NodeInterface $match_node
   *   The matched service request node.
   *
   * @return array
   *   Formatted duplicate match data.
   */
  protected function formatDuplicateMatch(object $row, NodeInterface $match_node): array {
    // Extract service request ID from title.
    $service_request_id = $this->getServiceRequestId($match_node);

    // Build URL for the request.
    $url = $match_node->toUrl('canonical', ['absolute' => TRUE])->toString();

    return [
      'match_id' => (int) $row->id,
      'nid' => (int) $match_node->id(),
      'service_request_id' => $service_request_id,
      'title' => $match_node->getTitle(),
      'similarity_score' => (float) $row->similarity_score,
      'distance_meters' => $row->distance_meters ? (float) $row->distance_meters : NULL,
      'status' => $row->status,
      'created' => (int) $row->created,
      'node_created' => $match_node->getCreatedTime(),
      'url' => $url,
    ];
  }

}
