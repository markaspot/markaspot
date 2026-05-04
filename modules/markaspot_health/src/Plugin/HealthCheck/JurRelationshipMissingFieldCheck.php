<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_health\GroupIntegrityCheckBase;

/**
 * Surfaces jur group relationships whose field_jurisdiction mirror is missing.
 *
 * @HealthCheck(
 *   id = "jur_relationship_missing_field",
 *   label = @Translation("Jurisdiction relationship missing field mirror"),
 *   severity = "warning",
 *   description = @Translation("Group relationship rows linking a service_request to a jur group, but node.field_jurisdiction does not mirror that gid."),
 *   fix_hint = @Translation("Run drush markaspot:group:repair to mirror the missing field values."),
 * )
 */
class JurRelationshipMissingFieldCheck extends GroupIntegrityCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function getCheckKey(): string {
    return 'jur_relationship_missing_field';
  }

}
