<?php

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for status note operations.
 */
class StatusNoteController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The georeport processor service.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface
   */
  protected $georeportProcessor;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a StatusNoteController object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GeoreportProcessorServiceInterface $georeport_processor,
    AccountProxyInterface $current_user,
    protected FeatureFlagChecker $featureFlagChecker,
    protected StatusTermScope $statusTermScope,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->georeportProcessor = $georeport_processor;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('markaspot_open311.processor'),
      $container->get('current_user'),
      $container->get('markaspot_nuxt.feature_flag_checker'),
      $container->get('markaspot_group.status_term_scope'),
    );
  }

  /**
   * Create a new status note.
   */
  public function add(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    if (empty($data['request_uuid'])) {
      return new JsonResponse(['error' => 'Missing request_uuid'], 400);
    }

    $limited_author = !$this->currentUser->hasPermission('manage dashboard notes');
    $statusAttributes = $this->normalizeStatusAttributes($data['status_attributes'] ?? NULL);
    if ($limited_author && (
      $statusAttributes !== NULL
      || !empty($data['boilerplate_uuid'])
    )) {
      return new JsonResponse([
        'error' => 'Status attributes and boilerplates require status note management permission.',
      ], 403);
    }

    // Load the service request.
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'uuid' => $data['request_uuid'],
      'type' => 'service_request',
    ]);

    if (empty($nodes)) {
      return new JsonResponse(['error' => 'Service request not found'], 404);
    }

    $node = reset($nodes);

    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    if ($statusAttributes !== NULL && !$this->statusAttributesEnabled($node)) {
      return new JsonResponse(['error' => 'Status attributes are not enabled for this jurisdiction.'], 403);
    }

    // Resolve UUIDs to entity IDs.
    $statusTermId = NULL;
    if (!empty($data['status_term_uuid'])) {
      $jurisdictionId = $this->statusJurisdictionId($node);
      $terms = $this->statusTermScope->loadByProperties(
        [
          'uuid' => $data['status_term_uuid'],
          'vid' => 'service_status',
        ],
        $jurisdictionId,
      );
      if (empty($terms) && $this->statusTermScope->canScope($jurisdictionId)) {
        return new JsonResponse([
          'error' => 'Status term is not available for this jurisdiction.',
        ], 400);
      }
      if (!empty($terms)) {
        $status_term = reset($terms);
        if ($limited_author && (
          !$status_term instanceof TermInterface
          || !$status_term->access('view', $this->currentUser)
        )) {
          return new JsonResponse(['error' => 'Status term is outside the service request jurisdiction.'], 403);
        }
        $statusTermId = (int) $status_term->id();
      }
      elseif ($limited_author) {
        return new JsonResponse(['error' => 'Status term not found'], 400);
      }
    }
    elseif ($limited_author
      && $node->hasField('field_status')
      && !$node->get('field_status')->isEmpty()) {
      $statusTermId = (int) $node->get('field_status')->target_id;
    }

    if ($limited_author && $statusTermId !== NULL) {
      // The frontend creates the note before its later form PATCH. Persist the
      // validated status in this same node save so the audit note and request
      // cannot disagree even if that later request fails.
      $node->set('field_status', $statusTermId);
    }

    $boilerplateId = NULL;
    if (!empty($data['boilerplate_uuid'])) {
      $boilerplates = $this->entityTypeManager->getStorage('node')->loadByProperties([
        'uuid' => $data['boilerplate_uuid'],
        'type' => 'boilerplate',
      ]);
      if (!empty($boilerplates)) {
        $boilerplateId = reset($boilerplates)->id();
      }
    }

    // Create paragraph via central factory.
    $paragraph = $this->georeportProcessor->createStatusNoteParagraph([
      'status_term_id' => $statusTermId,
      'note' => isset($data['note']) ? strip_tags($data['note']) : NULL,
      'boilerplate_id' => $boilerplateId,
      'author_id' => $this->currentUser->id(),
      ...($statusAttributes !== NULL ? ['status_attributes' => $statusAttributes] : []),
    ], $node->language()->getId());

    // Link to service request.
    $current = $node->get('field_status_notes')->getValue();
    $current[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
    $node->field_status_notes->setValue($current);
    $node->save();

    return new JsonResponse([
      'status' => 'success',
      'uuid' => $paragraph->uuid(),
    ], 201);
  }

  /**
   * Update a status note.
   */
  public function update(Request $request, $uuid) {
    $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadByProperties([
      'uuid' => $uuid,
      'type' => 'status',
    ]);

    if (empty($paragraphs)) {
      return new JsonResponse(['error' => 'Not found'], 404);
    }

    $paragraph = reset($paragraphs);
    $node = $this->loadParentNode((int) $paragraph->id());
    if (!$node) {
      return new JsonResponse(['error' => 'Parent request not found'], 403);
    }

    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    if (array_key_exists('status_attributes', $data)) {
      if (!$this->statusAttributesEnabled($node)) {
        return new JsonResponse(['error' => 'Status attributes are not enabled for this jurisdiction.'], 403);
      }
      if (!$paragraph->hasField('field_status_attributes')) {
        return new JsonResponse(['error' => 'Status attributes field is not installed.'], 500);
      }

      $statusAttributes = $this->normalizeStatusAttributes($data['status_attributes']);
      $paragraph->set('field_status_attributes', $statusAttributes === NULL
        ? NULL
        : ['value' => $statusAttributes, 'format' => 'plain_text']
      );
    }

    if (array_key_exists('note', $data)) {
      $note = is_string($data['note']) ? strip_tags($data['note']) : '';
      $paragraph->set('field_status_note', $note === ''
        ? NULL
        : ['value' => $note, 'format' => 'plain_text']
      );
    }

    if (array_key_exists('status_term_uuid', $data)) {
      $statusTermId = NULL;
      if (!empty($data['status_term_uuid'])) {
        $jurisdictionId = $this->statusJurisdictionId($node);
        $terms = $this->statusTermScope->loadByProperties(
          [
            'uuid' => $data['status_term_uuid'],
            'vid' => 'service_status',
          ],
          $jurisdictionId,
        );
        if (empty($terms) && $this->statusTermScope->canScope($jurisdictionId)) {
          return new JsonResponse([
            'error' => 'Status term is not available for this jurisdiction.',
          ], 400);
        }
        $statusTermId = empty($terms) ? NULL : reset($terms)->id();
      }
      $paragraph->set('field_status_term', $statusTermId ?? []);
    }

    if (array_key_exists('boilerplate_uuid', $data) && $paragraph->hasField('field_boilerplate')) {
      $boilerplateId = NULL;
      if (!empty($data['boilerplate_uuid'])) {
        $boilerplates = $this->entityTypeManager->getStorage('node')->loadByProperties([
          'uuid' => $data['boilerplate_uuid'],
          'type' => 'boilerplate',
        ]);
        if (!empty($boilerplates)) {
          $boilerplateId = reset($boilerplates)->id();
        }
      }
      $paragraph->set('field_boilerplate', $boilerplateId ?? []);
    }

    $paragraph->save();

    return new JsonResponse([
      'status' => 'success',
      'uuid' => $paragraph->uuid(),
    ]);
  }

  /**
   * Delete a status note.
   */
  public function delete($uuid) {
    $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadByProperties([
      'uuid' => $uuid,
      'type' => 'status',
    ]);

    if (empty($paragraphs)) {
      return new JsonResponse(['error' => 'Not found'], 404);
    }

    $paragraph = reset($paragraphs);
    $paragraph_id = $paragraph->id();

    $node = $this->loadParentNode((int) $paragraph_id);

    if (!$node) {
      return new JsonResponse(['error' => 'Parent request not found'], 403);
    }

    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Remove reference.
    $current = $node->get('field_status_notes')->getValue();
    $filtered = array_filter($current, fn($item) => $item['target_id'] != $paragraph_id);
    $node->field_status_notes->setValue(array_values($filtered));
    $node->save();

    $paragraph->delete();

    return new JsonResponse(['status' => 'success']);
  }

  /**
   * Load the service request that owns a status paragraph.
   */
  private function loadParentNode(int $paragraph_id): ?NodeInterface {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_status_notes.target_id', $paragraph_id)
      ->accessCheck(TRUE);
    $nids = $query->execute();

    if (empty($nids)) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('node')->load(reset($nids));
  }

  /**
   * Normalize status attributes to a JSON object string.
   */
  private function normalizeStatusAttributes(mixed $raw): ?string {
    if ($raw === NULL || $raw === '' || $raw === []) {
      return NULL;
    }

    if (is_string($raw)) {
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded)) {
        return NULL;
      }
      $raw = $decoded;
    }

    if (!is_array($raw)) {
      return NULL;
    }

    if (array_is_list($raw)) {
      return NULL;
    }

    return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Checks the operator-controlled tenant capability for status attributes.
   */
  private function statusAttributesEnabled(NodeInterface $node): bool {
    $jurisdiction = $this->featureFlagChecker->resolveJurisdictionForNode($node);
    return $this->featureFlagChecker->isEnabled('features.statusAttributes', $jurisdiction, FALSE);
  }

  /**
   * Reuses the processor's existing effective node jurisdiction resolution.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Service request node.
   *
   * @return int|null
   *   Resolved jurisdiction group ID.
   */
  private function statusJurisdictionId(NodeInterface $node): ?int {
    return $this->georeportProcessor->resolveNodeJurisdictionId($node);
  }

}
