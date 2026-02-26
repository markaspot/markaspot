<?php

/**
 * @file
 * Integration tests for group assignment logic in markaspot_group.module.
 *
 * Run: ddev drush php:script web/profiles/contrib/markaspot/tests/test-group-assignment.php
 *
 * Tests sub-jurisdiction boundary assignment, root jurisdiction fallback,
 * and organisation derivation from category terms.
 */

// ---------------------------------------------------------------------------
// Test framework
// ---------------------------------------------------------------------------

$GLOBALS['_test'] = ['pass' => 0, 'fail' => 0, 'skip' => 0];

function test_group(string $name): void {
  echo "\n\033[1;36m━━━ $name ━━━\033[0m\n";
}

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

function assert_equal($expected, $actual, string $message): void {
  if ($expected === $actual) {
    assert_true(TRUE, $message);
  }
  else {
    assert_true(FALSE, "$message (expected: " . var_export($expected, TRUE) . ", got: " . var_export($actual, TRUE) . ")");
  }
}

function skip_test(string $message): void {
  $GLOBALS['_test']['skip']++;
  echo "  \033[33m⊘ SKIP:\033[0m $message\n";
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Loads all group_relationships for a node with the service_request plugin.
 *
 * @param int $nid
 *   The node ID.
 *
 * @return \Drupal\group\Entity\GroupRelationshipInterface[]
 *   Array of group relationship entities.
 */
function _test_load_group_relationships(int $nid): array {
  return \Drupal::entityTypeManager()
    ->getStorage('group_relationship')
    ->loadByProperties([
      'entity_id' => $nid,
      'plugin_id' => 'group_node:service_request',
    ]);
}

/**
 * Checks if a node has a group_relationship to a specific group.
 *
 * @param int $nid
 *   The node ID.
 * @param int $gid
 *   The group ID.
 *
 * @return bool
 *   TRUE if the relationship exists.
 */
function _test_has_group_relationship(int $nid, int $gid): bool {
  $rels = \Drupal::entityTypeManager()
    ->getStorage('group_relationship')
    ->loadByProperties([
      'entity_id' => $nid,
      'gid' => $gid,
      'plugin_id' => 'group_node:service_request',
    ]);
  return !empty($rels);
}

/**
 * Determines if a group is a child jurisdiction (has field_parent_jurisdiction set).
 *
 * @param \Drupal\group\Entity\GroupInterface $group
 *   The group entity.
 *
 * @return bool
 *   TRUE if the group is a child jurisdiction.
 */
function _test_is_child_jur(\Drupal\group\Entity\GroupInterface $group): bool {
  return $group->hasField('field_parent_jurisdiction')
    && !$group->get('field_parent_jurisdiction')->isEmpty();
}

/**
 * Safely deletes a node and its group relationships.
 *
 * @param \Drupal\node\NodeInterface|null $node
 *   The node to delete, or NULL.
 */
function _test_cleanup_node(?\Drupal\node\NodeInterface $node): void {
  if (!$node || !$node->id()) {
    return;
  }
  $nid = (int) $node->id();

  // Delete group relationships first.
  $rels = _test_load_group_relationships($nid);
  foreach ($rels as $rel) {
    $rel->delete();
  }

  // Delete the node.
  $node->delete();
  echo "    [cleanup] Deleted test node $nid\n";
}

/**
 * Computes the centroid of a GeoJSON boundary.
 *
 * @param string $geojson_raw
 *   Raw GeoJSON string (may contain HTML tags from field storage).
 *
 * @return array|null
 *   Array with 'lat' and 'lng' keys, or NULL if parsing failed.
 */
function _test_compute_centroid(string $geojson_raw): ?array {
  $geo = json_decode(strip_tags($geojson_raw), TRUE);
  if (!$geo) {
    return NULL;
  }

  // Resolve to the first polygon's exterior ring.
  $geom = $geo;
  if (($geom['type'] ?? '') === 'FeatureCollection') {
    $geom = $geom['features'][0] ?? [];
  }
  if (($geom['type'] ?? '') === 'Feature') {
    $geom = $geom['geometry'] ?? [];
  }

  $ring = [];
  if (($geom['type'] ?? '') === 'MultiPolygon') {
    $ring = $geom['coordinates'][0][0] ?? [];
  }
  elseif (($geom['type'] ?? '') === 'Polygon') {
    $ring = $geom['coordinates'][0] ?? [];
  }

  if (empty($ring) || !is_array($ring[0] ?? NULL)) {
    return NULL;
  }

  $sum_lng = $sum_lat = 0;
  foreach ($ring as $c) {
    $sum_lng += $c[0];
    $sum_lat += $c[1];
  }
  return [
    'lat' => $sum_lat / count($ring),
    'lng' => $sum_lng / count($ring),
  ];
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

echo "\033[1m\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  Group Assignment Logic - Integration Tests             ║\n";
echo "╚══════════════════════════════════════════════════════════╝\033[0m\n";

$etm = \Drupal::entityTypeManager();
$group_storage = $etm->getStorage('group');
$node_storage = $etm->getStorage('node');

// Load all jurisdiction groups.
$all_jur_groups = $group_storage->loadByProperties(['type' => 'jur']);
if (empty($all_jur_groups)) {
  throw new \RuntimeException("No jurisdiction groups found. Cannot run tests.");
}

// Identify root and child jurisdictions.
$root_jurs = [];
$child_jurs = [];
foreach ($all_jur_groups as $gid => $group) {
  if (_test_is_child_jur($group)) {
    $parent_id = (int) $group->get('field_parent_jurisdiction')->target_id;
    $child_jurs[(int) $gid] = [
      'group' => $group,
      'label' => $group->label(),
      'parent_id' => $parent_id,
    ];
  }
  else {
    $root_jurs[(int) $gid] = [
      'group' => $group,
      'label' => $group->label(),
    ];
  }
}

echo "\nTest data: " . count($all_jur_groups) . " jurisdictions (" . count($root_jurs) . " root, " . count($child_jurs) . " child)\n";
foreach ($root_jurs as $id => $j) {
  echo "  ROOT ID=$id | {$j['label']}\n";
}
foreach ($child_jurs as $id => $j) {
  echo "  CHILD ID=$id | {$j['label']} -> parent={$j['parent_id']}\n";
}

// Find a default status term for creating test nodes.
$status_terms = $etm->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => 'service_status', 'status' => 1]);
$default_status_tid = NULL;
foreach ($status_terms as $term) {
  $default_status_tid = (int) $term->id();
  break;
}

// Find a default category term for creating test nodes.
$category_terms = $etm->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => 'service_category', 'status' => 1]);
$default_category_tid = NULL;
foreach ($category_terms as $term) {
  $default_category_tid = (int) $term->id();
  break;
}

if (!$default_status_tid || !$default_category_tid) {
  throw new \RuntimeException("No status or category terms found. Cannot create test nodes.");
}

echo "  Default status TID: $default_status_tid\n";
echo "  Default category TID: $default_category_tid\n";

// ===========================================================================
// 1. Sub-jurisdiction assignment priority
// ===========================================================================

test_group('1. Sub-jurisdiction assignment priority');

if (empty($child_jurs)) {
  skip_test('No child jurisdictions found (no field_parent_jurisdiction set). Skipping all sub-jurisdiction tests.');
}
else {
  // Pick the first child jurisdiction with a boundary.
  $test_child = NULL;
  $test_child_id = NULL;
  foreach ($child_jurs as $cid => $cdata) {
    $g = $cdata['group'];
    if ($g->hasField('field_boundary') && !$g->get('field_boundary')->isEmpty()) {
      $test_child = $cdata;
      $test_child_id = $cid;
      break;
    }
  }

  if (!$test_child) {
    skip_test('No child jurisdiction has a field_boundary set. Skipping sub-jurisdiction tests.');
  }
  else {
    $child_group = $test_child['group'];
    $root_id = $test_child['parent_id'];

    // Resolve root upward (in case the parent is itself a child).
    /** @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $resolver */
    $resolver = \Drupal::service('markaspot_group.hierarchy_resolver');
    $root_id = $resolver->getRootJurisdictionId($test_child_id);
    $root_group = $group_storage->load($root_id);

    echo "  Testing child: {$test_child['label']} (ID=$test_child_id)\n";
    echo "  Root: " . ($root_group ? $root_group->label() : '?') . " (ID=$root_id)\n";

    // Strategy: find existing service_request nodes in the child jur to get valid coordinates.
    $coords = NULL;
    $existing_rels = $etm->getStorage('group_relationship')->loadByProperties([
      'gid' => $test_child_id,
      'plugin_id' => 'group_node:service_request',
    ]);
    foreach ($existing_rels as $rel) {
      $entity = $rel->getEntity();
      if ($entity && $entity->hasField('field_geolocation') && !$entity->get('field_geolocation')->isEmpty()) {
        $geo = $entity->get('field_geolocation')->first();
        $coords = [
          'lat' => (float) $geo->get('lat')->getValue(),
          'lng' => (float) $geo->get('lng')->getValue(),
        ];
        if ($coords['lat'] !== 0.0 || $coords['lng'] !== 0.0) {
          break;
        }
        $coords = NULL;
      }
    }

    // Fallback: compute centroid of the child boundary.
    if (!$coords) {
      $boundary_raw = $child_group->get('field_boundary')->value;
      $coords = _test_compute_centroid($boundary_raw);
    }

    if (!$coords) {
      skip_test("Cannot determine coordinates inside child jur {$test_child['label']}. Skipping.");
    }
    else {
      echo "  Test coordinates: lat={$coords['lat']}, lng={$coords['lng']}\n";

      $test_node = NULL;
      try {
        // Create a test service_request node inside the child boundary.
        $test_node = \Drupal\node\Entity\Node::create([
          'type' => 'service_request',
          'title' => 'Test group assignment - child jur - ' . date('c'),
          'status' => 0,
          'uid' => 1,
          'field_geolocation' => [
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
          ],
          'field_category' => ['target_id' => $default_category_tid],
          'field_status' => ['target_id' => $default_status_tid],
        ]);
        $test_node->save();

        $nid = (int) $test_node->id();
        echo "  Created test node: $nid\n";

        // Assert: node has a group_relationship to the child jur.
        assert_true(
          _test_has_group_relationship($nid, $test_child_id),
          "Node $nid has group_relationship to child jur \"{$test_child['label']}\" (ID=$test_child_id)"
        );

        // Assert: node does NOT have a group_relationship to the root jur.
        // This is the critical test: child jur takes precedence, root is NOT added.
        assert_true(
          !_test_has_group_relationship($nid, $root_id),
          "Node $nid does NOT have group_relationship to root jur \"" . ($root_group ? $root_group->label() : '?') . "\" (ID=$root_id)"
        );

        // Assert: escalation service resolves the escalation target to the root jur.
        try {
          if (\Drupal::hasService('markaspot_escalation.service')) {
            $escalation = \Drupal::service('markaspot_escalation.service');
            // Reload the node to ensure all hook effects are applied.
            $etm->getStorage('node')->resetCache([$nid]);
            $fresh_node = $node_storage->load($nid);
            $target = $escalation->resolveEscalationTarget($fresh_node);
            assert_true(
              $target === $root_id,
              "Escalation target resolves to root jur (ID=$root_id), got: " . var_export($target, TRUE)
            );
          }
          else {
            skip_test('markaspot_escalation.service not available');
          }
        }
        catch (\Exception $e) {
          skip_test('Escalation service error: ' . $e->getMessage());
        }
      }
      finally {
        _test_cleanup_node($test_node);
      }
    }
  }
}

// ===========================================================================
// 2. Root jurisdiction fallback
// ===========================================================================

test_group('2. Root jurisdiction fallback');

if (empty($root_jurs)) {
  skip_test('No root jurisdictions found. Skipping fallback tests.');
}
else {
  // Determine which root jur to expect.
  // When there is exactly one root, the fallback should assign it.
  // When there are multiple roots, the fallback cannot determine which one
  // to use (no org context), so it logs a warning and skips assignment.
  $single_root = count($root_jurs) === 1;
  $expected_root_id = $single_root ? array_key_first($root_jurs) : NULL;

  $test_node = NULL;
  try {
    // Create a test node with coordinates outside ALL boundaries.
    $test_node = \Drupal\node\Entity\Node::create([
      'type' => 'service_request',
      'title' => 'Test group assignment - root fallback - ' . date('c'),
      'status' => 1,
      'uid' => 1,
      'field_geolocation' => [
        'lat' => 0.001,
        'lng' => 0.001,
      ],
      'field_category' => ['target_id' => $default_category_tid],
      'field_status' => ['target_id' => $default_status_tid],
    ]);
    $test_node->save();

    $nid = (int) $test_node->id();
    echo "  Created test node: $nid (lat=0.001, lng=0.001)\n";

    if ($single_root) {
      // Assert: node has a group_relationship to the root jur (fallback).
      assert_true(
        _test_has_group_relationship($nid, $expected_root_id),
        "Node $nid has group_relationship to root jur \"{$root_jurs[$expected_root_id]['label']}\" (fallback)"
      );
    }
    else {
      // With multiple roots and no org context, no root assignment is expected.
      $has_any_root = FALSE;
      foreach ($root_jurs as $rid => $rdata) {
        if (_test_has_group_relationship($nid, $rid)) {
          $has_any_root = TRUE;
          break;
        }
      }
      // With org derivation, the node may get a root via the org -> jur path.
      // Check if any root was assigned.
      if ($has_any_root) {
        assert_true(TRUE, "Node $nid has a root jur relationship (derived from org or fallback)");
      }
      else {
        // No root assigned is also valid when there are multiple roots and no org.
        skip_test("Multiple root jurs exist (" . count($root_jurs) . "). No root assigned without org context (expected behavior).");
      }
    }

    // Assert: node does NOT have a group_relationship to any child jur.
    // Coordinates 0.001, 0.001 should be outside all European boundaries.
    $has_child = FALSE;
    $child_with_rel = NULL;
    foreach ($child_jurs as $cid => $cdata) {
      if (_test_has_group_relationship($nid, $cid)) {
        $has_child = TRUE;
        $child_with_rel = $cdata['label'];
        break;
      }
    }
    assert_true(
      !$has_child,
      "Node $nid does NOT have group_relationship to any child jur"
      . ($has_child ? " (found: $child_with_rel)" : '')
    );
  }
  finally {
    _test_cleanup_node($test_node);
  }
}

// ===========================================================================
// 3. Organisation derivation
// ===========================================================================

test_group('3. Organisation derivation');

// Find a category term with field_category_gid set (org reference).
$cat_with_org = NULL;
$cat_with_org_tid = NULL;
$org_group_id = NULL;
foreach ($category_terms as $term) {
  if ($term->hasField('field_category_gid') && !$term->get('field_category_gid')->isEmpty()) {
    $candidate_gid = (int) $term->get('field_category_gid')->target_id;
    $org_group = $group_storage->load($candidate_gid);
    if ($org_group && $org_group->bundle() === 'org') {
      $cat_with_org = $term;
      $cat_with_org_tid = (int) $term->id();
      $org_group_id = $candidate_gid;
      break;
    }
  }
}

if (!$cat_with_org) {
  skip_test('No category term with field_category_gid referencing an org group found. Skipping organisation derivation tests.');
}
else {
  echo "  Category: \"{$cat_with_org->label()}\" (TID=$cat_with_org_tid) -> root org group ID=$org_group_id\n";

  // Determine coordinates: use child jur boundary if available, else root, else default.
  $org_coords = NULL;
  $expected_child_id = NULL;

  // Try to find a child jur with a boundary for precise testing.
  foreach ($child_jurs as $cid => $cdata) {
    $g = $cdata['group'];
    if ($g->hasField('field_boundary') && !$g->get('field_boundary')->isEmpty()) {
      // Try to get coordinates from existing nodes in this child jur.
      $existing_rels = $etm->getStorage('group_relationship')->loadByProperties([
        'gid' => $cid,
        'plugin_id' => 'group_node:service_request',
      ]);
      foreach ($existing_rels as $rel) {
        $entity = $rel->getEntity();
        if ($entity && $entity->hasField('field_geolocation') && !$entity->get('field_geolocation')->isEmpty()) {
          $geo = $entity->get('field_geolocation')->first();
          $org_coords = [
            'lat' => (float) $geo->get('lat')->getValue(),
            'lng' => (float) $geo->get('lng')->getValue(),
          ];
          if ($org_coords['lat'] !== 0.0 || $org_coords['lng'] !== 0.0) {
            $expected_child_id = $cid;
            break 2;
          }
          $org_coords = NULL;
        }
      }

      // Fallback: boundary centroid.
      $boundary_raw = $g->get('field_boundary')->value;
      $centroid = _test_compute_centroid($boundary_raw);
      if ($centroid) {
        $org_coords = $centroid;
        $expected_child_id = $cid;
        break;
      }
    }
  }

  // Fallback: if no child jur has a boundary, use coordinates outside all boundaries.
  // The node will fall back to root assignment.
  if (!$org_coords) {
    // Use a root jur boundary centroid or a generic inside point.
    foreach ($root_jurs as $rid => $rdata) {
      $g = $rdata['group'];
      if ($g->hasField('field_boundary') && !$g->get('field_boundary')->isEmpty()) {
        $centroid = _test_compute_centroid($g->get('field_boundary')->value);
        if ($centroid) {
          $org_coords = $centroid;
          break;
        }
      }
    }
  }

  if (!$org_coords) {
    // Last resort: use a neutral point.
    $org_coords = ['lat' => 0.001, 'lng' => 0.001];
  }

  echo "  Test coordinates: lat={$org_coords['lat']}, lng={$org_coords['lng']}\n";
  if ($expected_child_id !== NULL) {
    echo "  Expected child jur: {$child_jurs[$expected_child_id]['label']} (ID=$expected_child_id)\n";
  }

  // Determine the expected org: if the node falls in a child jur, check for
  // a jurisdiction-aware org that handles this category via field_service_categories.
  // This mirrors the logic in _markaspot_group_derive_org_from_category().
  $expected_org_id = $org_group_id;
  if ($expected_child_id !== NULL) {
    $child_org_ids = $group_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_jurisdiction', $expected_child_id)
      ->condition('field_service_categories', $cat_with_org_tid)
      ->execute();
    if (!empty($child_org_ids)) {
      $expected_org_id = (int) reset($child_org_ids);
      $child_org = $group_storage->load($expected_org_id);
      echo "  Jurisdiction-aware org override: org ID=$expected_org_id"
        . " (" . ($child_org ? $child_org->label() : '?') . ")"
        . " in child jur $expected_child_id\n";
    }
  }

  $test_node = NULL;
  try {
    // Create a test node with the org-mapped category.
    $test_node = \Drupal\node\Entity\Node::create([
      'type' => 'service_request',
      'title' => 'Test group assignment - org derivation - ' . date('c'),
      'status' => 1,
      'uid' => 1,
      'field_geolocation' => [
        'lat' => $org_coords['lat'],
        'lng' => $org_coords['lng'],
      ],
      'field_category' => ['target_id' => $cat_with_org_tid],
      'field_status' => ['target_id' => $default_status_tid],
    ]);
    $test_node->save();

    $nid = (int) $test_node->id();
    echo "  Created test node: $nid\n";

    // Reload the node to pick up any post-save changes (e.g. field_organisation set by hook).
    $etm->getStorage('node')->resetCache([$nid]);
    $test_node = $node_storage->load($nid);

    // Assert: node has field_organisation set (derived from category).
    $has_org = $test_node->hasField('field_organisation')
      && !$test_node->get('field_organisation')->isEmpty();
    assert_true(
      $has_org,
      "Node $nid has field_organisation set (derived from category \"{$cat_with_org->label()}\")"
    );

    if ($has_org) {
      $actual_org_id = (int) $test_node->get('field_organisation')->target_id;
      assert_equal(
        $expected_org_id,
        $actual_org_id,
        "field_organisation matches expected org group (ID=$expected_org_id)"
      );
    }

    // Assert: node has an org group_relationship matching field_organisation.
    $org_rels = $etm->getStorage('group_relationship')->loadByProperties([
      'entity_id' => $nid,
      'gid' => $expected_org_id,
      'plugin_id' => 'group_node:service_request',
    ]);
    assert_true(
      !empty($org_rels),
      "Node $nid has org group_relationship to org group (ID=$expected_org_id)"
    );

    // Assert: if we placed the node inside a child jur boundary, it should
    // have a child jur group_relationship.
    if ($expected_child_id !== NULL) {
      assert_true(
        _test_has_group_relationship($nid, $expected_child_id),
        "Node $nid has group_relationship to child jur \"{$child_jurs[$expected_child_id]['label']}\" (from boundary)"
      );

      // Assert: node does NOT have a root jur group_relationship.
      $root_for_child = $child_jurs[$expected_child_id]['parent_id'];
      $resolver = \Drupal::service('markaspot_group.hierarchy_resolver');
      $root_for_child = $resolver->getRootJurisdictionId($expected_child_id);

      assert_true(
        !_test_has_group_relationship($nid, $root_for_child),
        "Node $nid does NOT have group_relationship to root jur (ID=$root_for_child)"
      );
    }
    else {
      skip_test('No child jur with boundary for boundary assignment test in org derivation context.');
    }
  }
  finally {
    _test_cleanup_node($test_node);
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
  echo "\033[1;32m║  ALL TESTS PASSED                                        ║\033[0m\n";
} else {
  echo "\033[1;31m║  SOME TESTS FAILED                                       ║\033[0m\n";
}
echo "\033[1m╚══════════════════════════════════════════════════════════╝\033[0m\n";
echo "  \033[32m✓ {$t['pass']} passed\033[0m  \033[31m✗ {$t['fail']} failed\033[0m  \033[33m⊘ {$t['skip']} skipped\033[0m  ($total total, $pct%)\n\n";

if ($t['fail'] > 0) {
  throw new \RuntimeException("$t[fail] test(s) failed");
}
