<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for AI attribute filling from the dashboard.
 */
final class AttributeController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Constructs an AttributeController object.
   */
  public function __construct(
    protected Connection $database,
    protected QueueFactory $queueFactory,
    protected AttributeFillingService $attributeFillingService,
    protected RequestStack $requestStackService,
    protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('queue'),
      $container->get('markaspot_ai.attribute_filling'),
      $container->get('request_stack'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL
    );
  }

  /**
   * Get attribute filling status.
   *
   * Supports optional ?jurisdiction_id=N to scope counts.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with attribute filling status.
   */
  public function getStatus(): JsonResponse {
    $request = $this->requestStackService->getCurrentRequest();
    $jurisdiction_id = $this->resolveJurisdictionId($request?->query->get('jurisdiction_id'));

    if (!$this->currentUserCanSeeAllJurisdictions()) {
      if ($jurisdiction_id === NULL || !$this->currentUserCanAccessJurisdiction($jurisdiction_id)) {
        return new JsonResponse($this->buildEmptyStatus());
      }
    }

    $node_ids = $jurisdiction_id ? $this->getNodeIdsForJurisdiction($jurisdiction_id) : NULL;
    if ($node_ids !== NULL) {
      $node_ids = $this->filterAiEnabledNodeIds($node_ids);
    }

    if ($node_ids !== NULL && empty($node_ids)) {
      return new JsonResponse($this->buildEmptyStatus());
    }

    // Count nodes that have a category with service definitions.
    $totalWithDefs = $this->countNodesWithDefinitions($node_ids);

    // Count nodes that already have filled attributes.
    $filled = $this->countFilledAttributes($node_ids);

    $missing = max(0, $totalWithDefs - $filled);
    $percentage = $totalWithDefs > 0 ? round(($filled / $totalWithDefs) * 100) : 0;

    // Queue status. Queue items only carry node IDs, so tenant-scoped users
    // must not see global queue volume here.
    $queue = $this->queueFactory->get('markaspot_ai_attribute_filling');
    $queue_count = ($jurisdiction_id !== NULL && !$this->currentUserCanSeeAllJurisdictions())
      ? 0
      : $queue->numberOfItems();

    return new JsonResponse([
      'total_with_definitions' => $totalWithDefs,
      'filled' => $filled,
      'missing' => $missing,
      'percentage' => $percentage,
      'queue' => $queue_count,
    ]);
  }

  /**
   * Fill attributes for a single service request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing { nid: int }.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with proposed attributes.
   */
  public function fillSingle(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE) ?? [];
    $nid = (int) ($content['nid'] ?? 0);

    if (!$nid) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Missing nid parameter.',
      ], 400);
    }

    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'service_request') {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Service request not found.',
      ], 404);
    }

    // Node access check (prevents cross-jurisdiction IDOR).
    if (!$node->access('update')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    // GDPR: respect jurisdiction AI opt-out.
    if (!_markaspot_ai_is_ai_enabled_for_node($node)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'AI processing is disabled for this jurisdiction.',
      ], 403);
    }

    // Pass content language to AI service.
    $langcode = $content['langcode'] ?? NULL;
    // Preview mode: return proposed attributes without saving to node.
    $preview = !empty($content['preview']);
    $save = !$preview;

    try {
      $result = $this->attributeFillingService->fillAttributes($node, TRUE, $langcode, $save);

      if ($result === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Could not fill attributes. The category may not have service definitions, or the AI could not determine values.',
        ]);
      }

      return new JsonResponse([
        'success' => TRUE,
        'attributes' => $result['attributes'],
        'model' => $result['model'],
        'message' => 'Attributes filled successfully.',
      ]);

    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_ai')->error('Single attribute fill failed for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Attribute filling failed. Check logs for details.',
      ], 500);
    }
  }

  /**
   * Generate a description for a service request using AI vision.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing { nid: int, langcode?: string }.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with generated description.
   */
  public function describe(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE) ?? [];
    $nid = (int) ($content['nid'] ?? 0);

    if (!$nid) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Missing nid parameter.',
      ], 400);
    }

    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'service_request') {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Service request not found.',
      ], 404);
    }

    if (!$node->access('update')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    if (!_markaspot_ai_is_ai_enabled_for_node($node)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'AI processing is disabled for this jurisdiction.',
      ], 403);
    }

    $langcode = $content['langcode'] ?? NULL;

    try {
      $result = $this->attributeFillingService->generateDescription($node, $langcode);

      if ($result === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Could not generate description. The request may not have photos.',
        ]);
      }

      return new JsonResponse([
        'success' => TRUE,
        'description' => $result['description'],
        'model' => $result['model'],
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_ai')->error('Description generation failed for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Description generation failed.',
      ], 500);
    }
  }

  /**
   * Unified AI form assistant.
   *
   * Returns suggestions for multiple form fields in a single LLM call.
   * GDPR: Only allowlisted node fields are sent to the LLM. No PII.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request containing { nid, langcode?, fields: string[] }.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with suggestions for the requested fields.
   */
  public function assist(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE) ?? [];
    $nid = (int) ($content['nid'] ?? 0);

    if (!$nid) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Missing nid parameter.',
      ], 400);
    }

    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'service_request') {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Service request not found.',
      ], 404);
    }

    if (!$node->access('update')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    if (!_markaspot_ai_is_ai_enabled_for_node($node)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'AI processing is disabled for this jurisdiction.',
      ], 403);
    }

    $langcode = $content['langcode'] ?? NULL;

    // Validate requested fields against allowed set.
    if (empty($content['fields']) || !is_array($content['fields'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'The "fields" parameter is required (array of field names).',
      ], 400);
    }

    $allowedFields = ['body', 'attributes', 'organisation', 'status_note', 'priority'];
    $requestedFields = array_values(array_intersect(
      $content['fields'],
      $allowedFields
    ));

    if (empty($requestedFields)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'No valid fields requested.',
      ], 400);
    }

    try {
      $result = $this->attributeFillingService->assistForm($node, $requestedFields, $langcode);

      if ($result === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'AI could not generate suggestions for this request.',
        ]);
      }

      return new JsonResponse([
        'success' => TRUE,
        'suggestions' => $result['suggestions'],
        'model' => $result['model'],
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_ai')->error('AI assist failed for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'AI assist failed. Check logs for details.',
      ], 500);
    }
  }

  /**
   * Queue missing attributes for batch processing.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing { limit: int, jurisdiction_id?: int }.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with queue result.
   */
  public function queueBatch(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE) ?? [];
    $limit = min(1000, max(1, (int) ($content['limit'] ?? 100)));

    // Optional jurisdiction scoping (supports slugs).
    $jurisdiction_id = $this->resolveJurisdictionId(
      $content['jurisdiction_id'] ?? $request->query->get('jurisdiction_id')
    );

    if (!$this->currentUserCanSeeAllJurisdictions()) {
      if ($jurisdiction_id === NULL || !$this->currentUserCanAccessJurisdiction($jurisdiction_id)) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Access denied for the requested jurisdiction.',
          'queued' => 0,
        ], 403);
      }
    }

    $jurisdiction_node_ids = $jurisdiction_id
      ? $this->getNodeIdsForJurisdiction($jurisdiction_id)
      : NULL;

    try {
      $missing = $this->collectAiEnabledNodeIds(
        fn(int $batch_limit, int $offset): array => $this->attributeFillingService
          ->findMissingAttributes($batch_limit, $jurisdiction_node_ids, $offset),
        $limit
      );

      if (empty($missing)) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'Nothing queued: no eligible missing attributes exist, or the matching tenants are not opted in to AI text processing.',
          'queued' => 0,
        ]);
      }

      // Queue them.
      $queue = $this->queueFactory->get('markaspot_ai_attribute_filling');
      $count = 0;

      foreach ($missing as $nid) {
        $queue->createItem([
          'nid' => $nid,
        ]);
        $count++;
      }

      $this->getLogger('markaspot_ai')->notice('Queued @count items for attribute filling via dashboard.', [
        '@count' => $count,
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'message' => "Queued {$count} items for attribute filling.",
        'queued' => $count,
      ]);

    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_ai')->error('Failed to queue attribute filling: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to queue items. Check logs for details.',
      ], 500);
    }
  }

  /**
   * Counts nodes that have a category with service definitions.
   *
   * @param array|null $node_ids
   *   Optional array of node IDs to filter.
   *
   * @return int
   *   Count of matching nodes.
   */
  protected function countNodesWithDefinitions(?array $node_ids): int {
    return count($this->getNodeIdsWithVariableDefinitions($node_ids));
  }

  /**
   * Counts nodes that already have filled attributes.
   *
   * @param array|null $node_ids
   *   Optional array of node IDs to filter.
   *
   * @return int
   *   Count of nodes with filled attributes.
   */
  protected function countFilledAttributes(?array $node_ids): int {
    $eligible_node_ids = $this->getNodeIdsWithVariableDefinitions($node_ids);
    if (empty($eligible_node_ids)) {
      return 0;
    }

    $nodes = $this->entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($eligible_node_ids);

    $filled = 0;
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface && $this->nodeHasFilledAttributes($node)) {
        $filled++;
      }
    }

    return $filled;
  }

  /**
   * Gets service request IDs whose category has variable service attributes.
   *
   * @param array|null $node_ids
   *   Optional array of node IDs to filter.
   *
   * @return array<int>
   *   Node IDs with fillable category attributes.
   */
  protected function getNodeIdsWithVariableDefinitions(?array $node_ids): array {
    $candidate_ids = $this->getCandidateNodeIdsWithServiceDefinition($node_ids);
    if (empty($candidate_ids)) {
      return [];
    }

    $nodes = $this->entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($candidate_ids);

    $eligible = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
        continue;
      }
      if ($this->nodeHasVariableAttributes($node)) {
        $eligible[] = (int) $node->id();
      }
    }

    return array_values(array_unique($eligible));
  }

  /**
   * Gets candidate service request IDs with a non-empty definition field.
   *
   * The exact "has fillable attributes" check must be done by loading the term
   * and using AttributeFillingService's parser. The field tables are
   * translation- and revision-aware, so SQL joins alone can duplicate or miss
   * rows depending on tenant language setup.
   *
   * @param array|null $node_ids
   *   Optional array of node IDs to filter.
   *
   * @return array<int>
   *   Candidate node IDs.
   */
  protected function getCandidateNodeIdsWithServiceDefinition(?array $node_ids): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->distinct();
    $query->fields('n', ['nid']);
    $query->condition('n.type', 'service_request');
    $query->condition('n.default_langcode', 1);

    // Join to the category reference field.
    $query->innerJoin(
      'node__field_category',
      'fc',
      'n.nid = fc.entity_id AND fc.deleted = 0'
    );

    // Prefilter terms that have any stored definition. The exact parse happens
    // after loading the term translation.
    $query->innerJoin(
      'taxonomy_term__field_service_definition',
      'sd',
      'fc.field_category_target_id = sd.entity_id AND sd.deleted = 0'
    );
    $query->condition('sd.field_service_definition_value', '', '<>');

    if ($node_ids !== NULL) {
      $query->condition('n.nid', $node_ids, 'IN');
    }

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Checks whether a request's category has variable service attributes.
   */
  protected function nodeHasVariableAttributes(NodeInterface $node): bool {
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return FALSE;
    }

    $category = $node->get('field_category')->entity;
    if (!$category instanceof TermInterface) {
      return FALSE;
    }

    $langcode = $node->language()->getId();
    if ($langcode === 'und' || $langcode === 'zxx') {
      $langcode = NULL;
    }

    return !empty($this->attributeFillingService->getVariableAttributes($category, $langcode));
  }

  /**
   * Checks whether a request has non-empty filled request attributes.
   */
  protected function nodeHasFilledAttributes(NodeInterface $node): bool {
    if (!$node->hasField('field_request_attributes')
        || $node->get('field_request_attributes')->isEmpty()) {
      return FALSE;
    }

    $raw = $node->get('field_request_attributes')->value;
    if (!is_string($raw) || trim($raw) === '') {
      return FALSE;
    }

    $decoded = json_decode($raw, TRUE);
    if (json_last_error() === JSON_ERROR_NONE) {
      return is_array($decoded) ? !empty($decoded) : $decoded !== NULL;
    }

    return TRUE;
  }

  /**
   * Gets node IDs for a jurisdiction, including child jurisdictions.
   *
   * @param int $group_id
   *   The jurisdiction group ID.
   *
   * @return array<int>
   *   Array of node IDs belonging to the jurisdiction subtree.
   */
  protected function getNodeIdsForJurisdiction(int $group_id): array {
    if (!$this->moduleHandler()->moduleExists('group')) {
      return [];
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($group_id);
    if (!$this->isJurisdictionGroup($group)) {
      return [];
    }

    $node_ids = $this->hierarchyResolver
      ? $this->hierarchyResolver->getNodeIdsInJurisdiction($group_id)
      : [];
    $node_ids = array_merge($node_ids, $this->getNodeIdsByJurisdictionField($group_id));

    if (!empty($node_ids)) {
      return array_values(array_unique(array_map('intval', $node_ids)));
    }

    // Fallback: flat single-group query.
    $relationship_storage = $this->entityTypeManager()->getStorage('group_relationship');
    $relationship_ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group_id)
      ->condition('plugin_id', 'group_node:service_request')
      ->execute();

    if (empty($relationship_ids)) {
      return [];
    }

    $relationships = $relationship_storage->loadMultiple($relationship_ids);
    $node_ids = [];

    foreach ($relationships as $relationship) {
      $node_ids[] = (int) $relationship->get('entity_id')->target_id;
    }

    return array_values(array_unique($node_ids));
  }

  /**
   * Gets node IDs from the canonical field_jurisdiction reference.
   *
   * @param int $group_id
   *   The jurisdiction group ID.
   *
   * @return array<int>
   *   Service request node IDs in the requested jurisdiction subtree.
   */
  protected function getNodeIdsByJurisdictionField(int $group_id): array {
    $jurisdiction_ids = $this->hierarchyResolver
      ? $this->hierarchyResolver->getDescendantIds($group_id)
      : [$group_id];

    if (empty($jurisdiction_ids)) {
      return [];
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->distinct();
    $query->fields('n', ['nid']);
    $query->condition('n.type', 'service_request');
    $query->condition('n.default_langcode', 1);
    $query->innerJoin(
      'node__field_jurisdiction',
      'fj',
      'n.nid = fj.entity_id AND fj.deleted = 0'
    );
    $query->condition('fj.field_jurisdiction_target_id', $jurisdiction_ids, 'IN');

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Builds an empty attribute status response.
   */
  protected function buildEmptyStatus(): array {
    return [
      'total_with_definitions' => 0,
      'filled' => 0,
      'missing' => 0,
      'percentage' => 0,
      'queue' => 0,
    ];
  }

  /**
   * Checks if the current user can see all jurisdictions.
   */
  protected function currentUserCanSeeAllJurisdictions(): bool {
    $currentUser = $this->currentUser();
    return (int) $currentUser->id() === 1
      || $currentUser->hasPermission('administer nodes');
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

    $account = $this->currentUser();
    if (!in_array('tenant_admin', $account->getRoles(), TRUE)) {
      return FALSE;
    }

    foreach ($this->getTenantAdminJurisdictionIds((int) $account->id()) as $managed_id) {
      $managed_group = $this->entityTypeManager()->getStorage('group')->load($managed_id);
      if (!$this->isJurisdictionGroup($managed_group)) {
        continue;
      }
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
   * Gets jurisdiction IDs where the user has a tenant-admin group role.
   *
   * @param int $uid
   *   User ID.
   *
   * @return array<int>
   *   Managed jurisdiction group IDs.
   */
  protected function getTenantAdminJurisdictionIds(int $uid): array {
    $group_type = $this->getJurisdictionGroupType();
    $membership_types = array_values(array_unique([
      $group_type . '-group_membership',
      'jur-group_membership',
    ]));
    $role_ids = array_values(array_unique([
      $group_type . '-tenant_admin',
      'jur-tenant_admin',
    ]));

    $query = $this->database->select('group_relationship_field_data', 'gr');
    $query->distinct();
    $query->fields('gr', ['gid']);
    $query->condition('gr.plugin_id', 'group_membership');
    $query->condition('gr.entity_id', $uid);
    $query->condition('gr.default_langcode', 1);
    $query->condition('gr.type', $membership_types, 'IN');
    $query->innerJoin(
      'group_relationship__group_roles',
      'roles',
      'gr.id = roles.entity_id AND roles.deleted = 0'
    );
    $query->condition('roles.group_roles_target_id', $role_ids, 'IN');

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Filters node IDs to tenants with explicit AI processing opt-in.
   *
   * @param array $node_ids
   *   Candidate node IDs.
   *
   * @return array<int>
   *   Node IDs whose jurisdiction has features.aiProcessing=true.
   */
  protected function filterAiEnabledNodeIds(array $node_ids): array {
    if (empty($node_ids)) {
      return [];
    }

    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($node_ids);
    $enabled = [];
    foreach ($nodes as $node) {
      if ($node->bundle() !== 'service_request') {
        continue;
      }
      if (_markaspot_ai_is_ai_enabled_for_node($node)) {
        $enabled[] = (int) $node->id();
      }
    }

    return $enabled;
  }

  /**
   * Collects up to the requested limit after tenant opt-in filtering.
   *
   * @param callable $candidate_loader
   *   Callable with signature fn(int $limit, int $offset): array.
   * @param int $limit
   *   Maximum number of enabled node IDs to return.
   *
   * @return array<int>
   *   AI-enabled service request node IDs.
   */
  protected function collectAiEnabledNodeIds(callable $candidate_loader, int $limit): array {
    $enabled = [];
    $seen = [];
    $offset = 0;
    $batch_size = min(500, max(50, $limit));

    while (count($enabled) < $limit) {
      $candidates = $candidate_loader($batch_size, $offset);
      if (empty($candidates)) {
        break;
      }
      $offset += count($candidates);

      foreach ($this->filterAiEnabledNodeIds($candidates) as $nid) {
        if (isset($seen[$nid])) {
          continue;
        }
        $seen[$nid] = TRUE;
        $enabled[] = $nid;
        if (count($enabled) >= $limit) {
          break 2;
        }
      }

      if (count($candidates) < $batch_size) {
        break;
      }
    }

    return $enabled;
  }

}
