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
 * Asserts a POST update with status + note appends a status_note paragraph.
 *
 * Editorial workflow contract: when `/georeport/v2/requests/<id>.json`
 * receives a POST update carrying `extended_attributes.drupal.field_status`
 * and `extended_attributes.drupal.field_status_notes`, GeoreportRequestResource
 * calls createStatusNoteParagraph() and appends it to field_status_notes on
 * the node. Frontend status timelines depend on this paragraph; broken
 * integration here presents as a timeline that stops growing.
 *
 * Strategy: create a baseline node via the entity API (skipping privacy
 * presave checks by setting field_gdpr=1 directly), then drive the update
 * POST with an api_key whose owner is a member of the same jurisdiction.
 * Re-load, assert the count grew by one and the new paragraph references
 * the new status_term and the runId-tagged note text.
 *
 * Trade-off acknowledged: the baseline create skips the GeoReport submission
 * pipeline (BoundaryValidator, DoublePost, AI vision, geocoder, ECA presave).
 * That is by design — this smoke targets the status-update + paragraph-append
 * contract, which the report_create_via_open311 sibling already exercises
 * end-to-end via REST. A combined "create + transition via REST" plugin can
 * be added if regressions in the baseline-create path slip past both checks.
 *
 * Cleanup: the node is deleted unconditionally in the finally block, which
 * cascades through entity_reference_revisions and removes any status-note
 * paragraph we created.
 *
 * @SmokeCheck(
 *   id = "status_change_writes_status_note",
 *   label = @Translation("POST update with status note appends paragraph"),
 *   severity = "error",
 *   category = "georeport",
 *   mutates = TRUE,
 *   description = @Translation("Drives a POST update against /georeport/v2/requests/<id>.json with field_status + field_status_notes and asserts a new status_note paragraph appears with the new status reference and the run-id-tagged note text."),
 *   fix_hint = @Translation("Inspect GeoreportRequestResource::specialFieldHandling() — the field_status_notes path constructs the paragraph via GeoreportProcessorService::createStatusNoteParagraph(). The most likely regression is missing field_status_notes propagation in processUpdateFields()."),
 * )
 */
class StatusChangeWritesStatusNoteCheck extends SmokeCheckPluginBase {

  /**
   * Last error message captured during createServiceRequestNode().
   */
  protected ?string $lastCreateError = NULL;

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected HttpKernelInterface $httpKernel,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
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
      $container->get('database'),
      $container->get('config.factory'),
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
        'No usable fixture: need a jurisdiction with a service_category, two service_status terms, an api_key whose owner is a member of that jurisdiction, and a parseable boundary GeoJSON.',
        ['attempted' => 'fixture auto-discovery'],
        $mode,
      );
    }

    $runId = $this->generateRunId();
    $node = $this->createServiceRequestNode($fixture, $runId);
    if ($node === NULL) {
      return $this->fail(
        1,
        sprintf('Could not create the test service_request node: %s', $this->lastCreateError ?? 'unknown'),
        ['fixture' => $fixture, 'run_id' => $runId, 'error' => $this->lastCreateError],
        $mode,
      );
    }
    $nid = (int) $node->id();
    $requestId = (string) ($node->get('request_id')->value ?? '');
    $beforeCount = (int) $node->get('field_status_notes')->count();
    $statusNoteFields = $this->resolveStatusNoteFields();
    $evidence = [
      'fixture' => $fixture,
      'run_id' => $runId,
      'nid' => $nid,
      'request_id' => $requestId,
      'status_notes_before' => $beforeCount,
      'paragraph_fields' => $statusNoteFields,
    ];

    try {
      if ($requestId === '') {
        return $this->fail(
          1,
          'Created node has empty request_id; the update endpoint addresses by request_id, so the smoke cannot continue.',
          $evidence,
          $mode,
        );
      }
      $noteText = sprintf('SMOKE-%s status transition note', $runId);
      // GeoreportProcessorService::prepareNodeProperties() only accepts
      // direct field overrides via extended_attributes.drupal.*; setting
      // field_status / field_status_notes at top-level gets discarded
      // before reaching processUpdateFields().
      $patch = $this->patchSubmission($requestId, $fixture['api_key_value'], [
        'extended_attributes' => [
          'drupal' => [
            'field_status' => $fixture['status_b'],
            'field_status_notes' => $noteText,
          ],
        ],
      ]);
      $evidence['patch'] = [
        'http_status' => $patch['status'],
        'response_excerpt' => $patch['excerpt'],
      ];

      if ($patch['status'] < 200 || $patch['status'] >= 300) {
        return $this->fail(
          1,
          sprintf('Update returned HTTP %d, expected 2xx.', $patch['status']),
          $evidence,
          $mode,
        );
      }

      $reloaded = $this->entityTypeManager->getStorage('node')->loadUnchanged($nid);
      $afterCount = (int) $reloaded->get('field_status_notes')->count();
      $evidence['status_notes_after'] = $afterCount;

      if ($afterCount <= $beforeCount) {
        return $this->fail(
          1,
          sprintf('Update succeeded but field_status_notes did not grow (still %d).', $beforeCount),
          $evidence,
          $mode,
        );
      }

      $latest = $this->latestStatusNoteParagraph($reloaded);
      $latestStatus = NULL;
      $latestNote = '';
      if ($latest !== NULL) {
        if ($statusNoteFields['status_term'] !== NULL && $latest->hasField($statusNoteFields['status_term'])) {
          $latestStatus = (int) ($latest->get($statusNoteFields['status_term'])->target_id ?? 0);
        }
        if ($statusNoteFields['note_text'] !== NULL && $latest->hasField($statusNoteFields['note_text'])) {
          $latestNote = (string) ($latest->get($statusNoteFields['note_text'])->value ?? '');
        }
      }
      $evidence['latest_note_status'] = $latestStatus;
      $evidence['latest_note_text_excerpt'] = substr($latestNote, 0, 120);

      // $latestStatus is 0 (not NULL) when target_id is missing on the
      // paragraph — guard with > 0 so the assertion only triggers when the
      // field is genuinely populated and the value disagrees.
      if ($statusNoteFields['status_term'] !== NULL && $latestStatus > 0 && $latestStatus !== $fixture['status_b']) {
        return $this->fail(
          1,
          sprintf('Latest paragraph references status_term %d, expected %d.', $latestStatus, $fixture['status_b']),
          $evidence,
          $mode,
        );
      }
      if ($statusNoteFields['note_text'] !== NULL && $latestNote !== '' && !str_contains($latestNote, $runId)) {
        return $this->fail(
          1,
          'Latest paragraph note text does not contain the run-id marker — paragraph may belong to an earlier transition.',
          $evidence,
          $mode,
        );
      }

      return $this->pass(
        sprintf(
          'Update appended a status_note paragraph (notes %d → %d, status %d → %d).',
          $beforeCount,
          $afterCount,
          $fixture['status_a'],
          $fixture['status_b'],
        ),
        $evidence,
        $mode,
        $fixture['jurisdiction_id'],
      );
    }
    finally {
      try {
        $reloaded = $this->entityTypeManager->getStorage('node')->loadUnchanged($nid);
        if ($reloaded) {
          $reloaded->delete();
        }
      }
      catch (\Throwable) {
        // Title carries SMOKE-runId, orphans are findable via grep.
      }
    }
  }

  /**
   * Returns the most recently referenced field_status_notes paragraph.
   */
  protected function latestStatusNoteParagraph($node) {
    if (!$node->hasField('field_status_notes') || $node->get('field_status_notes')->isEmpty()) {
      return NULL;
    }
    $items = $node->get('field_status_notes')->referencedEntities();
    return end($items) ?: NULL;
  }

  /**
   * Discovers which paragraph fields hold the status_term + note text.
   *
   * The canonical paragraph bundle ships `field_status_term` plus
   * `field_status_note`. Two legacy field names (`field_status_note_text`,
   * `field_note`) survive on tenants migrated from older profile versions.
   * GeoreportProcessorService::createStatusNoteParagraph() writes to
   * `field_status_note` — match that first so the smoke asserts against
   * the same field production populates.
   *
   * @return array{status_term: ?string, note_text: ?string}
   *   Field names in use on the paragraph bundle, with NULL entries when
   *   no convention matches (assertion logic skips silently in that case).
   */
  protected function resolveStatusNoteFields(): array {
    $fields = ['status_term' => NULL, 'note_text' => NULL];
    if (!$this->entityTypeManager->hasDefinition('field_config')) {
      return $fields;
    }
    $defs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'paragraph',
        'bundle' => 'status',
      ]);
    $present = [];
    foreach ($defs as $def) {
      $present[$def->getName()] = TRUE;
    }
    if (isset($present['field_status_term'])) {
      $fields['status_term'] = 'field_status_term';
    }
    // Priority: canonical production field first, then legacy names. Pinning
    // an explicit order keeps the assertion deterministic across PHP versions
    // (loadByProperties() iteration order is not guaranteed).
    foreach (['field_status_note', 'field_status_note_text', 'field_note'] as $candidate) {
      if (isset($present[$candidate])) {
        $fields['note_text'] = $candidate;
        break;
      }
    }
    return $fields;
  }

  /**
   * Picks api_key + jurisdiction + service_code + status_a + status_b combo.
   *
   * @return array{api_key_id: string, api_key_value: string, owner_uid: int, jurisdiction_id: int, service_tid: int, status_a: int, status_b: int, lat: float, lng: float}|null
   *   Resolved fixture, or NULL when nothing usable exists on this tenant.
   */
  protected function discoverFixture(array $context): ?array {
    $statusPair = $this->discoverStatusPair();
    if ($statusPair === NULL) {
      return NULL;
    }

    $jurisdictionType = $this->jurisdictionGroupType();
    $forcedJur = $context['jurisdiction'] ?? NULL;
    $forcedJur = is_int($forcedJur) ? $forcedJur : NULL;

    $apiKeyStorage = $this->entityTypeManager->getStorage('api_key');
    $userStorage = $this->entityTypeManager->getStorage('user');
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $apiKeyIds = (array) $apiKeyStorage->getQuery()
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
      if ($forcedJur !== NULL && $jurId !== $forcedJur) {
        continue;
      }
      $jur = $groupStorage->load($jurId);
      if (!$jur || $jur->bundle() !== $jurisdictionType) {
        continue;
      }
      $serviceTid = $this->discoverCategoryForJurisdiction($jurId);
      if ($serviceTid === 0) {
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
        'owner_uid' => (int) $owner->id(),
        'jurisdiction_id' => $jurId,
        'service_tid' => $serviceTid,
        'status_a' => $statusPair[0],
        'status_b' => $statusPair[1],
        'lat' => $centroid[0],
        'lng' => $centroid[1],
      ];
    }
    return NULL;
  }

  /**
   * Picks two distinct service_status term ids for the transition.
   *
   * Service_status terms are global (not jurisdiction-scoped) in the
   * profile schema, so the lookup deliberately omits a tenant filter.
   *
   * @return array{0: int, 1: int}|null
   *   [start_tid, target_tid] tuple, or NULL when fewer than two terms exist.
   */
  protected function discoverStatusPair(): ?array {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return NULL;
    }
    $tids = (array) $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_status')
      ->condition('status', 1)
      ->sort('weight', 'ASC')
      ->range(0, 5)
      ->execute();
    $ids = array_values(array_map('intval', $tids));
    return count($ids) >= 2 ? [$ids[0], $ids[1]] : NULL;
  }

  /**
   * Returns the first category term id for a jurisdiction, or 0.
   */
  protected function discoverCategoryForJurisdiction(int $jurisdictionId): int {
    $tids = (array) $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_category')
      ->condition('field_jurisdiction', $jurisdictionId)
      ->range(0, 1)
      ->execute();
    if ($tids === []) {
      return 0;
    }
    return (int) reset($tids);
  }

  /**
   * Computes a boundary centroid the BoundaryValidator will accept.
   *
   * Average of every coordinate in the GeoJSON, plus a ~50 m random offset
   * so consecutive runs do not collide with the duplicate-by-radius
   * detector. Mirrors ReportCreateViaOpen311Check::boundaryCentroid().
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
    $coords = $this->collectGeoJsonCoordinates($geojson);
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
    $jitter = static fn(): float => (random_int(0, 1000) - 500) / 1_000_000;
    return [
      round($sumLat / $n + $jitter(), 6),
      round($sumLng / $n + $jitter(), 6),
    ];
  }

  /**
   * Walks any GeoJSON geometry and returns its [lng, lat] pairs.
   *
   * @return array<int, array{0: float, 1: float}>
   *   Flat list of [lng, lat] pairs collected from the geometry tree.
   */
  protected function collectGeoJsonCoordinates(array $node): array {
    if (isset($node['type']) && $node['type'] === 'FeatureCollection' && isset($node['features']) && is_array($node['features'])) {
      $out = [];
      foreach ($node['features'] as $feature) {
        if (is_array($feature)) {
          $out = array_merge($out, $this->collectGeoJsonCoordinates($feature));
        }
      }
      return $out;
    }
    if (isset($node['geometry']) && is_array($node['geometry'])) {
      return $this->collectGeoJsonCoordinates($node['geometry']);
    }
    if (isset($node['coordinates']) && is_array($node['coordinates'])) {
      return $this->walkGeoJsonCoordinates($node['coordinates']);
    }
    return [];
  }

  /**
   * Recursive coordinate walker.
   *
   * @return array<int, array{0: float, 1: float}>
   *   Flat list of [lng, lat] pairs collected by depth-first traversal.
   */
  protected function walkGeoJsonCoordinates(array $coords): array {
    if ($coords === []) {
      return [];
    }
    if (is_numeric($coords[0]) && isset($coords[1]) && is_numeric($coords[1])) {
      return [[(float) $coords[0], (float) $coords[1]]];
    }
    $out = [];
    foreach ($coords as $child) {
      if (is_array($child)) {
        $out = array_merge($out, $this->walkGeoJsonCoordinates($child));
      }
    }
    return $out;
  }

  /**
   * Creates a baseline service_request node via the entity API.
   *
   * Uses the discovered jurisdiction's boundary centroid (jittered) so the
   * coord lands inside whatever tenant `discoverFixture()` resolved to —
   * earlier hardcoded Rotterdam coords broke the smoke on Amsterdam,
   * Utrecht, BCP, etc.
   */
  protected function createServiceRequestNode(array $fixture, string $runId) {
    $title = sprintf('SMOKE-%s-status-note', $runId);
    try {
      $values = [
        'type' => 'service_request',
        'title' => $title,
        'body' => ['value' => $title, 'format' => 'plain_text'],
        'field_jurisdiction' => ['target_id' => $fixture['jurisdiction_id']],
        'field_category' => ['target_id' => $fixture['service_tid']],
        'field_status' => ['target_id' => $fixture['status_a']],
        'field_geolocation' => [
          'lat' => $fixture['lat'],
          'lng' => $fixture['lng'],
        ],
        'status' => 1,
      ];
      $fieldDefs = $this->entityTypeManager->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'node',
          'bundle' => 'service_request',
          'field_name' => 'field_gdpr',
        ]);
      if ($fieldDefs !== []) {
        $values['field_gdpr'] = 1;
      }
      $node = $this->entityTypeManager->getStorage('node')->create($values);
      $node->save();
      return $node;
    }
    catch (\Throwable $e) {
      $this->lastCreateError = $e::class . ': ' . substr($e->getMessage(), 0, 240);
      return NULL;
    }
  }

  /**
   * Issues a POST against /georeport/v2/requests/{request_id}.json.
   *
   * Open311 dialect uses POST on the single-resource URL for updates;
   * GeoreportRequestResource::post() routes through to updateNode().
   * Same MAIN_REQUEST + body-credential pattern as F-21 plugin.
   *
   * @return array{status: int, body: string, excerpt: string}
   *   Status code, full response body, and a 240-char excerpt for evidence.
   */
  protected function patchSubmission(string $requestId, string $apiKey, array $body): array {
    $url = '/georeport/v2/requests/' . rawurlencode($requestId) . '.json';
    $payload = $body + ['api_key' => $apiKey];
    $request = Request::create(
      $url,
      'POST',
      [],
      [],
      [],
      [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        // Tells DoublePostConstraintValidator to allow the save through;
        // the smoke deliberately reuses a recently created node (its own
        // baseline) to issue the status transition, which would otherwise
        // trip the radius dedup check.
        'HTTP_X_ACKNOWLEDGE_DUPLICATE' => 'true',
      ],
      Json::encode($payload),
    );
    $request->request->set('api_key', $apiKey);
    try {
      $response = $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
      $status = $response->getStatusCode();
      $bodyContent = (string) $response->getContent();
      return [
        'status' => $status,
        'body' => $bodyContent,
        'excerpt' => substr($bodyContent, 0, 240),
      ];
    }
    catch (\Throwable $e) {
      return [
        'status' => 0,
        'body' => '',
        'excerpt' => $e::class . ': ' . substr($e->getMessage(), 0, 200),
      ];
    }
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

  /**
   * Returns a runId-style identifier safe for titles + grep.
   */
  protected function generateRunId(): string {
    return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
  }

}
