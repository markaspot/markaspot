<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_moderation\Service\ModerationServiceInterface;
use Drupal\markaspot_group\Service\TenantAdminHelper;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for moderation API endpoints.
 *
 * Provides REST endpoints for submitting flags, viewing flagged requests,
 * and performing moderation actions (dismiss, hide, delete).
 */
class ModerationController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * The moderation service.
   *
   * @var \Drupal\markaspot_moderation\Service\ModerationServiceInterface
   */
  protected ModerationServiceInterface $moderationService;

  /**
   * Constructs a ModerationController.
   *
   * @param \Drupal\markaspot_moderation\Service\ModerationServiceInterface $moderationService
   *   The moderation service.
   */
  public function __construct(
    ModerationServiceInterface $moderationService,
  ) {
    $this->moderationService = $moderationService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_moderation.service')
    );
  }

  /**
   * Submits a new content flag.
   *
   * Expects JSON body with:
   * - service_request_id (required): The Open311 service request ID.
   * - reason (required): One of spam, offensive, personal, location, other.
   * - details (optional): Additional details, max 500 chars.
   *   Required for "other".
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the created flag ID.
   */
  public function submitFlag(Request $request): JsonResponse {
    try {
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      if (!is_array($data)) {
        return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
      }

      // Validate service_request_id.
      $serviceRequestId = $data['service_request_id'] ?? '';
      if (empty($serviceRequestId) || !is_string($serviceRequestId)) {
        return new JsonResponse(['error' => 'service_request_id is required.'], 400);
      }
      if (strlen($serviceRequestId) > 64 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $serviceRequestId)) {
        return new JsonResponse(['error' => 'service_request_id must be alphanumeric (max 64 chars).'], 400);
      }

      // Validate reason.
      $validReasons = ['spam', 'offensive', 'personal', 'location', 'other'];
      $reason = $data['reason'] ?? '';
      if (empty($reason) || !in_array($reason, $validReasons, TRUE)) {
        return new JsonResponse(['error' => 'reason must be one of: ' . implode(', ', $validReasons)], 400);
      }

      // Validate details.
      $details = $data['details'] ?? NULL;
      if ($details !== NULL) {
        if (!is_string($details)) {
          return new JsonResponse(['error' => 'details must be a string.'], 400);
        }
        $details = mb_substr(trim($details), 0, 500);
      }

      // Details required for "other" reason.
      if ($reason === 'other' && (empty($details))) {
        return new JsonResponse(['error' => 'details is required when reason is "other".'], 400);
      }

      // Hash IP and session with salt to prevent rainbow table attacks (GDPR).
      $salt = Settings::get('markaspot_moderation.ip_salt', 'mas_default_salt');
      $ipHash = hash_hmac('sha256', $request->getClientIp() ?? 'unknown', $salt);
      $sessionId = '';
      try {
        if ($request->hasSession()) {
          $sessionId = $request->getSession()->getId();
        }
      }
      catch (\Exception) {
        // Stateless/headless requests may not have a session.
      }
      $sessionHash = hash_hmac('sha256', $sessionId, $salt);

      $fid = $this->moderationService->createFlag(
        $serviceRequestId,
        $reason,
        $details,
        $ipHash,
        $sessionHash
      );

      return new JsonResponse(['success' => TRUE, 'flagId' => $fid], 201);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
    catch (\LogicException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 409);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_moderation')->error('Flag submission failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'An internal error occurred.'], 500);
    }
  }

  /**
   * Gets flagged service requests for the current user's jurisdictions.
   *
   * Supports query parameters:
   * - jurisdiction_id: Filter by specific jurisdiction (admin-all only).
   * - reason: Filter by flag reason.
   * - min_count: Minimum flag count threshold.
   * - start_date: Start date filter (UNIX timestamp).
   * - end_date: End date filter (UNIX timestamp).
   * - limit: Results per page (default 50, max 200).
   * - offset: Pagination offset.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with flagged request data and pagination metadata.
   */
  public function getFlaggedRequests(Request $request): JsonResponse {
    $jurisdictionIds = $this->resolveJurisdictionIds($request);

    if (empty($jurisdictionIds)) {
      return new JsonResponse(['data' => [], 'meta' => ['total' => 0, 'limit' => 50, 'offset' => 0]]);
    }

    $filters = [];
    $validReasons = ['spam', 'offensive', 'personal', 'location', 'other'];
    $reason = $request->query->get('reason');
    if ($reason) {
      if (!in_array($reason, $validReasons, TRUE)) {
        return new JsonResponse(['error' => 'Invalid reason filter.'], 400);
      }
      $filters['reason'] = $reason;
    }
    $minCount = $request->query->get('min_count');
    if ($minCount) {
      $filters['min_count'] = $minCount;
    }
    $startDate = $request->query->get('start_date');
    if ($startDate) {
      $filters['start_date'] = $startDate;
    }
    $endDate = $request->query->get('end_date');
    if ($endDate) {
      $filters['end_date'] = $endDate;
    }

    $limit = min((int) ($request->query->get('limit', 50)), 200);
    if ($limit < 1) {
      $limit = 50;
    }
    $offset = max((int) ($request->query->get('offset', 0)), 0);

    $data = $this->moderationService->getFlaggedRequests($jurisdictionIds, $filters, $limit, $offset);
    $total = $this->moderationService->getFlagCountForJurisdictions($jurisdictionIds);

    return new JsonResponse([
      'data' => $data,
      'meta' => [
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
      ],
    ]);
  }

  /**
   * Gets the count of flagged requests for the current user's jurisdictions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the flag count.
   */
  public function getFlaggedCount(Request $request): JsonResponse {
    $jurisdictionIds = $this->resolveJurisdictionIds($request);

    if (empty($jurisdictionIds)) {
      return new JsonResponse(['count' => 0]);
    }

    $count = $this->moderationService->getFlagCountForJurisdictions($jurisdictionIds);

    return new JsonResponse(['count' => $count]);
  }

  /**
   * Gets all flags for a specific service request node.
   *
   * @param int $nid
   *   The node ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with flag records.
   */
  public function getFlagsForRequest(int $nid, Request $request): JsonResponse {
    $accessError = $this->checkNodeJurisdictionAccess($nid, $request);
    if ($accessError) {
      return $accessError;
    }

    $data = $this->moderationService->getFlagsForRequest($nid);

    return new JsonResponse(['data' => $data]);
  }

  /**
   * Dismisses all active flags for a service request node.
   *
   * @param int $nid
   *   The node ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success.
   */
  public function dismissFlags(int $nid, Request $request): JsonResponse {
    $accessError = $this->checkNodeJurisdictionAccess($nid, $request);
    if ($accessError) {
      return $accessError;
    }

    $this->moderationService->dismissFlags($nid, (int) $this->currentUser()->id());

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Hides (unpublishes) a service request and dismisses its flags.
   *
   * @param int $nid
   *   The node ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success.
   */
  public function hideRequest(int $nid, Request $request): JsonResponse {
    $accessError = $this->checkNodeJurisdictionAccess($nid, $request);
    if ($accessError) {
      return $accessError;
    }

    try {
      $this->moderationService->hideRequest($nid, (int) $this->currentUser()->id());
      return new JsonResponse(['success' => TRUE]);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_moderation')->error('Hide request failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'An internal error occurred.'], 500);
    }
  }

  /**
   * Deletes a service request node and dismisses its flags.
   *
   * Requires 'administer all flags' permission (enforced by routing).
   *
   * @param int $nid
   *   The node ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success.
   */
  public function deleteRequest(int $nid, Request $request): JsonResponse {
    // Even super-admins get jurisdiction-checked for destructive actions.
    $accessError = $this->checkNodeJurisdictionAccess($nid, $request);
    if ($accessError) {
      return $accessError;
    }

    try {
      $nodeStorage = $this->entityTypeManager()->getStorage('node');
      $node = $nodeStorage->load($nid);

      if (!$node) {
        return new JsonResponse(['error' => 'Node not found.'], 404);
      }

      // Only allow deleting service_request nodes.
      if ($node->bundle() !== 'service_request') {
        return new JsonResponse(['error' => 'Only service requests can be deleted.'], 400);
      }

      // Dismiss flags for cleanup before deleting the node.
      $this->moderationService->dismissFlags($nid, (int) $this->currentUser()->id());

      // Delete the node entity.
      $node->delete();

      $this->getLogger('markaspot_moderation')->notice('Service request nid=@nid deleted by uid=@uid.', [
        '@nid' => $nid,
        '@uid' => $this->currentUser()->id(),
      ]);

      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      $this->getLogger('markaspot_moderation')->error('Delete request failed for nid=@nid: @error', [
        '@nid' => $nid,
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'An internal error occurred.'], 500);
    }
  }

  /**
   * Resolves jurisdiction IDs based on the current user's permissions.
   *
   * Users with 'administer all flags' can filter by any jurisdiction.
   * Users with 'administer flags' are scoped to their tenant admin
   * jurisdictions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return int[]
   *   Array of jurisdiction group IDs.
   */
  protected function resolveJurisdictionIds(Request $request): array {
    $currentUser = $this->currentUser();

    if ($currentUser->hasPermission('administer all flags')) {
      // Super admin: allow filtering by specific jurisdiction or return all.
      $jurisdictionId = $request->query->get('jurisdiction_id');
      if ($jurisdictionId) {
        return [(int) $jurisdictionId];
      }
      // Return all jurisdiction IDs. Query the group table for jur groups.
      $groupStorage = $this->entityTypeManager()->getStorage('group');
      $ids = $groupStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $this->getJurisdictionGroupType())
        ->execute();
      return array_map('intval', $ids);
    }

    // Tenant admin: scoped to their jurisdictions.
    return TenantAdminHelper::getUserJurisdictionIds($currentUser);
  }

  /**
   * Checks if the current user has jurisdiction access to a specific node.
   *
   * Users with 'administer all flags' always have access. Tenant admins
   * are verified against the node's jurisdiction group membership.
   *
   * @param int $nid
   *   The node ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response if access is denied, or NULL if access is granted.
   */
  protected function checkNodeJurisdictionAccess(int $nid, Request $request): ?JsonResponse {
    $currentUser = $this->currentUser();

    // Super admins bypass jurisdiction check.
    if ($currentUser->hasPermission('administer all flags')) {
      return NULL;
    }

    $jurisdictionIds = TenantAdminHelper::getUserJurisdictionIds($currentUser);
    if (empty($jurisdictionIds)) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    // Resolve the node's jurisdiction.
    $relationshipStorage = $this->entityTypeManager()->getStorage('group_relationship');
    $relationships = $relationshipStorage->loadByProperties([
      'entity_id' => $nid,
      'plugin_id' => 'group_node:service_request',
    ]);

    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if ($this->isJurisdictionGroup($group)) {
        if (in_array((int) $group->id(), $jurisdictionIds, TRUE)) {
          return NULL;
        }
      }
    }

    return new JsonResponse(['error' => 'Access denied.'], 403);
  }

}
