<?php

/**
 * @file
 * Post-update functions for markaspot_group.
 */

/**
 * Ensure group_roles field exists on all group_membership relationship types.
 *
 * Group module's GroupMembershipPostInstall::installGroupRolesField() skips
 * field creation when config is syncing ($is_syncing === TRUE). On cloud
 * migrations the group_relationship_type config for jur-group_membership /
 * org-group_membership is imported via drush cim, so the post-install hook
 * never runs and the group_roles field_config is never created for those
 * bundles.
 *
 * The visible failure is "Field group_roles is unknown" when saving any jur
 * or org group, because group creation auto-creates a membership relationship
 * for the creator, which tries to access the missing group_roles field.
 *
 * This post_update detects group_membership bundles without a corresponding
 * group_roles field_config and backfills them. Also repairs any existing
 * field_config whose handler_settings.group_type_id does not match the
 * relationship type's group type (symptom of a stale config import).
 *
 * Idempotent: after the first run, the loop finds no missing or drifted
 * fields and returns NULL.
 *
 * The canonical location for this fix would be an upstream Group module
 * update hook, but until that lands, every cloud tenant reaching this
 * profile version inherits the fix automatically on drush updb.
 */
function markaspot_group_post_update_backfill_group_roles_field_configs(array &$sandbox): ?string {
  $entityTypeManager = \Drupal::entityTypeManager();
  $fcStorage = $entityTypeManager->getStorage('field_config');
  $fscStorage = $entityTypeManager->getStorage('field_storage_config');
  $grtStorage = $entityTypeManager->getStorage('group_relationship_type');

  $fieldStorage = $fscStorage->load('group_relationship.group_roles');
  if (!$fieldStorage) {
    \Drupal::logger('markaspot_group')->warning(
      'field_storage_config group_relationship.group_roles is missing. Cannot backfill group_roles fields. Is the group module installed?'
    );
    return 'field_storage_config group_relationship.group_roles missing - nothing to do.';
  }

  $created = [];
  $repaired = [];
  foreach ($grtStorage->loadMultiple() as $relationshipType) {
    $rtId = $relationshipType->id();
    // Only membership-based relationship types have group_roles. Filter by
    // the plugin_id config property directly rather than going through the
    // plugin manager - we want to stay safe during update hooks when plugin
    // instantiation may not be fully wired.
    if ($relationshipType->get('plugin_id') !== 'group_membership') {
      continue;
    }
    $groupTypeId = $relationshipType->getGroupTypeId();
    $existing = $fcStorage->load("group_relationship.$rtId.group_roles");

    if ($existing) {
      // Repair case: handler_settings.group_type_id drifted away from the
      // relationship type's actual group type. This happens when someone
      // imports a field_config from an older sync where the group type was
      // renamed (e.g. organisation -> org) but the field_config wasn't
      // updated alongside.
      $settings = $existing->getSettings();
      if (($settings['handler_settings']['group_type_id'] ?? NULL) !== $groupTypeId) {
        $settings['handler_settings']['group_type_id'] = $groupTypeId;
        $existing->setSettings($settings);
        $existing->save();
        $repaired[] = $rtId;
      }
      continue;
    }

    // Match the canonical field.field.group_relationship.*.group_roles.yml
    // shape exactly, so drush cex does not show drift on the first export
    // after this hook runs. Reference: wbd-maengelmelder config/sync.
    $fcStorage->save($fcStorage->create([
      'field_storage' => $fieldStorage,
      'bundle' => $rtId,
      'label' => 'Roles',
      'description' => '',
      'required' => FALSE,
      'translatable' => TRUE,
      'default_value' => [],
      'default_value_callback' => '',
      'settings' => [
        'handler' => 'group_type:group_role',
        'handler_settings' => [
          'group_type_id' => $groupTypeId,
        ],
      ],
      'field_type' => 'entity_reference',
    ]));
    $created[] = $rtId;
  }

  $parts = [];
  if ($created) {
    \Drupal::logger('markaspot_group')->notice(
      'Created group_roles field_config on @count membership relationship type(s): @list.',
      ['@count' => count($created), '@list' => implode(', ', $created)]
    );
    $parts[] = 'created on ' . implode(', ', $created);
  }
  if ($repaired) {
    \Drupal::logger('markaspot_group')->notice(
      'Repaired handler_settings.group_type_id on @count group_roles field_config(s): @list.',
      ['@count' => count($repaired), '@list' => implode(', ', $repaired)]
    );
    $parts[] = 'repaired handler_settings on ' . implode(', ', $repaired);
  }

  if (!$parts) {
    return NULL;
  }

  return 'group_roles field_config: ' . implode('; ', $parts) . '.';
}

/**
 * Deletes safely repairable orphan group relationships.
 */
function markaspot_group_post_update_cleanup_orphaned_group_relationships(array &$sandbox): string {
  /** @var \Drupal\markaspot_group\Service\GroupIntegrityChecker $checker */
  $checker = \Drupal::service('markaspot_group.integrity_checker');
  $results = $checker->repairOrphanRelationships(FALSE);

  $deleted = ($results['relationship_missing_node'] ?? 0)
    + ($results['relationship_missing_user'] ?? 0);
  $skippedMissingGroups = $results['relationship_missing_group_skipped'] ?? 0;

  return sprintf(
    'Deleted %d orphan group relationship(s): missing node=%d, missing user=%d. Skipped missing group=%d.',
    $deleted,
    $results['relationship_missing_node'] ?? 0,
    $results['relationship_missing_user'] ?? 0,
    $skippedMissingGroups,
  );
}

/**
 * Clears entity definitions so group delete protection class is activated.
 */
function markaspot_group_post_update_activate_protected_group_entity_class(array &$sandbox): string {
  \Drupal::entityTypeManager()->clearCachedDefinitions();

  return 'Cleared entity type definitions so group entities use the protected Mark-a-Spot group class.';
}

/**
 * Rebuilds grants for organisation-scoped contractor request access.
 */
function markaspot_group_post_update_rebuild_contractor_request_grants(array &$sandbox): string {
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!isset($sandbox['total'])) {
    $sandbox['total'] = (int) $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->count()
      ->execute();
    $sandbox['progress'] = 0;
    $sandbox['last_nid'] = 0;
  }
  if ($sandbox['total'] === 0) {
    $sandbox['#finished'] = 1;
    return 'No service requests require contractor grant rebuilding.';
  }

  $node_ids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'service_request')
    ->condition('nid', $sandbox['last_nid'], '>')
    ->sort('nid')
    ->range(0, 50)
    ->execute();
  if ($node_ids === []) {
    $sandbox['#finished'] = 1;
    return 'Rebuilt node access grants for organisation-scoped contractor request access.';
  }
  $access_handler = \Drupal::entityTypeManager()->getAccessControlHandler('node');
  $grant_storage = \Drupal::service('node.grant_storage');
  foreach ($node_ids as $node_id) {
    $node_storage->resetCache([$node_id]);
    $node = $node_storage->load($node_id);
    if ($node) {
      $grant_storage->write($node, $access_handler->acquireGrants($node));
    }
    $sandbox['last_nid'] = (int) $node_id;
    $sandbox['progress']++;
  }
  $sandbox['#finished'] = min(1, $sandbox['progress'] / $sandbox['total']);

  return 'Rebuilding node access grants for organisation-scoped contractor request access.';
}
