<?php

namespace Drupal\markaspot_escalation\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_open311\Service\StatusClassifier;
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
   * The shared Open311 status classifier.
   */
  protected StatusClassifier $statusClassifier;

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
   * @param \Drupal\markaspot_open311\Service\StatusClassifier $statusClassifier
   *   The shared Open311 status classifier.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EscalationServiceInterface $escalationService,
    StatusClassifier $statusClassifier,
    TimeInterface $time,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->escalationService = $escalationService;
    $this->statusClassifier = $statusClassifier;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function processEscalations(): int {
    $count = 0;
    $now = $this->time->getRequestTime();

    // Term semantics and legacy config both contribute closed status IDs.
    $closedTids = $this->statusClassifier->closedTids();

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
      $targetGroupId = $this->escalationService->resolveEscalationTarget($node);
      if ($targetGroupId === NULL) {
        continue;
      }

      // Perform the escalation.
      try {
        $note = (string) $this->t(
          'Automatically escalated: no status update within @days days.',
          ['@days' => $escalationDays]
        );
        $this->escalationService->escalateRequest($node, $targetGroupId, $note);
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
