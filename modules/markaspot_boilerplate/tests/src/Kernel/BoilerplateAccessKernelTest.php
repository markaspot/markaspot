<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_boilerplate\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\markaspot_boilerplate\Access\BoilerplateAccess;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_boilerplate\Controller\LoadController;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\WorkspaceVisibilityNodeAccessControlHandler;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once dirname(__DIR__, 4) . '/markaspot_group/src/Service/TenantAdminHelper.php';
require_once dirname(__DIR__, 4) . '/markaspot_group/src/Service/WorkspaceVisibilityInterface.php';
require_once dirname(__DIR__, 4) . '/markaspot_group/src/Service/JurisdictionHierarchyResolverInterface.php';
require_once dirname(__DIR__, 4) . '/markaspot_group/src/WorkspaceVisibilityNodeAccessControlHandler.php';

/**
 * Covers template collection, entity and legacy route boundaries.
 *
 * @group markaspot_boilerplate
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class BoilerplateAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'options', 'entity',
    'flexible_permissions', 'group', 'gnode', 'markaspot_boilerplate',
    'boilerplate_access_test',
  ];

  /**
   * Tests that all read paths apply identical boundaries.
   */
  public function testTemplateBoundaries(): void {
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $entity) {
      $this->installEntitySchema($entity);
    }
    $this->installSchema('node', ['node_access']);
    $this->container->get('database')->insert('node_access')->fields([
      'nid' => 0, 'langcode' => 'en', 'fallback' => 1, 'realm' => 'all',
      'gid' => 0, 'grant_view' => 1, 'grant_update' => 0, 'grant_delete' => 0,
    ])->execute();
    $this->installConfig(['system', 'user', 'node', 'group']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);
    foreach (['anonymous', 'authenticated'] as $id) {
      Role::load($id)->grantPermission('access content')->save();
    }
    Role::create(['id' => 'staff', 'label' => 'Staff', 'permissions' => ['manage dashboard notes']])->save();
    Role::create(['id' => 'limited', 'label' => 'Limited', 'permissions' => ['add dashboard status notes']])->save();
    foreach (['jur', 'org'] as $id) {
      $type = GroupType::create(['id' => $id, 'label' => $id]);
      $type->save();
      $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
      if (!$storage->load($id . '-group_membership')) {
        $storage->createFromPlugin($type, 'group_membership')->save();
      }
      GroupRole::create([
        'id' => $id . '-member', 'label' => 'Member', 'group_type' => $id,
        'scope' => 'individual',
        'permissions' => ['view group_node:boilerplate entity'],
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction', 'entity_type' => 'group',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_jurisdiction', 'entity_type' => 'group', 'bundle' => 'org'])->save();
    $jur = Group::create(['type' => 'jur', 'label' => 'A']);
    $jur->save();
    $foreign = Group::create(['type' => 'jur', 'label' => 'B']);
    $foreign->save();
    $org = Group::create(['type' => 'org', 'label' => 'Assigned', 'field_jurisdiction' => $jur->id()]);
    $org->save();
    $other_org = Group::create(['type' => 'org', 'label' => 'Other', 'field_jurisdiction' => $jur->id()]);
    $other_org->save();
    NodeType::create(['type' => 'boilerplate', 'name' => 'Template'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->container->get('entity_type.manager')->getStorage('group_relationship_type')
      ->createFromPlugin(GroupType::load('jur'), 'group_node:boilerplate')->save();
    foreach (['field_jurisdiction', 'field_organisation'] as $field) {
      FieldStorageConfig::create([
        'field_name' => $field, 'entity_type' => 'node',
        'type' => 'entity_reference', 'settings' => ['target_type' => 'group'],
        'cardinality' => -1,
      ])->save();
      FieldConfig::create(['field_name' => $field, 'entity_type' => 'node', 'bundle' => 'boilerplate'])->save();
    }
    foreach (['body', 'field_boilerplate_type'] as $field) {
      if (!FieldStorageConfig::load('node.' . $field)) {
        FieldStorageConfig::create(['field_name' => $field, 'entity_type' => 'node', 'type' => 'string_long'])->save();
      }
      if (!FieldConfig::load('node.boilerplate.' . $field)) {
        FieldConfig::create(['field_name' => $field, 'entity_type' => 'node', 'bundle' => 'boilerplate'])->save();
      }
    }
    $nodes = [];
    foreach (['shared', 'assigned', 'other_org', 'foreign', 'inactive', 'remarks', 'unscoped', 'multiple'] as $key) {
      $nodes[$key] = Node::create([
        'type' => 'boilerplate', 'title' => $key, 'status' => $key !== 'inactive', 'uid' => 1,
        'body' => $key, 'field_boilerplate_type' => $key === 'remarks' ? 'remarks' : 'status_notes',
        'field_jurisdiction' => $key === 'unscoped' ? [] : ($key === 'multiple' ? [$jur->id(), $foreign->id()] : ($key === 'foreign' ? $foreign->id() : $jur->id())),
        'field_organisation' => $key === 'assigned' ? $org->id() : ($key === 'other_org' ? $other_org->id() : []),
      ]);
      $nodes[$key]->save();

    }
    $page = Node::create(['type' => 'page', 'title' => 'Public', 'status' => 1]);
    $page->save();
    $staff = User::create(['name' => 'staff', 'status' => 1, 'roles' => ['staff']]);
    $staff->save();
    $jur->addMember($staff, ['group_roles' => ['jur-member']]);
    $org->addMember($staff);
    $limited = User::create(['name' => 'limited', 'status' => 1, 'roles' => ['limited']]);
    $limited->save();
    $jur->addMember($limited, ['group_roles' => ['jur-member']]);
    $outsider = User::create(['name' => 'outsider', 'status' => 1, 'roles' => ['staff']]);
    $outsider->save();
    $foreign->addMember($outsider, ['group_roles' => ['jur-member']]);
    GroupRole::create([
      'id' => 'jur-tenant_admin', 'label' => 'Tenant administrator',
      'group_type' => 'jur', 'scope' => 'individual',
      'permissions' => [
        'view group_node:boilerplate entity',
        'view unpublished group_node:boilerplate entity',
        'update any group_node:boilerplate entity',
        'delete any group_node:boilerplate entity',
      ],
    ])->save();
    Role::create([
      'id' => 'manager', 'label' => 'Manager', 'permissions' => [
        'manage dashboard notes', 'edit any boilerplate content',
        'delete any boilerplate content', 'create boilerplate content',
      ],
    ])->save();
    $admin = User::create(['name' => 'tenant-admin', 'status' => 1, 'roles' => ['manager']]);
    $admin->save();
    $jur->addMember($admin, ['group_roles' => ['jur-tenant_admin']]);
    $group_admin = User::create(['name' => 'group-admin', 'status' => 1, 'roles' => ['staff']]);
    $group_admin->save();
    $jur->addMember($group_admin, ['group_roles' => ['jur-tenant_admin']]);
    $controller = LoadController::create($this->container);
    $handler = new WorkspaceVisibilityNodeAccessControlHandler(
      $this->container->get('entity_type.manager')->getDefinition('node'),
      $this->container->get('node.grant_storage'),
      $this->container->get('entity_type.manager'),
      $this->createMock(WorkspaceVisibilityInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
    );
    foreach ([
      [$staff, ['shared', 'assigned', 'remarks']],
      [$admin, ['shared', 'assigned', 'other_org', 'remarks', 'inactive']],
      [$group_admin, ['shared', 'assigned', 'other_org', 'remarks', 'inactive']],
      [$limited, ['shared']],
      [$outsider, ['foreign']],
      [new AnonymousUserSession(), []],
    ] as [$account, $expected]) {
      $this->container->get('current_user')->setAccount($account);
      $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
      $ids = array_values($this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', 'boilerplate')->execute());
      $all_ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)->execute();
      $this->assertContains($page->id(), $all_ids);
      $this->assertEqualsCanonicalizing(array_merge($ids, [$page->id()]), array_values($all_ids));
      $expected_ids = array_map(static fn($key) => $nodes[$key]->id(), $expected);
      sort($ids);
      sort($expected_ids);
      $this->assertEquals($expected_ids, $ids);
      foreach ($nodes as $key => $node) {
        $allowed = in_array($key, $expected, TRUE);
        $this->assertSame($allowed, $node->access('view', $account), $key);
        $this->assertSame($allowed, $handler->access($node, 'view', $account), $key);
        try {
          $response = $controller->load($node->id());
          $this->assertTrue($allowed && $node->isPublished(), $key);
          $this->assertSame($key, json_decode($response->getContent(), TRUE));
        }
        catch (AccessDeniedHttpException) {
          $this->assertFalse($allowed && $node->isPublished(), $key);
        }
      }
    }
    // Core's broad editorial bypass must not cross a tenant boundary.
    Role::create(['id' => 'broad', 'label' => 'Broad editor', 'permissions' => ['bypass node access']])->save();
    $outsider->addRole('broad')->save();
    $this->container->get('current_user')->setAccount($outsider);
    $handler->resetCache();
    $this->assertFalse($handler->access($nodes['shared'], 'view', $outsider));
    $this->assertTrue($handler->access($nodes['foreign'], 'view', $outsider));
    $this->assertTrue($handler->access($nodes['inactive'], 'view', $root));
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', 'boilerplate')->execute();
    $this->assertEquals([$nodes['foreign']->id()], array_values($ids));
    try {
      $controller->load($nodes['shared']->id());
      $this->fail('Broad editorial permission exposed a foreign template.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
    // Write guards preserve permissions and cannot be bypassed by rehoming.
    $this->container->get('current_user')->setAccount($admin);
    $this->assertTrue($handler->access($nodes['inactive'], 'update', $admin));
    $this->assertTrue($handler->access($nodes['inactive'], 'update', $group_admin));
    $this->assertFalse($handler->access($nodes['foreign'], 'update', $group_admin));
    $this->assertTrue($handler->access($nodes['shared'], 'delete', $admin));
    $this->assertFalse($handler->access($nodes['foreign'], 'update', $admin));
    $this->assertFalse($handler->access($nodes['foreign'], 'delete', $admin));
    $this->assertFalse($handler->access($nodes['shared'], 'update', $staff));
    $nodes['inactive']->setPublished()->save();
    $this->assertTrue(Node::load($nodes['inactive']->id())->isPublished());
    $nodes['inactive']->setUnpublished()->save();

    $new = Node::create([
      'type' => 'boilerplate', 'title' => 'Created', 'status' => 1,
      'field_jurisdiction' => $jur->id(), 'field_boilerplate_type' => 'status_notes',
      'field_organisation' => $other_org->id(),
    ]);
    $this->assertSame([], BoilerplateAccess::writeErrors($new, $admin));
    $this->container->get('current_user')->setAccount($group_admin);
    $new->save();
    $this->assertNotEmpty($new->id());
    $this->assertCount(1, $jur->getRelationshipsByEntity($new, 'group_node:boilerplate'));
    $this->assertTrue($handler->access($new, 'update', $group_admin));
    $new->setTitle('Edited after creation')->save();
    $this->assertCount(1, $jur->getRelationshipsByEntity($new, 'group_node:boilerplate'));
    $this->assertTrue($handler->access($new, 'delete', $group_admin));
    $new->delete();
    $this->container->get('current_user')->setAccount($admin);
    $this->assertNull(Node::load($new->id()));

    $invalid = [
      $nodes['foreign']->createDuplicate()->set('field_organisation', []),
      $nodes['shared']->createDuplicate()->set('field_jurisdiction', []),
      $nodes['shared']->createDuplicate()->set('field_jurisdiction', $org->id()),
      $nodes['shared']->createDuplicate()->set('field_jurisdiction', [$jur->id(), $foreign->id()]),
      $nodes['shared']->createDuplicate()->set('field_organisation', $jur->id()),
    ];
    $foreign_org = Group::create([
      'type' => 'org', 'label' => 'Foreign organisation',
      'field_jurisdiction' => $foreign->id(),
    ]);
    $foreign_org->save();
    $invalid[] = $nodes['shared']->createDuplicate()->set('field_organisation', $foreign_org->id());
    foreach ($invalid as $candidate) {
      $errors = BoilerplateAccess::writeErrors($candidate, $admin);
      $this->assertNotEmpty($errors);
      $violations = array_map(static fn($violation) => (string) $violation->getMessage(), iterator_to_array($candidate->validate()));
      $this->assertContains($errors[0], $violations);
      try {
        $candidate->save();
        $this->fail('Invalid template scope was persisted.');
      }
      catch (EntityStorageException) {
        $this->addToAssertionCount(1);
      }
    }
    $rehomed = clone $nodes['foreign'];
    $rehomed->set('field_jurisdiction', $jur->id());
    $this->assertNotEmpty(BoilerplateAccess::writeErrors($rehomed, $admin));
    try {
      $rehomed->save();
      $this->fail('A foreign template was moved into the current jurisdiction.');
    }
    catch (EntityStorageException) {
      $this->addToAssertionCount(1);
    }
    $this->assertSame((int) $foreign->id(), (int) Node::load($nodes['foreign']->id())->get('field_jurisdiction')->target_id);
    try {
      $nodes['foreign']->delete();
      $this->fail('A foreign template was deleted.');
    }
    catch (EntityStorageException) {
      $this->addToAssertionCount(1);
    }
    $this->assertNotNull(Node::load($nodes['foreign']->id()));

    $staff->addRole('manager')->save();
    GroupRole::create([
      'id' => 'jur-template_editor', 'label' => 'Template editor',
      'group_type' => 'jur', 'scope' => 'individual',
      'permissions' => ['view group_node:boilerplate entity', 'update any group_node:boilerplate entity'],
    ])->save();
    $staff_membership = $jur->getMember($staff)->getGroupRelationship();
    $staff_membership->set('group_roles', ['jur-template_editor'])->save();
    $this->container->get('current_user')->setAccount($staff);
    $handler->resetCache();
    $this->assertTrue($handler->access($nodes['assigned'], 'update', $staff));
    $this->assertFalse($handler->access($nodes['other_org'], 'update', $staff));
    $stolen = clone $nodes['other_org'];
    $stolen->set('field_organisation', $org->id());
    $this->assertNotEmpty(BoilerplateAccess::writeErrors($stolen, $staff));
    $expanded = clone $nodes['assigned'];
    $expanded->set('field_organisation', [$org->id(), $other_org->id()]);
    $this->assertNotEmpty(BoilerplateAccess::writeErrors($expanded, $staff));
    $this->assertSame([], BoilerplateAccess::writeErrors($rehomed, $root));
    $this->container->get('current_user')->setAccount($root);
    try {
      $rehomed->save();
      $this->fail('UID1 must not repair a foreign ownership relationship.');
    }
    catch (EntityStorageException) {
      $this->addToAssertionCount(1);
    }
    $this->assertCount(0, $jur->getRelationshipsByEntity($rehomed, 'group_node:boilerplate'));
    $this->assertCount(1, $foreign->getRelationshipsByEntity($rehomed, 'group_node:boilerplate'));
    $persisted = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($rehomed->id());
    $this->assertSame((int) $foreign->id(), (int) $persisted->get('field_jurisdiction')->target_id);
    // Corrupt storage directly: normal Group writes already reject these
    // states through the node save hook. Imports must reject legacy corruption.
    $relationships = $jur->getRelationshipsByEntity($nodes['assigned'], 'group_node:boilerplate');
    $original_link = reset($relationships);
    $duplicate_id = $this->duplicateRelationship((int) $original_link->id());
    try {
      $nodes['assigned']->save();
      $this->fail('UID1 must not repair duplicate ownership relationships.');
    }
    catch (EntityStorageException) {
      $this->addToAssertionCount(1);
    }
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('group_relationship')->loadUnchanged($duplicate_id));
    $this->assertCount(2, $jur->getRelationshipsByEntity($nodes['assigned'], 'group_node:boilerplate'));
    $relationships = $jur->getRelationshipsByEntity($nodes['remarks'], 'group_node:boilerplate');
    $foreign_link = reset($relationships);
    $definition = $this->container->get('entity_type.manager')->getDefinition('group_relationship');
    foreach (array_filter([$definition->getBaseTable(), $definition->getDataTable()]) as $table) {
      $db = $this->container->get('database');
      if ($db->schema()->fieldExists($table, 'gid')) {
        $db->update($table)->fields(['gid' => $foreign->id()])->condition('id', $foreign_link->id())->execute();
      }
    }
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();
    try {
      $nodes['remarks']->save();
      $this->fail('UID1 must not replace a foreign relationship.');
    }
    catch (EntityStorageException) {
      $this->addToAssertionCount(1);
    }
    $this->assertCount(0, $jur->getRelationshipsByEntity($nodes['remarks'], 'group_node:boilerplate'));
    $this->assertCount(1, $foreign->getRelationshipsByEntity($nodes['remarks'], 'group_node:boilerplate'));
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('group_relationship')->loadUnchanged($foreign_link->id()));
    $this->container->get('current_user')->setAccount($staff);
    $this->assertFalse($handler->access($nodes['shared'], 'delete', $outsider));

    try {
      $controller->load(999999);
      $this->fail('Missing templates must return not found.');
    }
    catch (NotFoundHttpException) {
      $this->addToAssertionCount(1);
    }
    $this->expectException(NotFoundHttpException::class);
    $controller->load($page->id());
  }

  /**
   * Inserts a corrupt duplicate without invoking protective entity hooks.
   */
  private function duplicateRelationship(int $id): int {
    $definition = $this->container->get('entity_type.manager')->getDefinition('group_relationship');
    $db = $this->container->get('database');
    $base = $definition->getBaseTable();
    $row = $db->select($base, 'r')->fields('r')->condition('id', $id)->execute()->fetchAssoc();
    unset($row['id']);
    $row['uuid'] = $this->container->get('uuid')->generate();
    $duplicate_id = (int) $db->insert($base)->fields($row)->execute();
    if ($data = $definition->getDataTable()) {
      foreach ($db->select($data, 'r')->fields('r')->condition('id', $id)->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $row['id'] = $duplicate_id;
        $db->insert($data)->fields($row)->execute();
      }
    }
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();
    return $duplicate_id;
  }

}
