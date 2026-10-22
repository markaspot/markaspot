<?php

declare(strict_types=1);

namespace Drupal\markaspot_privacy;

use Drupal\node\NodeInterface;

/**
 * Removes the reporter email from every retained revision of a report.
 */
interface ReportEmailErasureInterface {

  /**
   * Unpublishes a report and erases its current and historical email values.
   */
  public function erase(NodeInterface $node): void;

}
