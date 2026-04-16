<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Generic _custom_access check that gates routes on a feature flag.
 *
 * Reads the dot-path in the `_feature_flag` route option from the active
 * jurisdiction's `field_nuxt_config`. The `_feature_flag_default` route
 * option declares the fail-closed / fail-open behavior when the flag or
 * the jurisdiction cannot be resolved.
 *
 * Usage in routing.yml:
 * @code
 * markaspot_stats.status:
 *   path: '/api/stats/status'
 *   defaults:
 *     _controller: '\Drupal\markaspot_stats\Controller\StatsController::getStatusStats'
 *   requirements:
 *     _custom_access: '\Drupal\markaspot_nuxt\Access\FeatureFlagAccessCheck::check'
 *   options:
 *     _feature_flag: 'features.statistics'
 *     _feature_flag_default: false
 * @endcode
 *
 * Jurisdiction resolution order:
 * 1. `jurisdiction_id` query parameter (numeric or slug)
 * 2. `jurisdiction` query parameter (legacy alias)
 * 3. NULL — falls back to the declared default
 *
 * POST bodies are intentionally not inspected here: _custom_access runs
 * too early in the kernel for reliable body parsing across all routes.
 * Routes that only receive jurisdiction in the body should do their own
 * controller-level gate (see PasswordlessAuthController).
 */
final class FeatureFlagAccessCheck implements AccessInterface, ContainerInjectionInterface {

  public function __construct(
    private readonly FeatureFlagChecker $featureFlagChecker,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_nuxt.feature_flag_checker'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Checks access for the incoming request against the route's feature flag.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route being matched.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the feature flag resolves to TRUE for the active
   *   jurisdiction, forbidden otherwise. The result is not cached per
   *   user — it depends on the jurisdiction, which is derived from
   *   request state, not user state.
   */
  public function check(Route $route, AccountInterface $account): AccessResultInterface {
    $flag = $route->getOption('_feature_flag');
    if (!is_string($flag) || $flag === '') {
      // A route that invokes this service without declaring a flag is a
      // configuration error, not a user-facing access denial. Fail closed
      // to surface the misconfiguration loudly.
      return AccessResult::forbidden('Route missing _feature_flag option.');
    }

    $default = (bool) $route->getOption('_feature_flag_default');
    $jurisdiction = $this->resolveJurisdictionFromRequest();

    $enabled = $this->featureFlagChecker->isEnabled($flag, $jurisdiction, $default);
    $result = $enabled
      ? AccessResult::allowed()
      : AccessResult::forbidden(sprintf('Feature flag "%s" is disabled for this jurisdiction.', $flag));

    // Two-layer cache discipline. setCacheMaxAge(0) keeps the access
    // result itself out of the access-check cache, and the query-args
    // contexts make the dynamic-page-cache vary per jurisdiction — the
    // latter matters for any future consumer that does NOT set
    // no_cache on its route options. Without the contexts, the first
    // allowed jurisdiction's response could be served to every other
    // tenant from the dynamic-page-cache key.
    return $result
      ->setCacheMaxAge(0)
      ->addCacheContexts([
        'url.query_args:jurisdiction_id',
        'url.query_args:jurisdiction',
      ]);
  }

  /**
   * Resolves the active jurisdiction group from the current request.
   */
  private function resolveJurisdictionFromRequest(): ?GroupInterface {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }

    // `?:` (not `??`) so that an empty-string primary param correctly
    // falls through to the legacy alias instead of returning '' early.
    $raw = $request->query->get('jurisdiction_id') ?: $request->query->get('jurisdiction');
    if ($raw === NULL || $raw === '') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('group');

    // Numeric: direct GID lookup with bundle + existence check to block
    // phantom-GID pass-through. ctype_digit() is stricter than is_numeric()
    // and rejects scientific notation (`42e5`) and signed values.
    if (is_string($raw) && ctype_digit($raw)) {
      $gid = (int) $raw;
      if ($gid <= 0) {
        return NULL;
      }
      $group = $storage->load($gid);
      return ($group instanceof GroupInterface && $group->bundle() === 'jur') ? $group : NULL;
    }
    if (is_int($raw) && $raw > 0) {
      $group = $storage->load($raw);
      return ($group instanceof GroupInterface && $group->bundle() === 'jur') ? $group : NULL;
    }

    // Slug: alphanumeric + hyphen/underscore, max 64 chars.
    if (!is_string($raw) || !preg_match('/^[a-z0-9_-]{1,64}$/i', $raw)) {
      return NULL;
    }
    $groups = $storage->loadByProperties([
      'type' => 'jur',
      'field_slug' => $raw,
      'status' => 1,
    ]);
    $group = reset($groups);
    return $group instanceof GroupInterface ? $group : NULL;
  }

}
