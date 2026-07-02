<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Thin storage wrapper for the markaspot_request_links table.
 */
final class RequestLinkService implements RequestLinkServiceInterface {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function storeLink(int $sourceNid, int $targetNid, int $uid, string $linkType = 'split'): void {
    $this->database->insert('markaspot_request_links')
      ->fields([
        'source_nid' => $sourceNid,
        'target_nid' => $targetNid,
        'link_type' => $linkType,
        'uid' => $uid,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getLinksForNode(int $nid, string $linkType = 'split'): array {
    $orGroup = $this->database->condition('OR')
      ->condition('source_nid', $nid)
      ->condition('target_nid', $nid);

    $result = $this->database->select('markaspot_request_links', 'l')
      ->fields('l', ['source_nid', 'target_nid', 'link_type', 'uid', 'created'])
      ->condition($orGroup)
      ->condition('link_type', $linkType)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    $rows = [];
    foreach ($result as $row) {
      $rows[] = [
        'source_nid' => (int) $row->source_nid,
        'target_nid' => (int) $row->target_nid,
        'link_type' => (string) $row->link_type,
        'uid' => (int) $row->uid,
        'created' => (int) $row->created,
      ];
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteForNode(int $nid): int {
    try {
      $orGroup = $this->database->condition('OR')
        ->condition('source_nid', $nid)
        ->condition('target_nid', $nid);

      return (int) $this->database->delete('markaspot_request_links')
        ->condition($orGroup)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to purge request links for node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

}
