<?php

declare(strict_types=1);

namespace Drupal\markaspot_cap\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Allows CAP feeds only for the explicitly resolved active jurisdiction.
 */
class EmergencyActiveAccessCheck implements AccessInterface {

  /**
   * Constructs the CAP access checker.
   */
  public function __construct(
    protected EmergencyModeService $emergencyService,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Checks jurisdiction-scoped emergency state.
   */
  public function access(Route $route, AccountInterface $account) {
    $request = $this->requestStack->getCurrentRequest();
    try {
      $parameters = $request?->query->all() ?? [];
      $identifier = $parameters['jurisdiction_id'] ?? NULL;
      if ($identifier !== NULL && !is_string($identifier) && !is_int($identifier)) {
        throw new \InvalidArgumentException('The jurisdiction_id parameter must be a scalar ID or slug.');
      }
      $rootId = $this->emergencyService->resolveRootJurisdictionId(
        $identifier,
      );
      $isActive = $this->emergencyService->isActive($rootId);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      return AccessResult::forbidden('A valid jurisdiction_id is required.')
        ->addCacheTags([EmergencyModeService::CACHE_TAG])
        ->addCacheContexts(['url.query_args:jurisdiction_id']);
    }

    return AccessResult::allowedIf($isActive)
      ->addCacheTags([
        EmergencyModeService::CACHE_TAG,
        EmergencyModeService::cacheTag($rootId),
      ])
      ->addCacheContexts(['url.query_args:jurisdiction_id']);
  }

}
