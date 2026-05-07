<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Interface for TenantAlert plugins.
 *
 * Each plugin inspects a jurisdiction group + the calling account and either
 * returns a structured alert payload that the dashboard surface can render,
 * or NULL when no action is required.
 *
 * The returned alert is shaped so the controller can layer per-user "handled"
 * state on top without the plugin needing to know about user.data.
 */
interface TenantAlertInterface extends PluginInspectionInterface {

  /**
   * Inspects the group and returns an alert payload, or NULL.
   *
   * The returned payload uses translation KEYS (not translated strings) so
   * the controller renders them in the response language. Shape:
   * @code
   * [
   *   'id' => 'branding',
   *   'category' => 'setup',
   *   'severity' => 'info',
   *   'title_key' => 'branding_title',
   *   'body_key' => 'branding_body',
   *   'cta' => [
   *     'label_key' => 'branding_cta',
   *     'path_template' => '/dashboard/settings/branding',
   *   ],
   * ]
   * @endcode
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group the alert is being evaluated against.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user the alert is being evaluated for.
   *
   * @return array<string, mixed>|null
   *   The alert payload, or NULL when the alert does not apply.
   */
  public function check(GroupInterface $group, AccountInterface $account): ?array;

}
