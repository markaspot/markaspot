<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_health\SmokeCheckResult;
use Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * End-to-end facility create check against /georeport/v2/requests.json.
 *
 * @SmokeCheck(
 *   id = "report_create_facility_via_open311",
 *   label = @Translation("Create facility service_request via Open311"),
 *   severity = "error",
 *   category = "georeport",
 *   mutates = TRUE,
 *   description = @Translation("Posts a facility-tagged service_request via /georeport/v2/requests.json, verifies the stored facility, geodata and jurisdiction relationships, then deletes the created node."),
 *   fix_hint = @Translation("Check field_facility create mapping, FacilityOwnership validation, facility catalogue mode and child-jurisdiction boundaries."),
 * )
 */
final class ReportCreateFacilityViaOpen311Check extends ReportCreateViaOpen311Check {

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    HttpKernelInterface $httpKernel,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    Connection $database,
    protected ?FacilityManager $facilityManager,
    protected JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $httpKernel,
      $entityTypeManager,
      $configFactory,
      $database,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_kernel'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('database'),
      $container->has('markaspot_facility.manager') ? $container->get('markaspot_facility.manager') : NULL,
      $container->get('markaspot_group.hierarchy_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);
    if ($this->facilityManager === NULL) {
      return $this->skip(
        'Facility manager service is unavailable; facility smoke check skipped.',
        ['service' => 'markaspot_facility.manager'],
        $mode,
      );
    }

    $fixture = $this->discoverFacilityFixture($context);
    if ($fixture === NULL) {
      return $this->skip(
        'No usable fixture: need the regular Open311 create fixture plus an exclusive-mode active facility with numeric coordinates.',
        ['attempted' => 'facility fixture auto-discovery'],
        $mode,
      );
    }

    $runId = $this->generateRunId();
    $title = sprintf('SMOKE-%s-create-open311-facility', $runId);
    $body = [
      'service_code' => $fixture['service_code'],
      'jurisdiction_id' => $fixture['jurisdiction_id'],
      'api_key' => $fixture['api_key_value'],
      'description' => $title,
      'email' => sprintf('smoke-%s@example.invalid', $runId),
      'field_facility' => $fixture['facility_id'],
      'lat' => $fixture['facility_lat'],
      'long' => $fixture['facility_lng'],
    ];

    $response = $this->postSubmission($body);
    $evidence = [
      'fixture' => [
        'api_key_id' => $fixture['api_key_id'],
        'jurisdiction_id' => $fixture['jurisdiction_id'],
        'service_code' => $fixture['service_code'],
        'facility_id' => $fixture['facility_id'],
        'facility_label' => $fixture['facility_label'],
        'facility_coordinates' => [$fixture['facility_lat'], $fixture['facility_lng']],
        'facility_address' => $fixture['facility_address'],
        'expected_child_jurisdiction_id' => $fixture['expected_child_jurisdiction_id'],
      ],
      'run_id' => $runId,
      'http_status' => $response['status'],
      'response_excerpt' => $response['excerpt'],
    ];

    $serviceRequestId = $this->extractServiceRequestId($response['body']);
    $verification = NULL;
    if ($serviceRequestId !== NULL) {
      $evidence['service_request_id'] = $serviceRequestId;
      $verification = $this->verifyCreatedNode($serviceRequestId, $runId, $fixture);
      $evidence['verification'] = $verification;
      $cleanup = $this->cleanupNode($serviceRequestId, $runId);
      $evidence['cleanup'] = $cleanup;
    }

    if (!in_array($response['status'], [200, 201], TRUE)) {
      return $this->fail(
        1,
        sprintf('POST returned HTTP %d, expected 200 or 201. Run id %s.', $response['status'], $runId),
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
    if ($verification === NULL || !$verification['ok']) {
      return $this->fail(
        1,
        'Created facility request failed stored-field verification.',
        $evidence,
        $mode,
      );
    }
    if (!$cleanup['deleted']) {
      return $this->fail(
        1,
        sprintf('Created node with request_id=%s but cleanup failed: %s.', $serviceRequestId, $cleanup['reason']),
        $evidence,
        $mode,
      );
    }

    return $this->pass(
      sprintf(
        'Facility round-trip OK: request_id=%s facility=%s node=%d.',
        $serviceRequestId,
        $fixture['facility_id'],
        $cleanup['nid'],
      ),
      $evidence,
      $mode,
      $fixture['jurisdiction_id'],
    );
  }

  /**
   * Discovers an Open311 fixture plus one usable exclusive-mode facility.
   *
   * @return array<string, mixed>|null
   *   Resolved fixture, or NULL when the tenant has no usable data.
   */
  protected function discoverFacilityFixture(array $context): ?array {
    $jurisdictionIds = $this->exclusiveFacilityJurisdictionIds();
    if (isset($context['jurisdiction']) && is_int($context['jurisdiction'])) {
      array_unshift($jurisdictionIds, $context['jurisdiction']);
      $jurisdictionIds = array_values(array_unique($jurisdictionIds));
    }

    foreach ($jurisdictionIds as $jurisdictionId) {
      $fixture = parent::discoverFixture(array_replace($context, ['jurisdiction' => $jurisdictionId]));
      if ($fixture === NULL) {
        continue;
      }
      $facilityFixture = $this->discoverFacilityForOpen311Fixture($fixture);
      if ($facilityFixture !== NULL) {
        return $facilityFixture;
      }
    }

    return NULL;
  }

  /**
   * Adds facility metadata to a regular Open311 create fixture.
   *
   * @param array<string, mixed> $fixture
   *   Regular Open311 fixture metadata.
   *
   * @return array<string, mixed>|null
   *   Facility fixture metadata, or NULL when that jurisdiction is unusable.
   */
  private function discoverFacilityForOpen311Fixture(array $fixture): ?array {
    $group = $this->entityTypeManager->getStorage('group')->load($fixture['jurisdiction_id']);
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    $settings = $this->facilityManager->getDashboardSettings($group);
    if (($settings['mode'] ?? 'disabled') !== 'exclusive') {
      return NULL;
    }

    $items = $settings['items'] ?? [];
    if (!is_array($items) || $items === []) {
      return NULL;
    }

    $fallback = NULL;
    foreach ($items as $facility) {
      if (!is_array($facility) || ($facility['active'] ?? TRUE) === FALSE) {
        continue;
      }
      if (empty($facility['id']) || empty($facility['label']) || !is_numeric($facility['lat'] ?? NULL) || !is_numeric($facility['lng'] ?? NULL)) {
        continue;
      }

      $facilityFixture = $fixture + [
        'facility_id' => (string) $facility['id'],
        'facility_label' => (string) $facility['label'],
        'facility_lat' => (float) $facility['lat'],
        'facility_lng' => (float) $facility['lng'],
        'facility_address' => $this->summarizeFacilityAddress($facility['address'] ?? NULL),
        'expected_child_jurisdiction_id' => $this->findChildJurisdictionContainingPoint(
          (int) $fixture['jurisdiction_id'],
          (float) $facility['lat'],
          (float) $facility['lng'],
        ),
      ];

      if ($facilityFixture['expected_child_jurisdiction_id'] !== NULL) {
        return $facilityFixture;
      }
      $fallback ??= $facilityFixture;
    }

    return $fallback;
  }

  /**
   * Returns jurisdictions that currently expose exclusive facilities.
   *
   * @return int[]
   *   Jurisdiction ids sorted by entity query order.
   */
  private function exclusiveFacilityJurisdictionIds(): array {
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $ids = $groupStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType())
      ->execute();

    $jurisdictionIds = [];
    foreach ($groupStorage->loadMultiple($ids) as $group) {
      if (!$group instanceof GroupInterface) {
        continue;
      }
      $settings = $this->facilityManager->getDashboardSettings($group);
      $items = $settings['items'] ?? [];
      if (($settings['mode'] ?? 'disabled') === 'exclusive' && is_array($items) && $items !== []) {
        $jurisdictionIds[] = (int) $group->id();
      }
    }

    return $jurisdictionIds;
  }

  /**
   * Verifies the node created by the Open311 facility POST.
   *
   * @param string $serviceRequestId
   *   Open311 service_request_id returned by the API response.
   * @param string $runId
   *   Smoke run marker embedded in the created node body.
   * @param array<string, mixed> $fixture
   *   Facility fixture metadata.
   *
   * @return array<string, mixed>
   *   Verification evidence with ok=false when any assertion fails.
   */
  private function verifyCreatedNode(string $serviceRequestId, string $runId, array $fixture): array {
    $node = $this->loadCreatedNode($serviceRequestId, $runId);
    if (!$node instanceof ContentEntityInterface) {
      return ['ok' => FALSE, 'errors' => ['created node not found']];
    }

    $errors = [];
    $storedFacility = $node->hasField('field_facility') && !$node->get('field_facility')->isEmpty()
      ? trim((string) $node->get('field_facility')->getString())
      : '';
    if ($storedFacility !== $fixture['facility_id']) {
      $errors[] = sprintf('field_facility=%s expected %s', $storedFacility, $fixture['facility_id']);
    }

    $storedCoordinates = $this->nodeCoordinates($node);
    if ($storedCoordinates === NULL) {
      $errors[] = 'field_geolocation missing';
    }
    else {
      [$lat, $lng] = $storedCoordinates;
      if (abs($lat - (float) $fixture['facility_lat']) > 0.000001 || abs($lng - (float) $fixture['facility_lng']) > 0.000001) {
        $errors[] = sprintf('field_geolocation=%s,%s expected %s,%s', $lat, $lng, $fixture['facility_lat'], $fixture['facility_lng']);
      }
    }

    $storedAddress = $node->hasField('field_address') && !$node->get('field_address')->isEmpty()
      ? trim((string) $node->get('field_address')->getString())
      : '';
    if ($fixture['facility_address'] !== '' && $storedAddress === '') {
      $errors[] = 'field_address empty despite fixture address';
    }

    $storedJurisdictionId = $node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()
      ? (int) $node->get('field_jurisdiction')->target_id
      : NULL;
    $relationshipJurisdictionIds = $this->jurisdictionRelationshipIds((int) $node->id());
    $expectedChild = $fixture['expected_child_jurisdiction_id'];
    if ($expectedChild !== NULL && !in_array((int) $expectedChild, $relationshipJurisdictionIds, TRUE)) {
      $errors[] = sprintf('missing child jurisdiction relationship %d', (int) $expectedChild);
    }

    return [
      'ok' => $errors === [],
      'errors' => $errors,
      'nid' => (int) $node->id(),
      'stored_facility' => $storedFacility,
      'stored_coordinates' => $storedCoordinates,
      'stored_address' => $storedAddress,
      'stored_jurisdiction_id' => $storedJurisdictionId,
      'relationship_jurisdiction_ids' => $relationshipJurisdictionIds,
    ];
  }

  /**
   * Loads the node belonging to the request id and run marker.
   */
  private function loadCreatedNode(string $serviceRequestId, string $runId): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $marker = 'SMOKE-' . $runId;
    $nids = (array) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('request_id', $serviceRequestId)
      ->condition('body.value', '%' . $this->database->escapeLike($marker) . '%', 'LIKE')
      ->range(0, 2)
      ->execute();
    if (count($nids) !== 1) {
      return NULL;
    }

    $node = $storage->load((int) reset($nids));
    return $node instanceof ContentEntityInterface ? $node : NULL;
  }

  /**
   * Returns node coordinates as [lat, lng].
   *
   * @return array{0: float, 1: float}|null
   *   Stored coordinates, or NULL when unavailable.
   */
  private function nodeCoordinates(ContentEntityInterface $node): ?array {
    if (!$node->hasField('field_geolocation') || $node->get('field_geolocation')->isEmpty()) {
      return NULL;
    }

    $item = $node->get('field_geolocation')->first();
    $lat = (float) $item->get('lat')->getValue();
    $lng = (float) $item->get('lng')->getValue();
    return [$lat, $lng];
  }

  /**
   * Finds the deepest child jurisdiction boundary containing a point.
   */
  private function findChildJurisdictionContainingPoint(int $rootJurisdictionId, float $lat, float $lng): ?int {
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $groupIds = $groupStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType())
      ->exists('field_boundary')
      ->execute();
    if ($groupIds === []) {
      return NULL;
    }

    $matchedChildIds = [];
    foreach ($groupStorage->loadMultiple($groupIds) as $group) {
      if (!$group instanceof GroupInterface) {
        continue;
      }
      if (!$group->hasField('field_parent_jurisdiction') || $group->get('field_parent_jurisdiction')->isEmpty()) {
        continue;
      }
      if ($this->rootJurisdictionId((int) $group->id()) !== $rootJurisdictionId) {
        continue;
      }
      if ($group->get('field_boundary')->isEmpty()) {
        continue;
      }
      $boundary = GeoJsonBoundary::fromJson((string) $group->get('field_boundary')->value);
      if ($boundary && $boundary->contains($lng, $lat)) {
        $matchedChildIds[] = (int) $group->id();
      }
    }

    if ($matchedChildIds === []) {
      return NULL;
    }
    if (count($matchedChildIds) === 1) {
      return $matchedChildIds[0];
    }

    $parentIdsInSet = [];
    foreach ($groupStorage->loadMultiple($matchedChildIds) as $group) {
      if ($group instanceof GroupInterface && $group->hasField('field_parent_jurisdiction') && !$group->get('field_parent_jurisdiction')->isEmpty()) {
        $parentIdsInSet[(int) $group->get('field_parent_jurisdiction')->target_id] = TRUE;
      }
    }
    foreach ($matchedChildIds as $id) {
      if (!isset($parentIdsInSet[$id])) {
        return $id;
      }
    }

    return $matchedChildIds[0];
  }

  /**
   * Returns the root jurisdiction id for a group.
   */
  private function rootJurisdictionId(int $groupId): ?int {
    return $this->hierarchyResolver->getRootJurisdictionId($groupId);
  }

  /**
   * Gets jurisdiction group relationship ids for a node.
   *
   * @return int[]
   *   Sorted jurisdiction group ids.
   */
  private function jurisdictionRelationshipIds(int $nid): array {
    try {
      $query = $this->database->select('group_relationship_field_data', 'gr')
        ->fields('gr', ['gid'])
        ->condition('gr.entity_id', $nid)
        ->condition('gr.plugin_id', 'group_node:service_request');
      $query->join('groups_field_data', 'g', 'g.id = gr.gid');
      $query->condition('g.type', $this->jurisdictionGroupType());
      $ids = array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
      sort($ids, SORT_NUMERIC);
      return $ids;
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Summarizes a facility address value for smoke evidence.
   */
  private function summarizeFacilityAddress(mixed $address): string {
    if (is_string($address)) {
      return trim($address);
    }
    if (!is_array($address)) {
      return '';
    }

    $parts = [];
    foreach (['address_line1', 'postal_code', 'locality', 'country_code'] as $key) {
      if (!empty($address[$key]) && is_scalar($address[$key])) {
        $parts[] = trim((string) $address[$key]);
      }
    }
    return implode(', ', array_filter($parts, static fn(string $part): bool => $part !== ''));
  }

}
