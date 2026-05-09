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
 * Acceptance test for F-21: GeoReport submission scope-lock.
 *
 * Two POST legs against /georeport/v2/requests.json with a real api_key:
 * 1. Scope-lock leg: claim a jurisdiction the api_key owner is NOT a member
 *    of. Expect a non-2xx response (403). Catches regressions where the
 *    JurisdictionScopeValidator stops being wired into the endpoint.
 * 2. Boundary leg: claim the in-scope jurisdiction but submit lat/long far
 *    outside its boundary. Expect 4xx (422 in the canonical implementation).
 *    Catches regressions where the BoundaryValidator stops running.
 *
 * Both legs expect rejection BEFORE service-code or title validation, so the
 * body stays minimal and no node is ever created. No cleanup needed.
 *
 * Each leg also asserts a validator-specific marker substring in the response
 * body. A 4xx for the wrong reason (e.g. service_code-not-found 404 if the
 * validators silently fail open) would otherwise pass the status check and
 * mask the regression the smoke is supposed to catch.
 *
 * Fixtures: an api_key whose owner is a member of exactly one jurisdiction
 * group. Auto-discovered from existing api_key config entities. Without a
 * usable fixture the check skips with setup hints — it never creates users.
 *
 * @SmokeCheck(
 *   id = "request_create_jurisdiction_scope_lock",
 *   label = @Translation("GeoReport submission scope-lock (F-21)"),
 *   severity = "error",
 *   category = "georeport",
 *   mutates = TRUE,
 *   description = @Translation("Asserts JurisdictionScopeValidator and BoundaryValidator reject cross-jurisdiction and out-of-boundary POSTs to /georeport/v2/requests.json."),
 *   fix_hint = @Translation("Verify markaspot_group.jurisdiction_scope_validator and markaspot_validation.boundary_validator services are injected into GeoreportRequestIndexResource. See modules/markaspot_open311/src/Plugin/rest/resource/GeoreportRequestIndexResource.php::post()."),
 * )
 */
class RequestCreateJurisdictionScopeLockCheck extends SmokeCheckPluginBase {

  /**
   * Latitude/longitude clearly outside any real-world municipal boundary.
   *
   * Null Island in the Atlantic. No tenant should ever have a boundary that
   * contains 0/0 — using it for the boundary leg avoids guessing whether a
   * picked offset still lands inside a sprawling polygon (e.g. Amsterdam's
   * harbour annex stretching far south-east).
   */
  private const OUT_OF_BOUNDARY_LAT = 0.0;
  private const OUT_OF_BOUNDARY_LNG = 0.0;

  /**
   * Marker phrase emitted by JurisdictionScopeValidator on foreign-scope 403.
   *
   * @see \Drupal\markaspot_group\Service\JurisdictionScopeValidator::resolveSubmissionJurisdiction()
   */
  private const SCOPE_LOCK_MARKER = 'not authorized for jur';

  /**
   * Marker phrase emitted by BoundaryValidator on out-of-boundary 422.
   *
   * @see \Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource::enforceSubmissionBoundary()
   */
  private const BOUNDARY_MARKER = 'coordinates outside jurisdiction boundary';

  /**
   * Maximum number of api_key entities scanned during fixture discovery.
   *
   * Bounded so a tenant with hundreds of seeded keys does not turn the smoke
   * into an O(n) hydration walk. The first usable key wins and the loop
   * short-circuits, so 50 is comfortably above the typical fixture count
   * while still capping the worst case.
   */
  private const FIXTURE_SCAN_LIMIT = 50;

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
        'No usable fixture: need an api_key whose owner is a member of exactly one jurisdiction group, plus a second jurisdiction to test cross-scope rejection. Pass --jurisdiction-other=N or seed an api_key (see services_api_key_auth.api_key.*.yml).',
        ['attempted' => 'fixture auto-discovery'],
        $mode,
      );
    }

    $evidence = [
      'fixture' => [
        'api_key_id' => $fixture['api_key_id'],
        'owner_uid' => $fixture['owner_uid'],
        'in_scope_jurisdiction' => $fixture['in_scope_gid'],
        'out_of_scope_jurisdiction' => $fixture['out_of_scope_gid'],
      ],
    ];

    $crossScope = $this->postSubmission(
      $fixture['api_key_value'],
      $fixture['out_of_scope_gid'],
      NULL,
      NULL,
    );
    $evidence['scope_lock_leg'] = [
      'claimed_jurisdiction_id' => $fixture['out_of_scope_gid'],
      'http_status' => $crossScope['status'],
      'response_excerpt' => $crossScope['excerpt'],
    ];
    $scopeFailure = $this->validateLegResponse(
      $crossScope,
      self::SCOPE_LOCK_MARKER,
      sprintf(
        'Scope-lock leg: cross-jurisdiction POST with api_key "%s" (scope=[%d]) claiming jurisdiction_id=%d returned HTTP %d.',
        $fixture['api_key_id'],
        $fixture['in_scope_gid'],
        $fixture['out_of_scope_gid'],
        $crossScope['status'],
      ),
      'JurisdictionScopeValidator may not be wired into createRequest() (or is rejecting for a different reason than foreign-scope).',
    );
    if ($scopeFailure !== NULL) {
      return $this->fail(1, $scopeFailure, $evidence, $mode);
    }

    $boundary = $this->postSubmission(
      $fixture['api_key_value'],
      $fixture['in_scope_gid'],
      self::OUT_OF_BOUNDARY_LAT,
      self::OUT_OF_BOUNDARY_LNG,
    );
    $evidence['boundary_leg'] = [
      'claimed_jurisdiction_id' => $fixture['in_scope_gid'],
      'lat' => self::OUT_OF_BOUNDARY_LAT,
      'long' => self::OUT_OF_BOUNDARY_LNG,
      'http_status' => $boundary['status'],
      'response_excerpt' => $boundary['excerpt'],
    ];
    $boundaryFailure = $this->validateLegResponse(
      $boundary,
      self::BOUNDARY_MARKER,
      sprintf(
        'Boundary leg: POST with api_key "%s" claiming in-scope jurisdiction_id=%d but lat/long=0/0 (Null Island) returned HTTP %d.',
        $fixture['api_key_id'],
        $fixture['in_scope_gid'],
        $boundary['status'],
      ),
      'BoundaryValidator may not be wired into createRequest() (or is rejecting for a different reason than out-of-boundary).',
    );
    if ($boundaryFailure !== NULL) {
      return $this->fail(1, $boundaryFailure, $evidence, $mode);
    }

    return $this->pass(
      sprintf(
        'F-21 enforced: scope-lock returned HTTP %d, boundary returned HTTP %d.',
        $crossScope['status'],
        $boundary['status'],
      ),
      $evidence,
      $mode,
    );
  }

  /**
   * Asserts a leg returned a 4xx with the expected validator marker substring.
   *
   * @param array{status: int, excerpt: string} $leg
   *   Status + body excerpt from postSubmission().
   * @param string $expectedMarker
   *   Substring the validator's error response must contain.
   * @param string $statusContext
   *   Pre-formatted operator-facing context for the status portion.
   * @param string $markerContext
   *   Pre-formatted operator-facing context for the marker portion.
   *
   * @return string|null
   *   Failure message when the leg did not satisfy the contract; NULL when
   *   the leg passed both the status range and the marker assertion.
   */
  protected function validateLegResponse(array $leg, string $expectedMarker, string $statusContext, string $markerContext): ?string {
    $status = $leg['status'];
    if ($status < 400 || $status >= 500) {
      return $statusContext . ' Expected 4xx. ' . $markerContext;
    }
    if (!str_contains($leg['excerpt'], $expectedMarker)) {
      return sprintf(
        '%s Response did not contain expected marker "%s". %s',
        $statusContext,
        $expectedMarker,
        $markerContext,
      );
    }
    return NULL;
  }

  /**
   * Picks a usable api_key + jurisdiction pair for the test legs.
   *
   * Strategy: find an api_key whose owner is a direct member of exactly one
   * jurisdiction group ("in-scope"). Then pick any other jurisdiction the
   * owner is NOT a member of ("out-of-scope"), preferring --jurisdiction-other
   * from context when provided.
   *
   * Bounded scan via FIXTURE_SCAN_LIMIT — once a usable api_key is found the
   * loop returns; tenants with only unusable keys at the head of the list
   * still get inspected up to the cap.
   *
   * @return array{api_key_id: string, api_key_value: string, owner_uid: int, in_scope_gid: int, out_of_scope_gid: int}|null
   *   Resolved fixture, or NULL when nothing usable exists.
   */
  protected function discoverFixture(array $context): ?array {
    $apiKeyStorage = $this->entityTypeManager->getStorage('api_key');
    $userStorage = $this->entityTypeManager->getStorage('user');
    $groupStorage = $this->entityTypeManager->getStorage('group');

    $jurisdictionType = $this->jurisdictionGroupType();
    // accessCheck(FALSE) — smoke runs as the system user, no per-user
    // visibility filter applies; we are listing tenants the operator already
    // has implicit access to via Drush.
    $allJurisdictionIds = array_map(
      'intval',
      $groupStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $jurisdictionType)
        ->execute(),
    );
    if ($allJurisdictionIds === []) {
      return NULL;
    }

    $forcedOther = $context['jurisdiction_other'] ?? NULL;
    $forcedOther = is_int($forcedOther) ? $forcedOther : NULL;

    $apiKeyIds = $apiKeyStorage->getQuery()
      ->accessCheck(FALSE)
      ->range(0, self::FIXTURE_SCAN_LIMIT)
      ->execute();
    if ($apiKeyIds === []) {
      return NULL;
    }

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

      $inScope = (int) $allowed[0];

      if ($forcedOther !== NULL) {
        if ($forcedOther === $inScope || in_array($forcedOther, $allowed, TRUE)) {
          continue;
        }
        if (!in_array($forcedOther, $allJurisdictionIds, TRUE)) {
          continue;
        }
        $outOfScope = $forcedOther;
      }
      else {
        $candidates = array_values(array_diff($allJurisdictionIds, $allowed));
        if ($candidates === []) {
          continue;
        }
        $outOfScope = (int) $candidates[0];
      }

      $keyValue = $apiKey->get('key');
      if (!is_string($keyValue) || $keyValue === '') {
        continue;
      }

      return [
        'api_key_id' => (string) $apiKey->id(),
        'api_key_value' => $keyValue,
        'owner_uid' => (int) $owner->id(),
        'in_scope_gid' => $inScope,
        'out_of_scope_gid' => $outOfScope,
      ];
    }

    return NULL;
  }

  /**
   * Issues a POST to /georeport/v2/requests.json via the HTTP kernel.
   *
   * Uses MAIN_REQUEST so Drupal's AuthenticationSubscriber actually runs and
   * lets the api_key provider resolve currentUser. The subscriber gates on
   * RequestEvent::isMainRequest(), so SUB_REQUEST would skip authentication
   * entirely and every leg would 401 as anonymous, hiding the real F-21
   * behaviour we want to assert.
   *
   * The api_key travels in the request body, NOT the query string. Reverse
   * proxies and access logs typically capture the request URI verbatim;
   * keeping the credential out of it avoids leaking it into nginx/Traefik
   * logs and dblog rows. The services_api_key_auth provider checks the body
   * (`request->request->get($postName)`) before falling through to the query
   * parameter, so this is just as recognised by the auth chain.
   *
   * @return array{status: int, excerpt: string}
   *   Response status and a short excerpt for evidence.
   */
  protected function postSubmission(string $apiKey, int $jurisdictionId, ?float $lat, ?float $lng): array {
    // jurisdiction_id MUST live in the body — that is where
    // GeoreportRequestIndexResource::resolveClaimedJurisdictionId() reads it
    // from. If only present as a query parameter, hasJurisdictionClaim()
    // returns false and the endpoint silently falls back to the api_key's
    // single membership, so the cross-jurisdiction leg never reaches the
    // scope check we are trying to assert.
    $body = [
      'service_code' => 'smoke',
      'jurisdiction_id' => $jurisdictionId,
      'api_key' => $apiKey,
    ];
    if ($lat !== NULL) {
      $body['lat'] = $lat;
    }
    if ($lng !== NULL) {
      $body['long'] = $lng;
    }

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
    // services_api_key_auth reads the body parameter via
    // Request::$request->get($name); JSON bodies are not auto-decoded into
    // that ParameterBag, so populate it explicitly to match what the auth
    // provider expects.
    $request->request->set('api_key', $apiKey);

    try {
      $response = $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
      $status = $response->getStatusCode();
      $excerpt = substr((string) $response->getContent(), 0, 200);
    }
    catch (\Throwable $e) {
      $status = 0;
      $excerpt = $e::class . ': ' . substr($e->getMessage(), 0, 180);
    }

    return ['status' => $status, 'excerpt' => $excerpt];
  }

  /**
   * Returns sorted jurisdiction group ids the user is a direct member of.
   *
   * Mirrors JurisdictionScopeValidator::getAllowedJurisdictionIds() rather
   * than depending on it directly — keeps the smoke independent of any future
   * changes to that validator's signature, since this plugin is its
   * acceptance test.
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
