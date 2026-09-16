<?php

namespace Drupal\service_request;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Resolves an organisation's opt-out from assignment email notifications.
 */
final class OrganisationNotificationPolicy {

  /**
   * Keeps legacy behaviour unless the organisation explicitly opts out.
   */
  public static function isEnabled(FieldableEntityInterface $organisation): bool {
    $field = 'field_assignment_notifications';
    if (!$organisation->hasField($field) || $organisation->get($field)->isEmpty()) {
      return TRUE;
    }
    return !in_array($organisation->get($field)->value, [FALSE, 0, '0'], TRUE);
  }

}
