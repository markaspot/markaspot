<?php

declare(strict_types=1);

namespace Drupal\markaspot_ui\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;

/**
 * Gates the privileged Drupal management surface by operating mode.
 *
 * On SaaS instances the Drupal admin UI intentionally stays admin-only: tenant
 * staff work through the Nuxt dashboard, and the cross-tenant management view
 * (bulk operations, the management edit form) must not turn the Drupal backend
 * into a multi-tenant SaaS admin. Self-hosted installations are single tenant,
 * so their editorial roles keep the full management surface.
 *
 * The mode is read from $settings['markaspot_operating_mode'] (the cloud image
 * maps MARKASPOT_OPERATING_MODE to it) and defaults to self_hosted.
 */
final class ManagementAccessGate {

  /**
   * Permission marking a trusted operator allowed to manage all content.
   *
   * On SaaS this separates internal operators (administrator, editorial_board)
   * from tenant-facing roles such as moderator, which lack it.
   */
  private const TRUSTED_OPERATOR_PERMISSION = 'administer nodes';

  /**
   * Constructs the management access gate.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Whether the current account may use the full management surface.
   *
   * @return bool
   *   TRUE on self-hosted installations, and on SaaS only for trusted
   *   operators. FALSE hides bulk operations and the management edit form.
   */
  public function allowFullManagement(): bool {
    if (Settings::get('markaspot_operating_mode', 'self_hosted') !== 'saas') {
      return TRUE;
    }
    return $this->currentUser->hasPermission(self::TRUSTED_OPERATOR_PERMISSION);
  }

}
