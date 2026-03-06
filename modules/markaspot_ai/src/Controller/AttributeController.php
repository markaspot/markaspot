<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\markaspot_ai\Service\AttributeFillingService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for AI attribute filling from the dashboard.
 */
class AttributeController extends ControllerBase {

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
    $jurisdiction_id = $request?->query->get('jurisdiction_id');
    $node_ids = $jurisdiction_id ? $this->getNodeIdsForJurisdiction((int) $jurisdiction_id) : NULL;

    if ($node_ids !== NULL && empty($node_ids)) {
      return new JsonResponse([
        'total_with_definitions' => 0,
        'filled' => 0,
        'missing' => 0,
        'percentage' => 0,
        'queue' => 0,
      ]);
    }

    // Count nodes that have a category with service definitions.
    $totalWithDefs = $this->countNodesWithDefinitions($node_ids);

    // Count nodes that already have filled attributes.
    $filled = $this->countFilledAttributes($node_ids);

    $missing = max(0, $totalWithDefs - $filled);
    $percentage = $totalWithDefs > 0 ? round(($filled / $totalWithDefs) * 100) : 0;

    // Queue status.
    $queue = $this->queueFactory->get('markaspot_ai_attribute_filling');

    return new JsonResponse([
      'total_with_definitions' => $totalWithDefs,
      'filled' => $filled,
      'missing' => $missing,
      'percentage' => $percentage,
      'queue' => $queue->numberOfItems(),
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

    // Optional jurisdiction scoping.
    $jurisdiction_id = $content['jurisdiction_id']
      ?? $request->query->get('jurisdiction_id');
    $jurisdiction_node_ids = $jurisdiction_id
      ? $this->getNodeIdsForJurisdiction((int) $jurisdiction_id)
      : NULL;

    try {
      $missing = $this->attributeFillingService->findMissingAttributes(
        $limit,
        $jurisdiction_node_ids
      );

      if (empty($missing)) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'No missing attributes to queue.',
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
    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', 'service_request');

    // Join to the category reference field.
    $query->innerJoin('node__field_category', 'fc', 'n.nid = fc.entity_id');

    // Join to check that the referenced term has a service definition.
    $query->innerJoin('taxonomy_term__field_service_definition', 'sd',
      'fc.field_category_target_id = sd.entity_id');
    $query->condition('sd.field_service_definition_value', '', '<>');

    if ($node_ids !== NULL) {
      $query->condition('n.nid', $node_ids, 'IN');
    }

    return (int) $query->countQuery()->execute()->fetchField();
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
    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', 'service_request');

    // Join to the category reference field (only count nodes whose
    // category actually has a service definition).
    $query->innerJoin('node__field_category', 'fc', 'n.nid = fc.entity_id');
    $query->innerJoin('taxonomy_term__field_service_definition', 'sd',
      'fc.field_category_target_id = sd.entity_id');
    $query->condition('sd.field_service_definition_value', '', '<>');

    // Join to the attributes field and check it's not empty.
    $query->innerJoin('node__field_request_attributes', 'ra', 'n.nid = ra.entity_id');
    $query->condition('ra.field_request_attributes_value', '', '<>');

    if ($node_ids !== NULL) {
      $query->condition('n.nid', $node_ids, 'IN');
    }

    return (int) $query->countQuery()->execute()->fetchField();
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
    if (!$group || $group->bundle() !== 'jur') {
      return [];
    }

    if ($this->hierarchyResolver) {
      return $this->hierarchyResolver->getNodeIdsInJurisdiction($group_id);
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

}
