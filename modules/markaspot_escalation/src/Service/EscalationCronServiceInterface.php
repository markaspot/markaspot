<?php

namespace Drupal\markaspot_escalation\Service;

/**
 * Interface for the escalation cron service.
 */
interface EscalationCronServiceInterface {

  /**
   * Processes automatic escalations for overdue service requests.
   *
   * Finds all service requests that are assigned to an organisation, not yet
   * escalated, and overdue based on the category's field_escalation_days.
   * Escalates each matching request to the configured target jurisdiction.
   *
   * @return int
   *   The number of requests that were escalated.
   */
  public function processEscalations(): int;

}
