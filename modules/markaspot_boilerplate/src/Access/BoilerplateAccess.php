<?php

declare(strict_types=1);

namespace Drupal\markaspot_boilerplate\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\TenantAdminHelper;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Applies the same staff boundary to template entities and collections.
 */
final class BoilerplateAccess {

  /**
   * Resolves exact memberships, without parent/child jurisdiction expansion.
   *
   * @return array{global: bool, jurisdictions: int[], admins: int[], orgs: int[]}
   *   The account's template scope.
   */
  public static function scope(AccountInterface $account): array {
    $scope = ['global' => FALSE, 'jurisdictions' => [], 'admins' => [], 'orgs' => []];
    if ($account->isAnonymous()) {
      return $scope;
    }
    $scope['global'] = (int) $account->id() === 1 || in_array('administrator', $account->getRoles(), TRUE);
    if ($scope['global'] || !\Drupal::moduleHandler()->moduleExists('group')) {
      return $scope;
    }
    $user = User::load($account->id());
    if (!$user) {
      return $scope;
    }
    $jurisdiction_type = \Drupal::config('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';
    foreach (GroupMembership::loadByUser($user) as $membership) {
      $group = $membership->getGroup();
      if ($group->bundle() === $jurisdiction_type) {
        $scope['jurisdictions'][] = (int) $group->id();
      }
      elseif ($group->bundle() === 'org') {
        $scope['orgs'][] = (int) $group->id();
      }
    }
    if (class_exists(TenantAdminHelper::class)) {
      $scope['admins'] = TenantAdminHelper::getUserJurisdictionIds($account);
    }
    return $scope;
  }

  /**
   * Returns readable template types for staff accounts.
   *
   * @return string[]
   *   Allowed template type values.
   */
  public static function types(AccountInterface $account): array {
    if ($account->isAnonymous()) {
      return [];
    }
    if ($account->hasPermission('manage dashboard notes')) {
      return ['status_notes', 'remarks', 'service_provider'];
    }
    return $account->hasPermission('add dashboard status notes') ? ['status_notes'] : [];
  }

  /**
   * Checks template publication, staff capabilities and exact tenant scope.
   */
  public static function view(NodeInterface $node, AccountInterface $account): AccessResult {
    $result = AccessResult::forbidden('This template is not available to this account.')
      ->cachePerUser()->cachePerPermissions()->addCacheableDependency($node)
      ->addCacheTags(['group_list', 'group_relationship_list', 'user:' . $account->id()]);
    if ($node->bundle() !== 'boilerplate'
      || (!$node->isPublished() && !self::canManage($node, $account))) {
      return $result;
    }
    $scope = self::scope($account);
    $type = $node->hasField('field_boilerplate_type') ? $node->get('field_boilerplate_type')->value : NULL;
    if (!in_array($type, self::types($account), TRUE)) {
      return $result;
    }
    if (!$scope['global']) {
      $jurisdictions = $node->hasField('field_jurisdiction') ? array_column($node->get('field_jurisdiction')->getValue(), 'target_id') : [];
      if (count($jurisdictions) !== 1 || !in_array((int) $jurisdictions[0], $scope['jurisdictions'], TRUE)) {
        return $result;
      }
      $orgs = $node->hasField('field_organisation') ? array_column($node->get('field_organisation')->getValue(), 'target_id') : [];
      if ($orgs !== [] && !in_array((int) $jurisdictions[0], $scope['admins'], TRUE)
        && array_intersect($orgs, $scope['orgs']) === []) {
        return $result;
      }
    }
    return AccessResult::allowed()->addCacheableDependency($result);
  }

  /**
   * Whether an account can manage templates, subject to tenant scoping.
   */
  private static function canManageAny(AccountInterface $account): bool {
    return $account->isAuthenticated() && ($account->hasPermission('bypass node access')
      || $account->hasPermission('edit any boilerplate content')
      || $account->hasPermission('delete any boilerplate content'));
  }

  /**
   * Checks management capability without granting a write permission.
   */
  private static function canManage(NodeInterface $node, AccountInterface $account): bool {
    $jurisdictions = $node->hasField('field_jurisdiction')
      ? array_map('intval', array_column($node->get('field_jurisdiction')->getValue(), 'target_id')) : [];
    $management = self::managementScope($account);
    return self::canManageAny($account)
      || array_intersect($jurisdictions, $management['any']) !== []
      || ((int) $node->getOwnerId() === (int) $account->id() && array_intersect($jurisdictions, $management['own']) !== [])
      || ($account->isAuthenticated() && (int) $node->getOwnerId() === (int) $account->id()
        && ($account->hasPermission('edit own boilerplate content') || $account->hasPermission('delete own boilerplate content')));
  }

  /**
   * Resolves existing Group management permissions without granting new ones.
   *
   * @return array{any: int[], own: int[]}
   *   Jurisdiction IDs with template management permission.
   */
  private static function managementScope(AccountInterface $account): array {
    $result = ['any' => [], 'own' => []];
    foreach (self::scope($account)['jurisdictions'] as $jurisdiction_id) {
      $group = \Drupal::entityTypeManager()->getStorage('group')->load($jurisdiction_id);
      if (!$group instanceof GroupInterface) {
        continue;
      }
      foreach (['any', 'own'] as $ownership) {
        if ($group->hasPermission("update $ownership group_node:boilerplate entity", $account)
          || $group->hasPermission("delete $ownership group_node:boilerplate entity", $account)) {
          $result[$ownership][] = $jurisdiction_id;
        }
      }
    }
    return $result;
  }

  /**
   * Restricts existing write permissions to valid original and proposed scope.
   */
  public static function write(NodeInterface $node, AccountInterface $account): AccessResult {
    $result = self::writeErrors($node, $account) === []
      ? AccessResult::neutral() : AccessResult::forbidden('Template changes must remain within their tenant and organisation scope.');
    return $result->cachePerUser()->cachePerPermissions()->addCacheableDependency($node)
      ->addCacheTags(['group_list', 'group_relationship_list', 'user:' . $account->id()]);
  }

  /**
   * Validates scope of creates, updates and deletes independently of grants.
   *
   * @return string[]
   *   Validation errors, or an empty list.
   */
  public static function writeErrors(NodeInterface $node, AccountInterface $account): array {
    // Installation and the validated bootstrap importer run as UID1.
    if ($node->bundle() !== 'boilerplate' || (int) $account->id() === 1) {
      return [];
    }
    $jurisdictions = $node->hasField('field_jurisdiction')
      ? array_map('intval', array_column($node->get('field_jurisdiction')->getValue(), 'target_id')) : [];
    if (count($jurisdictions) !== 1 || $jurisdictions[0] <= 0) {
      return ['A template must belong to exactly one jurisdiction.'];
    }
    $jurisdiction_id = $jurisdictions[0];
    $storage = \Drupal::entityTypeManager()->getStorage('group');
    $jurisdiction = $storage->load($jurisdiction_id);
    $jurisdiction_type = \Drupal::config('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';
    if (!$jurisdiction instanceof GroupInterface || $jurisdiction->bundle() !== $jurisdiction_type) {
      return ['The template jurisdiction is invalid.'];
    }
    $scope = self::scope($account);
    if ($account->isAnonymous() || (!$scope['global'] && !in_array($jurisdiction_id, $scope['jurisdictions'], TRUE))) {
      return ['You cannot change templates outside your jurisdiction.'];
    }
    if (!$node->isNew()) {
      $original = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
      $original_jurisdictions = $original && $original->hasField('field_jurisdiction')
        ? array_map('intval', array_column($original->get('field_jurisdiction')->getValue(), 'target_id')) : [];
      if ($original_jurisdictions !== $jurisdictions) {
        return ['A template cannot be moved to another jurisdiction.'];
      }
      // Updating organisation assignments must not take over an inaccessible
      // original template by first changing its target organisation.
      $original_orgs = $original && $original->hasField('field_organisation')
        ? array_map('intval', array_column($original->get('field_organisation')->getValue(), 'target_id')) : [];
      if (!$scope['global'] && !in_array($jurisdiction_id, $scope['admins'], TRUE)
        && array_diff($original_orgs, $scope['orgs']) !== []) {
        return ['You cannot change templates outside your organisations.'];
      }
    }
    $org_ids = $node->hasField('field_organisation')
      ? array_map('intval', array_column($node->get('field_organisation')->getValue(), 'target_id')) : [];
    foreach ($org_ids as $org_id) {
      $org = $storage->load($org_id);
      $org_jurisdictions = $org instanceof GroupInterface && $org->hasField('field_jurisdiction')
        ? array_map('intval', array_column($org->get('field_jurisdiction')->getValue(), 'target_id')) : [];
      if (!$org instanceof GroupInterface || $org->bundle() !== 'org' || $org_jurisdictions !== [$jurisdiction_id]) {
        return ['Every template organisation must belong to its jurisdiction.'];
      }
      if (!$scope['global'] && !in_array($jurisdiction_id, $scope['admins'], TRUE) && !in_array($org_id, $scope['orgs'], TRUE)) {
        return ['You cannot change templates outside your organisations.'];
      }
    }
    return [];
  }

  /**
   * Mirrors validated jurisdiction ownership into canonical Group relations.
   */
  public static function syncRelationship(NodeInterface $node): void {
    if ($node->bundle() !== 'boilerplate' || !\Drupal::moduleHandler()->moduleExists('gnode')) {
      return;
    }
    $lock = \Drupal::lock();
    $key = 'markaspot_boilerplate.relationship:' . $node->id();
    if (!$lock->acquire($key, 30.0)) {
      throw new \RuntimeException('Another template relationship update is in progress.');
    }
    try {
      $jurisdiction_type = \Drupal::config('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';
      $jurisdictions = $node->hasField('field_jurisdiction')
        ? array_map('intval', array_column($node->get('field_jurisdiction')->getValue(), 'target_id')) : [];
      $jurisdiction = count($jurisdictions) === 1
        ? \Drupal::entityTypeManager()->getStorage('group')->load($jurisdictions[0]) : NULL;
      $target_id = $jurisdiction instanceof GroupInterface && $jurisdiction->bundle() === $jurisdiction_type
        ? (int) $jurisdiction->id() : NULL;
      $storage = \Drupal::entityTypeManager()->getStorage('group_relationship');
      $relationships = $storage->loadByProperties(['entity_id' => $node->id(), 'plugin_id' => 'group_node:boilerplate']);
      // An existing foreign or duplicate link is evidence of inconsistent
      // ownership. Never repair it implicitly, including during UID1 imports.
      if (count($relationships) > 1) {
        throw new \RuntimeException('Template ownership has duplicate Group relationships.');
      }
      if ($relationships !== []) {
        $relationship = reset($relationships);
        if ($target_id === NULL || (int) $relationship->getGroupId() !== $target_id
          || $relationship->getGroup()->bundle() !== $jurisdiction_type) {
          throw new \RuntimeException('Template ownership has a foreign Group relationship.');
        }
      }
      elseif ($target_id !== NULL) {
        $jurisdiction->addRelationship($node, 'group_node:boilerplate');
      }
    }
    finally {
      $lock->release($key);
    }
  }

  /**
   * Restricts SQL node collections before pagination and count calculation.
   */
  public static function alterQuery(SelectInterface $query): void {
    $node_alias = NULL;
    foreach ($query->getTables() as $alias => $table) {
      if (in_array($table['table'], ['node', 'node_field_data'], TRUE)) {
        $node_alias = $alias;
        break;
      }
    }
    if ($node_alias === NULL) {
      return;
    }
    $account = $query->getMetaData('account');
    if (!$account instanceof AccountInterface) {
      $account = \Drupal::currentUser();
    }
    $scope = self::scope($account);
    $types = self::types($account);
    $db = \Drupal::database();
    $allowed = $db->select('node_field_data', 'template');
    $allowed->addField('template', 'nid');
    $allowed->condition('template.type', 'boilerplate');
    if (!self::canManageAny($account)) {
      $publication = $allowed->orConditionGroup()->condition('template.status', 1);
      if ($account->isAuthenticated() && ($account->hasPermission('edit own boilerplate content') || $account->hasPermission('delete own boilerplate content'))) {
        $publication->condition('template.uid', (int) $account->id());
      }
      $management = self::managementScope($account);
      if ($management['any'] !== []) {
        $publication->condition('template_jur.field_jurisdiction_target_id', $management['any'], 'IN');
      }
      if ($management['own'] !== []) {
        $publication->condition($allowed->andConditionGroup()
          ->condition('template_jur.field_jurisdiction_target_id', $management['own'], 'IN')
          ->condition('template.uid', (int) $account->id()));
      }
      $allowed->condition($publication);
    }
    $schema = $db->schema();
    if ($types === [] || (!$scope['global'] && $scope['jurisdictions'] === [])
      || !$schema->tableExists('node__field_boilerplate_type')
      || !$schema->tableExists('node__field_jurisdiction')
      || !$schema->tableExists('node__field_organisation')) {
      $allowed->where('1 = 0');
    }
    else {
      $allowed->innerJoin('node__field_boilerplate_type', 'template_type', 'template_type.entity_id = template.nid AND template_type.deleted = 0');
      $allowed->condition('template_type.field_boilerplate_type_value', $types, 'IN');
      if (!$scope['global']) {
        $allowed->innerJoin('node__field_jurisdiction', 'template_jur', 'template_jur.entity_id = template.nid AND template_jur.deleted = 0');
        $allowed->condition('template_jur.field_jurisdiction_target_id', $scope['jurisdictions'], 'IN');
        $extra_jur = $db->select('node__field_jurisdiction', 'extra_jur')->fields('extra_jur', ['entity_id']);
        $extra_jur->condition('extra_jur.deleted', 0)->condition('extra_jur.delta', 0, '>');
        $allowed->condition('template.nid', $extra_jur, 'NOT IN');
        $allowed->leftJoin('node__field_organisation', 'template_org', 'template_org.entity_id = template.nid AND template_org.deleted = 0');
        $org_scope = $allowed->orConditionGroup()->isNull('template_org.field_organisation_target_id');
        if ($scope['admins'] !== []) {
          $org_scope->condition('template_jur.field_jurisdiction_target_id', $scope['admins'], 'IN');
        }
        if ($scope['orgs'] !== []) {
          $org_scope->condition('template_org.field_organisation_target_id', $scope['orgs'], 'IN');
        }
        $allowed->condition($org_scope);
      }
    }
    $query->condition($query->orConditionGroup()
      ->condition($node_alias . '.type', 'boilerplate', '<>')
      ->condition($node_alias . '.nid', $allowed, 'IN'));
    // Collection responses must never be shared across membership changes.
    if (\Drupal::hasService('renderer') && \Drupal::service('renderer')->hasRenderContext()) {
      $build = ['#cache' => ['max-age' => 0]];
      \Drupal::service('renderer')->render($build);
    }
  }

}
