<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\user\UserDataInterface;

/**
 * Persists per-user "handled" state for tenant alerts.
 *
 * Wraps the Drupal core user.data service with a fixed namespace
 * ("markaspot_nuxt") and a key shape
 * "alert_state.{alert_id}.{jurisdiction_id}" so the same alert can be marked
 * handled or unhandled per workspace without leaking state across tenants.
 *
 * The stored value is an associative array:
 * @code
 * [
 *   'handled' => bool,
 *   'handled_at' => int|null, // unix timestamp
 * ]
 * @endcode
 *
 * No new schema, no config entity: state is per-user, transient enough to
 * tolerate the user.data table semantics.
 */
class AlertStateStore {

  /**
   * The user.data namespace under which alert state is stored.
   */
  protected const MODULE = 'markaspot_nuxt';

  /**
   * The user data service.
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected UserDataInterface $userData;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructs an AlertStateStore.
   *
   * @param \Drupal\user\UserDataInterface $user_data
   *   The user data service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service used to stamp the moment a user marks an alert handled.
   */
  public function __construct(UserDataInterface $user_data, TimeInterface $time) {
    $this->userData = $user_data;
    $this->time = $time;
  }

  /**
   * Checks whether a user has marked an alert as handled.
   *
   * @param int $uid
   *   The user account ID.
   * @param string $alert_id
   *   The TenantAlert plugin ID.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE when the user has marked the alert handled for this jurisdiction.
   */
  public function isHandled(int $uid, string $alert_id, int $jurisdiction_id): bool {
    $value = $this->load($uid, $alert_id, $jurisdiction_id);
    return is_array($value) && !empty($value['handled']);
  }

  /**
   * Returns the timestamp when an alert was marked handled.
   *
   * @param int $uid
   *   The user account ID.
   * @param string $alert_id
   *   The TenantAlert plugin ID.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   *
   * @return int|null
   *   The unix timestamp when the alert was marked handled, or NULL when
   *   not handled or no timestamp is recorded.
   */
  public function getHandledAt(int $uid, string $alert_id, int $jurisdiction_id): ?int {
    $value = $this->load($uid, $alert_id, $jurisdiction_id);
    if (!is_array($value) || empty($value['handled'])) {
      return NULL;
    }
    return isset($value['handled_at']) && is_numeric($value['handled_at'])
      ? (int) $value['handled_at']
      : NULL;
  }

  /**
   * Sets the handled state for an alert.
   *
   * Setting handled to FALSE clears the handled_at timestamp so subsequent
   * reads do not surface a stale "handled at" value while the alert is open
   * again.
   *
   * @param int $uid
   *   The user account ID.
   * @param string $alert_id
   *   The TenantAlert plugin ID.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   * @param bool $handled
   *   TRUE to mark handled, FALSE to clear the handled state.
   */
  public function setHandled(int $uid, string $alert_id, int $jurisdiction_id, bool $handled): void {
    $key = $this->buildKey($alert_id, $jurisdiction_id);
    $value = [
      'handled' => $handled,
      'handled_at' => $handled ? $this->time->getRequestTime() : NULL,
    ];
    $this->userData->set(self::MODULE, $uid, $key, $value);
  }

  /**
   * Loads the raw stored value for an alert.
   *
   * @param int $uid
   *   The user account ID.
   * @param string $alert_id
   *   The TenantAlert plugin ID.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   *
   * @return mixed
   *   The decoded user.data value, or NULL when none is stored.
   */
  protected function load(int $uid, string $alert_id, int $jurisdiction_id): mixed {
    $key = $this->buildKey($alert_id, $jurisdiction_id);
    return $this->userData->get(self::MODULE, $uid, $key);
  }

  /**
   * Builds the user.data key for an alert/jurisdiction pair.
   *
   * @param string $alert_id
   *   The TenantAlert plugin ID.
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   *
   * @return string
   *   The user.data key.
   */
  protected function buildKey(string $alert_id, int $jurisdiction_id): string {
    return sprintf('alert_state.%s.%d', $alert_id, $jurisdiction_id);
  }

}
