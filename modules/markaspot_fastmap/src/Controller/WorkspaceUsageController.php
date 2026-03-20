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
    if (!$group || $group->bundle() !== 'jur') {
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

    // Admins can view any workspace usage.
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()
        ->cachePerPermissions()
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

    // Non-admins must be a member of this group to prevent
    // cross-tenant usage enumeration.
    $membership = $entity->getMember($account);
    $isMember = AccessResult::allowedIf($membership !== FALSE)
      ->cachePerUser()
      ->addCacheableDependency($entity);

    return $hasPermission->andIf($isMember);
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
