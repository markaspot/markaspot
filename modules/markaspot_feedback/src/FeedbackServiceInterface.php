<?php

namespace Drupal\markaspot_feedback;

use Drupal\node\NodeInterface;

/**
 * Interface for feedback service.
 */
interface FeedbackServiceInterface {

  /**
   * Loads eligible nodes for feedback processing.
   *
   * @param int $limit
   *   The maximum number of nodes to load.
   *
   * @return array
   *   An array of node IDs.
   */
  public function loadEligibleNodes($limit = 50);

  /**
   * Processes feedback for a specific node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to process.
   *
   * @return bool
   *   TRUE if the processing was successful, FALSE otherwise.
   */
  public function processFeedbackForNode(NodeInterface $node);

  /**
   * Adds a node to the feedback processing queue.
   *
   * @param int $nid
   *   The node ID to add to the queue.
   *
   * @return bool
   *   TRUE if the node was added to the queue, FALSE otherwise.
   */
  public function queueNodeForProcessing($nid);

  /**
   * Gets statistics about feedback processing.
   *
   * @return array
   *   An array of statistics including:
   *   - total_processed: The total number of nodes processed for feedback.
   *   - last_processed_nid: The last node ID processed.
   *   - last_run_count: The number of nodes processed in the last run.
   */
  public function getStatistics();

}