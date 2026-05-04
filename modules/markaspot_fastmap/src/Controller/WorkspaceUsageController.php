<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns workspace usage data for dashboard display.
 *
 * Accepts both numeric group IDs and URL slugs via the {group}
 * path parameter.
 */
class WorkspaceUsageController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * The tier configuration service.
   *
   * @var \Drupal\markaspot_fastmap\Service\TierConfigService
   */
  protected TierConfigService $tierConfig;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->tierConfig = $container->get('markaspot_fastmap.tier_config');
    return $instance;
  }

  /**
   * Loads a jurisdiction group from a slug or numeric ID.
   *
   * @param string $identifier
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The group entity, or NULL if not found.
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
   * Access check: validates group exists and user has permission.
   *
   * Prevents cross-tenant usage enumeration by verifying group
   * bundle and requiring group membership or admin permission.
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
        ->addCacheContexts(['url.path']);
    }

    // Site administrators can view any workspace usage.
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

    // Check basic permission.
    $hasPermission = AccessResult::allowedIfHasPermission(
      $account,
      'access workspace usage'
    );
    if (!$hasPermission->isAllowed()) {
      return $hasPermission;
    }

    // Non-admins must be a tenant_admin of this group. Regular members
    // (invited moderators) must not access billing or usage data.
    $membership = $entity->getMember($account);
    $membership_cache_tags = [
      'group_relationship_list:plugin:group_membership:group:' . $entity->id(),
      'group_relationship_list:plugin:group_membership:entity:' . $account->id(),
    ];
    $isTenantAdmin = FALSE;
    if ($membership) {
      foreach ($membership->getRoles() as $role) {
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
    if ($membership) {
      $isOwner->addCacheableDependency($membership);
    }

    return $hasPermission->andIf($isOwner);
  }

  /**
   * Returns usage data for a jurisdiction.
   *
   * @param string $group
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with tier, limit, count, and usage data.
   */
  public function usage(string $group): JsonResponse {
    $entity = $this->loadJurisdictionGroup($group);
    if (!$entity) {
      return new JsonResponse(
        ['error' => 'Jurisdiction not found'],
        404
      );
    }
    if (!$this->access($group, $this->currentUser())->isAllowed()) {
      return new JsonResponse(
        ['error' => 'Access denied'],
        403
      );
    }

    $tier = 'free';
    if ($entity->hasField('field_tier') && !$entity->get('field_tier')->isEmpty()) {
      $tier = $entity->get('field_tier')->value;
    }

    $cache = new CacheableMetadata();
    $cache->setCacheMaxAge(60);
    $cache->addCacheTags([
      'node_list:service_request',
      'group:' . $entity->id(),
      'config:markaspot_fastmap.settings',
    ]);

    $tierLimits = $this->tierConfig->getLimits($tier);

    if ($tierLimits === NULL || !empty($tierLimits['unlimited'])) {
      $count = $tierLimits !== NULL
        ? $this->tierConfig->countRequests(
          (int) $entity->id(),
          $tierLimits['period']
        )
        : NULL;

      $response = new CacheableJsonResponse([
        'tier' => $tier,
        'limit' => NULL,
        'count' => $count,
        'period' => $tierLimits['period'] ?? NULL,
        'unlimited' => TRUE,
      ]);
      $response->addCacheableDependency($cache);
      return $response;
    }

    $count = $this->tierConfig->countRequests(
      (int) $entity->id(),
      $tierLimits['period']
    );

    $response_data = [
      'tier' => $tier,
      'limit' => $tierLimits['limit'],
      'count' => $count,
      'period' => $tierLimits['period'],
      'unlimited' => FALSE,
      'remaining' => max(0, $tierLimits['limit'] - $count),
      'percentage' => min(
        100,
        (int) round(($count / $tierLimits['limit']) * 100)
      ),
    ];

    $response = new CacheableJsonResponse($response_data);
    $response->addCacheableDependency($cache);
    return $response;
  }

}
