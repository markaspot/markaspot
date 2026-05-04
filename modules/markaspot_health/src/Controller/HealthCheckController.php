<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_health\HealthCheckPluginManager;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for the Mark-a-Spot health check report endpoint.
 */
class HealthCheckController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected HealthCheckPluginManager $pluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.markaspot_health_check'),
    );
  }

  /**
   * Returns the health check report as JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with results and summary.
   */
  public function report(Request $request): JsonResponse {
    $context = [];
    $jurisdictionId = $this->resolveJurisdiction($request);
    if ($jurisdictionId !== NULL) {
      $context['jurisdiction'] = $jurisdictionId;
    }

    $results = $this->pluginManager->runAll($context);

    $response = new CacheableJsonResponse([
      'checked_at' => gmdate('c'),
      'summary' => $this->buildSummary($results),
      'checks' => array_map(
        static fn(HealthCheckResult $result): array => $result->toArray(),
        $results,
      ),
    ]);

    $cache = (new CacheableMetadata())
      ->setCacheContexts(['user.permissions', 'url.query_args:jurisdiction'])
      ->setCacheMaxAge(0);
    $response->addCacheableDependency($cache);

    return $response;
  }

  /**
   * Resolves and authorises the optional jurisdiction query argument.
   *
   * Validates the value as a positive integer and verifies the calling user
   * is allowed to read it. Site administrators ("administer site
   * configuration" or is_admin role) bypass the membership check; everyone
   * else must be a member of the requested group. Without this gate, a
   * tenant_admin of jurisdiction A could probe drift counts of jurisdiction
   * B by querying ?jurisdiction=B.
   *
   * @return int|null
   *   Validated jurisdiction ID, or NULL when no parameter was supplied.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the value is non-empty but not a positive integer.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the caller is not entitled to inspect the requested jurisdiction.
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
   * Builds an aggregate summary across all results.
   *
   * @param array<int, \Drupal\markaspot_health\HealthCheckResult> $results
   *   The results.
   *
   * @return array<string, int>
   *   Summary keyed by errors, warnings, info, passed.
   */
  protected function buildSummary(array $results): array {
    $summary = [
      'errors' => 0,
      'warnings' => 0,
      'infos' => 0,
      'passed' => 0,
    ];

    foreach ($results as $result) {
      if ($result->passed) {
        $summary['passed']++;
        continue;
      }
      $summary[match ($result->severity) {
        'error' => 'errors',
        'warning' => 'warnings',
        'info' => 'infos',
        default => 'warnings',
      }]++;
    }

    return $summary;
  }

}
