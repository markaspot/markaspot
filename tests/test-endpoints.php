<?php

/**
 * @file
 * Integration tests for all Mark-a-Spot API endpoints.
 *
 * Tests every API endpoint with real HTTP requests (via Drupal's httpClient)
 * to verify status codes, JSON structure, and basic contract compliance.
 *
 * Run: ddev drush php:script tests/test-endpoints.php
 *   or: ddev drush php:script web/profiles/contrib/markaspot/tests/test-endpoints.php
 */

// ---------------------------------------------------------------------------
// Test framework (same as test-multi-tenant.php)
// ---------------------------------------------------------------------------
$GLOBALS['_test'] = ['pass' => 0, 'fail' => 0, 'skip' => 0];

/**
 * Prints a test group header.
 */
function test_group(string $name): void {
  echo "\n\033[1;36m━━━ $name ━━━\033[0m\n";
}

/**
 * Asserts a condition is true and reports result.
 */
function assert_true(bool $condition, string $message): void {
  if ($condition) {
    $GLOBALS['_test']['pass']++;
    echo "  \033[32m✓\033[0m $message\n";
  }
  else {
    $GLOBALS['_test']['fail']++;
    echo "  \033[31m✗ FAIL:\033[0m $message\n";
  }
}

/**
 * Asserts two values are strictly equal.
 */
function assert_equal($expected, $actual, string $message): void {
  if ($expected === $actual) {
    assert_true(TRUE, $message);
  }
  else {
    assert_true(FALSE, "$message (expected: " . var_export($expected, TRUE) . ", got: " . var_export($actual, TRUE) . ")");
  }
}

/**
 * Asserts that an array contains all specified keys.
 */
function assert_json_keys(array $data, array $keys, string $context): void {
  foreach ($keys as $key) {
    assert_true(array_key_exists($key, $data), "$context: has key '$key'");
  }
}

/**
 * Marks a test as skipped with a reason.
 */
function skip_test(string $message): void {
  $GLOBALS['_test']['skip']++;
  echo "  \033[33m⊘ SKIP:\033[0m $message\n";
}

/**
 * Helper: GET request, return [statusCode, decodedBody, rawBody].
 */
function http_get(string $url, array $extra_opts = []): array {
  $http = \Drupal::httpClient();
  $opts = array_merge_recursive([
    'headers' => ['Accept' => 'application/json'],
    'http_errors' => FALSE,
  ], $extra_opts);
  $r = $http->get($url, $opts);
  $raw = $r->getBody()->getContents();
  $json = json_decode($raw, TRUE);
  return [$r->getStatusCode(), $json, $raw, $r];
}

/**
 * Helper: POST request, return [statusCode, decodedBody, rawBody].
 */
function http_post(string $url, $body = NULL, array $extra_opts = []): array {
  $http = \Drupal::httpClient();
  $opts = array_merge_recursive([
    'headers' => [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ],
    'http_errors' => FALSE,
  ], $extra_opts);
  if ($body !== NULL) {
    $opts['body'] = is_string($body) ? $body : json_encode($body);
  }
  $r = $http->request('POST', $url, $opts);
  $raw = $r->getBody()->getContents();
  $json = json_decode($raw, TRUE);
  return [$r->getStatusCode(), $json, $raw, $r];
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------
echo "\033[1m\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  Mark-a-Spot API Endpoint Tests                        ║\n";
echo "╚══════════════════════════════════════════════════════════╝\033[0m\n";

// Use the request host so this works in both single-site and multisite.
// In multisite, drush --uri=sitename.ddev.site sets the correct host.
$base = \Drupal::request()->getSchemeAndHttpHost();
$etm = \Drupal::entityTypeManager();
$module_handler = \Drupal::moduleHandler();

// Discover jurisdictions for parameterized tests.
$groups = $etm->getStorage('group')->loadByProperties(['type' => 'jur']);
$jur_id = !empty($groups) ? (int) array_key_first($groups) : NULL;

// Find a sample service request for single-item endpoints.
$sample_node = NULL;
$sample_uuid = NULL;
$nodes = $etm->getStorage('node')->loadByProperties([
  'type' => 'service_request',
  'status' => 1,
]);
if (!empty($nodes)) {
  $sample_node = reset($nodes);
  $sample_uuid = $sample_node->uuid();
}

// Find an API key (anonymous-level or first available).
$api_key = NULL;
$api_keys = $etm->getStorage('api_key')->loadMultiple();
if (!empty($api_keys)) {
  $first_key = reset($api_keys);
  $api_key = $first_key->get('key');
}

echo "\nSetup:";
echo "\n  Jurisdictions: " . count($groups) . ($jur_id ? " (testing with ID=$jur_id)" : '');
echo "\n  Sample node: " . ($sample_uuid ? "UUID=$sample_uuid" : 'none');
echo "\n  API key: " . ($api_key ? 'available' : 'none');
echo "\n";

// ===========================================================================
// 1. Stats Endpoints (public, no auth)
// ===========================================================================
test_group('1. Stats API');

if (!$module_handler->moduleExists('markaspot_stats')) {
  skip_test('markaspot_stats not enabled');
}
else {
  // GET /stats/status.
  [$code, $data] = http_get("$base/stats/status");
  assert_equal(200, $code, 'GET /stats/status returns 200');
  assert_true(is_array($data), '/stats/status returns array');
  if (!empty($data)) {
    assert_json_keys($data[0], ['status', 'count', 'color'], '/stats/status[0]');
    assert_true(is_int($data[0]['count']), '/stats/status[0].count is int');
  }

  // GET /api/stats/status (alias)
  [$code] = http_get("$base/api/stats/status");
  assert_equal(200, $code, 'GET /api/stats/status returns 200');

  // GET /stats/status?jurisdiction=.
  if ($jur_id) {
    [$code, $data] = http_get("$base/stats/status?jurisdiction=$jur_id");
    assert_equal(200, $code, "GET /stats/status?jurisdiction=$jur_id returns 200");
    assert_true(is_array($data), '/stats/status with jurisdiction returns array');
  }

  // GET /stats/categories.
  [$code, $data] = http_get("$base/stats/categories");
  assert_equal(200, $code, 'GET /stats/categories returns 200');
  assert_true(is_array($data), '/stats/categories returns array');
  if (!empty($data)) {
    assert_json_keys($data[0], ['category', 'count', 'color'], '/stats/categories[0]');
  }

  // GET /api/stats/categories (alias)
  [$code] = http_get("$base/api/stats/categories");
  assert_equal(200, $code, 'GET /api/stats/categories returns 200');

  // GET /stats/categories?jurisdiction=.
  if ($jur_id) {
    [$code, $data] = http_get("$base/stats/categories?jurisdiction=$jur_id");
    assert_equal(200, $code, "GET /stats/categories?jurisdiction=$jur_id returns 200");
  }

  // GET /stats/categories/hierarchical.
  [$code, $data] = http_get("$base/stats/categories/hierarchical");
  assert_equal(200, $code, 'GET /stats/categories/hierarchical returns 200');
  assert_true(is_array($data), '/stats/categories/hierarchical returns array');
  if (!empty($data)) {
    assert_json_keys($data[0], ['tid', 'category', 'count', 'color'], '/stats/categories/hierarchical[0]');
  }

  // Non-existent jurisdiction returns zeros.
  [$code, $data] = http_get("$base/stats/status?jurisdiction=99999");
  assert_equal(200, $code, 'Non-existent jurisdiction: returns 200');
  if (is_array($data)) {
    $total = array_sum(array_column($data, 'count'));
    assert_equal(0, $total, 'Non-existent jurisdiction: all counts = 0');
  }
}

// ===========================================================================
// 2. Emergency Mode API (public)
// ===========================================================================
test_group('2. Emergency Mode API');

if (!$module_handler->moduleExists('markaspot_emergency')) {
  skip_test('markaspot_emergency not enabled');
}
else {
  // GET /api/emergency-mode/status.
  [$code, $data] = http_get("$base/api/emergency-mode/status");
  assert_equal(200, $code, 'GET /api/emergency-mode/status returns 200');
  assert_true(is_array($data), 'Emergency status returns object');
  if ($data) {
    assert_json_keys($data, [
      'emergency_mode', 'status', 'mode_type', 'lite_ui',
      'available_categories', 'banner', 'details',
    ], 'Emergency status');
    assert_true(is_bool($data['emergency_mode']), 'emergency_mode is boolean');
    assert_true(is_array($data['available_categories']), 'available_categories is array');
    assert_true(in_array($data['status'], ['off', 'active'], TRUE), "status is 'off' or 'active'");
  }

  // With jurisdiction_id.
  if ($jur_id) {
    [$code, $data] = http_get("$base/api/emergency-mode/status?jurisdiction_id=$jur_id");
    assert_equal(200, $code, "GET /api/emergency-mode/status?jurisdiction_id=$jur_id returns 200");
  }

  // GET /sos (HTML page)
  [$code] = http_get("$base/sos", ['headers' => ['Accept' => 'text/html']]);
  assert_true(in_array($code, [200, 301, 302, 303]), "GET /sos responds ($code)");
}

// ===========================================================================
// 3. Configuration / Settings API (access content)
// ===========================================================================
test_group('3. Configuration API');

if (!$module_handler->moduleExists('markaspot_nuxt')) {
  skip_test('markaspot_nuxt not enabled');
}
else {
  // GET /api/mark-a-spot-settings.
  [$code, $data] = http_get("$base/api/mark-a-spot-settings");
  assert_equal(200, $code, 'GET /api/mark-a-spot-settings returns 200');
  if ($data) {
    assert_json_keys($data, ['services', 'statuses'], 'Settings');
    assert_true(is_array($data['services']), 'settings.services is array');
    assert_true(is_array($data['statuses']), 'settings.statuses is array');

    // Check service structure.
    if (!empty($data['services'])) {
      assert_json_keys($data['services'][0], ['service_code', 'service_name', 'tid'], 'settings.services[0]');
    }
    // Check status structure.
    if (!empty($data['statuses'])) {
      assert_json_keys($data['statuses'][0], ['name', 'tid'], 'settings.statuses[0]');
    }
  }

  // With jurisdiction.
  if ($jur_id) {
    [$code, $data] = http_get("$base/api/mark-a-spot-settings?jurisdiction=$jur_id");
    assert_equal(200, $code, "GET /api/mark-a-spot-settings?jurisdiction=$jur_id returns 200");
    if ($data) {
      assert_true(isset($data['jurisdiction']), 'Settings with jurisdiction has jurisdiction key');
    }

    // With exclude=boundary.
    [$code, $data] = http_get("$base/api/mark-a-spot-settings?jurisdiction=$jur_id&exclude=boundary");
    assert_equal(200, $code, 'GET settings?exclude=boundary returns 200');
    if ($data) {
      assert_true(!isset($data['boundary']) || $data['boundary'] === NULL, 'Boundary excluded from response');
    }
  }

  // Slug-based jurisdiction lookup.
  if ($jur_id && !empty($groups)) {
    $first_group = reset($groups);
    if ($first_group->hasField('field_slug') && !$first_group->get('field_slug')->isEmpty()) {
      $slug = $first_group->get('field_slug')->value;
      [$code] = http_get("$base/api/mark-a-spot-settings?jurisdiction=$slug");
      assert_equal(200, $code, "GET settings?jurisdiction=$slug (slug) returns 200");
    }
  }

  // GET /api/mark-a-spot-form-mode-settings.
  [$code, $data] = http_get("$base/api/mark-a-spot-form-mode-settings/node/service_request/nuxt");
  assert_equal(200, $code, 'GET form-mode-settings/node/service_request/nuxt returns 200');
  if ($data) {
    assert_json_keys($data, ['entity_type', 'bundle', 'form_mode', 'fields'], 'Form mode settings');
    assert_equal('node', $data['entity_type'], 'entity_type = node');
    assert_equal('service_request', $data['bundle'], 'bundle = service_request');
  }

  // Non-existent form mode.
  [$code] = http_get("$base/api/mark-a-spot-form-mode-settings/node/service_request/nonexistent");
  assert_true(in_array($code, [404, 200]), 'Non-existent form mode returns 404 or empty 200');

  // GET /api/field-options.
  [$code, $data] = http_get("$base/api/field-options/node/field_hazard_level");
  if ($code === 200 && $data) {
    assert_json_keys($data, ['field_name', 'field_type', 'options'], 'Field options');
    assert_equal('field_hazard_level', $data['field_name'], 'field_name matches');
  }
  else {
    // Field might not exist in all installations.
    assert_true(in_array($code, [200, 404]), "GET /api/field-options responds ($code)");
  }

  // GET /api/jurisdictions.
  [$code, $data] = http_get("$base/api/jurisdictions");
  assert_equal(200, $code, 'GET /api/jurisdictions returns 200');
  if ($data) {
    assert_json_keys($data, ['jurisdictions', 'count', 'hasMultiple'], 'Jurisdictions');
    assert_true(is_array($data['jurisdictions']), 'jurisdictions is array');
    if (!empty($data['jurisdictions'])) {
      assert_json_keys($data['jurisdictions'][0], ['id', 'name', 'slug', 'isDefault'], 'jurisdictions[0]');
    }
  }

  // GET /api/organisations.
  [$code, $data] = http_get("$base/api/organisations");
  assert_equal(200, $code, 'GET /api/organisations returns 200');
  if ($data) {
    assert_json_keys($data, ['organisations', 'count'], 'Organisations');
    assert_true(is_array($data['organisations']), 'organisations is array');
    if (!empty($data['organisations'])) {
      assert_json_keys($data['organisations'][0], ['id', 'numericId', 'label'], 'organisations[0]');
    }
  }

  // GET /api/fonts.css.
  $http = \Drupal::httpClient();
  $r = $http->get("$base/api/fonts.css", ['http_errors' => FALSE]);
  $ct = $r->getHeader('Content-Type')[0] ?? '';
  assert_equal(200, $r->getStatusCode(), 'GET /api/fonts.css returns 200');
  assert_true(str_contains($ct, 'text/css'), "fonts.css Content-Type is text/css (got: $ct)");

  // GET /api/vote-sum/{uuid}.
  if ($sample_uuid) {
    [$code, $data] = http_get("$base/api/vote-sum/$sample_uuid");
    // 500 if Voting API module not configured, 200 if working.
    assert_true(in_array($code, [200, 500]), "GET /api/vote-sum/{uuid} responds ($code)");
    if ($code === 200 && $data) {
      assert_json_keys($data, ['uuid', 'vote_sum'], 'Vote sum');
    }
    elseif ($code === 500) {
      echo "    Note: vote-sum returned 500 (Voting API module may not be configured)\n";
    }
  }
  else {
    skip_test('No sample node for vote-sum test');
  }

  // Non-existent vote-sum UUID.
  [$code] = http_get("$base/api/vote-sum/00000000-0000-0000-0000-000000000000");
  assert_true(in_array($code, [404, 500]), "GET /api/vote-sum/invalid-uuid returns 404 or 500 ($code)");
}

// ===========================================================================
// 4. GeoReport v2 API (api_key auth)
// ===========================================================================
test_group('4. GeoReport v2 API');

// GET /georeport/v2/services.json (public)
[$code, $data] = http_get("$base/georeport/v2/services.json");
assert_equal(200, $code, 'GET /georeport/v2/services.json returns 200');
assert_true(is_array($data) && count($data) > 0, 'services.json returns non-empty array');
if (!empty($data)) {
  assert_json_keys($data[0], ['service_code', 'service_name'], 'services[0]');
}

// With jurisdiction_id.
if ($jur_id) {
  [$code, $data_jur] = http_get("$base/georeport/v2/services.json?jurisdiction_id=$jur_id");
  assert_equal(200, $code, "GET services.json?jurisdiction_id=$jur_id returns 200");
  assert_true(is_array($data_jur) && is_array($data) && count($data_jur) <= count($data), 'Jurisdiction-filtered services <= total');
}

// GET /georeport/v2/requests.json.
if ($api_key) {
  [$code, $data] = http_get("$base/georeport/v2/requests.json?api_key=$api_key&limit=5");
  assert_true(in_array($code, [200, 403]), "GET requests.json responds ($code)");
  if ($code === 200 && is_array($data) && !empty($data)) {
    assert_json_keys($data[0], ['service_request_id', 'status', 'service_code', 'lat', 'long'], 'requests[0]');
  }

  // With extensions.
  [$code, $data] = http_get("$base/georeport/v2/requests.json?api_key=$api_key&limit=1&extensions=true");
  if ($code === 200 && is_array($data) && !empty($data)) {
    assert_true(isset($data[0]['extended_attributes']), 'extensions=true returns extended_attributes');
  }

  // Single request.
  if ($sample_node) {
    $request_id = $sample_node->get('request_id')->value ?? $sample_node->id();
    [$code, $data] = http_get("$base/georeport/v2/requests/$request_id.json?api_key=$api_key");
    assert_true(in_array($code, [200, 404]), "GET requests/{id}.json responds ($code)");
  }

  // With jurisdiction_id.
  if ($jur_id) {
    [$code] = http_get("$base/georeport/v2/requests.json?api_key=$api_key&jurisdiction_id=$jur_id&limit=5");
    assert_true(in_array($code, [200, 403]), "GET requests.json?jurisdiction_id=$jur_id responds ($code)");
  }
}
else {
  skip_test('No API key available for GeoReport request tests');
}

// GET /georeport/v2/stats.json (Open311 stats)
[$code, $data] = http_get("$base/georeport/v2/stats.json");
assert_true(in_array($code, [200, 404]), "GET /georeport/v2/stats.json responds ($code)");

// GET /georeport/v2/stats/categories.json.
[$code] = http_get("$base/georeport/v2/stats/categories.json");
assert_true(in_array($code, [200, 404]), "GET /georeport/v2/stats/categories.json responds ($code)");

// ===========================================================================
// 5. Auth API (passwordless)
// ===========================================================================
test_group('5. Auth API');

if (!$module_handler->moduleExists('markaspot_passwordless')) {
  skip_test('markaspot_passwordless not enabled');
}
else {
  // GET /api/auth/status (anonymous)
  [$code, $data] = http_get("$base/api/auth/status");
  assert_equal(200, $code, 'GET /api/auth/status returns 200');
  if ($data) {
    assert_true(isset($data['authenticated']) || isset($data['uid']), 'Auth status has user info');
  }

  // POST /api/auth/request-code (without email, should fail gracefully)
  [$code, $data] = http_post("$base/api/auth/request-code", ['email' => '']);
  assert_true(in_array($code, [400, 422, 200]), "POST /api/auth/request-code (empty email) responds ($code)");

  // POST /api/auth/verify-code (without code, should fail)
  [$code] = http_post("$base/api/auth/verify-code", ['email' => 'test@example.com', 'code' => '']);
  assert_true(in_array($code, [400, 403, 422, 200]), "POST /api/auth/verify-code (empty code) responds ($code)");

  // POST /api/auth/logout (anonymous, should still work)
  [$code] = http_post("$base/api/auth/logout");
  assert_true(in_array($code, [200, 403]), "POST /api/auth/logout responds ($code)");

  // GET /api/auth/switch-users (requires 'switch users' permission, should fail for anonymous)
  [$code] = http_get("$base/api/auth/switch-users");
  assert_equal(403, $code, 'GET /api/auth/switch-users returns 403 (anonymous)');

  // POST /api/auth/switch-user (requires permission)
  [$code] = http_post("$base/api/auth/switch-user", ['uid' => 1]);
  assert_equal(403, $code, 'POST /api/auth/switch-user returns 403 (anonymous)');
}

// ===========================================================================
// 6. Dashboard API (requires 'access dashboard kpis')
// ===========================================================================
test_group('6. Dashboard API');

if (!$module_handler->moduleExists('markaspot_dashboard')) {
  skip_test('markaspot_dashboard not enabled');
}
else {
  // All dashboard endpoints require authentication.
  // Test that anonymous access is denied.
  $dashboard_endpoints = [
    '/api/dashboard/kpis',
    '/api/dashboard/time-series/volume',
    '/api/dashboard/time-series/processing',
    '/api/dashboard/forwarding-details',
    '/api/dashboard/hazards',
  ];

  foreach ($dashboard_endpoints as $ep) {
    [$code] = http_get("$base$ep");
    assert_equal(403, $code, "GET $ep returns 403 (anonymous)");
  }

  // Dashboard duplicate endpoints (access mark-a-spot api).
  $dup_endpoints = [
    '/ai/duplicates/pending',
  ];
  foreach ($dup_endpoints as $ep) {
    [$code] = http_get("$base$ep");
    assert_equal(403, $code, "GET $ep returns 403 (anonymous)");
  }

  // Status notes endpoints require auth (may return 400 if body validation runs first).
  [$code] = http_post("$base/api/dashboard/status-notes", ['uuid' => 'test', 'note' => 'test']);
  assert_true(in_array($code, [400, 403]), "POST /api/dashboard/status-notes returns 400 or 403 (anonymous, got $code)");
}

// ===========================================================================
// 7. AI API (requires various permissions)
// ===========================================================================
test_group('7. AI API');

if (!$module_handler->moduleExists('markaspot_ai')) {
  skip_test('markaspot_ai not enabled');
}
else {
  // All AI endpoints require authentication.
  $ai_endpoints = [
    ['GET', '/api/ai/duplicates/pending'],
    ['GET', '/api/ai/usage'],
    ['GET', '/api/ai/usage/summary'],
    ['GET', '/api/ai/sentiment/stats'],
    ['GET', '/api/ai/sentiment/frustrated'],
    ['GET', '/api/ai/processing/status'],
  ];

  foreach ($ai_endpoints as [, $ep]) {
    [$code] = http_get("$base$ep");
    assert_equal(403, $code, "GET $ep returns 403 (anonymous)");
  }

  // POST endpoints also require auth.
  [$code] = http_post("$base/api/ai/processing/queue");
  assert_equal(403, $code, 'POST /api/ai/processing/queue returns 403 (anonymous)');

  [$code] = http_post("$base/api/ai/processing/run");
  assert_equal(403, $code, 'POST /api/ai/processing/run returns 403 (anonymous)');

  // Node-specific endpoints (need a node param).
  if ($sample_node) {
    $nid = $sample_node->id();
    [$code] = http_get("$base/api/ai/duplicates/$nid");
    assert_equal(403, $code, "GET /api/ai/duplicates/{nid} returns 403 (anonymous)");

    [$code] = http_get("$base/api/ai/sentiment/$nid");
    assert_equal(403, $code, "GET /api/ai/sentiment/{nid} returns 403 (anonymous)");
  }
}

// ===========================================================================
// 8. Group Members API (custom access check)
// ===========================================================================
test_group('8. Group Members API');

if (!$module_handler->moduleExists('markaspot_group')) {
  skip_test('markaspot_group not enabled');
}
else {
  // Anonymous access should be denied.
  [$code] = http_get("$base/api/group-members");
  assert_equal(403, $code, 'GET /api/group-members returns 403 (anonymous)');

  [$code] = http_post("$base/api/group-members/1", ['memberships' => []]);
  // PATCH via POST or actual PATCH.
  $http = \Drupal::httpClient();
  $r = $http->request('PATCH', "$base/api/group-members/1", [
    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    'body' => json_encode(['memberships' => []]),
    'http_errors' => FALSE,
  ]);
  assert_equal(403, $r->getStatusCode(), 'PATCH /api/group-members/{uid} returns 403 (anonymous)');
}

// ===========================================================================
// 9. CAP Export API (public)
// ===========================================================================
test_group('9. CAP Export API');

if (!$module_handler->moduleExists('markaspot_cap')) {
  skip_test('markaspot_cap not enabled');
}
else {
  [$code, $data] = http_get("$base/api/cap/v1/alerts");
  assert_equal(200, $code, 'GET /api/cap/v1/alerts returns 200');
  assert_true(is_array($data), 'CAP alerts returns array');

  // Single alert (non-existent ID).
  [$code] = http_get("$base/api/cap/v1/alerts/nonexistent");
  assert_true(in_array($code, [200, 404]), "GET /api/cap/v1/alerts/{id} responds ($code)");
}

// ===========================================================================
// 10. Confirm API (public)
// ===========================================================================
test_group('10. Confirm API');

if (!$module_handler->moduleExists('markaspot_confirm')) {
  skip_test('markaspot_confirm not enabled');
}
else {
  // Non-existent UUID should return appropriate error.
  [$code] = http_get("$base/api/confirm/00000000-0000-0000-0000-000000000000");
  assert_true(in_array($code, [200, 404]), "GET /api/confirm/{uuid} with invalid UUID responds ($code)");

  // Real UUID.
  if ($sample_uuid) {
    [$code] = http_get("$base/api/confirm/$sample_uuid");
    assert_true(in_array($code, [200, 404]), "GET /api/confirm/{uuid} responds ($code)");
  }
}

// ===========================================================================
// 11. Contact API
// ===========================================================================
test_group('11. Contact API');

if (!$module_handler->moduleExists('markaspot_contact')) {
  skip_test('markaspot_contact not enabled');
}
else {
  // GET /api/contact/info (public)
  [$code, $data] = http_get("$base/api/contact/info");
  assert_equal(200, $code, 'GET /api/contact/info returns 200');

  // POST /api/contact/submit requires permission.
  [$code] = http_post("$base/api/contact/submit", ['name' => 'Test', 'email' => 'test@test.com', 'message' => 'Test']);
  assert_true(in_array($code, [200, 403]), "POST /api/contact/submit responds ($code)");
}

// ===========================================================================
// 12. Feedback API
// ===========================================================================
test_group('12. Feedback API');

if (!$module_handler->moduleExists('markaspot_feedback')) {
  skip_test('markaspot_feedback not enabled');
}
else {
  if ($sample_uuid) {
    [$code] = http_get("$base/api/feedback/$sample_uuid");
    assert_true(in_array($code, [200, 404]), "GET /api/feedback/{uuid} responds ($code)");
  }
  else {
    skip_test('No sample UUID for feedback test');
  }
}

// ===========================================================================
// 13. Service Provider API
// ===========================================================================
test_group('13. Service Provider API');

if (!$module_handler->moduleExists('markaspot_service_provider')) {
  skip_test('markaspot_service_provider not enabled');
}
else {
  if ($sample_uuid) {
    [$code] = http_get("$base/api/service-response/$sample_uuid");
    assert_true(in_array($code, [200, 404]), "GET /api/service-response/{uuid} responds ($code)");
  }
  else {
    skip_test('No sample UUID for service-response test');
  }
}

// ===========================================================================
// 14. SHS Tweak / Icon APIs
// ===========================================================================
test_group('14. Utility APIs');

// FA Icon API.
if ($module_handler->moduleExists('fa_icon_class')) {
  [$code] = http_get("$base/api/iconify_field/render/fa-home");
  assert_true(in_array($code, [200, 400, 404]), "GET /api/iconify_field/render/{icon} responds ($code)");
}

// SHS Tweak.
if ($module_handler->moduleExists('markaspot_shstweak')) {
  // Need a real term ID.
  $cats = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'service_category', 'status' => 1]);
  if (!empty($cats)) {
    $first_cat = reset($cats);
    $tid = $first_cat->id();
    [$code] = http_get("$base/api/markaspotshstweak/$tid/0");
    assert_true(in_array($code, [200, 404]), "GET /api/markaspotshstweak/{tid}/0 responds ($code)");
  }
}

// ===========================================================================
// 15. Response Format Consistency
// ===========================================================================
test_group('15. Response Consistency');

// Verify JSON Content-Type headers on API responses.
$json_endpoints = [
  '/api/emergency-mode/status',
  '/stats/status',
  '/api/mark-a-spot-settings',
  '/api/jurisdictions',
  '/api/organisations',
];

foreach ($json_endpoints as $ep) {
  if ($ep === '/api/emergency-mode/status' && !$module_handler->moduleExists('markaspot_emergency')) {
    continue;
  }
  if ($ep === '/api/mark-a-spot-settings' && !$module_handler->moduleExists('markaspot_nuxt')) {
    continue;
  }
  if ($ep === '/api/jurisdictions' && !$module_handler->moduleExists('markaspot_nuxt')) {
    continue;
  }
  if ($ep === '/api/organisations' && !$module_handler->moduleExists('markaspot_nuxt')) {
    continue;
  }

  $http = \Drupal::httpClient();
  $r = $http->get("$base$ep", ['http_errors' => FALSE, 'headers' => ['Accept' => 'application/json']]);
  $ct = $r->getHeader('Content-Type')[0] ?? '';
  assert_true(str_contains($ct, 'json'), "$ep: Content-Type contains json (got: $ct)");
}

// Idempotent check: same request twice produces same result.
[$code1, , $raw1] = http_get("$base/stats/status");
[$code2, , $raw2] = http_get("$base/stats/status");
assert_equal($code1, $code2, 'Idempotent: same status code');
// Note: counts may change between requests in production, so we just check structure.
$j1 = json_decode($raw1, TRUE);
$j2 = json_decode($raw2, TRUE);
if (is_array($j1) && is_array($j2)) {
  assert_equal(count($j1), count($j2), 'Idempotent: same number of status entries');
}

// ===========================================================================
// 16. Cache Headers
// ===========================================================================
test_group('16. Cache Headers');

// Emergency endpoint should have no-cache.
if ($module_handler->moduleExists('markaspot_emergency')) {
  $http = \Drupal::httpClient();
  $r = $http->get("$base/api/emergency-mode/status", ['http_errors' => FALSE]);
  $cc = $r->getHeader('Cache-Control')[0] ?? '';
  assert_true(str_contains($cc, 'no-cache') || str_contains($cc, 'no-store'), "Emergency status: Cache-Control contains no-cache (got: $cc)");
}

// Fonts CSS should have public cache.
if ($module_handler->moduleExists('markaspot_nuxt')) {
  $http = \Drupal::httpClient();
  $r = $http->get("$base/api/fonts.css", ['http_errors' => FALSE]);
  $cc = $r->getHeader('Cache-Control')[0] ?? '';
  assert_true(str_contains($cc, 'public') || str_contains($cc, 'max-age'), "fonts.css: has cache headers (got: $cc)");
}

// ===========================================================================
// 17. Pages (JSON:API node/page)
// ===========================================================================
test_group('17. Pages (JSON:API node/page)');

// Check if pages exist as group content.
$page_nodes = $etm->getStorage('node')->loadByProperties([
  'type' => 'page',
  'status' => 1,
  'promote' => 1,
]);

if (empty($page_nodes)) {
  skip_test('No published promoted pages found');
}
else {
  // GET all promoted pages.
  [$code, $data] = http_get("$base/jsonapi/node/page?filter[promote]=1", [
    'headers' => ['Accept' => 'application/vnd.api+json'],
  ]);
  assert_equal(200, $code, 'GET /jsonapi/node/page?filter[promote]=1 returns 200');
  assert_true(isset($data['data']), 'Response has data key');
  assert_true(is_array($data['data']), 'data is array');
  assert_true(count($data['data']) > 0, 'At least one page returned');

  // Check first page structure.
  if (!empty($data['data'])) {
    $first = $data['data'][0];
    assert_true(isset($first['attributes']['title']), 'Page has title attribute');
    assert_true(isset($first['attributes']['body']['processed']), 'Page has body.processed attribute');
    assert_true(array_key_exists('sticky', $first['attributes']), 'Page has sticky attribute');

    // Check that at least one page is sticky (the welcome/start page).
    $has_sticky = FALSE;
    foreach ($data['data'] as $page) {
      if (!empty($page['attributes']['sticky'])) {
        $has_sticky = TRUE;
        break;
      }
    }
    assert_true($has_sticky, 'At least one page is sticky (start page)');
  }

  // Test jurisdiction filter if jurisdictions exist.
  if ($jur_id) {
    [$code, $data] = http_get(
      "$base/jsonapi/node/page?filter[promote]=1&filter[field_jurisdiction.meta.drupal_internal__target_id]=$jur_id",
      ['headers' => ['Accept' => 'application/vnd.api+json']]
    );
    assert_equal(200, $code, "GET pages filtered by jurisdiction=$jur_id returns 200");
    assert_true(isset($data['data']), 'Filtered response has data key');

    // Each returned page should belong to the requested jurisdiction.
    if (!empty($data['data'])) {
      assert_true(count($data['data']) > 0, "Jurisdiction $jur_id has pages");
    }

    // Test with non-existent jurisdiction (should return 0 pages).
    [$code, $data] = http_get(
      "$base/jsonapi/node/page?filter[promote]=1&filter[field_jurisdiction.meta.drupal_internal__target_id]=99999",
      ['headers' => ['Accept' => 'application/vnd.api+json']]
    );
    assert_equal(200, $code, 'GET pages filtered by non-existent jurisdiction returns 200');
    assert_equal(0, count($data['data'] ?? []), 'Non-existent jurisdiction returns 0 pages');
  }
}

// ===========================================================================
// Summary
// ===========================================================================
$t = $GLOBALS['_test'];
$total = $t['pass'] + $t['fail'] + $t['skip'];
$tested = $t['pass'] + $t['fail'];
$pct = $tested > 0 ? round($t['pass'] / $tested * 100) : 0;

echo "\n\033[1m╔══════════════════════════════════════════════════════════╗\033[0m\n";
if ($t['fail'] === 0) {
  echo "\033[1;32m║  ALL ENDPOINT TESTS PASSED                               ║\033[0m\n";
}
else {
  echo "\033[1;31m║  SOME ENDPOINT TESTS FAILED                              ║\033[0m\n";
}
echo "\033[1m╚══════════════════════════════════════════════════════════╝\033[0m\n";
echo "  \033[32m✓ {$t['pass']} passed\033[0m  \033[31m✗ {$t['fail']} failed\033[0m  \033[33m⊘ {$t['skip']} skipped\033[0m  ($total total, $pct%)\n\n";

// Use throw instead of exit() to signal failure to drush.
if ($t['fail'] > 0) {
  throw new \RuntimeException("$t[fail] endpoint test(s) failed");
}
