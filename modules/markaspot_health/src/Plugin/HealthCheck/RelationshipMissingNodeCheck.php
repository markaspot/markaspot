<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\markaspot_health\GroupIntegrityCheckBase;

/**
 * Surfaces group relationships pointing at a deleted service_request node.
 *
 * @HealthCheck(
 *   id = "relationship_missing_node",
 *   label = @Translation("Service request relationships with missing node"),
 *   severity = "warning",
 *   description = @Translation("Group relationships pointing at a service_request node that no longer exists."),
 *   fix_hint = @Translation("Run drush markaspot:group:repair to remove the dangling relationship rows."),
 * )
 */
class RelationshipMissingNodeCheck extends GroupIntegrityCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function getCheckKey(): string {
    return 'relationship_missing_node';
  }

}
