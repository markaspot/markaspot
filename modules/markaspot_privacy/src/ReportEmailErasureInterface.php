<?php

declare(strict_types=1);

namespace Drupal\markaspot_privacy;

use Drupal\node\NodeInterface;

/**
 * Removes reporter email and feedback from every retained report revision.
 */
interface ReportEmailErasureInterface {

  /**
   * Unpublishes a report and erases current and historical email and feedback.
   */
  public function erase(NodeInterface $node): void;

}
