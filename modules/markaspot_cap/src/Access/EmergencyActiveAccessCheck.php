<?php

namespace Drupal\markaspot_cap\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\Routing\Route;

/**
 * Access checker that grants access only when emergency mode is active.
 *
 * Gating on the route prevents URL-decoding bypass attacks: Drupal normalizes
 * the path before routing, so '/api/c%61p/v1/alerts' resolves to the same
 * route as '/api/cap/v1/alerts' and hits this check. A regex on the raw
 * path-info would miss the percent-encoded variant.
 *
 * Register as service tag 'access_check' with applies_to
 * '_cap_emergency_active'.
 */
class EmergencyActiveAccessCheck implements AccessInterface {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Checks access.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check access for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, cacheable on the emergency status tag.
   */
  public function access(Route $route, AccountInterface $account) {
    $emergencyStatus = (string) $this->state->get('markaspot_emergency.status', 'off');
    return AccessResult::allowedIf($emergencyStatus === 'active')
      ->addCacheTags(['markaspot_emergency:status']);
  }

}
