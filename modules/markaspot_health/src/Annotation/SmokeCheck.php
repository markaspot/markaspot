<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a SmokeCheck annotation object.
 *
 * Smoke checks differ from health checks: a health check inspects current
 * configuration drift (always read-only), a smoke check exercises the running
 * tenant end-to-end. Smoke results carry a four-state status (pass/fail/skip/
 * warning), an evidence payload, and an opt-in "mutates" flag so the runner
 * can skip mutating checks when running in --mode=read-only.
 *
 * @Annotation
 */
class SmokeCheck extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public string $id;

  /**
   * The human-readable label.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * The default severity if the check fails.
   *
   * One of: error, warning, info.
   *
   * @var string
   */
  public string $severity = 'warning';

  /**
   * Catalog category, used for --category filtering.
   *
   * One of: http_sanity, drupal_internal, auth, georeport, jsonapi, media,
   * dashboard, mail, wrap.
   *
   * @var string
   */
  public string $category = 'drupal_internal';

  /**
   * TRUE if the check writes to the system.
   *
   * Mutating checks are skipped under --mode=read-only and only execute under
   * --mode=full. Opt-in to keep the prod-safe default genuinely safe.
   *
   * @var bool
   */
  public bool $mutates = FALSE;

  /**
   * Optional description of what the check exercises.
   *
   * @var \Drupal\Core\Annotation\Translation|null
   *
   * @ingroup plugin_translatable
   */
  public $description = NULL;

  /**
   * Optional human-readable hint to fix a failing check.
   *
   * @var \Drupal\Core\Annotation\Translation|null
   *
   * @ingroup plugin_translatable
   */
  public $fix_hint = NULL;

  /**
   * Optional URL pointing at a remediation page or runbook.
   *
   * @var string|null
   */
  public ?string $fix_url = NULL;

}
