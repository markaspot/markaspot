<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_health\GroupIntegrityCheckBase;

/**
 * Surfaces org group relationships whose field_organisation mirror is missing.
 *
 * @HealthCheck(
 *   id = "org_relationship_missing_field",
 *   label = @Translation("Organisation relationship missing field mirror"),
 *   severity = "warning",
 *   description = @Translation("Group relationship rows linking a service_request to an org group, but node.field_organisation does not mirror that gid."),
 *   fix_hint = @Translation("Run drush markaspot:group:repair to mirror the missing field values."),
 * )
 */
class OrgRelationshipMissingFieldCheck extends GroupIntegrityCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function getCheckKey(): string {
    return 'org_relationship_missing_field';
  }

}
