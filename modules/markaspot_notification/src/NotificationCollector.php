<?php

declare(strict_types=1);

namespace Drupal\markaspot_notification;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Accumulates notifications in PrivateTempStore keyed by node UUID.
 *
 * Services that produce side effects (e.g. mail sent, group assigned) call
 * record() during the request. The frontend drains them once via the REST
 * endpoint. The current node UUID is carried as a plain PHP property so that
 * the RequestContextSubscriber can seed it from the inbound path before any
 * ECA action runs.
 */
class NotificationCollector {

  private const COLLECTION = 'markaspot_notification';
  private const KEY_PREFIX = 'notifications:';

  /**
   * UUID of the node currently being processed, set per-request.
   */
  private ?string $currentNodeUuid = NULL;

  /**
   * Constructs a NotificationCollector.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStore
   *   The private temp store factory.
   */
  public function __construct(
    private readonly PrivateTempStoreFactory $tempStore,
  ) {}

  /**
   * Records a notification for a node UUID.
   *
   * @param string $nodeUuid
   *   The UUID of the service_request node.
   * @param string $type
   *   Notification type identifier (e.g. 'mail_sent', 'group_assigned').
   * @param array $data
   *   Additional payload data for the notification.
   */
  public function record(string $nodeUuid, string $type, array $data): void {
    $store = $this->tempStore->get(self::COLLECTION);
    $key = self::KEY_PREFIX . $nodeUuid;
    $existing = $store->get($key) ?? [];
    $existing[] = array_merge(['type' => $type], $data);
    $store->set($key, $existing);
  }

  /**
   * Drains and returns all notifications for a node UUID.
   *
   * Clears the stored notifications after reading so subsequent calls return
   * an empty array (drain semantics).
   *
   * @param string $nodeUuid
   *   The UUID of the service_request node.
   *
   * @return array
   *   Array of notification entries, each with at least a 'type' key.
   */
  public function drain(string $nodeUuid): array {
    $store = $this->tempStore->get(self::COLLECTION);
    $key = self::KEY_PREFIX . $nodeUuid;
    $data = $store->get($key) ?? [];
    if ($data) {
      $store->delete($key);
    }
    return $data;
  }

  /**
   * Sets the UUID of the node currently being processed.
   *
   * Called by RequestContextSubscriber on kernel.request so downstream
   * recorders (NotifyMailManager, hook_group_relationship_insert) can
   * associate their events with the right node.
   *
   * @param string $uuid
   *   The node UUID parsed from the JSON:API path.
   */
  public function setCurrentNodeUuid(string $uuid): void {
    $this->currentNodeUuid = $uuid;
  }

  /**
   * Returns the UUID of the node currently being processed.
   *
   * @return string|null
   *   The node UUID, or NULL if not set for this request.
   */
  public function getCurrentNodeUuid(): ?string {
    return $this->currentNodeUuid;
  }

}
