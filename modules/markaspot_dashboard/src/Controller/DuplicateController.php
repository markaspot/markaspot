<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
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
 * - Fetching all pending duplicates system-wide.
 */
class DuplicateController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The group membership loader.
   */
  protected GroupMembershipLoaderInterface $membershipLoader;

  /**
   * The optional jurisdiction hierarchy resolver.
   */
  protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Constructs a DuplicateController object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory,
    GroupMembershipLoaderInterface $membership_loader,
    ModuleHandlerInterface $module_handler,
    ?JurisdictionHierarchyResolverInterface $hierarchy_resolver = NULL,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->time = $time;
    $this->configFactory = $config_factory;
    $this->membershipLoader = $membership_loader;
    $this->moduleHandler = $module_handler;
    $this->hierarchyResolver = $hierarchy_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('language_manager'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('group.membership_loader'),
      $container->get('module_handler'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL
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

    if (!$this->isDuplicateDetectionEnabled() || !$this->canAccessNode($node, 'view')) {
      return $this->scopedDuplicateResponse([
        'error' => 'Duplicate data is disabled for this jurisdiction.',
      ], 403, ['node:' . $nid]);
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
      if (!$match_node || $match_node->bundle() !== 'service_request') {
        continue;
      }
      if (!$this->canAccessNode($match_node, 'view')) {
        continue;
      }

      $duplicates[] = $this->formatDuplicateMatch($row, $match_node);
    }

    return $this->scopedDuplicateResponse([
      'node' => [
        'nid' => $node->id(),
        'title' => $node->getTitle(),
        'created' => $node->getCreatedTime(),
      ],
      'duplicates' => $duplicates,
      'count' => count($duplicates),
    ], 200, ['node:' . $nid]);
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
    $limit = min(max((int) $limit, 1), 100);
    $offset = max((int) $offset, 0);

    if (!$this->isDuplicateDetectionEnabled()) {
      return $this->scopedDuplicateResponse([
        'matches' => [],
        'total_counts' => $this->emptyMatchCounts(),
        'limit' => $limit,
        'offset' => $offset,
        'jurisdiction_id' => NULL,
      ], 200, ['markaspot_ai_duplicates'], [
        'url.query_args:jurisdiction_id',
        'url.query_args:limit',
        'url.query_args:offset',
      ]);
    }

    $jurisdictionId = $this->resolveJurisdictionFilter($request);
    $nodeIds = $this->getAccessibleAiEnabledNodeIds($jurisdictionId, 'view');
    if (empty($nodeIds)) {
      return $this->scopedDuplicateResponse([
        'matches' => [],
        'total_counts' => $this->emptyMatchCounts(),
        'limit' => $limit,
        'offset' => $offset,
        'jurisdiction_id' => $jurisdictionId,
      ], 200, ['markaspot_ai_duplicates'], [
        'url.query_args:jurisdiction_id',
        'url.query_args:limit',
        'url.query_args:offset',
      ]);
    }

    $total_counts = $this->getDuplicateMatchCounts($nodeIds);
    $results = $this->getPendingDuplicateMatches($nodeIds, $limit, $offset);

    // Build matches array with node data.
    $matches = [];
    foreach ($results as $row) {
      $source_node = $this->entityTypeManager->getStorage('node')->load($row->source_nid);
      $match_node = $this->entityTypeManager->getStorage('node')->load($row->match_nid);

      if (!$source_node || !$match_node) {
        continue;
      }
      if (!$this->canAccessNode($source_node, 'view') || !$this->canAccessNode($match_node, 'view')) {
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

    return $this->scopedDuplicateResponse([
      'matches' => $matches,
      'total_counts' => $total_counts,
      'limit' => $limit,
      'offset' => $offset,
      'jurisdiction_id' => $jurisdictionId,
    ], 200, ['markaspot_ai_duplicates'], [
      'url.query_args:jurisdiction_id',
      'url.query_args:limit',
      'url.query_args:offset',
    ]);
  }

  /**
   * Review a duplicate match (confirm or reject).
   *
   * When confirmed, this will:
   * - Add a status note to the duplicate request
   * - Set the duplicate request's status to Closed
   * - Update the match status.
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

    if (!$this->isDuplicateDetectionEnabled()
      || !$this->canAccessNode($duplicate_node, 'update')
      || !$this->canAccessNode($source_node, 'view')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    // Update the match status.
    $this->database->update('markaspot_ai_duplicate_matches')
      ->fields([
        'status' => $new_status,
        'reviewed_by' => $this->currentUser->id(),
        'reviewed_at' => $this->time->getRequestTime(),
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
   * Checks whether the AI duplicate feature is globally available.
   */
  protected function isDuplicateDetectionEnabled(): bool {
    if (!$this->moduleHandler->moduleExists('markaspot_ai')) {
      return FALSE;
    }
    $this->moduleHandler->loadInclude('markaspot_ai', 'module');

    return (bool) $this->configFactory
      ->get('markaspot_ai.settings')
      ->get('duplicate_detection.enabled');
  }

  /**
   * Builds a user-scoped duplicate response.
   */
  protected function scopedDuplicateResponse(
    array $data,
    int $status = 200,
    array $tags = [],
    array $contexts = [],
  ): CacheableJsonResponse {
    $response = new CacheableJsonResponse($data, $status);
    $response->getCacheableMetadata()
      ->addCacheContexts(array_values(array_unique(array_merge(['user'], $contexts))))
      ->addCacheTags($tags)
      ->setCacheMaxAge(0);

    return $response;
  }

  /**
   * Resolves the requested jurisdiction scope for the current user.
   */
  protected function resolveJurisdictionFilter(Request $request): ?int {
    $resolved = $this->resolveJurisdictionId($request->query->get('jurisdiction_id'));
    if ($this->currentUserCanSeeAllJurisdictions()) {
      return $resolved;
    }
    if ($resolved === NULL) {
      return -1;
    }

    return $this->currentUserCanAccessJurisdiction($resolved) ? $resolved : -1;
  }

  /**
   * Checks whether the current user may see all jurisdictions.
   */
  protected function currentUserCanSeeAllJurisdictions(): bool {
    return (int) $this->currentUser->id() === 1
      || $this->currentUser->hasPermission('administer nodes');
  }

  /**
   * Checks node access, tenant membership and tenant AI opt-in.
   */
  protected function canAccessNode(NodeInterface $node, string $operation): bool {
    if (!$node->access($operation)) {
      return FALSE;
    }
    if (!$this->isNodeAiProcessingEnabled($node)) {
      return FALSE;
    }

    return $this->currentUserCanAccessJurisdiction($this->getJurisdictionIdForNode($node));
  }

  /**
   * Checks whether a node belongs to a tenant opted in to AI text processing.
   */
  protected function isNodeAiProcessingEnabled(NodeInterface $node): bool {
    if (!$this->moduleHandler->moduleExists('markaspot_ai')) {
      return FALSE;
    }
    $this->moduleHandler->loadInclude('markaspot_ai', 'module');
    if (!function_exists('_markaspot_ai_is_ai_enabled_for_node')) {
      return FALSE;
    }

    return _markaspot_ai_is_ai_enabled_for_node($node);
  }

  /**
   * Resolves a service request node's jurisdiction ID.
   */
  protected function getJurisdictionIdForNode(NodeInterface $node): ?int {
    if (!$this->moduleHandler->moduleExists('markaspot_ai')) {
      return NULL;
    }
    $this->moduleHandler->loadInclude('markaspot_ai', 'module');
    if (!function_exists('_markaspot_ai_get_jurisdiction_id_for_node')) {
      return NULL;
    }

    return _markaspot_ai_get_jurisdiction_id_for_node($node);
  }

  /**
   * Checks if the current user administers the requested jurisdiction scope.
   */
  protected function currentUserCanAccessJurisdiction(?int $jurisdiction_id): bool {
    if ($jurisdiction_id === NULL) {
      return FALSE;
    }
    if ($this->currentUserCanSeeAllJurisdictions()) {
      return TRUE;
    }
    if (!in_array('tenant_admin', $this->currentUser->getRoles(), TRUE)) {
      return FALSE;
    }

    $memberships = $this->membershipLoader->loadByUser(
      $this->currentUser,
      $this->jurisdictionRoleIds('tenant_admin')
    );
    foreach ($memberships as $membership) {
      $managed_group = $membership->getGroup();
      if (!$this->isJurisdictionGroup($managed_group)) {
        continue;
      }
      $managed_id = (int) $managed_group->id();
      $scope_ids = $this->hierarchyResolver
        ? $this->hierarchyResolver->getDescendantIds($managed_id)
        : [$managed_id];
      if (in_array($jurisdiction_id, $scope_ids, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets service request IDs accessible to the current user and AI-enabled.
   */
  protected function getAccessibleAiEnabledNodeIds(?int $jurisdiction_id, string $operation): array {
    if ($jurisdiction_id !== NULL && !$this->currentUserCanAccessJurisdiction($jurisdiction_id)) {
      return [];
    }

    if ($jurisdiction_id !== NULL && $this->hierarchyResolver) {
      $node_ids = $this->hierarchyResolver->getNodeIdsInJurisdiction($jurisdiction_id);
    }
    elseif ($jurisdiction_id !== NULL) {
      $query = $this->database
        ->select('group_relationship_field_data', 'gr')
        ->fields('gr', ['entity_id'])
        ->condition('gr.plugin_id', 'group_node:service_request');
      $query->innerJoin('groups_field_data', 'grp', 'gr.gid = grp.id AND grp.default_langcode = 1');
      $query->condition('grp.type', $this->getJurisdictionGroupType());
      $query->condition('grp.id', $jurisdiction_id);
      $node_ids = $query->execute()->fetchCol();
    }
    else {
      if (!$this->currentUserCanSeeAllJurisdictions()) {
        return [];
      }
      $node_ids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'service_request')
        ->execute();
    }

    if (empty($node_ids)) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
    $enabled = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
        continue;
      }
      if ($this->canAccessNode($node, $operation)) {
        $enabled[] = (int) $node->id();
      }
    }

    return $enabled;
  }

  /**
   * Gets duplicate match counts for an already scoped node set.
   */
  protected function getDuplicateMatchCounts(array $node_ids): array {
    if (empty($node_ids)) {
      return $this->emptyMatchCounts();
    }

    $query = $this->database->select('markaspot_ai_duplicate_matches', 'd')
      ->fields('d', ['status'])
      ->condition('d.source_nid', $node_ids, 'IN')
      ->condition('d.match_nid', $node_ids, 'IN');
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

  /**
   * Gets pending duplicate matches for an already scoped node set.
   */
  protected function getPendingDuplicateMatches(array $node_ids, int $limit, int $offset): array {
    if (empty($node_ids)) {
      return [];
    }

    $query = $this->database->select('markaspot_ai_duplicate_matches', 'm')
      ->fields('m')
      ->condition('m.status', 'pending')
      ->condition('m.source_nid', $node_ids, 'IN')
      ->condition('m.match_nid', $node_ids, 'IN')
      ->orderBy('m.similarity_score', 'DESC')
      ->orderBy('m.created', 'DESC')
      ->range($offset, $limit);

    return $query->execute()->fetchAll();
  }

  /**
   * Returns the empty duplicate count shape.
   */
  protected function emptyMatchCounts(): array {
    return [
      'pending' => 0,
      'confirmed' => 0,
      'rejected' => 0,
      'total' => 0,
    ];
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

    // Resolve the "closed" status term for the node's jurisdiction.
    // Status terms are jurisdiction-specific (field_jurisdiction + field_open311_mapping).
    $closed_tid = $this->resolveClosedStatusTid($duplicate_node);
    if ($closed_tid && $duplicate_node->hasField('field_status')) {
      $duplicate_node->set('field_status', ['target_id' => $closed_tid]);
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
   * Resolves the "closed" status term ID for a node's jurisdiction.
   *
   * Status terms are jurisdiction-specific: each jurisdiction has its own
   * set of terms with field_open311_mapping indicating open/closed/initial.
   * The jurisdiction is derived from the node's category term.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The closed status term ID, or NULL if not found.
   */
  protected function resolveClosedStatusTid(NodeInterface $node): ?int {
    // Resolve jurisdiction from node's category.
    $jurisdictionId = NULL;
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $category = $node->get('field_category')->entity;
      if ($category && $category->hasField('field_jurisdiction') && !$category->get('field_jurisdiction')->isEmpty()) {
        $jurisdictionId = (int) $category->get('field_jurisdiction')->target_id;
      }
    }

    // Build query for "closed" status terms in this jurisdiction.
    $properties = [
      'vid' => 'service_status',
      'status' => 1,
      'field_open311_mapping' => 'closed',
    ];
    if ($jurisdictionId) {
      $properties['field_jurisdiction'] = $jurisdictionId;
    }

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties($properties);

    if (!empty($terms)) {
      $term = reset($terms);
      return (int) $term->id();
    }

    return NULL;
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
