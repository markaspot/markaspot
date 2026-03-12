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
   * @param string $tier
   *   The tier machine name (free, starter, pro, heart).
   *
   * @return array{limit: int, period: string}|null
   *   The limit config or NULL if no tier_limits config exists.
   */
  public function getLimits(string $tier): ?array {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $allLimits = $config->get('tier_limits');

    // No tier_limits config at all: module not fully configured.
    if (empty($allLimits)) {
      return NULL;
    }

    $tierConfig = $allLimits[$tier] ?? NULL;

    if (!$tierConfig || empty($tierConfig['limit'])) {
      // Unknown or corrupted tier: fall back to free limits (fail-closed).
      $freeLimits = $allLimits['free'] ?? NULL;

      if (!$freeLimits || empty($freeLimits['limit'])) {
        // Even free tier is broken. Log and apply a hard default.
        $this->logger->error('Tier limits config is missing or corrupted. Tier "@tier" and free fallback both unavailable.', [
          '@tier' => $tier,
        ]);
        return ['limit' => 50, 'period' => 'monthly'];
      }

      if ($tier !== 'free') {
        $this->logger->warning('Unknown or misconfigured tier "@tier". Falling back to free tier limits.', [
          '@tier' => $tier,
        ]);
      }

      return [
        'limit' => (int) $freeLimits['limit'],
        'period' => $freeLimits['period'] ?? 'monthly',
      ];
    }

    return [
      'limit' => (int) $tierConfig['limit'],
      'period' => $tierConfig['period'] ?? 'monthly',
    ];
  }

  /**
   * Gets all tier limits.
   *
   * @return array<string, array{limit: int, period: string}>
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
   * concurrent writes. This is acceptable for soft monthly limits (50-2000
   * range). At most 1-2 requests can overshoot per race event.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string $period
   *   The counting period: 'monthly' or 'total'.
   *
   * @return int
   *   The number of service requests.
   */
  public function countRequests(int $groupId, string $period): int {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_jurisdiction', $groupId);

    if ($period === 'monthly') {
      $now = $this->time->getRequestTime();
      $firstOfMonth = (int) strtotime(date('Y-m-01 00:00:00', $now));
      $query->condition('created', $firstOfMonth, '>=');
    }

    return (int) $query->count()->execute();
  }

  /**
   * Raw tier lookup without fallback (for getAllLimits).
   */
  protected function getLimitsRaw(string $tier): ?array {
    $config = $this->configFactory->get('markaspot_fastmap.settings');
    $tierConfig = $config->get('tier_limits.' . $tier);

    if (!$tierConfig || empty($tierConfig['limit'])) {
      return NULL;
    }

    return [
      'limit' => (int) $tierConfig['limit'],
      'period' => $tierConfig['period'] ?? 'monthly',
    ];
  }

}
