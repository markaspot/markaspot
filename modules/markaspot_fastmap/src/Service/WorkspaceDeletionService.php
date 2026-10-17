<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Self-service deletion of a FastMap workspace.
 *
 * The flow has three steps, each one a separate request:
 * 1. request(): a tenant admin types the workspace slug; a one-time
 *    confirmation link goes to the admin's own mail address.
 * 2. confirm(): the link is redeemed (after the Nuxt layer has cancelled the
 *    Stripe subscription). The workspace goes offline at once and is
 *    scheduled for teardown after the grace period.
 * 3. processDue(): cron tears scheduled workspaces down as a system run.
 *
 * Until the teardown, restore() brings a workspace back. Confirmation tokens
 * are stored only as SHA-256 hashes; the schedule lives in a plain key/value
 * collection, so no schema change is needed.
 */
class WorkspaceDeletionService {

  /**
   * Lifetime of a confirmation link in seconds.
   */
  public const TOKEN_TTL = 900;

  /**
   * Default grace period between confirmation and teardown in days.
   */
  public const DEFAULT_GRACE_DAYS = 7;

  /**
   * Tiers that may be deleted without support.
   */
  public const SELF_SERVICE_TIERS = ['free', 'starter', 'pro'];

  /**
   * Expirable key/value collection holding the confirmation tokens.
   */
  private const TOKEN_COLLECTION = 'markaspot_fastmap_deletion_tokens';

  /**
   * Key/value collection holding the scheduled deletions, keyed by group ID.
   */
  private const SCHEDULE_COLLECTION = 'markaspot_fastmap_workspace_deletions';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected readonly KeyValueFactoryInterface $keyValue,
    protected readonly LockBackendInterface $lock,
    protected readonly MailManagerInterface $mailManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly LoggerInterface $logger,
    protected readonly WorkspaceProvisioningServiceInterface $provisioning,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Returns why the workspace cannot be deleted by its admin, or NULL.
   *
   * @return string|null
   *   'pending' (already scheduled), 'support' (tier set up by hand, or a
   *   workspace with sub-jurisdictions), or NULL when self-service applies.
   */
  public function ineligibilityReason(GroupInterface $group): ?string {
    if ($this->getSchedule((int) $group->id()) !== NULL) {
      return 'pending';
    }
    if ($this->hasChildJurisdictions($group)) {
      return 'support';
    }
    $tier = $this->fieldValue($group, 'field_tier');
    if (in_array($tier, self::SELF_SERVICE_TIERS, TRUE)) {
      return NULL;
    }
    if ($tier === '' && _markaspot_fastmap_workspace_in_demo_state($group)) {
      return NULL;
    }
    return 'support';
  }

  /**
   * Whether the account is a tenant admin of exactly this workspace.
   */
  public function isWorkspaceAdmin(GroupInterface $group, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }
    /** @var \Drupal\group\Entity\Storage\GroupRoleStorageInterface $roleStorage */
    $roleStorage = $this->entityTypeManager->getStorage('group_role');
    // The storage caches roles per request; a membership may have changed
    // since the token was issued.
    $roleStorage->resetUserGroupRoleCache($account, $group);
    foreach (array_keys($roleStorage->loadByUserAndGroup($account, $group, FALSE)) as $roleId) {
      if ($roleId === $group->bundle() . '-tenant_admin' || $roleId === 'jur-tenant_admin') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Starts a deletion: checks the typed slug and mails a confirmation link.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The workspace.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The requesting tenant admin (access is checked by the caller).
   * @param string $typedSlug
   *   The slug the admin typed as confirmation.
   * @param string $frontendBase
   *   Origin of the FastMap frontend that serves the confirmation page.
   *
   * @return array{status: string, reason?: string, email?: string}
   *   status 'sent', 'slug_mismatch', 'not_eligible' (with reason),
   *   'no_email' or 'mail_failed'.
   */
  public function request(GroupInterface $group, AccountInterface $account, string $typedSlug, string $frontendBase): array {
    $slug = $this->fieldValue($group, 'field_slug');
    // Slugs are lowercase; phone keyboards like to capitalize the first
    // letter, which must not fail the confirmation.
    if ($slug === '' || !hash_equals(mb_strtolower($slug), mb_strtolower(trim($typedSlug)))) {
      return ['status' => 'slug_mismatch'];
    }
    $reason = $this->ineligibilityReason($group);
    if ($reason !== NULL) {
      return ['status' => 'not_eligible', 'reason' => $reason];
    }
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    $email = $user instanceof UserInterface ? (string) $user->getEmail() : '';
    if ($email === '') {
      return ['status' => 'no_email'];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $tokens = $this->keyValueExpirable->get(self::TOKEN_COLLECTION);
    $tokens->setWithExpire($tokenHash, [
      'gid' => (int) $group->id(),
      'uid' => (int) $account->id(),
      'slug' => $slug,
      'created' => $this->time->getRequestTime(),
    ], self::TOKEN_TTL);

    $langcode = $user->getPreferredLangcode();
    $result = $this->mailManager->mail('markaspot_fastmap', 'workspace_deletion_confirm', $email, $langcode, [
      'site_name' => $this->siteName(),
      'workspace_name' => (string) $group->label(),
      'confirm_url' => rtrim($frontendBase, '/') . '/delete-workspace#token=' . $token,
      'minutes' => (string) intdiv(self::TOKEN_TTL, 60),
      'days' => (string) $this->graceDays(),
    ], $this->mailFrom());
    if (empty($result['result'])) {
      $tokens->delete($tokenHash);
      $this->logger->error('Deletion confirmation mail for workspace @id could not be sent.', ['@id' => $group->id()]);
      return ['status' => 'mail_failed'];
    }

    $this->logger->notice('Deletion of workspace @id requested by user @uid; confirmation link sent.', [
      '@id' => $group->id(),
      '@uid' => $account->id(),
    ]);
    return ['status' => 'sent', 'email' => self::maskEmail($email)];
  }

  /**
   * Resolves a confirmation token without redeeming it.
   *
   * @return array{group: \Drupal\group\Entity\GroupInterface, uid: int}|null
   *   The workspace and requesting user, or NULL when the token is unknown,
   *   expired, or no longer matches a deletable workspace.
   */
  public function peek(string $token): ?array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
      return NULL;
    }
    $record = $this->keyValueExpirable->get(self::TOKEN_COLLECTION)->get(hash('sha256', $token));
    if (!is_array($record) || empty($record['gid'])) {
      return NULL;
    }
    $group = $this->entityTypeManager->getStorage('group')->load((int) $record['gid']);
    if (!$group instanceof GroupInterface || $group->bundle() !== _markaspot_fastmap_jurisdiction_group_type()) {
      return NULL;
    }
    if ($this->fieldValue($group, 'field_slug') !== ($record['slug'] ?? NULL)) {
      return NULL;
    }
    $account = $this->entityTypeManager->getStorage('user')->load((int) $record['uid']);
    if (!$account instanceof UserInterface || !$account->isActive() || !$this->isWorkspaceAdmin($group, $account)) {
      return NULL;
    }
    if ($this->ineligibilityReason($group) !== NULL) {
      return NULL;
    }
    return ['group' => $group, 'uid' => (int) $record['uid']];
  }

  /**
   * Redeems a confirmation token: the workspace goes offline and is scheduled.
   *
   * @param string $token
   *   The token from the confirmation link.
   * @param bool $subscriptionsCancelled
   *   Whether the caller has cancelled the workspace's Stripe subscriptions.
   *   Required when a subscription ID is stored on the workspace.
   * @param string|null $supportEmail
   *   Where the admin can ask for a restore; NULL falls back to config.
   *
   * @return array{status: string, scheduled_for?: int}
   *   status 'scheduled', 'invalid', 'subscription_active' or 'busy'.
   */
  public function confirm(string $token, bool $subscriptionsCancelled, ?string $supportEmail = NULL): array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
      return ['status' => 'invalid'];
    }
    $tokenHash = hash('sha256', $token);
    $record = $this->keyValueExpirable->get(self::TOKEN_COLLECTION)->get($tokenHash);
    if (!is_array($record) || empty($record['gid'])) {
      return ['status' => 'invalid'];
    }
    // One lock per workspace: two links of the same workspace confirmed at
    // once must not both schedule and mail.
    $lockName = 'markaspot_fastmap:deletion_confirm:' . (int) $record['gid'];
    if (!$this->lock->acquire($lockName, 30.0)) {
      return ['status' => 'busy'];
    }
    try {
      $resolved = $this->peek($token);
      if ($resolved === NULL) {
        return ['status' => 'invalid'];
      }
      $group = $resolved['group'];
      if ($this->fieldValue($group, 'field_stripe_subscription_id') !== '' && !$subscriptionsCancelled) {
        return ['status' => 'subscription_active'];
      }
      // Redeem before any write: a second request with the same link must
      // find nothing, even if a later step fails.
      $this->keyValueExpirable->get(self::TOKEN_COLLECTION)->delete($tokenHash);

      $now = $this->time->getRequestTime();
      $scheduledFor = $now + $this->graceDays() * 86400;
      $schedule = [
        'gid' => (int) $group->id(),
        'slug' => $this->fieldValue($group, 'field_slug'),
        'label' => (string) $group->label(),
        'requested_by' => $resolved['uid'],
        'confirmed_at' => $now,
        'scheduled_for' => $scheduledFor,
        'prior_status' => $group->isPublished(),
        'prior_visibility' => $group->hasField('field_visibility') ? $this->fieldValue($group, 'field_visibility') : '',
        'prior_tier' => $this->fieldValue($group, 'field_tier'),
        'prior_expiry' => (int) $this->fieldValue($group, 'field_expiry_date'),
        'had_subscription' => $this->fieldValue($group, 'field_stripe_subscription_id') !== '',
      ];
      // Written before the group save so the workspace is marked even when
      // a hook on the save fails; cron then still finds and removes it.
      $this->keyValue->get(self::SCHEDULE_COLLECTION)->set((string) $group->id(), $schedule);

      $group->setUnpublished();
      if ($group->hasField('field_visibility')) {
        $group->set('field_visibility', 'blocked');
      }
      // The deletion schedule replaces a demo or checkout expiry: no reminder
      // mail, and no demo cron teardown before the grace period ends.
      if ($group->hasField('field_expiry_date')) {
        $group->set('field_expiry_date', NULL);
      }
      if ($group->hasField('field_stripe_subscription_id') && $this->fieldValue($group, 'field_stripe_subscription_id') !== '') {
        $group->set('field_stripe_subscription_id', NULL);
        if ($group->hasField('field_tier')) {
          $group->set('field_tier', 'free');
        }
      }
      $group->save();

      $this->logger->notice('Deletion of workspace @id confirmed; offline now, teardown scheduled for @date.', [
        '@id' => $group->id(),
        '@date' => date('c', $scheduledFor),
      ]);
      // The caller always reports TRUE once Stripe is done; whether there was
      // anything to cancel comes from the workspace itself.
      $paid = $schedule['had_subscription'] || in_array($schedule['prior_tier'], ['starter', 'pro'], TRUE);
      $this->sendScheduledMails($group, $resolved['uid'], $scheduledFor, $supportEmail, $paid);

      return ['status' => 'scheduled', 'scheduled_for' => $scheduledFor];
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Tears down every workspace whose grace period has ended.
   *
   * @return int
   *   Number of workspaces removed.
   */
  public function processDue(?int $now = NULL): int {
    $now ??= $this->time->getRequestTime();
    $store = $this->keyValue->get(self::SCHEDULE_COLLECTION);
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $removed = 0;

    foreach ($store->getAll() as $gid => $schedule) {
      if (!is_array($schedule) || (int) ($schedule['scheduled_for'] ?? PHP_INT_MAX) > $now) {
        continue;
      }
      $groupStorage->resetCache([(int) $gid]);
      $group = $groupStorage->load((int) $gid);
      if (!$group instanceof GroupInterface) {
        // Already gone, e.g. removed by the demo expiry cron.
        $store->delete((string) $gid);
        continue;
      }
      // Only a workspace that is still offline may go. Anything else means
      // somebody brought it back by hand; keep it and drop the schedule.
      $blocked = !$group->hasField('field_visibility') || $this->fieldValue($group, 'field_visibility') === 'blocked';
      if ($group->isPublished() || !$blocked) {
        $this->logger->warning('Scheduled deletion of workspace @id skipped: the workspace is online again.', ['@id' => $gid]);
        $store->delete((string) $gid);
        continue;
      }
      try {
        $this->provisioning->teardownWorkspace((int) $gid);
      }
      catch (\Throwable $e) {
        $this->logger->error('Scheduled teardown of workspace @id failed: @message', [
          '@id' => $gid,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
      $store->delete((string) $gid);
      $removed++;
      $this->logger->notice('Workspace @id (@slug) removed after its deletion grace period.', [
        '@id' => $gid,
        '@slug' => $schedule['slug'] ?? '',
      ]);
    }
    return $removed;
  }

  /**
   * Brings a scheduled workspace back online and cancels the deletion.
   *
   * The Stripe subscription stays cancelled; the workspace continues on the
   * free tier and can subscribe again through the regular checkout.
   *
   * @return bool
   *   FALSE when no deletion was scheduled for the workspace.
   */
  public function restore(GroupInterface $group): bool {
    $store = $this->keyValue->get(self::SCHEDULE_COLLECTION);
    $schedule = $store->get((string) $group->id());
    if (!is_array($schedule)) {
      return FALSE;
    }
    if (!empty($schedule['prior_status'])) {
      $group->setPublished();
    }
    $priorExpiry = (int) ($schedule['prior_expiry'] ?? 0);
    if ($priorExpiry > 0 && $group->hasField('field_expiry_date')) {
      // A demo comes back with at least two days left, so the reminder
      // mail still reaches the admin before the demo cron removes it.
      $group->set('field_expiry_date', max($priorExpiry, $this->time->getRequestTime() + 2 * 86400));
    }
    if ($group->hasField('field_visibility')) {
      $prior = (string) ($schedule['prior_visibility'] ?? '');
      $group->set('field_visibility', $prior !== '' && $prior !== 'blocked' ? $prior : NULL);
    }
    $group->save();
    $store->delete((string) $group->id());
    $this->logger->notice('Scheduled deletion of workspace @id cancelled; workspace restored.', ['@id' => $group->id()]);
    return TRUE;
  }

  /**
   * Returns the deletion schedule of a workspace, or NULL.
   */
  public function getSchedule(int $gid): ?array {
    $schedule = $this->keyValue->get(self::SCHEDULE_COLLECTION)->get((string) $gid);
    return is_array($schedule) ? $schedule : NULL;
  }

  /**
   * Returns all scheduled deletions, keyed by group ID.
   */
  public function getSchedules(): array {
    return array_filter($this->keyValue->get(self::SCHEDULE_COLLECTION)->getAll(), 'is_array');
  }

  /**
   * Masks a mail address for display: "ann@example.org" to "a***@example.org".
   */
  public static function maskEmail(string $email): string {
    $at = strrpos($email, '@');
    if ($at === FALSE || $at === 0) {
      return '***';
    }
    return mb_substr($email, 0, 1) . '***' . substr($email, $at);
  }

  /**
   * Mails the admin the deletion date and notifies the operator (uid 1).
   */
  private function sendScheduledMails(GroupInterface $group, int $uid, int $scheduledFor, ?string $supportEmail, bool $paid): void {
    $userStorage = $this->entityTypeManager->getStorage('user');
    $admin = $userStorage->load($uid);
    $operator = $userStorage->load(1);
    $support = $this->supportEmail($supportEmail);

    if ($admin && $admin->getEmail()) {
      $langcode = $admin->getPreferredLangcode();
      $this->mailManager->mail('markaspot_fastmap', 'workspace_deletion_scheduled', $admin->getEmail(), $langcode, [
        'site_name' => $this->siteName(),
        'workspace_name' => (string) $group->label(),
        'deletion_date' => $this->formatDate($scheduledFor, $langcode),
        'support_email' => $support,
        'paid' => $paid,
      ], $this->mailFrom());
    }
    if ($operator && $operator->getEmail() && (int) $operator->id() !== $uid) {
      $this->mailManager->mail('markaspot_fastmap', 'workspace_deletion_operator', $operator->getEmail(), 'en', [
        'workspace_name' => (string) $group->label(),
        'workspace_slug' => $this->fieldValue($group, 'field_slug'),
        'workspace_id' => (string) $group->id(),
        'requested_by' => $admin ? (string) $admin->getEmail() : (string) $uid,
        'deletion_date' => $this->formatDate($scheduledFor, 'en'),
      ], $this->mailFrom());
    }
  }

  /**
   * Formats the deletion date for a mail: date only, numeric, local order.
   *
   * Numeric because FastMap sites have no locale module, so month names
   * would stay English in every language.
   */
  private function formatDate(int $timestamp, string $langcode): string {
    $dotted = ['de', 'da', 'fi', 'nb', 'cs', 'pl', 'tr', 'uk'];
    return $this->dateFormatter->format($timestamp, 'custom', in_array($langcode, $dotted, TRUE) ? 'd.m.Y' : 'Y-m-d', NULL, $langcode);
  }

  /**
   * Whether other jurisdictions name this workspace as their parent.
   */
  private function hasChildJurisdictions(GroupInterface $group): bool {
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('group');
    if (!isset($definitions['field_parent_jurisdiction'])) {
      return FALSE;
    }
    $children = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_parent_jurisdiction', $group->id())
      ->range(0, 1)
      ->execute();
    return $children !== [];
  }

  /**
   * Grace period in days, from config with a sane floor.
   */
  public function graceDays(): int {
    $days = (int) ($this->configFactory->get('markaspot_fastmap.settings')->get('deletion_grace_days') ?? self::DEFAULT_GRACE_DAYS);
    return $days > 0 ? $days : self::DEFAULT_GRACE_DAYS;
  }

  /**
   * Restore contact: caller value, then config, then the site mail.
   *
   * Never mail_from: that is a no-reply sender on hosted platforms.
   */
  private function supportEmail(?string $requested): string {
    foreach ([$requested, $this->configFactory->get('markaspot_fastmap.settings')->get('support_email')] as $candidate) {
      if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
        return $candidate;
      }
    }
    return (string) $this->configFactory->get('system.site')->get('mail');
  }

  /**
   * Site name for mail subjects, as the signup mails use it.
   */
  private function siteName(): string {
    return (string) ($this->configFactory->get('system.site')->get('name') ?: 'FastMap');
  }

  /**
   * Configured sender address, or NULL for the site default.
   */
  private function mailFrom(): ?string {
    $from = (string) ($this->configFactory->get('markaspot_fastmap.settings')->get('mail_from') ?? '');
    return $from !== '' ? $from : NULL;
  }

  /**
   * Returns a trimmed string field value, or '' when absent.
   */
  private function fieldValue(GroupInterface $group, string $field): string {
    if (!$group->hasField($field) || $group->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) $group->get($field)->value);
  }

}
