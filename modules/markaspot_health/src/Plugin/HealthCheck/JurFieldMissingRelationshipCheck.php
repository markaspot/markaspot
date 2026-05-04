<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_health\GroupIntegrityCheckBase;

/**
 * Surfaces field_jurisdiction values without a matching group relationship row.
 *
 * @HealthCheck(
 *   id = "jur_field_missing_relationship",
 *   label = @Translation("Jurisdiction field missing matching group relationship"),
 *   severity = "warning",
 *   description = @Translation("service_request.field_jurisdiction set, but no group_node:service_request relationship row exists for that gid."),
 *   fix_hint = @Translation("Run drush markaspot:group:repair to materialise the missing relationship rows."),
 * )
 */
class JurFieldMissingRelationshipCheck extends GroupIntegrityCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function getCheckKey(): string {
    return 'jur_field_missing_relationship';
  }

}
