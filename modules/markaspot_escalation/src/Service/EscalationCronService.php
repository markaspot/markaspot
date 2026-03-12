<?php

namespace Drupal\markaspot_escalation\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Processes automatic time-based escalation of overdue service requests.
 */
class EscalationCronService implements EscalationCronServiceInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The escalation service.
   *
   * @var \Drupal\markaspot_escalation\Service\EscalationServiceInterface
   */
  protected EscalationServiceInterface $escalationService;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an EscalationCronService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\markaspot_escalation\Service\EscalationServiceInterface $escalationService
   *   The escalation service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EscalationServiceInterface $escalationService,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->escalationService = $escalationService;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function processEscalations(): int {
    $count = 0;
    $now = $this->time->getRequestTime();

    // Get closed status term IDs to exclude.
    $open311Config = $this->configFactory->get('markaspot_open311.settings');
    $closedStatuses = $open311Config->get('status_closed') ?: [];
    $closedTids = array_map('intval', array_values($closedStatuses));

    // Query: all service_request nodes that are assigned to an org,
    // not yet escalated, published, and not closed.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->exists('field_organisation')
      ->notExists('field_escalation')
      ->condition('status', 1);

    // Exclude closed statuses.
    if (!empty($closedTids)) {
      $query->condition('field_status', $closedTids, 'NOT IN');
    }

    $nids = $query->execute();
    if (empty($nids)) {
      return 0;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($nids as $nid) {
      $node = $nodeStorage->load($nid);
      if (!$node) {
        continue;
      }

      // Get the category term.
      if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
        continue;
      }

      $categoryTerm = $node->get('field_category')->entity;
      if (!$categoryTerm) {
        continue;
      }

      // Check if category has escalation config.
      if (!$categoryTerm->hasField('field_escalation_target')
          || $categoryTerm->get('field_escalation_target')->isEmpty()) {
        continue;
      }

      if (!$categoryTerm->hasField('field_escalation_days')
          || $categoryTerm->get('field_escalation_days')->isEmpty()) {
        continue;
      }

      $escalationDays = (int) $categoryTerm->get('field_escalation_days')->value;
      if ($escalationDays <= 0) {
        continue;
      }

      // Check if the node has been unchanged for longer than escalation_days.
      $changedTimestamp = (int) $node->getChangedTime();
      $thresholdTimestamp = $now - ($escalationDays * 86400);

      if ($changedTimestamp > $thresholdTimestamp) {
        // Node was updated recently, skip.
        continue;
      }

      // Resolve escalation target.
      $targetJurId = $this->escalationService->resolveEscalationTarget($node);
      if ($targetJurId === NULL) {
        continue;
      }

      // Perform the escalation.
      try {
        $note = (string) $this->t(
          'Automatically escalated: no status update within @days days.',
          ['@days' => $escalationDays]
        );
        $this->escalationService->escalateRequest($node, $targetJurId, $note);
        $count++;

        $this->logger->notice('Auto-escalated service request @nid (unchanged for @days days).', [
          '@nid' => $nid,
          '@days' => $escalationDays,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to auto-escalate service request @nid: @error', [
          '@nid' => $nid,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    if ($count > 0) {
      $this->logger->notice('Auto-escalation cron completed: @count requests escalated.', [
        '@count' => $count,
      ]);
    }

    return $count;
  }

}
