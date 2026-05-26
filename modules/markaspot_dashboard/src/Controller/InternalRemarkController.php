<?php

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for internal remark operations.
 */
final class InternalRemarkController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs an InternalRemarkController object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Create a new internal remark.
   */
  public function add(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['request_uuid'])) {
      return new JsonResponse(['error' => 'Missing request_uuid'], 400);
    }

    if (empty(trim($data['text'] ?? ''))) {
      return new JsonResponse(['error' => 'Missing text'], 400);
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

    // Create the paragraph in the same language as the parent node.
    $paragraph = Paragraph::create([
      'type' => 'internal_remark',
      'langcode' => $node->language()->getId(),
    ]);

    // Set remark text.
    if (!empty($data['text'])) {
      $paragraph->set('field_internal_remark_text', [
        'value' => $data['text'],
        'format' => 'plain_text',
      ]);
    }

    // Track the actual author (paragraphs inherit parent ownership).
    $paragraph->set('field_author', $this->currentUser->id());

    $paragraph->save();

    // Link to service request.
    $current = $node->get('field_internal_remark')->getValue();
    $current[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
    $node->field_internal_remark->setValue($current);
    $node->save();

    return new JsonResponse([
      'status' => 'success',
      'uuid' => $paragraph->uuid(),
    ], 201);
  }

  /**
   * Update an internal remark.
   *
   * Authorship is enforced on the server: only the original author or a user
   * with the 'administer nodes' permission may modify a remark. The
   * client-side canEditRemark check is UX, not security — without this method
   * a staff member could PATCH a peer's remark directly via JSON:API and
   * silently rewrite the audit trail.
   */
  public function update(string $uuid, Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $text = trim($data['text'] ?? '');

    if ($text === '') {
      return new JsonResponse(['error' => 'Missing text'], 400);
    }

    $paragraph = $this->loadRemarkParagraph($uuid);
    if (!$paragraph instanceof Paragraph) {
      return new JsonResponse(['error' => 'Not found'], 404);
    }

    $node = $this->loadParentRequest((int) $paragraph->id());
    if (!$node) {
      return new JsonResponse(['error' => 'Parent request not found'], 403);
    }

    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    if (!$this->canModifyRemark($paragraph)) {
      return new JsonResponse(['error' => 'Only the author or an administrator may modify this remark'], 403);
    }

    $paragraph->set('field_internal_remark_text', [
      'value' => $text,
      'format' => 'plain_text',
    ]);
    $paragraph->save();

    return new JsonResponse([
      'status' => 'success',
      'uuid' => $paragraph->uuid(),
    ]);
  }

  /**
   * Delete an internal remark.
   *
   * Same authorship constraint as update() — node-update access is necessary
   * but not sufficient.
   */
  public function delete($uuid) {
    $paragraph = $this->loadRemarkParagraph($uuid);
    if (!$paragraph instanceof Paragraph) {
      return new JsonResponse(['error' => 'Not found'], 404);
    }

    $paragraph_id = (int) $paragraph->id();
    $node = $this->loadParentRequest($paragraph_id);
    if (!$node) {
      return new JsonResponse(['error' => 'Parent request not found'], 403);
    }

    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    if (!$this->canModifyRemark($paragraph)) {
      return new JsonResponse(['error' => 'Only the author or an administrator may delete this remark'], 403);
    }

    $current = $node->get('field_internal_remark')->getValue();
    $filtered = array_filter($current, fn($item) => $item['target_id'] != $paragraph_id);
    $node->field_internal_remark->setValue(array_values($filtered));
    $node->save();

    $paragraph->delete();

    return new JsonResponse(['status' => 'success']);
  }

  /**
   * Load an internal_remark paragraph by uuid.
   */
  protected function loadRemarkParagraph(string $uuid): ?Paragraph {
    $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadByProperties([
      'uuid' => $uuid,
      'type' => 'internal_remark',
    ]);
    $paragraph = reset($paragraphs) ?: NULL;
    return $paragraph instanceof Paragraph ? $paragraph : NULL;
  }

  /**
   * Find the service_request node that owns a given remark paragraph.
   */
  protected function loadParentRequest(int $paragraph_id) {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_internal_remark.target_id', $paragraph_id)
      ->accessCheck(TRUE)
      ->execute();
    if (empty($nids)) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('node')->load(reset($nids));
  }

  /**
   * Whether the current user may modify (edit/delete) a given remark.
   *
   * Author-or-admin pattern: the original author always wins, otherwise the
   * caller must hold the high-trust 'administer nodes' permission. We do not
   * fall back to node-update access alone — that would let any dispatcher
   * silently rewrite peers' remarks.
   */
  protected function canModifyRemark(Paragraph $paragraph): bool {
    if ($this->currentUser->hasPermission('administer nodes')) {
      return TRUE;
    }
    $author_id = (int) ($paragraph->get('field_author')->target_id ?? 0);
    return $author_id !== 0 && $author_id === (int) $this->currentUser->id();
  }

}
