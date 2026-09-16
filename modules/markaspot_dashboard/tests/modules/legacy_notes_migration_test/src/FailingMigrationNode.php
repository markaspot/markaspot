<?php

declare(strict_types=1);

namespace Drupal\legacy_notes_migration_test;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\Entity\Node;

/**
 * Injects a failure after the paragraph write but before the node revision.
 */
final class FailingMigrationNode extends Node {

  /**
   * {@inheritdoc}
   */
  public function preSaveRevision(EntityStorageInterface $storage, \stdClass $record) {
    if (\Drupal::state()->get('legacy_notes_test.fail_revision')) {
      throw new \RuntimeException('Injected revision failure.');
    }
    parent::preSaveRevision($storage, $record);
  }

}
