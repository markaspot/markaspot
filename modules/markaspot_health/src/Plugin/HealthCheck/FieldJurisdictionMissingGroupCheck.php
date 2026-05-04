<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_health\GroupIntegrityCheckBase;

/**
 * Surfaces field_jurisdiction values pointing at missing/wrong-bundle groups.
 *
 * @HealthCheck(
 *   id = "field_jurisdiction_missing_group",
 *   label = @Translation("Jurisdiction field points to missing or wrong group"),
 *   severity = "warning",
 *   description = @Translation("service_request.field_jurisdiction values pointing at a missing group or a group with the wrong bundle."),
 *   fix_hint = @Translation("Edit the affected nodes or run drush markaspot:group:repair."),
 * )
 */
class FieldJurisdictionMissingGroupCheck extends GroupIntegrityCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function getCheckKey(): string {
    return 'field_jurisdiction_missing_group';
  }

}
