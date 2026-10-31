<?php

namespace Drupal\markaspot_vision\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupRelationshipInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies dashboard staff sessions without trusting shared API accounts.
 */
class StaffAnalysisAccess {

  /**
   * Constructs the staff analysis access resolver.
   */
  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a private session identifier for authenticated dashboard staff.
   */
  public function getRateLimitIdentifier(Request $request): ?string {
    if (!$this->currentUser->isAuthenticated() || !$request->hasSession()) {
      return NULL;
    }

    try {
      $session = $request->getSession();
      $session_id = $session->getId();
      if ($session_id === '' || (int) $session->get('uid', 0) !== (int) $this->currentUser->id()) {
        return NULL;
      }

      if ($this->hasDashboardAccess()) {
        // Staff budgets follow the real session, never a caller-chosen CSRF
        // header. Separate sessions of the same account get separate budgets.
        return 'sid:' . hash_hmac('sha256', $session_id, Settings::getHashSalt());
      }
    }
    catch (\Throwable) {
      // Incomplete session or membership state must not grant a larger budget.
    }

    return NULL;
  }

  /**
   * Matches the existing global and jurisdiction dashboard staff capabilities.
   */
  protected function hasDashboardAccess(): bool {
    // Shipped moderator, editorial_board and tenant_admin roles have this
    // restricted dashboard permission; administrators inherit it as well.
    // Legacy/custom editorial roles may instead carry report editing rights.
    if ($this->currentUser->hasPermission('access dashboard notifications')
      || $this->currentUser->hasPermission('administer nodes')
      || $this->currentUser->hasPermission('edit any service_request content')) {
      return TRUE;
    }

    if (!$this->entityTypeManager->hasDefinition('group_relationship')) {
      return FALSE;
    }

    $jurisdiction_type = $this->configFactory->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type') ?: 'jur';
    $memberships = $this->entityTypeManager->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $this->currentUser->id(),
        'plugin_id' => 'group_membership',
      ]);
    foreach ($memberships as $membership) {
      if (!$membership instanceof GroupRelationshipInterface) {
        continue;
      }
      $group = $membership->getGroup();
      // The Nuxt dashboard admits elevated jurisdiction memberships, not plain
      // jur-member, derived jur-org_member or organisation-only memberships.
      // Unpublished-report access distinguishes jur-moderator, jur-editorial,
      // jur-tenant_admin and group admins without hard-coding their role IDs.
      if ($group->bundle() === $jurisdiction_type
        && $group->hasPermission('view unpublished group_node:service_request entity', $this->currentUser)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
