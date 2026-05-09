<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_health\SmokeCheckPluginManager;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for the Mark-a-Spot smoke check report endpoint.
 */
class SmokeCheckController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected SmokeCheckPluginManager $pluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.markaspot_smoke_check'),
    );
  }

  /**
   * Returns the smoke check report as JSON.
   *
   * Hard-pins mode to read-only on the HTTP path: the endpoint is reachable
   * via standard Drupal routing, so a runaway browser tab or stale dashboard
   * polling tab MUST NOT be able to trigger mutating checks. mode=full is
   * intentionally CLI-only.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with results and summary.
   */
  public function report(Request $request): JsonResponse {
    $context = ['mode' => SmokeCheckResult::MODE_READ_ONLY];
    $jurisdictionId = $this->resolveJurisdiction($request);
    if ($jurisdictionId !== NULL) {
      $context['jurisdiction'] = $jurisdictionId;
    }
    $category = $this->resolveCategory($request);
    if ($category !== NULL) {
      $context['category'] = $category;
    }

    $results = $this->pluginManager->runAll($context);

    $response = new CacheableJsonResponse([
      'checked_at' => gmdate('c'),
      'mode' => $context['mode'],
      'summary' => $this->buildSummary($results),
      'checks' => array_map(
        static fn(SmokeCheckResult $result): array => $result->toArray(),
        $results,
      ),
    ]);

    $cache = (new CacheableMetadata())
      ->setCacheContexts(['user.permissions', 'url.query_args:jurisdiction', 'url.query_args:category'])
      ->setCacheMaxAge(0);
    $response->addCacheableDependency($cache);

    return $response;
  }

  /**
   * Resolves and authorises the optional jurisdiction query argument.
   *
   * Same authorisation model as HealthCheckController::resolveJurisdiction():
   * site administrators bypass the membership check; everyone else must be a
   * member of the requested group. Without this, a tenant_admin of jur A
   * could probe smoke results of jur B by querying ?jurisdiction=B.
   *
   * @return int|null
   *   Validated jurisdiction ID, or NULL when no parameter was supplied.
   */
  protected function resolveJurisdiction(Request $request): ?int {
    $raw = $request->query->get('jurisdiction');
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    $jid = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($jid === FALSE) {
      throw new BadRequestHttpException('The jurisdiction parameter must be a positive integer.');
    }

    $account = $this->currentUser();
    if ($account->hasPermission('administer site configuration')) {
      return $jid;
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($jid);
    if (!$group instanceof GroupInterface) {
      throw new AccessDeniedHttpException();
    }
    if (!$group->getMember($account)) {
      throw new AccessDeniedHttpException();
    }

    return $jid;
  }

  /**
   * Resolves the optional category query argument.
   *
   * Restricted to a small allow-list to keep injection of arbitrary strings
   * out of the plugin manager loop. Unknown categories return NULL (run all).
   */
  protected function resolveCategory(Request $request): ?string {
    $raw = $request->query->get('category');
    if (!is_string($raw) || $raw === '') {
      return NULL;
    }
    $allowed = [
      'http_sanity',
      'drupal_internal',
      'auth',
      'georeport',
      'jsonapi',
      'media',
      'dashboard',
      'mail',
      'wrap',
    ];
    return in_array($raw, $allowed, TRUE) ? $raw : NULL;
  }

  /**
   * Builds an aggregate summary across all results.
   *
   * @param array<int, \Drupal\markaspot_health\SmokeCheckResult> $results
   *   The results.
   *
   * @return array<string, int>
   *   Summary keyed by passed, failed, skipped, warnings, errors_count.
   */
  protected function buildSummary(array $results): array {
    $summary = [
      'passed' => 0,
      'failed' => 0,
      'skipped' => 0,
      'warnings' => 0,
      'error_severity_failures' => 0,
    ];

    foreach ($results as $result) {
      switch ($result->status) {
        case SmokeCheckResult::STATUS_PASS:
          $summary['passed']++;
          break;

        case SmokeCheckResult::STATUS_FAIL:
          $summary['failed']++;
          if ($result->severity === 'error') {
            $summary['error_severity_failures']++;
          }
          break;

        case SmokeCheckResult::STATUS_SKIP:
          $summary['skipped']++;
          break;

        case SmokeCheckResult::STATUS_WARNING:
          $summary['warnings']++;
          break;
      }
    }

    return $summary;
  }

}
