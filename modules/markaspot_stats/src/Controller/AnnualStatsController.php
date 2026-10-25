<?php

declare(strict_types=1);

namespace Drupal\markaspot_stats\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\markaspot_group\Service\FormOnlyReportQueryScope;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Counts incoming reports by calendar year, independently of archive state.
 */
class AnnualStatsController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  final public function __construct(
    private readonly Connection $database,
    private readonly JurisdictionHierarchyResolverInterface $hierarchy,
    private readonly FormOnlyReportQueryScope $reportScope,
    private readonly ConfigFactoryInterface $dateConfigFactory,
    private readonly WorkspaceVisibilityInterface $visibility,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('markaspot_group.hierarchy_resolver'),
      $container->get('markaspot_group.form_only_report_query_scope'),
      $container->get('config.factory'),
      $container->get('markaspot_group.workspace_visibility'),
    );
  }

  /**
   * Returns unique report counts in the site's configured calendar timezone.
   */
  public function getStats(Request $request): JsonResponse {
    $raw = $request->query->get('jurisdiction_id') ?: $request->query->get('jurisdiction');
    if ($raw === NULL || $raw === '') {
      throw new BadRequestHttpException('A jurisdiction is required.');
    }
    $jurisdictionId = $this->resolveJurisdictionId($raw);
    $jurisdictionIds = $jurisdictionId ? $this->hierarchy->getScopeJurisdictionIds($jurisdictionId) : [];
    if ($jurisdictionIds === []) {
      throw new NotFoundHttpException('Jurisdiction not found.');
    }

    $viewer = $this->visibility->requestUsesPublicApiKey($request) ? new AnonymousUserSession() : $this->currentUser();
    if (!$this->visibility->allowsReadFor($viewer, $jurisdictionId)) {
      throw new AccessDeniedHttpException('Workspace reports are not readable.');
    }

    $timezone = new \DateTimeZone($this->dateConfigFactory->get('system.date')->get('timezone.default') ?: 'UTC');
    $restriction = $this->reportScope->getSqlRestrictionForRequest($request, $viewer);
    // EXISTS avoids duplicate counts for multiple or translated relationships.
    // No publication, current status, contact or revision filter belongs here.
    $where = "n.type = :type AND n.default_langcode = 1
      AND EXISTS (SELECT 1 FROM {group_relationship_field_data} gr
        WHERE gr.entity_id = n.nid AND gr.plugin_id = :plugin
        AND gr.gid IN (:jurisdictions[])) $restriction";
    $params = [
      ':type' => 'service_request',
      ':plugin' => 'group_node:service_request',
      ':jurisdictions[]' => $jurisdictionIds,
    ];
    $unreadable = $this->visibility->getUnreadableJurisdictionIds($viewer);
    if ($unreadable !== []) {
      // A public parent aggregate must not reveal a restricted child's count,
      // even if a report also has a relationship to the parent jurisdiction.
      $where .= ' AND NOT EXISTS (SELECT 1 FROM {group_relationship_field_data} denied
        WHERE denied.entity_id = n.nid AND denied.plugin_id = :denied_plugin
        AND denied.gid IN (:unreadable[]))';
      $params[':denied_plugin'] = 'group_node:service_request';
      $params[':unreadable[]'] = $unreadable;
    }
    $bounds = $this->database->query(
      "SELECT MIN(n.created) AS first_created, MAX(n.created) AS last_created
       FROM {node_field_data} n WHERE $where", $params,
    )->fetchAssoc();
    if ($bounds === FALSE || $bounds['first_created'] === NULL) {
      return $this->response([], $timezone);
    }

    $firstYear = (int) (new \DateTimeImmutable('@' . $bounds['first_created']))->setTimezone($timezone)->format('Y');
    $lastYear = (int) (new \DateTimeImmutable('@' . $bounds['last_created']))->setTimezone($timezone)->format('Y');
    if ($firstYear < 1 || $lastYear > 9999 || $lastYear - $firstYear > 1000) {
      throw new \UnexpectedValueException('Report timestamps exceed the supported calendar range.');
    }

    // Calculate exact timezone boundaries in PHP. This also works when the
    // database has no timezone tables, including historical timezone changes.
    $expression = 'CASE';
    $years = [];
    for ($year = $firstYear; $year <= $lastYear; $year++) {
      $start = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $timezone);
      $params[":start_$year"] = $start->getTimestamp();
      $params[":end_$year"] = $start->modify('+1 year')->getTimestamp();
      $expression .= " WHEN n.created >= :start_$year AND n.created < :end_$year THEN $year";
      $years[$year] = ['year' => $year, 'count' => 0];
    }
    $expression .= ' END';
    $rows = $this->database->query(
      "SELECT $expression AS incoming_year, COUNT(DISTINCT n.nid) AS count
       FROM {node_field_data} n WHERE $where GROUP BY incoming_year", $params,
    )->fetchAll();
    foreach ($rows as $row) {
      $year = (int) $row->incoming_year;
      if (isset($years[$year])) {
        $years[$year]['count'] = (int) $row->count;
      }
    }
    return $this->response(array_reverse(array_values($years)), $timezone);
  }

  /**
   * Builds an aggregate-only response that cannot leak via a shared cache.
   *
   * @param array<array{year: int, count: int}> $years
   *   Annual counts, newest first.
   * @param \DateTimeZone $timezone
   *   The calendar timezone used to derive year boundaries.
   */
  private function response(array $years, \DateTimeZone $timezone): JsonResponse {
    return new JsonResponse([
      'years' => $years,
      'total' => array_sum(array_column($years, 'count')),
      'timezone' => $timezone->getName(),
    ], 200, ['Cache-Control' => 'private, no-store']);
  }

}
