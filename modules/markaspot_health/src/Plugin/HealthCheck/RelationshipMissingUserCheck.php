<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_health\GroupIntegrityCheckBase;

/**
 * Surfaces group memberships whose user account no longer exists.
 *
 * @HealthCheck(
 *   id = "relationship_missing_user",
 *   label = @Translation("Group memberships with missing user"),
 *   severity = "warning",
 *   description = @Translation("Group membership relationships pointing at a user that no longer exists."),
 *   fix_hint = @Translation("Run drush markaspot:group:repair to clean up the orphaned membership rows."),
 * )
 */
class RelationshipMissingUserCheck extends GroupIntegrityCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function getCheckKey(): string {
    return 'relationship_missing_user';
  }

}
