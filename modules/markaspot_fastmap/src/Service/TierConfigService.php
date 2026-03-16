<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Central source for tier limit configuration and usage counting.
 */
class TierConfigService {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets the limit config for a tier.
   *
   * Falls back to 'free' tier limits for unknown/misconfigured tiers and logs
   * a warning. Returns NULL only if no tier_limits config exists at all.
   *
   * A tier with `limit: null` is explicitly unlimited (starter, pro, heart).
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
