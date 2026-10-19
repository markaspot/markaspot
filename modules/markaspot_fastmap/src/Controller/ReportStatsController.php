<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reports and removes the demo service requests of a jurisdiction.
 *
 * Powers the frontend onboarding "first real report" step. A "demo report"
 * is a service_request node whose body carries the literal "[demo-content]"
 * marker (seeded at workspace provisioning); "real" reports are everything
 * else. The response is strictly jurisdiction-scoped and gated to the
 * jurisdiction's own tenant admins (plus site administrators), so no tenant
 * can read another tenant's counts.
 */
class ReportStatsController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * The tier configuration service.
   *
   * @var \Drupal\markaspot_fastmap\Service\TierConfigService
   */
  protected TierConfigService $tierConfig;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->tierConfig = $container->get('markaspot_fastmap.tier_config');
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * Loads a jurisdiction group from a slug or numeric ID.
   *
   * @param string $identifier
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The group entity, or NULL if not found or not a jurisdiction.
   */
  private function loadJurisdictionGroup(string $identifier) {
    $resolved_id = $this->resolveJurisdictionId($identifier);
    if ($resolved_id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager()
      ->getStorage('group')
      ->load($resolved_id);
    if (!$this->isJurisdictionGroup($group)) {
      return NULL;
    }

    return $group;
  }

  /**
   * Access check: validates group exists and user owns this jurisdiction.
   *
   * Prevents cross-tenant enumeration by verifying group bundle and requiring
   * site-admin status or a tenant_admin membership of THIS jurisdiction.
   * Mirrors WorkspaceUsageController::access() so the two dashboard endpoints
   * share one access posture.
   *
   * @param string $group
   *   The jurisdiction identifier (numeric ID or slug).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function access(string $group, AccountInterface $account): AccessResultInterface {
    $entity = $this->loadJurisdictionGroup($group);
    if (!$entity) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.query_args:jurisdiction_id']);
    }

    // Site administrators can view any workspace's report stats.
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()
        ->addCacheContexts(['user'])
        ->addCacheableDependency($entity);
    }
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.roles'])
        ->addCacheableDependency($entity);
    }

    // Basic permission gate (granted to authenticated + staff roles).
    $hasPermission = AccessResult::allowedIfHasPermission(
      $account,
      'access workspace usage'
    );
    if (!$hasPermission->isAllowed()) {
      return $hasPermission;
    }

    // Non-admins must be a tenant_admin of THIS group. Regular members
    // (invited moderators) must not read report stats of a workspace they
    // do not administer.
    $membership = GroupMembership::loadSingle($entity, $account);
    $membership_cache_tags = [
      'group_relationship_list:plugin:group_membership:group:' . $entity->id(),
      'group_relationship_list:plugin:group_membership:entity:' . $account->id(),
    ];
    $isTenantAdmin = FALSE;
    if ($membership instanceof GroupMembershipInterface) {
      foreach ($membership->getRoles(FALSE) as $role) {
        if ($this->isJurisdictionRole($role, 'tenant_admin')) {
          $isTenantAdmin = TRUE;
          break;
        }
      }
    }
    $isOwner = AccessResult::allowedIf($isTenantAdmin)
      ->addCacheContexts(['user'])
      ->addCacheableDependency($entity)
      ->addCacheTags($membership_cache_tags);
    if ($membership instanceof GroupMembershipInterface) {
      $isOwner->addCacheableDependency($membership);
    }

    return $hasPermission->andIf($isOwner);
  }

  /**
   * Returns total / demo / real report counts for a jurisdiction.
   *
   * The response JSON contract (consumed verbatim by the frontend) is:
   * {
   *   "total_requests": <int>,
   *   "demo_request_count": <int>,
   *   "real_request_count": <int>
   * }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request; reads the "jurisdiction_id" query parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with total_requests, demo_request_count and real_request_count.
   */
  public function stats(Request $request): JsonResponse {
    $identifier = (string) $request->query->get('jurisdiction_id', '');
    if ($identifier === '') {
      return new JsonResponse(
        ['error' => 'Missing required query parameter: jurisdiction_id'],
        400
      );
    }

    $entity = $this->loadJurisdictionGroup($identifier);
    if (!$entity) {
      return new JsonResponse(['error' => 'Jurisdiction not found'], 404);
    }

    if (!$this->access($identifier, $this->currentUser())->isAllowed()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    return new JsonResponse($this->tierConfig->getReportStats((int) $entity->id()));
  }

  /**
   * Deletes the provisioned demo requests of a jurisdiction.
   *
   * Only nodes that provisioning created are removed: service requests of
   * this jurisdiction, owned by uid 1 (the provisioning owner) and carrying
   * the "[demo-content]" marker. A citizen can type the marker into a report,
   * so the marker alone never qualifies a node for deletion.
   *
   * Request body: {"jurisdiction_id": <numeric group ID>}. Response:
   * {"removed": <int>, "demo_request_count": <remaining int>}.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   200 with the counts, 400 for a missing or invalid ID, 403 without
   *   tenant admin access. An unknown jurisdiction is 404 for platform admins
   *   and 403 for everyone else, so the endpoint cannot enumerate group IDs.
   */
  public function removeDemoContent(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $id = is_array($data) ? ($data['jurisdiction_id'] ?? NULL) : NULL;
    if (is_string($id) && ctype_digit($id)) {
      $id = (int) $id;
    }
    if (!is_int($id) || $id <= 0) {
      return new JsonResponse(['error' => 'Missing or invalid jurisdiction_id'], 400);
    }

    $account = $this->currentUser();
    if (!$this->loadJurisdictionGroup((string) $id)) {
      $isPlatformAdmin = (int) $account->id() === 1 || in_array('administrator', $account->getRoles(), TRUE);
      return $isPlatformAdmin
        ? new JsonResponse(['error' => 'Jurisdiction not found'], 404)
        : new JsonResponse(['error' => 'Access denied'], 403);
    }

    if (!$this->access((string) $id, $account)->isAllowed()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $storage = $this->entityTypeManager()->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_jurisdiction', $id)
      ->condition('uid', 1)
      ->condition('body.value', '%' . $this->database->escapeLike('[demo-content]') . '%', 'LIKE')
      ->execute();

    $removed = 0;
    foreach (array_chunk($nids, 50) as $chunk) {
      $nodes = $storage->loadMultiple($chunk);
      $storage->delete($nodes);
      $removed += count($nodes);
    }

    $this->getLogger('markaspot_fastmap')->notice('Removed @count demo requests from jurisdiction @gid (user @uid).', [
      '@count' => $removed,
      '@gid' => $id,
      '@uid' => $account->id(),
    ]);

    return new JsonResponse([
      'removed' => $removed,
      'demo_request_count' => $this->tierConfig->getReportStats($id)['demo_request_count'],
    ]);
  }

}
