<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns workspace usage data for dashboard display.
 */
class WorkspaceUsageController extends ControllerBase {

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
   * Access check: user must have permission AND the group must be a jur.
   *
   * Prevents cross-tenant usage enumeration by verifying group bundle and
   * requiring either group membership or admin permission.
   */
  public function access(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    // Only jur groups have tier usage data.
    if ($group->bundle() !== 'jur') {
      return AccessResult::forbidden('Not a jurisdiction group.')
        ->addCacheableDependency($group);
    }

    // Admins can view any workspace usage.
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheableDependency($group);
    }

    // Check basic permission.
    $hasPermission = AccessResult::allowedIfHasPermission($account, 'access workspace usage');
    if (!$hasPermission->isAllowed()) {
      return $hasPermission;
    }

    // Non-admins must be a member of this group.
    $membership = $group->getMember($account);
    $isMember = AccessResult::allowedIf($membership !== FALSE)
      ->cachePerUser()
      ->addCacheableDependency($group);

    return $hasPermission->andIf($isMember);
  }

  /**
   * Returns usage data for a jurisdiction.
   */
  public function usage(GroupInterface $group): JsonResponse {
    $tier = 'free';
    if ($group->hasField('field_tier') && !$group->get('field_tier')->isEmpty()) {
      $tier = $group->get('field_tier')->value;
    }

    $cache = new CacheableMetadata();
    $cache->setCacheMaxAge(60);
    $cache->addCacheTags([
      'node_list:service_request',
      'group:' . $group->id(),
      'config:markaspot_fastmap.settings',
    ]);

    $tierLimits = $this->tierConfig->getLimits($tier);

    if ($tierLimits === NULL) {
      $response = new CacheableJsonResponse([
        'tier' => $tier,
        'limit' => NULL,
        'count' => NULL,
        'period' => NULL,
        'unlimited' => TRUE,
      ]);
      $response->addCacheableDependency($cache);
      return $response;
    }

    $count = $this->tierConfig->countRequests((int) $group->id(), $tierLimits['period']);

    $response_data = [
      'tier' => $tier,
      'limit' => $tierLimits['limit'],
      'count' => $count,
      'period' => $tierLimits['period'],
      'unlimited' => FALSE,
      'remaining' => max(0, $tierLimits['limit'] - $count),
      'percentage' => min(100, (int) round(($count / $tierLimits['limit']) * 100)),
    ];

    $response = new CacheableJsonResponse($response_data);
    $response->addCacheableDependency($cache);
    return $response;
  }

}
