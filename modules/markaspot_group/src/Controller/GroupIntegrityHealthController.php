<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Service\GroupIntegrityChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for installation-level group integrity health checks.
 */
final class GroupIntegrityHealthController extends ControllerBase {

  /**
   * Default number of detail rows returned per check.
   */
  private const DEFAULT_DETAIL_LIMIT = 50;

  /**
   * Maximum number of detail rows returned per check.
   */
  private const MAX_DETAIL_LIMIT = 200;

  public function __construct(
    private readonly GroupIntegrityChecker $checker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_group.integrity_checker'),
    );
  }

  /**
   * Access check for installation-level group integrity diagnostics.
   *
   * This is intentionally stricter than tenant-admin group management: only
   * user 1 or global Drupal administrators may inspect installation drift.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $allowed = (int) $account->id() === 1 || in_array('administrator', $account->getRoles(), TRUE);
    return AccessResult::allowedIf($allowed)->addCacheContexts(['user.roles', 'user']);
  }

  /**
   * Returns structured group integrity check results.
   */
  public function checks(Request $request): JsonResponse {
    $limit = $this->resolveLimit($request);
    $checks = [];

    foreach ($this->checker->check() as $id => $result) {
      $rows = $result['rows'];
      $count = count($rows);
      $checks[$id] = [
        'description' => $result['description'],
        'count' => $count,
        'passed' => $count === 0,
        'rows' => array_slice($rows, 0, $limit),
        'truncated_count' => max(0, $count - $limit),
      ];
    }

    return new JsonResponse([
      'checked_at' => gmdate('c'),
      'checks' => $checks,
    ]);
  }

  /**
   * Resolves the requested per-check detail limit.
   */
  private function resolveLimit(Request $request): int {
    $requested = $request->query->get('limit', self::DEFAULT_DETAIL_LIMIT);
    if (!is_numeric($requested)) {
      return self::DEFAULT_DETAIL_LIMIT;
    }

    return min(self::MAX_DETAIL_LIMIT, max(0, (int) $requested));
  }

}
