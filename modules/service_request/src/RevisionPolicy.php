<?php

declare(strict_types=1);

namespace Drupal\service_request;

use Drupal\node\NodeInterface;

/**
 * Prepares revisions before composite field items save their own revisions.
 */
final class RevisionPolicy {

  /**
   * Preserves trusted metadata and attributes otherwise forced revisions.
   */
  public static function prepare(NodeInterface $node): void {
    if ($node->isNew() || $node->isNewRevision()) {
      return;
    }
    $original = $node->getOriginal();
    if ($original instanceof NodeInterface && $node->getRevisionLogMessage() === $original->getRevisionLogMessage()) {
      $node->setRevisionLogMessage('');
    }
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) \Drupal::currentUser()->id());
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
  }

}
