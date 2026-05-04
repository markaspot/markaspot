<?php

namespace Drupal\markaspot_escalation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_escalation\Service\EscalationServiceInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles escalation and delegation of service requests.
 */
class EscalationController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Maximum allowed length for escalation/delegation notes.
   */
  const NOTES_MAX_LENGTH = 2000;

  /**
   * The escalation service.
   *
   * @var \Drupal\markaspot_escalation\Service\EscalationServiceInterface
   */
  protected EscalationServiceInterface $escalationService;

  /**
   * The GeoReport processor service.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface
   */
  protected GeoreportProcessorServiceInterface $processor;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $escalationLogger;

  /**
   * Constructs an EscalationController.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EscalationServiceInterface $escalation_service,
    GeoreportProcessorServiceInterface $processor,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->escalationService = $escalation_service;
    $this->processor = $processor;
    $this->escalationLogger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('markaspot_escalation.service'),
      $container->get('markaspot_open311.processor'),
      $container->get('logger.factory')->get('markaspot_escalation'),
    );
  }

  /**
   * Escalates a service request to the parent jurisdiction.
   *
   * @param string $service_request_id
   *   The service request ID from the URL.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with escalation result.
   */
  public function escalate(string $service_request_id, Request $request): JsonResponse {
    $data = $this->decodeJsonBody($request);
    $node = $this->loadServiceRequest($service_request_id);

    // canEscalate() performs its own group-membership check against the
    // node's source jurisdiction, which is more precise than the generic
    // validateJurisdictionAccess() that resolves to the root jurisdiction.
    if (!$this->escalationService->canEscalate($node, $this->currentUser())) {
      throw new AccessDeniedHttpException('You do not have permission to escalate this request.');
    }

    // Validate and sanitise notes.
    $notes = $this->validateNotes($data, TRUE);

    // Resolve escalation target.
    $targetJurId = $this->escalationService->resolveEscalationTarget($node);
    if ($targetJurId === NULL) {
      throw new HttpException(422, 'No escalation target could be determined for this request.');
    }

    // Perform escalation.
    try {
      $this->escalationService->escalateRequest($node, $targetJurId, $notes);
    }
    catch (\InvalidArgumentException $e) {
      throw new HttpException(422, 'Invalid escalation target.');
    }

    $targetLabel = $this->entityTypeManager()->getStorage('group')
      ->load($targetJurId)?->label() ?? '';

    return new JsonResponse([
      'service_requests' => [
        'request' => [
          'service_request_id' => $service_request_id,
          'escalated' => TRUE,
          'escalation_target' => $targetLabel,
        ],
      ],
    ]);
  }

  /**
   * Delegates a service request to a specific organisation.
   *
   * @param string $service_request_id
   *   The service request ID from the URL.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with delegation result.
   */
  public function delegate(string $service_request_id, Request $request): JsonResponse {
    $data = $this->decodeJsonBody($request);
    $node = $this->loadServiceRequest($service_request_id);

    // canDelegate() performs its own group-membership check.
    if (!$this->escalationService->canDelegate($node, $this->currentUser())) {
      throw new AccessDeniedHttpException('You do not have permission to delegate this request.');
    }

    // Validate target organisation.
    $targetOrgId = $data['target_organisation'] ?? NULL;
    if (empty($targetOrgId)) {
      throw new BadRequestHttpException('The "target_organisation" field is required for delegation.');
    }

    $targetOrg = $this->entityTypeManager()->getStorage('group')->load($targetOrgId);
    if (!$targetOrg || $targetOrg->bundle() !== 'org') {
      throw new HttpException(422, 'Invalid target organisation.');
    }

    // Validate target org belongs to the same jurisdiction scope.
    $this->validateDelegationScope($node, $targetOrg);

    // Validate and sanitise notes.
    $notes = $this->validateNotes($data, FALSE);

    // Perform delegation.
    try {
      $this->escalationService->delegateRequest($node, (int) $targetOrgId, $notes);
    }
    catch (\InvalidArgumentException $e) {
      throw new HttpException(422, 'Invalid delegation target.');
    }

    return new JsonResponse([
      'service_requests' => [
        'request' => [
          'service_request_id' => $service_request_id,
          'delegated' => TRUE,
          'target_organisation' => $targetOrg->label(),
        ],
      ],
    ]);
  }

  /**
   * Decodes and validates the JSON request body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return array
   *   The decoded JSON data.
   */
  protected function decodeJsonBody(Request $request): array {
    $content = $request->getContent();
    if (empty($content)) {
      throw new BadRequestHttpException('Request body is required.');
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new BadRequestHttpException('Invalid JSON in request body.');
    }

    return $data;
  }

  /**
   * Loads a service request node by its request ID.
   *
   * @param string $service_request_id
   *   The service request ID (e.g. "182-2026").
   *
   * @return \Drupal\node\NodeInterface
   *   The loaded node.
   */
  protected function loadServiceRequest(string $service_request_id): NodeInterface {
    // Validate format: alphanumeric, hyphens, underscores only.
    if (!preg_match('/^[\w-]+$/', $service_request_id)) {
      throw new BadRequestHttpException('Invalid service request ID format.');
    }

    $nodes = $this->entityTypeManager()->getStorage('node')
      ->loadByProperties([
        'type' => 'service_request',
        'status' => 1,
        'request_id' => $service_request_id,
      ]);

    if (empty($nodes)) {
      throw new NotFoundHttpException('Service request not found.');
    }

    return reset($nodes);
  }

  /**
   * Validates and sanitises the notes field.
   *
   * @param array $data
   *   The request data.
   * @param bool $required
   *   Whether the notes field is required.
   *
   * @return string
   *   The sanitised notes string.
   */
  protected function validateNotes(array $data, bool $required): string {
    // Strip tags as defense in depth. The paragraph uses 'plain_text' format
    // which also strips on render, but we enforce at the input boundary.
    $notes = mb_substr(trim(strip_tags($data['notes'] ?? '')), 0, self::NOTES_MAX_LENGTH);

    if ($required && empty($notes)) {
      throw new BadRequestHttpException('The "notes" field is required.');
    }

    return $notes;
  }

  /**
   * Gets the effective jurisdiction ID for a node.
   *
   * Escalated nodes use field_escalation; non-escalated nodes fall back
   * to the category-based jurisdiction lookup.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if not determined.
   */
  protected function getEffectiveNodeJurisdictionId(NodeInterface $node): ?int {
    if ($node->hasField('field_escalation') && !$node->get('field_escalation')->isEmpty()) {
      return (int) $node->get('field_escalation')->target_id;
    }
    return $this->processor->getJurisdictionIdFromNode($node);
  }

  /**
   * Validates that a delegation target org is within the node's jurisdiction.
   *
   * Prevents cross-tenant data leakage by ensuring the target organisation
   * belongs to the same jurisdiction hierarchy as the service request.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param \Drupal\group\Entity\GroupInterface $targetOrg
   *   The target organisation group.
   */
  protected function validateDelegationScope(NodeInterface $node, $targetOrg): void {
    // Get the node's effective jurisdiction context.
    // For escalated nodes, field_escalation is authoritative.
    // For non-escalated nodes, fall back to category-based lookup.
    $nodeJurId = $this->getEffectiveNodeJurisdictionId($node);
    if ($nodeJurId === NULL) {
      throw new HttpException(422, 'Cannot determine jurisdiction context for this request.');
    }

    // Get the target org's jurisdiction.
    $orgJurId = NULL;
    if ($targetOrg->hasField('field_jurisdiction') && !$targetOrg->get('field_jurisdiction')->isEmpty()) {
      $orgJurId = (int) $targetOrg->get('field_jurisdiction')->target_id;
    }

    if ($orgJurId === NULL) {
      throw new HttpException(422, 'Target organisation has no jurisdiction assigned.');
    }

    // The org's jurisdiction must match the node's jurisdiction context.
    // In a hierarchy, the node might be escalated to a parent jur,
    // and the org should belong to that jur or a child of it.
    if ((int) $nodeJurId !== $orgJurId) {
      // Check if the org's jur is a child of the node's jur.
      $nodeJurGroup = $this->entityTypeManager()->getStorage('group')->load($nodeJurId);
      $orgJurGroup = $this->entityTypeManager()->getStorage('group')->load($orgJurId);

      if (!$nodeJurGroup || !$orgJurGroup) {
        throw new HttpException(422, 'Target organisation is not within the request\'s jurisdiction.');
      }

      // Walk up from the org's jur to see if we reach the node's jur.
      $currentId = $orgJurId;
      $maxDepth = 10;
      $found = FALSE;
      while ($maxDepth-- > 0) {
        $current = $this->entityTypeManager()->getStorage('group')->load($currentId);
        if (!$this->isJurisdictionGroup($current)) {
          break;
        }
        if ((int) $current->id() === (int) $nodeJurId) {
          $found = TRUE;
          break;
        }
        if (!$current->hasField('field_parent_jurisdiction')
            || $current->get('field_parent_jurisdiction')->isEmpty()) {
          break;
        }
        $currentId = (int) $current->get('field_parent_jurisdiction')->target_id;
      }

      if (!$found) {
        $this->escalationLogger->warning('Delegation rejected: org @oid (jur @ojid) is not within node jur @njid.', [
          '@oid' => $targetOrg->id(),
          '@ojid' => $orgJurId,
          '@njid' => $nodeJurId,
        ]);
        throw new HttpException(422, 'Target organisation is not within the request\'s jurisdiction.');
      }
    }
  }

}
