<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_dashboard\Service\RequestLinkServiceInterface;
use Drupal\markaspot_dashboard\Service\SplitRequestService;
use Drupal\markaspot_dashboard\Service\SplitRequestServiceInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST endpoints for splitting a service request into a linked sibling.
 *
 * Both routes require the 'split service requests' permission (global) plus
 * an explicit, fail-closed jurisdiction membership check on the queried
 * node's jurisdiction, mirroring InboundMailAccessControlHandler (#482):
 * global bypass is uid 1 or the site-wide 'administrator' role only.
 *
 * @phpstan-consistent-constructor
 */
class SplitController extends ControllerBase {

  /**
   * Maximum allowed length for the child's description.
   */
  protected const DESCRIPTION_MAX_LENGTH = 10000;

  /**
   * Constructs the controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    protected SplitRequestServiceInterface $splitRequestService,
    protected RequestLinkServiceInterface $requestLinkService,
    protected LoggerInterface $logger,
    protected ?object $scopeValidator = NULL,
    protected ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('markaspot_dashboard.split_request'),
      $container->get('markaspot_dashboard.request_link'),
      $container->get('logger.channel.markaspot_dashboard'),
      $container->has('markaspot_group.jurisdiction_scope_validator')
        ? $container->get('markaspot_group.jurisdiction_scope_validator')
        : NULL,
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL,
    );
  }

  /**
   * POST /api/dashboard/requests/{nid}/split.
   */
  public function split(int $nid, Request $request): JsonResponse {
    $node = $this->loadServiceRequest($nid);
    if (!$node instanceof NodeInterface) {
      return $this->errorResponse('Service request not found.', 404);
    }
    if (!$node->access('update')) {
      return $this->errorResponse('Access denied.', 403);
    }

    $jurisdictionId = $this->splitRequestService->resolveJurisdictionForNode($node);
    if ($jurisdictionId === NULL || !$this->userMayAccessJurisdiction($jurisdictionId)) {
      return $this->errorResponse('Access denied.', 403);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->errorResponse('Invalid JSON body.', 400);
    }

    $categoryTid = (int) ($payload['category_tid'] ?? 0);
    if ($categoryTid <= 0) {
      return $this->errorResponse('category_tid is required.', 400);
    }

    $description = trim((string) ($payload['description'] ?? ''));
    if ($description === '') {
      return $this->errorResponse('description is required.', 400);
    }
    if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
      return $this->errorResponse(sprintf('description exceeds the maximum length of %d characters.', self::DESCRIPTION_MAX_LENGTH), 400);
    }

    $mediaIds = [];
    if (array_key_exists('media_ids', $payload)) {
      if (!is_array($payload['media_ids'])) {
        return $this->errorResponse('media_ids must be an array.', 400);
      }
      $mediaIds = array_values(array_unique(array_map('intval', $payload['media_ids'])));
    }
    if (!SplitRequestService::isMediaSubset($mediaIds, $this->sourceMediaIds($node))) {
      return $this->errorResponse('media_ids must be a subset of the source request\'s media.', 400);
    }

    $copyReporter = !array_key_exists('copy_reporter', $payload) || (bool) $payload['copy_reporter'];
    $notifyCitizen = !array_key_exists('notify_citizen', $payload) || (bool) $payload['notify_citizen'];

    if (!$this->splitRequestService->isCategoryInJurisdiction($categoryTid, $jurisdictionId)) {
      return $this->errorResponse('The category is not valid for this jurisdiction.', 422);
    }

    try {
      $result = $this->splitRequestService->split($node, [
        'category_tid' => $categoryTid,
        'description' => $description,
        'media_ids' => $mediaIds,
        'copy_reporter' => $copyReporter,
        'notify_citizen' => $notifyCitizen,
      ], $this->currentUser);
    }
    catch (\Throwable $e) {
      $this->logger->error('Split of service request @nid failed (@type).', [
        '@nid' => $nid,
        '@type' => get_class($e),
      ]);
      return $this->errorResponse('Split failed. The error has been logged.', 500);
    }

    $child = $result['child'];
    $original = $result['original'];

    return new JsonResponse([
      'child' => [
        'nid' => (int) $child->id(),
        'uuid' => (string) $child->uuid(),
        'request_id' => $this->nodeRequestId($child),
        'title' => $child->getTitle(),
      ],
      'original' => [
        'nid' => (int) $original->id(),
        'request_id' => $this->nodeRequestId($original),
      ],
      'message' => 'Request split successfully.',
    ]);
  }

  /**
   * GET /api/dashboard/requests/{nid}/links.
   */
  public function links(int $nid): JsonResponse {
    $node = $this->loadServiceRequest($nid);
    if (!$node instanceof NodeInterface) {
      return $this->errorResponse('Service request not found.', 404);
    }
    if (!$node->access('view')) {
      return $this->errorResponse('Access denied.', 403);
    }

    $jurisdictionId = $this->splitRequestService->resolveJurisdictionForNode($node);
    if ($jurisdictionId === NULL || !$this->userMayAccessJurisdiction($jurisdictionId)) {
      return $this->errorResponse('Access denied.', 403);
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $links = [];
    foreach ($this->requestLinkService->getLinksForNode($nid) as $row) {
      $isSource = $row['source_nid'] === $nid;
      $linkedNid = $isSource ? $row['target_nid'] : $row['source_nid'];

      $linkedNode = $nodeStorage->load($linkedNid);
      if (!$linkedNode instanceof NodeInterface || !$linkedNode->access('view')) {
        // Silently filter: only linked nodes the current user can view.
        continue;
      }

      $links[] = [
        'nid' => $linkedNid,
        'request_id' => $this->nodeRequestId($linkedNode),
        'title' => $linkedNode->getTitle(),
        'relationship' => $isSource ? 'split_from_this' : 'this_was_split_from',
        'created' => $row['created'],
        'uid' => $row['uid'],
      ];
    }

    return new JsonResponse([
      'node' => [
        'nid' => (int) $node->id(),
        'request_id' => $this->nodeRequestId($node),
        'title' => $node->getTitle(),
      ],
      'links' => $links,
      'count' => count($links),
    ]);
  }

  /**
   * Loads a service_request node by ID, or NULL when not found/wrong bundle.
   */
  protected function loadServiceRequest(int $nid): ?NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
      return NULL;
    }
    return $node;
  }

  /**
   * Gets the source node's field_request_media target IDs.
   *
   * @return int[]
   *   The media entity IDs referenced by the source node.
   */
  protected function sourceMediaIds(NodeInterface $node): array {
    if (!$node->hasField('field_request_media')) {
      return [];
    }
    return array_map(
      'intval',
      array_column($node->get('field_request_media')->getValue(), 'target_id')
    );
  }

  /**
   * Checks whether the current user may act on the given jurisdiction.
   *
   * Global bypass mirrors InboundMailAccessControlHandler::hasGlobalBypass():
   * uid 1 or the site-wide 'administrator' role only. Non-global users must
   * be a member of the jurisdiction, OR a member of an ANCESTOR jurisdiction
   * (a root-tenant member is not a direct member of every child jurisdiction
   * boundary-matched under it, mirroring DuplicateController's hierarchy
   * walk), per markaspot_group's scope validator and hierarchy resolver. A
   * missing validator or resolver fails closed to direct membership only.
   */
  protected function userMayAccessJurisdiction(int $jurisdictionId): bool {
    if ((int) $this->currentUser->id() === 1 || in_array('administrator', $this->currentUser->getRoles(), TRUE)) {
      return TRUE;
    }
    if ($this->scopeValidator === NULL || !method_exists($this->scopeValidator, 'getAllowedJurisdictionIds')) {
      return FALSE;
    }
    $allowed = array_map('intval', $this->scopeValidator->getAllowedJurisdictionIds($this->currentUser));
    if (in_array($jurisdictionId, $allowed, TRUE)) {
      return TRUE;
    }
    if ($this->hierarchyResolver === NULL) {
      return FALSE;
    }
    foreach ($allowed as $allowedId) {
      if (in_array($jurisdictionId, $this->hierarchyResolver->getDescendantIds($allowedId), TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The node's citizen-facing request_id (markaspot_request_id base field).
   *
   * Non-nullable: after a successful save request_id is guaranteed
   * (markaspot_request_id_node_presave() either sets it or aborts the save
   * with an EntityStorageException). The nid-string fallback mirrors
   * SplitRequestService::requestId() for nodes loaded outside that
   * guarantee (e.g. legacy data).
   */
  protected function nodeRequestId(NodeInterface $node): string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      return (string) $node->get('request_id')->value;
    }
    return (string) $node->id();
  }

  /**
   * Builds a JSON error response (no internal detail).
   */
  protected function errorResponse(string $message, int $status): JsonResponse {
    return new JsonResponse(['error' => $message], $status);
  }

}
