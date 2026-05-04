<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Central source for tier limit configuration and usage counting.
 */
class TierConfigService {

  /**
   * Tier names that can be stored in group.field_tier.
   */
  protected const EXPECTED_TIER_LIMITS = ['free', 'starter', 'pro', 'heart'];

  /**
   * Count periods understood by countRequests().
   */
  protected const ALLOWED_PERIODS = ['published', 'monthly', 'total'];

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
    protected readonly Connection $database,
  ) {}

  /**
   * Gets the limit config for a tier.
   *
   * Falls back to 'free' tier limits for unknown/misconfigured tiers and logs
   * a warning. Returns NULL only if no tier_limits config exists at all.
   *
   * A tier with `limit: null` is explicitly unlimited (heart).
   * This is distinct from a missing or corrupted tier config, which falls back
   * to the free tier (fail-closed).
   *
   * @param string $tier
   *   The tier machine name (free, starter, pro, heart).
   *
   * @return array{limit: int|null, period: string, unlimited?: bool}|null
   *   The limit config, or NULL if no tier_limits config exists at all.
   *   When limit is null, 'unlimited' is TRUE.
   */
  public function getLimits(string $tier): ?array {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $allLimits = $config->get('tier_limits');

    // No tier_limits config at all: module not fully configured.
    if (empty($allLimits)) {
      return NULL;
    }

    $tierConfig = $allLimits[$tier] ?? NULL;

    // Tier exists and has an explicit config entry (including limit: null).
    if (is_array($tierConfig) && array_key_exists('limit', $tierConfig)) {
      return $this->buildLimitsResult($tierConfig);
    }

    // Unknown or corrupted tier: fall back to free limits (fail-closed).
    $freeLimits = $allLimits['free'] ?? NULL;

    if (!is_array($freeLimits) || !array_key_exists('limit', $freeLimits)) {
      // Even free tier is broken. Log and apply a hard default.
      $this->logger->error('Tier limits config is missing or corrupted. Tier "@tier" and free fallback both unavailable.', [
        '@tier' => $tier,
      ]);
      return ['limit' => 50, 'period' => 'published'];
    }

    if ($tier !== 'free') {
      $this->logger->warning('Unknown or misconfigured tier "@tier". Falling back to free tier limits.', [
        '@tier' => $tier,
      ]);
    }

    return $this->buildLimitsResult($freeLimits);
  }

  /**
   * Gets all tier limits.
   *
   * @return array<string, array{limit: int, period: string}>
   *   All tier limits keyed by tier name.
   */
  public function getAllLimits(): array {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $tiers = array_keys($config->get('tier_limits') ?? []);

    return array_filter(array_combine(
      $tiers,
      array_map(fn(string $tier) => $this->getLimitsRaw($tier), $tiers),
    ));
  }

  /**
   * Gets tier limit config issues that should be visible in status reports.
   *
   * The getLimits() method keeps quota enforcement fail-closed by falling back
   * to the free tier. This method exposes the same config drift explicitly, so
   * broken SaaS tier config is not only visible in watchdog logs.
   *
   * @return string[]
   *   Human-readable config issue summaries.
   */
  public function getTierLimitConfigIssues(): array {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $allLimits = $config->get('tier_limits');

    if (empty($allLimits) || !is_array($allLimits)) {
      return ['tier_limits is missing or empty.'];
    }

    $issues = [];
    foreach (self::EXPECTED_TIER_LIMITS as $tier) {
      if (!array_key_exists($tier, $allLimits)) {
        $issues[] = sprintf('tier_limits.%s is missing.', $tier);
        continue;
      }

      $tierConfig = $allLimits[$tier];
      if (!is_array($tierConfig)) {
        $issues[] = sprintf('tier_limits.%s must be a mapping.', $tier);
        continue;
      }

      $issues = array_merge($issues, $this->validateTierLimitConfig($tier, $tierConfig));
    }

    foreach (array_keys($allLimits) as $tier) {
      if (!in_array($tier, self::EXPECTED_TIER_LIMITS, TRUE)) {
        $issues[] = sprintf('tier_limits.%s is not a supported field_tier value.', $tier);
      }
    }

    return $issues;
  }

  /**
   * Counts service requests for a jurisdiction in the given period.
   *
   * Uses accessCheck(FALSE) for authoritative quota enforcement.
   * Note: the count-then-validate pattern has an inherent race window under
   * concurrent writes. This is acceptable for soft limits (50-2000 range).
   * At most 1-2 requests can overshoot per race event.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string $period
   *   The counting period: 'published', 'monthly', or 'total'.
   *
   * @return int
   *   The number of service requests.
   */
  public function countRequests(int $groupId, string $period): int {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_jurisdiction', $groupId);

    if ($period === 'published') {
      // Count only currently published nodes (no time window).
      $query->condition('status', 1);
    }
    elseif ($period === 'monthly') {
      $now = $this->time->getRequestTime();
      $firstOfMonth = (int) strtotime(date('Y-m-01 00:00:00', $now));
      $query->condition('created', $firstOfMonth, '>=');
    }

    return (int) $query->count()->execute();
  }

  /**
   * Gets the member limit for a tier.
   *
   * @param string $tier
   *   The tier machine name (free, starter, pro, heart).
   *
   * @return int|null
   *   The member limit, or NULL for unknown tiers (unlimited).
   */
  public function getMemberLimit(string $tier): ?int {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $memberLimits = $config->get('member_limits');

    if (!empty($memberLimits) && isset($memberLimits[$tier])) {
      return (int) $memberLimits[$tier];
    }

    // Hardcoded defaults if config is not set.
    return match ($tier) {
      'free' => 1,
      'starter', 'heart' => 5,
      'pro' => 20,
      default => NULL,
    };
  }

  /**
   * Gets group role IDs assignable through invitations for a tier.
   *
   * @param string $tier
   *   The tier machine name (free, starter, pro, heart).
   *
   * @return string[]
   *   The permitted group role IDs.
   */
  public function getAssignableRoleIds(string $tier): array {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $roleLimits = $config->get('assignable_roles');

    if (!empty($roleLimits) && isset($roleLimits[$tier]) && is_array($roleLimits[$tier])) {
      return $this->normalizeRoleIds($roleLimits[$tier]);
    }

    return match ($tier) {
      'free' => ['jur-member', 'org-member'],
      'starter', 'pro', 'heart' => [
        'jur-member',
        'jur-moderator',
        'org-member',
        'org-moderator',
        'jur-tenant_admin',
        'org-tenant_admin',
      ],
      default => ['jur-member', 'org-member'],
    };
  }

  /**
   * Counts active members of a group.
   *
   * Uses a direct database query on group_relationship_field_data for
   * performance, avoiding loading full entity objects.
   *
   * @param int $groupId
   *   The group entity ID.
   *
   * @return int
   *   The number of active members.
   */
  public function countMembers(int $groupId): int {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return 0;
    }

    $groupType = $group->bundle();
    $membershipType = $groupType . '-group_membership';

    $query = $this->entityTypeManager->getStorage('group_relationship')->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $groupId)
      ->condition('type', $membershipType);

    return (int) $query->count()->execute();
  }

  /**
   * Gets the monthly AI analysis budget for a tier.
   *
   * @param string $tier
   *   The tier machine name.
   *
   * @return int
   *   Monthly AI analysis limit. 0 means unlimited.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function getAIBudget(string $tier): int {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $budgets = $config->get('ai_budgets');

    if (!empty($budgets) && array_key_exists($tier, $budgets)) {
      return (int) $budgets[$tier];
    }

    return match ($tier) {
      'free' => 50,
      'starter' => 200,
      'heart' => 100,
      'pro' => 500,
      'partner' => 0,
      default => 50,
    };
  }

  /**
   * Counts AI analyses for a jurisdiction in the current calendar month.
   *
   * Uses a month-scoped cache to avoid a DB query on every settings API call.
   * Cache is invalidated by recordAIAnalysis() after each new analysis.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   *
   * @return int
   *   Number of AI analyses this calendar month.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function countAIAnalyses(int $groupId): int {
    $now = $this->time->getRequestTime();
    $monthKey = date('Y-m', $now);
    $cid = 'markaspot_ai_usage:' . $groupId . ':' . $monthKey;

    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $cached = \Drupal::cache()->get($cid);
    if ($cached !== FALSE) {
      return (int) $cached->data;
    }

    $firstOfMonth = (int) strtotime(date('Y-m-01 00:00:00', $now));
    $count = (int) $this->database->select('markaspot_ai_usage', 'u')
      ->condition('u.group_id', $groupId)
      ->condition('u.created', $firstOfMonth, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Cache until first of next month.
    $nextMonth = (int) strtotime('first day of next month 00:00:00', $now);
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::cache()->set($cid, $count, $nextMonth);

    return $count;
  }

  /**
   * Records an AI analysis for a jurisdiction.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param int|null $nid
   *   The service request node ID (optional).
   * @param int $tokensUsed
   *   Tokens consumed (for future cost tracking).
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function recordAIAnalysis(int $groupId, ?int $nid = NULL, int $tokensUsed = 0): void {
    $this->database->insert('markaspot_ai_usage')
      ->fields([
        'group_id' => $groupId,
        'nid' => $nid,
        'created' => $this->time->getRequestTime(),
        'tokens_used' => $tokensUsed,
      ])
      ->execute();

    // Invalidate the cached count for this jurisdiction/month.
    $monthKey = date('Y-m', $this->time->getRequestTime());
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::cache()->delete('markaspot_ai_usage:' . $groupId . ':' . $monthKey);
  }

  /**
   * Checks whether the AI budget for a jurisdiction/tier is exhausted.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string $tier
   *   The tier machine name.
   *
   * @return bool
   *   TRUE if the budget is exhausted. Always FALSE for unlimited (0) budgets.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function isAIBudgetExhausted(int $groupId, string $tier): bool {
    $budget = $this->getAIBudget($tier);
    if ($budget === 0) {
      return FALSE;
    }
    return $this->countAIAnalyses($groupId) >= $budget;
  }

  /**
   * Deletes AI usage records older than the given timestamp.
   *
   * @param int $olderThan
   *   Unix timestamp. Records with created < $olderThan are deleted.
   *
   * @return int
   *   Number of deleted records.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function pruneAIUsage(int $olderThan): int {
    return (int) $this->database->delete('markaspot_ai_usage')
      ->condition('created', $olderThan, '<')
      ->execute();
  }

  /**
   * Raw tier lookup without fallback (for getAllLimits).
   */
  protected function getLimitsRaw(string $tier): ?array {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $tierConfig = $config->get('tier_limits.' . $tier);

    if (!is_array($tierConfig) || !array_key_exists('limit', $tierConfig)) {
      return NULL;
    }

    return $this->buildLimitsResult($tierConfig);
  }

  /**
   * Normalizes configured role IDs.
   *
   * @param mixed[] $roleIds
   *   Raw configured role IDs.
   *
   * @return string[]
   *   Non-empty role IDs.
   */
  protected function normalizeRoleIds(array $roleIds): array {
    return array_values(array_unique(array_filter(array_map(
      static fn(mixed $roleId): string => trim((string) $roleId),
      $roleIds,
    ))));
  }

  /**
   * Validates a single tier limit config entry.
   *
   * @param string $tier
   *   The tier machine name.
   * @param array $tierConfig
   *   Raw tier config.
   *
   * @return string[]
   *   Human-readable issue summaries.
   */
  protected function validateTierLimitConfig(string $tier, array $tierConfig): array {
    $issues = [];

    if (!array_key_exists('limit', $tierConfig)) {
      $issues[] = sprintf('tier_limits.%s.limit is missing.', $tier);
    }
    elseif (!$this->isValidLimitValue($tierConfig['limit'])) {
      $issues[] = sprintf('tier_limits.%s.limit must be a non-negative integer or null.', $tier);
    }

    $period = $tierConfig['period'] ?? NULL;
    if (!is_string($period) || !in_array($period, self::ALLOWED_PERIODS, TRUE)) {
      $issues[] = sprintf('tier_limits.%s.period must be one of: %s.', $tier, implode(', ', self::ALLOWED_PERIODS));
    }

    return $issues;
  }

  /**
   * Checks whether a limit config value can be used safely.
   */
  protected function isValidLimitValue(mixed $limit): bool {
    return $limit === NULL || (is_int($limit) && $limit >= 0);
  }

  /**
   * Builds a normalized limits result array from raw tier config.
   *
   * @param array $tierConfig
   *   Raw tier config with 'limit' and optionally 'period'.
   *
   * @return array{limit: int|null, period: string, unlimited?: bool}
   *   Normalized limits. When limit is null, 'unlimited' is TRUE.
   */
  protected function buildLimitsResult(array $tierConfig): array {
    $limit = $tierConfig['limit'];
    $period = $tierConfig['period'] ?? 'published';

    if ($limit === NULL) {
      return [
        'limit' => NULL,
        'period' => $period,
        'unlimited' => TRUE,
      ];
    }

    return [
      'limit' => (int) $limit,
      'period' => $period,
    ];
  }

}
