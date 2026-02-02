<?php

namespace Drupal\markaspot_nuxt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller to fetch the aggregated vote sum using the Entity Query API.
 */
class VotingApiController extends ControllerBase {

  /**
   * Returns the aggregated vote sum for a node by UUID.
   *
   * @param string $uuid
   *   The UUID of the node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the node ID and vote sum.
   */
  public function voteSum(string $uuid): JsonResponse {
    // Load the node by UUID.
    $nodes = $this->entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]);
    if (empty($nodes)) {
      throw new NotFoundHttpException('Node not found');
    }
    $node = reset($nodes);
    $nid = $node->id();

    // Use the entity query to get the VoteResult entity IDs.
    $query = $this->entityTypeManager()->getStorage('vote_result')->getQuery();
    $query->accessCheck(FALSE);
    $ids = $query
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nid)
      ->condition('function', 'vote_sum')
      ->execute();

    $vote_sum = 0;
    if (!empty($ids)) {
      $vote_results = $this->entityTypeManager()
        ->getStorage('vote_result')
        ->loadMultiple($ids);
      $vote_result = reset($vote_results);
      if ($vote_result && $vote_result->hasField('value')) {
        $vote_sum = $vote_result->get('value')->value;
      }
    }

    return new JsonResponse([
      'uuid' => $uuid,
      'nid' => $nid,
      'vote_sum' => $vote_sum,
    ]);
  }

}
