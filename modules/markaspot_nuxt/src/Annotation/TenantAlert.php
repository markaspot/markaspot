<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a TenantAlert annotation object.
 *
 * Tenant alerts surface actionable workspace-state issues to tenant
 * administrators in the dashboard (sidebar indicator + alerts center
 * slideover). Each alert source is a plugin: it inspects the jurisdiction
 * group and the calling account, and either returns a structured alert
 * payload (translation keys, CTA path template, severity) or NULL when no
 * action is required.
 *
 * Per-user "handled" state lives in the user.data service and is layered on
 * top of the plugin result by DashboardAlertsController; plugins are stateless
 * and must not write user.data themselves.
 *
 * @Annotation
 */
class TenantAlert extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public string $id;

  /**
   * The alert category, used for grouping in the UI.
   *
   * One of: setup, billing, integration, health.
   *
   * @var string
   */
  public string $category = 'setup';

  /**
   * The alert severity.
   *
   * One of: info, warning, critical.
   *
   * @var string
   */
  public string $severity = 'info';

}
