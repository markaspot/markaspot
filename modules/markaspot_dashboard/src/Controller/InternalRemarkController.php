<?php

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for internal remark operations.
 */
class InternalRemarkController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an InternalRemarkController object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
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

    // Create the paragraph.
    $paragraph = Paragraph::create(['type' => 'internal_remark']);

    // Set remark text.
    if (!empty($data['text'])) {
      $paragraph->set('field_internal_remark_text', [
        'value' => $data['text'],
        'format' => 'plain_text',
      ]);
    }

    // Track the actual author (paragraphs inherit parent ownership).
    $paragraph->set('field_author', \Drupal::currentUser()->id());

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
   * Delete an internal remark.
   */
  public function delete($uuid) {
    $paragraphs = $this->entityTypeManager->getStorage('paragraph')->loadByProperties([
      'uuid' => $uuid,
      'type' => 'internal_remark',
    ]);

    if (empty($paragraphs)) {
      return new JsonResponse(['error' => 'Not found'], 404);
    }

    $paragraph = reset($paragraphs);
    $paragraph_id = $paragraph->id();

    // Find parent node.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_internal_remark.target_id', $paragraph_id)
      ->accessCheck(TRUE);
    $nids = $query->execute();

    if (empty($nids)) {
      return new JsonResponse(['error' => 'Parent request not found'], 403);
    }

    $node = $this->entityTypeManager->getStorage('node')->load(reset($nids));

    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Remove reference.
    $current = $node->get('field_internal_remark')->getValue();
    $filtered = array_filter($current, fn($item) => $item['target_id'] != $paragraph_id);
    $node->field_internal_remark->setValue(array_values($filtered));
    $node->save();

    $paragraph->delete();

    return new JsonResponse(['status' => 'success']);
  }

}
