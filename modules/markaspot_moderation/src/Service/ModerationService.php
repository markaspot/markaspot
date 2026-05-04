<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Psr\Log\LoggerInterface;

/**
 * Service for managing content flags on citizen report service requests.
 *
 * Handles flag creation, threshold-based auto-hiding, email notifications,
 * and flag lifecycle management (dismiss, hide, query). Implements DSA
 * Article 16 compliant notice-and-action for offensive/personal content.
 */
class ModerationService implements ModerationServiceInterface {

  use JurisdictionIdResolverTrait;
  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * Constructs a ModerationService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    MailManagerInterface $mailManager,
    LoggerInterface $logger,
    TimeInterface $time,
    AccountInterface $currentUser,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
    $this->mailManager = $mailManager;
    $this->logger = $logger;
    $this->time = $time;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public function createFlag(string $serviceRequestId, string $reason, ?string $details, string $ipHash, ?string $sessionHash): int {
    // Load the node by its Open311 service request ID.
    // The request_id is a computed base field on the service_request node type,
    // resolved via EntityStorageInterface::loadByProperties().
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadByProperties(['request_id' => $serviceRequestId]);

    if (empty($nodes)) {
      throw new \InvalidArgumentException(
        sprintf('No service request found for ID "%s".', $serviceRequestId)
      );
    }

    $node = reset($nodes);
    $nid = (int) $node->id();

    // Resolve the jurisdiction_id from the node's group relationships.
    $jurisdictionId = $this->resolveJurisdictionForNode($nid);

    // Duplicate flag protection: reject if this IP already flagged this node.
    $existingCount = (int) $this->database->select('markaspot_moderation_flags', 'f')
      ->condition('f.nid', $nid)
      ->condition('f.ip_hash', $ipHash)
      ->condition('f.dismissed', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($existingCount > 0) {
      throw new \LogicException('This content has already been flagged from this source.');
    }

    // Insert the flag record.
    $fid = (int) $this->database->insert('markaspot_moderation_flags')
      ->fields([
        'nid' => $nid,
        'service_request_id' => $serviceRequestId,
        'jurisdiction_id' => $jurisdictionId,
        'reason' => $reason,
        'details' => $details,
        'ip_hash' => $ipHash,
        'session_hash' => $sessionHash,
        'created' => $this->time->getRequestTime(),
        'dismissed' => 0,
      ])
      ->execute();

    $this->logger->notice('Flag @fid created for service request @sr_id (nid=@nid, reason=@reason).', [
      '@fid' => $fid,
      '@sr_id' => $serviceRequestId,
      '@nid' => $nid,
      '@reason' => $reason,
    ]);

    // Check threshold and send notifications if needed.
    $this->checkThresholdAndNotify($nid, $reason, $details);

    return $fid;
  }

  /**
   * {@inheritdoc}
   */
  public function getFlaggedRequests(array $jurisdictionIds, array $filters = [], int $limit = 50, int $offset = 0): array {
    if (empty($jurisdictionIds)) {
      return [];
    }

    // Build the main query for flagged requests grouped by nid.
    $query = $this->database->select('markaspot_moderation_flags', 'f');
    $query->addField('f', 'nid');
    $query->addField('f', 'service_request_id');
    $query->addExpression('COUNT(*)', 'flag_count');
    $query->addExpression('MAX(f.created)', 'last_flag_date');
    $query->condition('f.dismissed', 0);
    $query->condition('f.jurisdiction_id', $jurisdictionIds, 'IN');

    // Apply optional filters.
    if (!empty($filters['reason'])) {
      $query->condition('f.reason', $filters['reason']);
    }
    if (!empty($filters['start_date'])) {
      $query->condition('f.created', (int) $filters['start_date'], '>=');
    }
    if (!empty($filters['end_date'])) {
      $query->condition('f.created', (int) $filters['end_date'], '<=');
    }

    $query->groupBy('f.nid');
    $query->groupBy('f.service_request_id');

    // Apply min_count filter via HAVING.
    $minCount = !empty($filters['min_count']) ? (int) $filters['min_count'] : 1;
    $query->having('COUNT(*) >= :min_count', [':min_count' => $minCount]);

    $query->orderBy('last_flag_date', 'DESC');
    $query->range($offset, $limit);

    // JOIN with node_field_data for title and status.
    $query->join('node_field_data', 'n', 'f.nid = n.nid');
    $query->addField('n', 'title');
    $query->addField('n', 'status', 'is_published');

    $results = $query->execute()->fetchAll();

    // Batch-resolve the top reason per node to avoid N+1 queries.
    $nids = array_map(fn($row) => (int) $row->nid, $results);
    $topReasons = $this->getTopReasonsForNodes($nids);

    $enriched = [];
    foreach ($results as $row) {
      $nid = (int) $row->nid;
      $enriched[] = [
        'nid' => $nid,
        'service_request_id' => $row->service_request_id,
        'title' => $row->title,
        'flag_count' => (int) $row->flag_count,
        'top_reason' => $topReasons[$nid] ?? NULL,
        'last_flag_date' => (int) $row->last_flag_date,
        'is_published' => (bool) $row->is_published,
      ];
    }

    return $enriched;
  }

  /**
   * {@inheritdoc}
   */
  public function getFlagCountForJurisdictions(array $jurisdictionIds): int {
    if (empty($jurisdictionIds)) {
      return 0;
    }

    $query = $this->database->select('markaspot_moderation_flags', 'f');
    $query->addExpression('COUNT(DISTINCT f.nid)', 'count');
    $query->condition('f.dismissed', 0);
    $query->condition('f.jurisdiction_id', $jurisdictionIds, 'IN');

    return (int) $query->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getFlagsForRequest(int $nid): array {
    $query = $this->database->select('markaspot_moderation_flags', 'f')
      ->fields('f')
      ->condition('f.nid', $nid)
      ->orderBy('f.created', 'DESC');

    $results = $query->execute()->fetchAll();

    return array_map(function ($row) {
      // Do not expose ip_hash or session_hash to tenant admins (GDPR).
      return [
        'fid' => (int) $row->fid,
        'nid' => (int) $row->nid,
        'service_request_id' => $row->service_request_id,
        'reason' => $row->reason,
        'details' => $row->details,
        'created' => (int) $row->created,
        'dismissed' => (int) $row->dismissed,
        'dismissed_by' => $row->dismissed_by ? (int) $row->dismissed_by : NULL,
        'dismissed_at' => $row->dismissed_at ? (int) $row->dismissed_at : NULL,
      ];
    }, $results);
  }

  /**
   * {@inheritdoc}
   */
  public function dismissFlags(int $nid, int $uid): void {
    $this->database->update('markaspot_moderation_flags')
      ->fields([
        'dismissed' => 1,
        'dismissed_by' => $uid,
        'dismissed_at' => $this->time->getRequestTime(),
      ])
      ->condition('nid', $nid)
      ->condition('dismissed', 0)
      ->execute();

    $this->logger->notice('Flags dismissed for nid=@nid by uid=@uid.', [
      '@nid' => $nid,
      '@uid' => $uid,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function hideRequest(int $nid, int $uid): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $node = $nodeStorage->load($nid);

    if (!$node) {
      throw new \InvalidArgumentException(
        sprintf('Node %d not found.', $nid)
      );
    }

    // Unpublish the node.
    $node->setUnpublished();
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage(
      (string) $this->t('Hidden by moderator due to content flags.')
    );
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionUserId($uid);
    $node->save();

    // Dismiss all active flags.
    $this->dismissFlags($nid, $uid);

    $this->logger->notice('Service request nid=@nid hidden (unpublished) by uid=@uid.', [
      '@nid' => $nid,
      '@uid' => $uid,
    ]);
  }

  /**
   * Resolves the jurisdiction group ID for a node via group relationships.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int
   *   The jurisdiction group ID. Returns 0 if no jurisdiction found.
   */
  protected function resolveJurisdictionForNode(int $nid): int {
    $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');
    $relationships = $relationshipStorage->loadByProperties([
      'entity_id' => $nid,
      'plugin_id' => 'group_node:service_request',
    ]);

    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if ($this->isJurisdictionGroup($group)) {
        return (int) $group->id();
      }
    }

    $this->logger->warning('No jurisdiction group found for nid=@nid.', [
      '@nid' => $nid,
    ]);

    return 0;
  }

  /**
   * Gets the most frequent active flag reason for a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return string|null
   *   The top reason, or NULL if no active flags exist.
   */
  protected function getTopReasonForNode(int $nid): ?string {
    $query = $this->database->select('markaspot_moderation_flags', 'f');
    $query->addField('f', 'reason');
    $query->addExpression('COUNT(*)', 'cnt');
    $query->condition('f.nid', $nid);
    $query->condition('f.dismissed', 0);
    $query->groupBy('f.reason');
    $query->orderBy('cnt', 'DESC');
    $query->range(0, 1);

    $result = $query->execute()->fetchField();
    return $result ?: NULL;
  }

  /**
   * Gets the most frequent active flag reason for multiple nodes in one query.
   *
   * @param int[] $nids
   *   Array of node IDs.
   *
   * @return array<int, string>
   *   Keyed by nid, value is the top reason.
   */
  protected function getTopReasonsForNodes(array $nids): array {
    if (empty($nids)) {
      return [];
    }

    // Get reason counts per nid, then pick the top one per nid in PHP.
    $query = $this->database->select('markaspot_moderation_flags', 'f');
    $query->addField('f', 'nid');
    $query->addField('f', 'reason');
    $query->addExpression('COUNT(*)', 'cnt');
    $query->condition('f.nid', $nids, 'IN');
    $query->condition('f.dismissed', 0);
    $query->groupBy('f.nid');
    $query->groupBy('f.reason');
    $query->orderBy('cnt', 'DESC');

    $results = $query->execute()->fetchAll();

    $topReasons = [];
    foreach ($results as $row) {
      $nid = (int) $row->nid;
      // First occurrence per nid is the top reason (ordered by cnt DESC).
      if (!isset($topReasons[$nid])) {
        $topReasons[$nid] = $row->reason;
      }
    }

    return $topReasons;
  }

  /**
   * Checks flag threshold and sends notifications if warranted.
   *
   * When the active flag count for a node reaches exactly the configured
   * threshold, the node is automatically hidden (unpublished) and a
   * threshold notification email is sent to the jurisdiction admin.
   *
   * For flags with reasons listed in immediate_notify_reasons (e.g.
   * offensive, personal), an immediate notification is sent to both the
   * jurisdiction admin and the site admin (user 1) per DSA Article 16.
   *
   * @param int $nid
   *   The node ID.
   * @param string $reason
   *   The reason of the latest flag.
   * @param string|null $details
   *   Optional details from the latest flag.
   */
  protected function checkThresholdAndNotify(int $nid, string $reason, ?string $details): void {
    $config = $this->configFactory->get('markaspot_moderation.settings');
    $threshold = (int) $config->get('flag_threshold');

    // Count active flags for this node.
    $query = $this->database->select('markaspot_moderation_flags', 'f');
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('f.nid', $nid);
    $query->condition('f.dismissed', 0);
    $activeCount = (int) $query->execute()->fetchField();

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $node = $nodeStorage->load($nid);

    if (!$node) {
      return;
    }

    $title = $node->getTitle();
    $serviceRequestId = '';
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      $serviceRequestId = $node->get('request_id')->value;
    }

    // Threshold reached: auto-hide and notify (only if still published).
    if ($threshold > 0 && $activeCount >= $threshold && $node->isPublished()) {
      // Auto-hide: unpublish the node.
      $node->setUnpublished();
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage(
        (string) $this->t('Automatically hidden: flag threshold (@count) reached.', [
          '@count' => $threshold,
        ])
      );
      $node->setRevisionCreationTime($this->time->getRequestTime());
      $node->setRevisionUserId(0);
      $node->save();

      $this->logger->notice('Auto-hidden nid=@nid: flag threshold @threshold reached.', [
        '@nid' => $nid,
        '@threshold' => $threshold,
      ]);

      $topReason = $this->getTopReasonForNode($nid) ?? $reason;
      $this->sendNotification($nid, 'flag_threshold', [
        'subject' => (string) $this->t('Content flagged: @title (@sr_id)', [
          '@title' => $title,
          '@sr_id' => $serviceRequestId,
        ]),
        'body' => (string) $this->t("The report @title has been flagged @count times and has been automatically hidden.\n\nTop reason: @reason\n\nPlease review in the moderation dashboard.", [
          '@title' => $title,
          '@count' => $activeCount,
          '@reason' => $topReason,
        ]),
      ]);
    }

    // Immediate notification for DSA-critical reasons.
    $immediateReasons = $config->get('immediate_notify_reasons') ?? [];
    if (in_array($reason, $immediateReasons, TRUE)) {
      $this->sendNotification($nid, 'flag_immediate', [
        'subject' => (string) $this->t('Urgent: @reason flag on @title (@sr_id)', [
          '@reason' => $reason,
          '@title' => $title,
          '@sr_id' => $serviceRequestId,
        ]),
        'body' => (string) $this->t("A report has been flagged as @reason.\n\nReport: @title\nDetails: @details\n\nThis requires immediate review under DSA Article 16.", [
          '@reason' => $reason,
          '@title' => $title,
          '@details' => Html::escape(strip_tags($details ?? '')),
        ]),
      ]);
    }
  }

  /**
   * Sends a notification email to the jurisdiction admin.
   *
   * For 'flag_immediate' notifications, also sends to user 1 (site admin).
   *
   * @param int $nid
   *   The node ID.
   * @param string $mailKey
   *   The mail key (flag_threshold or flag_immediate).
   * @param array $params
   *   Mail parameters including 'subject' and 'body'.
   */
  protected function sendNotification(int $nid, string $mailKey, array $params): void {
    // Resolve the jurisdiction group for this node.
    $jurisdictionId = $this->resolveJurisdictionForNode($nid);
    if ($jurisdictionId === 0) {
      $this->logger->notice('No jurisdiction for nid=@nid. Skipping @key notification.', [
        '@nid' => $nid,
        '@key' => $mailKey,
      ]);
      return;
    }

    $groupStorage = $this->entityTypeManager->getStorage('group');
    $jurGroup = $groupStorage->load($jurisdictionId);

    if (!$jurGroup) {
      return;
    }

    // Get jurisdiction email.
    if (!$jurGroup->hasField('field_jurisdiction_e_mail')
        || $jurGroup->get('field_jurisdiction_e_mail')->isEmpty()) {
      $this->logger->notice('No email configured for jurisdiction "@jur" (id=@jid). Skipping @key notification.', [
        '@jur' => $jurGroup->label(),
        '@jid' => $jurisdictionId,
        '@key' => $mailKey,
      ]);
      return;
    }

    $to = $jurGroup->get('field_jurisdiction_e_mail')->value;
    if (empty($to)) {
      return;
    }

    // Use site default language, not the flag submitter's (often anonymous).
    $langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail(
      'markaspot_moderation',
      $mailKey,
      $to,
      $langcode,
      $params,
      NULL,
      TRUE
    );

    if (!$result['result']) {
      $this->logger->error('Failed to send @key notification to @email for nid=@nid.', [
        '@key' => $mailKey,
        '@email' => $to,
        '@nid' => $nid,
      ]);
    }

    // For immediate notifications, also send to user 1 (site admin).
    if ($mailKey === 'flag_immediate') {
      $userStorage = $this->entityTypeManager->getStorage('user');
      $siteAdmin = $userStorage->load(1);
      if ($siteAdmin && $siteAdmin->getEmail()) {
        $adminEmail = $siteAdmin->getEmail();
        $adminResult = $this->mailManager->mail(
          'markaspot_moderation',
          $mailKey,
          $adminEmail,
          $langcode,
          $params,
          NULL,
          TRUE
        );

        if (!$adminResult['result']) {
          $this->logger->error('Failed to send @key notification to site admin @email for nid=@nid.', [
            '@key' => $mailKey,
            '@email' => $adminEmail,
            '@nid' => $nid,
          ]);
        }
      }
    }
  }

}
