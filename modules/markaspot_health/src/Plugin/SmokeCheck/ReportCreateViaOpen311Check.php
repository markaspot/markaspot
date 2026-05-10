<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * End-to-end create check against /georeport/v2/requests.json.
 *
 * Counterpart to the F-21 scope-lock smoke (which asserts rejections only).
 * This one drives the happy path: a properly-scoped, in-boundary, valid
 * service_code POST must reach createNode() and return a request_id. Catches
 * regressions where the endpoint silently 4xx's on legitimate submissions —
 * e.g. category mapping breaks, status taxonomy missing, or new validation
 * tightens past the contract.
 *
 * Cleanup: every successful POST creates a node tagged with
 * `SMOKE-{runId}` in the title. After parsing the request_id from the
 * response, the plugin deletes the node and verifies it is gone. RunId-tagged
 * titles also let an out-of-band cleanup script find orphans if the plugin
 * crashes between create and delete.
 *
 * Auto-discovers the fixture pair from existing config:
 * - api_key whose owner is a member of exactly one jurisdiction
 * - that jurisdiction's first taxonomy_term:service_category with a non-empty
 *   field_service_code (so the GeoReport `service_code` resolution succeeds)
 * - boundary centroid as a coordinate that point-in-polygon will accept
 *
 * @SmokeCheck(
 *   id = "report_create_via_open311",
 *   label = @Translation("Create service_request via Open311 (happy path)"),
 *   severity = "error",
 *   category = "georeport",
 *   mutates = TRUE,
 *   description = @Translation("Posts a runId-tagged service_request via /georeport/v2/requests.json with a valid api_key, service_code and in-boundary coordinates, then deletes the created node."),
 *   fix_hint = @Translation("Look at the response_excerpt — common failures are a missing field_service_code on the chosen category, an unmapped initial status, or BoundaryValidator rejecting the centroid (replace with a tenant-known good coordinate via fixture seed)."),
 * )
 */
class ReportCreateViaOpen311Check extends SmokeCheckPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected HttpKernelInterface $httpKernel,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_kernel'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);
    $fixture = $this->discoverFixture($context);
    if ($fixture === NULL) {
      return $this->skip(
        'No usable fixture: need an api_key whose owner is in exactly one jurisdiction, a service_category with field_service_code on that jurisdiction, and a parseable boundary GeoJSON.',
        ['attempted' => 'fixture auto-discovery'],
        $mode,
      );
    }

    $runId = $this->generateRunId();
    $title = sprintf('SMOKE-%s-create-open311', $runId);
    // Reserved per RFC 6761 — .invalid TLD never resolves, so any
    // citizen-confirmation mail the save-hook tries to send fails fast at
    // SMTP lookup with a tenant-greppable address instead of triggering
    // Drupal's "You must provide at least one recipient" error from an
    // empty field_e_mail. The runId tag keeps cleanup discoverable.
    $body = [
      'service_code' => $fixture['service_code'],
      'jurisdiction_id' => $fixture['jurisdiction_id'],
      'api_key' => $fixture['api_key_value'],
      'description' => $title,
      'email' => sprintf('smoke-%s@example.invalid', $runId),
      'lat' => $fixture['lat'],
      'long' => $fixture['lng'],
    ];

    $response = $this->postSubmission($body);
    $evidence = [
      'fixture' => [
        'api_key_id' => $fixture['api_key_id'],
        'jurisdiction_id' => $fixture['jurisdiction_id'],
        'service_code' => $fixture['service_code'],
        'centroid' => [$fixture['lat'], $fixture['lng']],
      ],
      'run_id' => $runId,
      'http_status' => $response['status'],
      'response_excerpt' => $response['excerpt'],
    ];

    // Always parse service_request_id first, regardless of status code, so
    // a partial-success (created node + non-2xx tail) still gets cleaned up.
    // Open311 specs 201, but Drupal's REST stack returns 200; accept both.
    $serviceRequestId = $this->extractServiceRequestId($response['body']);
    if ($serviceRequestId !== NULL) {
      $evidence['service_request_id'] = $serviceRequestId;
      $cleanup = $this->cleanupNode($serviceRequestId, $runId);
      $evidence['cleanup'] = $cleanup;
    }

    $statusOk = in_array($response['status'], [200, 201], TRUE);
    if (!$statusOk) {
      return $this->fail(
        1,
        sprintf(
          'POST returned HTTP %d, expected 200 or 201. Run id %s.',
          $response['status'],
          $runId,
        ),
        $evidence,
        $mode,
      );
    }
    if ($serviceRequestId === NULL) {
      return $this->fail(
        1,
        'POST returned 2xx but the response did not contain service_request_id; the contract is broken or the format changed.',
        $evidence,
        $mode,
      );
    }
    if (!$cleanup['deleted']) {
      return $this->fail(
        1,
        sprintf(
          'Created node with request_id=%s but cleanup failed: %s. Manual delete required (search title for SMOKE-%s).',
          $serviceRequestId,
          $cleanup['reason'],
          $runId,
        ),
        $evidence,
        $mode,
      );
    }

    return $this->pass(
      sprintf(
        'Round-trip OK: HTTP %d, created request_id=%s and cleaned up node %d.',
        $response['status'],
        $serviceRequestId,
        $cleanup['nid'],
      ),
      $evidence,
      $mode,
      $fixture['jurisdiction_id'],
    );
  }

  /**
   * Discovers an api_key + jurisdiction + service_code + centroid combination.
   *
   * @return array{api_key_id: string, api_key_value: string, jurisdiction_id: int, service_code: string, lat: float, lng: float}|null
   *   Resolved fixture, or NULL when nothing usable exists on this tenant.
   */
  protected function discoverFixture(array $context): ?array {
    $apiKeyStorage = $this->entityTypeManager->getStorage('api_key');
    $userStorage = $this->entityTypeManager->getStorage('user');
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $jurisdictionType = $this->jurisdictionGroupType();

    $forcedJur = $context['jurisdiction'] ?? NULL;
    $forcedJur = is_int($forcedJur) ? $forcedJur : NULL;

    $apiKeyIds = $apiKeyStorage->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 50)
      ->execute();
    foreach ($apiKeyStorage->loadMultiple($apiKeyIds) as $apiKey) {
      $userUuid = $apiKey->get('user_uuid');
      if (!is_string($userUuid) || $userUuid === '') {
        continue;
      }
      $users = $userStorage->loadByProperties(['uuid' => $userUuid]);
      $owner = reset($users);
      if (!$owner) {
        continue;
      }
      $allowed = $this->getJurisdictionMemberships((int) $owner->id(), $jurisdictionType);
      if (count($allowed) !== 1) {
        continue;
      }
      $jurId = (int) $allowed[0];
      if ($forcedJur !== NULL && $forcedJur !== $jurId) {
        continue;
      }
      $jur = $groupStorage->load($jurId);
      if (!$jur || $jur->bundle() !== $jurisdictionType) {
        continue;
      }
      $serviceCode = $this->discoverServiceCode($jur);
      if ($serviceCode === NULL) {
        continue;
      }
      $centroid = $this->boundaryCentroid($jur);
      if ($centroid === NULL) {
        continue;
      }
      $keyValue = $apiKey->get('key');
      if (!is_string($keyValue) || $keyValue === '') {
        continue;
      }
      return [
        'api_key_id' => (string) $apiKey->id(),
        'api_key_value' => $keyValue,
        'jurisdiction_id' => $jurId,
        'service_code' => $serviceCode,
        'lat' => $centroid[0],
        'lng' => $centroid[1],
      ];
    }
    return NULL;
  }

  /**
   * Picks the first taxonomy_term:service_category with field_service_code.
   *
   * The term ↔ jurisdiction relationship in the running profile is stored
   * via field_jurisdiction ON THE TERM (not field_service_categories on the
   * jur group, which is currently a forward-looking duplicate that some
   * tenants have not populated yet). GeoreportProcessorService uses the
   * term-side relation when resolving service_code → tid; we mirror that
   * to stay aligned with the production code path.
   */
  protected function discoverServiceCode($jurisdiction): ?string {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return NULL;
    }
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = (array) $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_category')
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->exists('field_service_code')
      ->range(0, 25)
      ->execute();
    if ($tids === []) {
      return NULL;
    }
    foreach ($termStorage->loadMultiple($tids) as $term) {
      $code = (string) ($term->get('field_service_code')->value ?? '');
      if ($code !== '') {
        return $code;
      }
    }
    return NULL;
  }

  /**
   * Computes a boundary centroid the BoundaryValidator will accept.
   *
   * Average of every coordinate in the GeoJSON. Works for the convex-ish
   * municipal polygons we ship; a tenant with an L-shaped boundary may need
   * a tenant-specific override (out of scope here — the plugin will skip if
   * BoundaryValidator rejects the centroid in a follow-up enforcement).
   *
   * Adds a small random offset (~50 m at typical latitudes) so consecutive
   * runs do not collide with the markaspot duplicate-by-radius detector,
   * which rejects same-category reports within 10 m of an existing one.
   * The jitter stays well inside the smallest realistic municipal polygon.
   *
   * @return array{0: float, 1: float}|null
   *   [lat, lng] pair, or NULL when the boundary is missing or unparseable.
   */
  protected function boundaryCentroid($jurisdiction): ?array {
    if (!$jurisdiction->hasField('field_boundary')) {
      return NULL;
    }
    $raw = (string) ($jurisdiction->get('field_boundary')->value ?? '');
    if ($raw === '') {
      return NULL;
    }
    $geojson = json_decode($raw, TRUE);
    if (!is_array($geojson)) {
      return NULL;
    }
    $coords = $this->collectCoordinates($geojson);
    if ($coords === []) {
      return NULL;
    }
    $sumLng = 0.0;
    $sumLat = 0.0;
    foreach ($coords as [$lng, $lat]) {
      $sumLng += $lng;
      $sumLat += $lat;
    }
    $n = count($coords);
    // ~0.0005 degrees ≈ 55 m at lat 50°. The dupe detector uses a 10 m
    // radius, so this guarantees a fresh location per run while staying
    // far inside any realistic municipal polygon.
    $jitter = static fn(): float => (random_int(0, 1000) - 500) / 1_000_000;
    return [
      round($sumLat / $n + $jitter(), 6),
      round($sumLng / $n + $jitter(), 6),
    ];
  }

  /**
   * Walks any GeoJSON geometry and returns its [lng, lat] pairs.
   *
   * GeoJSON nests coordinates differently per geometry type (Point, Polygon,
   * MultiPolygon, FeatureCollection). Recurse until we hit a numeric pair.
   *
   * @return array<int, array{0: float, 1: float}>
   *   Flat list of [lng, lat] pairs collected from the geometry tree.
   */
  protected function collectCoordinates(array $node): array {
    if (isset($node['type']) && $node['type'] === 'FeatureCollection' && isset($node['features']) && is_array($node['features'])) {
      $out = [];
      foreach ($node['features'] as $feature) {
        if (is_array($feature)) {
          $out = array_merge($out, $this->collectCoordinates($feature));
        }
      }
      return $out;
    }
    if (isset($node['geometry']) && is_array($node['geometry'])) {
      return $this->collectCoordinates($node['geometry']);
    }
    if (isset($node['coordinates']) && is_array($node['coordinates'])) {
      return $this->walkCoordinates($node['coordinates']);
    }
    return [];
  }

  /**
   * Recursive coordinate walker.
   *
   * @return array<int, array{0: float, 1: float}>
   *   Flat list of [lng, lat] pairs collected by depth-first traversal.
   */
  protected function walkCoordinates(array $coords): array {
    if ($coords === []) {
      return [];
    }
    if (is_numeric($coords[0]) && isset($coords[1]) && is_numeric($coords[1])) {
      return [[(float) $coords[0], (float) $coords[1]]];
    }
    $out = [];
    foreach ($coords as $child) {
      if (is_array($child)) {
        $out = array_merge($out, $this->walkCoordinates($child));
      }
    }
    return $out;
  }

  /**
   * Issues the POST through the HttpKernel as a MAIN_REQUEST.
   *
   * Same auth/transport caveats as the F-21 plugin: MAIN_REQUEST so the
   * AuthenticationSubscriber runs, api_key in body to keep it out of access
   * logs.
   *
   * @return array{status: int, body: string, excerpt: string}
   *   Status code, full response body, and a 240-char excerpt for evidence.
   */
  protected function postSubmission(array $body): array {
    $request = Request::create(
      '/georeport/v2/requests.json',
      'POST',
      [],
      [],
      [],
      [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
      ],
      Json::encode($body),
    );
    $request->request->set('api_key', $body['api_key']);
    try {
      $response = $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
      $status = $response->getStatusCode();
      $bodyContent = (string) $response->getContent();
      $excerpt = substr($bodyContent, 0, 240);
    }
    catch (\Throwable $e) {
      $status = 0;
      $bodyContent = '';
      $excerpt = $e::class . ': ' . substr($e->getMessage(), 0, 200);
    }
    return ['status' => $status, 'body' => $bodyContent, 'excerpt' => $excerpt];
  }

  /**
   * Extracts service_request_id from the create-response body.
   *
   * Open311 wraps the id under
   * service_requests.request.service_request_id. Some implementations
   * unwrap to a top-level service_request_id; check both shapes.
   */
  protected function extractServiceRequestId(string $body): ?string {
    if ($body === '') {
      return NULL;
    }
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    $candidate = $decoded['service_requests']['request']['service_request_id']
      ?? $decoded['service_request_id']
      ?? NULL;
    if (is_array($candidate)) {
      $candidate = reset($candidate);
    }
    return is_string($candidate) || is_int($candidate) ? (string) $candidate : NULL;
  }

  /**
   * Deletes the node belonging to the request_id, scoped by runId marker.
   *
   * Request_id is not globally unique across tenants in the running schema;
   * a single id can resurface on multiple nodes after large imports or
   * cross-tenant migrations. Match on (request_id AND body LIKE SMOKE-runId)
   * so cleanup never touches a sibling node that happens to share the id.
   *
   * @return array{deleted: bool, nid: int|null, reason: string}
   *   deleted=TRUE only when the matched node is gone after delete().
   */
  protected function cleanupNode(string $serviceRequestId, string $runId): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $marker = 'SMOKE-' . $runId;
    $nids = (array) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('request_id', $serviceRequestId)
      ->condition('body.value', '%' . $this->database->escapeLike($marker) . '%', 'LIKE')
      ->range(0, 5)
      ->execute();
    if ($nids === []) {
      // Body-marker query may fail on tenants without body access; fall back
      // to request_id alone but only when exactly one match exists, so we
      // never silently nuke a duplicate from another smoke run.
      $candidates = (array) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'service_request')
        ->condition('request_id', $serviceRequestId)
        ->range(0, 5)
        ->execute();
      if (count($candidates) !== 1) {
        return [
          'deleted' => FALSE,
          'nid' => NULL,
          'reason' => sprintf(
            'no node matched (request_id=%s, body LIKE %s); fallback found %d candidate(s) — abort.',
            $serviceRequestId,
            $marker,
            count($candidates),
          ),
        ];
      }
      $nids = $candidates;
    }
    $nid = (int) reset($nids);
    try {
      $node = $storage->load($nid);
      if ($node) {
        $node->delete();
      }
      $storage->resetCache([$nid]);
      $remaining = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('nid', $nid)
        ->count()
        ->execute();
      if ($remaining > 0) {
        return ['deleted' => FALSE, 'nid' => $nid, 'reason' => 'node ' . $nid . ' still present after delete()'];
      }
      return ['deleted' => TRUE, 'nid' => $nid, 'reason' => 'ok'];
    }
    catch (\Throwable $e) {
      return ['deleted' => FALSE, 'nid' => $nid, 'reason' => $e::class . ': ' . substr($e->getMessage(), 0, 160)];
    }
  }

  /**
   * Returns a runId-style identifier safe for titles + grep.
   */
  protected function generateRunId(): string {
    return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
  }

  /**
   * Returns sorted jurisdiction group ids the user is a direct member of.
   *
   * @return int[]
   *   Sorted, deduplicated jurisdiction group ids the user belongs to.
   */
  protected function getJurisdictionMemberships(int $uid, string $jurisdictionType): array {
    if ($uid <= 0) {
      return [];
    }
    try {
      $query = $this->database->select('group_relationship_field_data', 'gr')
        ->fields('gr', ['gid'])
        ->condition('gr.entity_id', $uid)
        ->condition('gr.plugin_id', 'group_membership');
      $query->join('groups_field_data', 'g', 'g.id = gr.gid');
      $query->condition('g.type', $jurisdictionType);
      $rows = array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
      sort($rows, SORT_NUMERIC);
      return $rows;
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Returns the configured jurisdiction group bundle, defaulting to "jur".
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory->get('markaspot_open311.settings')->get('jurisdiction_group_type');
    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
