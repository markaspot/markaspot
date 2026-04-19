<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cross-tenant platform admin aggregates.
 *
 * Feeds the Nuxt `/admin` overview cards (pro-layer). This controller is
 * deliberately NOT scoped to a jurisdiction: it returns counts across the
 * whole installation. Access is restricted to the `access platform admin`
 * permission, which the profile grants only to `administrator`.
 *
 * Response shape (contract shared with pro-layer/app/pages/admin/index.vue):
 *
 *   {
 *     "tenants": {
 *       "total":        int,   // published jurisdiction groups
 *       "zero_reports": int    // tenants with 0 service_request nodes
 *     },
 *     "reports": {
 *       "total":   int,        // published service_request nodes
 *       "last_7d": int         // created in last 7 days
 *     },
 *     "signups": {
 *       "last_7d": int         // jur groups created in last 7 days
 *     }
 *   }
 *
 * Extension path:
 *   Follow-up issues will layer tier-aware counts (active/demo/expired) once
 *   the FastMap tier field is the single source of truth across all
 *   deployments. For now we keep the endpoint honest with the fields that
 *   exist on every Mark-a-Spot install.
 */
final class AdminStatsController extends ControllerBase {

  /**
   * Window (in seconds) for the "last 7 days" aggregates.
   */
  private const WINDOW_7D_SECONDS = 7 * 86400;

  /**
   * Maximum number of signups the feed endpoint returns.
   */
  private const SIGNUPS_FEED_LIMIT = 30;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * GET /api/admin/stats.
   *
   * Returns cross-tenant aggregates. Response is cacheable with tags that
   * invalidate on new jurisdictions or service requests.
   */
  public function stats(): CacheableJsonResponse {
    $since = (int) ($this->time->getRequestTime() - self::WINDOW_7D_SECONDS);

    $response = new CacheableJsonResponse([
      'tenants' => [
        'total'        => $this->countPublishedJurisdictions(),
        'zero_reports' => $this->countJurisdictionsWithoutReports(),
      ],
      'reports' => [
        'total'   => $this->countPublishedReports(),
        'last_7d' => $this->countReportsSince($since),
      ],
      'signups' => [
        'last_7d' => $this->countJurisdictionsCreatedSince($since),
      ],
    ]);

    $cache = (new CacheableMetadata())
      ->addCacheTags([
        'node_list:service_request',
        'group_list:jur',
      ])
      ->setCacheMaxAge(60)
      ->addCacheContexts(['user.permissions']);
    $response->addCacheableDependency($cache);

    return $response;
  }

  /**
   * GET /api/admin/signups.
   *
   * Recent jurisdiction creations — the closest proxy we have to a
   * "signup feed" without building a dedicated audit log. The endpoint
   * streams the most recent N `jur` groups along with the data that is
   * safe to expose on a platform admin surface (no tenant PII).
   */
  public function signups(): CacheableJsonResponse {
    // `groups_field_data` has one row per langcode; filter to the default
    // translation so multilingual tenants do not appear multiple times.
    $query = $this->database
      ->select('groups_field_data', 'g')
      ->fields('g', ['id', 'label', 'created'])
      ->condition('g.type', 'jur')
      ->condition('g.default_langcode', 1)
      ->orderBy('g.created', 'DESC')
      ->range(0, self::SIGNUPS_FEED_LIMIT);
    $query->leftJoin('group__field_slug', 'fs', 'fs.entity_id = g.id');
    $query->addField('fs', 'field_slug_value', 'slug');

    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $signups = [];
    foreach ($rows as $row) {
      $signups[] = [
        'id'      => (string) $row['id'],
        'label'   => $row['label'] ?? '',
        'slug'    => $row['slug'] ?? '',
        'created' => isset($row['created']) ? (int) $row['created'] : 0,
      ];
    }

    $response = new CacheableJsonResponse(['signups' => $signups]);
    $response->addCacheableDependency(
      (new CacheableMetadata())
        ->addCacheTags(['group_list:jur'])
        ->setCacheMaxAge(60)
        ->addCacheContexts(['user.permissions'])
    );

    return $response;
  }

  /**
   * Count published jurisdictions (groups of type `jur` with status = 1).
   */
  private function countPublishedJurisdictions(): int {
    return (int) $this->database
      ->select('groups_field_data', 'g')
      ->condition('g.type', 'jur')
      ->condition('g.status', 1)
      ->condition('g.default_langcode', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Count jurisdictions with 0 service_request nodes.
   *
   * Looks up jurisdictions without a matching row in the group_relationship
   * membership table.
   *
   * Stalled onboarding indicator: a tenant that was created but has seen no
   * citizen reports yet.
   */
  private function countJurisdictionsWithoutReports(): int {
    // Subquery: jurisdictions that DO have at least one service_request node
    // via the group_relationship association. We count the complement.
    // Table: `group_relationship_field_data` (Group 4.x / Drupal 11).
    $with_reports = $this->database
      ->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['gid'])
      ->condition('gr.type', 'jur-group_node-service_request')
      ->distinct();

    return (int) $this->database
      ->select('groups_field_data', 'g')
      ->condition('g.type', 'jur')
      ->condition('g.status', 1)
      ->condition('g.default_langcode', 1)
      ->condition('g.id', $with_reports, 'NOT IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Count published service_request nodes across all jurisdictions.
   */
  private function countPublishedReports(): int {
    return (int) $this->database
      ->select('node_field_data', 'n')
      ->condition('n.type', 'service_request')
      ->condition('n.status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Count published service_request nodes created since the given timestamp.
   */
  private function countReportsSince(int $since): int {
    return (int) $this->database
      ->select('node_field_data', 'n')
      ->condition('n.type', 'service_request')
      ->condition('n.status', 1)
      ->condition('n.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Count jurisdictions (jur groups) created since the given timestamp.
   */
  private function countJurisdictionsCreatedSince(int $since): int {
    return (int) $this->database
      ->select('groups_field_data', 'g')
      ->condition('g.type', 'jur')
      ->condition('g.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
