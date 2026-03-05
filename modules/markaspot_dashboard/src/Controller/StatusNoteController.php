<?php

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
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
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->georeportProcessor = $georeport_processor;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('markaspot_open311.processor'),
      $container->get('current_user'),
    );
  }

  /**
   * Create a new status note.
   */
  public function add(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['request_uuid'])) {
      return new JsonResponse(['error' => 'Missing request_uuid'], 400);
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

    // Resolve UUIDs to entity IDs.
    $statusTermId = NULL;
    if (!empty($data['status_term_uuid'])) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'uuid' => $data['status_term_uuid'],
        'vid' => 'service_status',
      ]);
      if (!empty($terms)) {
        $statusTermId = reset($terms)->id();
      }
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
      'note' => $data['note'] ?? NULL,
      'boilerplate_id' => $boilerplateId,
      'author_id' => $this->currentUser->id(),
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

    // Find parent node.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_status_notes.target_id', $paragraph_id)
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
    $current = $node->get('field_status_notes')->getValue();
    $filtered = array_filter($current, fn($item) => $item['target_id'] != $paragraph_id);
    $node->field_status_notes->setValue(array_values($filtered));
    $node->save();

    $paragraph->delete();

    return new JsonResponse(['status' => 'success']);
  }

}
