<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_health\GroupIntegrityCheckBase;

/**
 * Surfaces dangling group_relationship rows whose target group is gone.
 *
 * @HealthCheck(
 *   id = "relationship_missing_group",
 *   label = @Translation("Group relationships with missing group"),
 *   severity = "warning",
 *   description = @Translation("Group relationship rows whose gid no longer exists in the groups table."),
 *   fix_hint = @Translation("Run drush markaspot:health and inspect the affected entity_ids in the dashboard, or repair via drush markaspot:group:repair."),
 * )
 */
class RelationshipMissingGroupCheck extends GroupIntegrityCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function getCheckKey(): string {
    return 'relationship_missing_group';
  }

}
