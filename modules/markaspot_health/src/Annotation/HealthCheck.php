<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a HealthCheck annotation object.
 *
 * @Annotation
 */
class HealthCheck extends Plugin {

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
   * Optional description of what the check does.
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
