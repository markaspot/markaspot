<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_tenant_import\Exception\TenantImportValidationException;
use Drupal\markaspot_tenant_import\Service\TenantImportFieldMapper;
use Drupal\markaspot_tenant_import\Service\TenantImporter;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests tenant configuration planning, application, and validation.
 *
 * @group markaspot_tenant_import
 */
#[RunTestsInSeparateProcesses]
final class TenantImporterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'taxonomy',
    'address',
    'color_field',
    'entity',
    'flexible_permissions',
    'group',
    'markaspot_group',
    'markaspot_tenant_import',
    'markaspot_tenant_import_test',
  ];

  /**
   * Target jurisdiction.
   */
  private GroupInterface $jurisdiction;

  /**
   * Import service under test.
   */
  private TenantImporter $importer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig([
      'system',
      'user',
      'field',
      'filter',
      'taxonomy',
      'group',
    ]);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);

    Role::create([
      'id' => 'tenant_admin',
      'label' => 'Tenant administrator',
    ])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    foreach ([
      'jur-member' => ['jur', 'individual', NULL],
      'jur-tenant_admin' => ['jur', 'individual', NULL],
      'jur-moderator' => ['jur', 'individual', NULL],
      'jur-editorial' => ['jur', 'individual', NULL],
      'jur-org_member' => ['jur', 'individual', NULL],
      'org-member' => ['org', 'insider', 'authenticated'],
    ] as $id => [$groupType, $scope, $globalRole]) {
      GroupRole::create([
        'id' => $id,
        'label' => $id,
        'group_type' => $groupType,
        'scope' => $scope,
        'global_role' => $globalRole,
      ])->save();
    }
    Vocabulary::create([
      'vid' => 'service_category',
      'name' => 'Service categories',
    ])->save();
    Vocabulary::create([
      'vid' => 'service_status',
      'name' => 'Service statuses',
    ])->save();

    $this->createModelFields();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->set(
      'lock',
      new DatabaseLockBackend($this->container->get('database')),
    );

    $this->jurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Fresh jurisdiction',
      'field_slug' => 'erfurt',
    ]);
    $this->jurisdiction->save();
    $this->importer = $this->container->get('markaspot_tenant_import.tenant_importer');
  }

  /**
   * Imports the full example, links entities, and converges on the second run.
   */
  public function testExampleImportIsIdempotent(): void {
    $configuration = $this->exampleConfiguration();

    $first = $this->importer->import(
      $configuration,
      (int) $this->jurisdiction->id(),
      [],
      TRUE,
    );
    $this->assertSame([], $first['errors']);
    $this->assertNotContains('error', array_column($first['rows'], 'action'));

    $groupStorage = $this->container->get('entity_type.manager')->getStorage('group');
    $organisationIds = $groupStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_jurisdiction', $this->jurisdiction->id())
      ->execute();
    $this->assertCount(3, $organisationIds);

    $termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $categoryIds = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_category')
      ->condition('field_jurisdiction', $this->jurisdiction->id())
      ->execute();
    $statusIds = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'service_status')
      ->condition('field_jurisdiction', $this->jurisdiction->id())
      ->execute();
    $this->assertCount(5, $categoryIds);
    $this->assertCount(4, $statusIds);

    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    $jurisdiction = Group::load($this->jurisdiction->id());
    $this->assertInstanceOf(GroupInterface::class, $jurisdiction);
    $this->assertCount(5, $jurisdiction->get('field_service_categories'));
    $this->assertCount(4, $jurisdiction->get('field_service_statuses'));
    $this->assertSame('Mängelmelder Erfurt', $jurisdiction->get('field_platform_name')->value);
    $this->assertSame('maengelmelder@erfurt.de', $jurisdiction->get('field_jurisdiction_e_mail')->value);
    $this->assertSame('Fischmarkt 1', $jurisdiction->get('field_jurisdiction_address')->address_line1);
    $this->assertSame('99084', $jurisdiction->get('field_jurisdiction_address')->postal_code);
    $this->assertSame('Erfurt', $jurisdiction->get('field_jurisdiction_address')->locality);

    $tba = $this->loadOrganisation('TBA');
    $swe = $this->loadOrganisation('SWE');
    $roadDamage = $this->loadCategory('1.1');
    $streetlight = $this->loadCategory('1.4');
    $tree = $this->loadCategory('3.2');
    $greenParent = $this->loadCategory('3');
    $this->assertSame('#8B0000', strtoupper((string) $roadDamage->get('field_category_hex')->color));
    $this->assertSame(1.0, (float) $roadDamage->get('field_category_hex')->opacity);
    $roadDamage->set('field_category_hex', ['color' => '#8b0000', 'opacity' => 1])->save();
    $this->assertSame((int) $tba->id(), (int) $roadDamage->get('field_category_gid')->target_id);
    $this->assertSame((int) $swe->id(), (int) $streetlight->get('field_category_gid')->target_id);
    $this->assertSame((int) $greenParent->id(), (int) $tree->get('parent')->target_id);
    $this->assertContains(
      (int) $roadDamage->id(),
      array_map('intval', array_column($tba->get('field_service_categories')->getValue(), 'target_id')),
    );
    $definition = json_decode(
      (string) $streetlight->get('field_service_definition')->value,
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $this->assertSame(TRUE, $definition['attributes'][0]['variable']);
    $this->assertSame(0, $definition['attributes'][0]['order']);
    $this->assertSame([], $definition['attributes'][0]['values']);

    $initial = $this->loadStatus('Erfasst');
    $closed = $this->loadStatus('Erledigt');
    $archived = $this->loadStatus('Archiviert');
    $this->assertSame('initial', $initial->get('field_open311_mapping')->value);
    $this->assertSame('status_open', $initial->get('field_notification_key')->value);
    $this->assertSame('status_closed', $closed->get('field_notification_key')->value);
    $this->assertTrue($archived->get('field_notification_key')->isEmpty());

    $userStorage = $this->container->get('entity_type.manager')->getStorage('user');
    $userIds = $userStorage->getQuery()->accessCheck(FALSE)->condition('uid', 1, '>')->execute();
    $this->assertCount(4, $userIds);
    $tenantAdmin = $this->loadUser('erika.muster@erfurt.de');
    $moderator = $this->loadUser('max.beispiel@erfurt.de');
    $editorial = $this->loadUser('presse@erfurt.de');
    $orgMember = $this->loadUser('lampen@stadtwerke-erfurt.de');
    $this->assertSame(
      ['jur-member', 'jur-tenant_admin'],
      $this->storedMembershipRoles($jurisdiction, $tenantAdmin),
    );
    $this->assertSame(['jur-moderator'], $this->storedMembershipRoles($jurisdiction, $moderator));
    $this->assertSame(['jur-editorial'], $this->storedMembershipRoles($jurisdiction, $editorial));
    $this->assertSame(['jur-org_member'], $this->storedMembershipRoles($jurisdiction, $orgMember));
    $this->assertTrue($tenantAdmin->hasRole('tenant_admin'));
    $this->assertFalse($moderator->hasRole('moderator'));
    $this->assertFalse($editorial->hasRole('editorial_board'));
    $orgMembership = GroupMembership::loadSingle($swe, $orgMember);
    $this->assertInstanceOf(GroupMembership::class, $orgMembership);
    $this->assertArrayHasKey('org-member', $orgMembership->getRoles());

    $second = $this->importer->import(
      $configuration,
      (int) $this->jurisdiction->id(),
      [],
      TRUE,
    );
    $this->assertSame([], $second['errors']);
    $this->assertNotEmpty($second['rows']);
    $this->assertSame(
      ['skip', 'unchanged'],
      array_values(array_unique(array_column($second['rows'], 'action'))),
      (string) json_encode($second['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );
  }

  /**
   * Rejects a status set without exactly one initial item before writing.
   */
  public function testMissingInitialStatusIsRejected(): void {
    $configuration = $this->exampleConfiguration();
    foreach ($configuration['statuses'] as &$status) {
      if ($status['kind'] === 'initial') {
        $status['kind'] = 'open';
      }
    }
    unset($status);

    try {
      $this->importer->import(
        $configuration,
        (int) $this->jurisdiction->id(),
      );
      $this->fail('Missing initial status should fail validation.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertContains(
        'statuses must contain exactly one item with kind "initial"; found 0.',
        $exception->getErrors(),
      );
    }

    $organisationIds = $this->container->get('entity_type.manager')
      ->getStorage('group')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->execute();
    $this->assertSame([], array_values($organisationIds));
  }

  /**
   * Rejects a separately constructed missing parent before creating entities.
   */
  public function testMissingParentIsRejected(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['categories'] = array_values(array_filter($configuration['categories'], static fn(array $row): bool => $row['code'] !== '3'));
    try {
      $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
      $this->fail('Missing category parent must fail.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('unknown category "3"', implode(' ', $exception->getErrors()));
    }
    $this->assertSame([], Term::loadMultiple());
  }

  /**
   * Preserves existing identity, blocked accounts, and additive memberships.
   */
  public function testExistingAccountProtection(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][1]];
    $row = $configuration['users'][0];
    $account = User::create(['name' => 'Existing login', 'mail' => strtoupper($row['email']), 'status' => 0]);
    $account->save();
    $id = (int) $this->jurisdiction->id();
    $skip = ['organisations', 'categories', 'statuses'];
    $result = $this->importer->import($configuration, $id, $skip, TRUE, FALSE, TRUE);
    $this->assertSame([], $result['errors']);
    $this->assertStringContainsString('blocked, membership skipped', json_encode($result['rows']));
    $this->assertFalse(GroupMembership::loadSingle($this->jurisdiction, $account));
    $account = User::load($account->id());
    $this->assertTrue($account->isBlocked());
    $this->assertSame('Existing login', $account->getAccountName());
    $account->activate()->save();
    $this->jurisdiction->addRelationship($account, 'group_membership', ['group_roles' => ['jur-editorial']]);
    $result = $this->importer->import($configuration, $id, $skip, TRUE);
    $this->assertSame([], $result['errors']);
    $account = User::load($account->id());
    $this->assertSame('Existing login', $account->getAccountName());
    $this->assertSame(strtoupper($row['email']), $account->getEmail());
    $this->assertSame($row['first_name'], $account->get('field_first_name')->getString());
    $this->assertSame($row['last_name'], $account->get('field_last_name')->getString());
    $this->assertEqualsCanonicalizing(['jur-editorial', 'jur-moderator'], $this->storedMembershipRoles($this->jurisdiction, $account));
    $this->assertStringNotContainsString($row['first_name'], json_encode($result['rows']));
    $this->assertStringNotContainsString($row['last_name'], json_encode($result['rows']));
    $configuration['users'][0]['first_name'] = 'Replacement';
    $configuration['users'][0]['last_name'] = 'Name';
    $again = $this->importer->import($configuration, $id, $skip, TRUE);
    $userRows = array_values(array_filter($again['rows'], static fn(array $row): bool => $row['entity'] === 'user'));
    $this->assertSame('unchanged', $userRows[0]['action']);
    $account = User::load($account->id());
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->assertSame($row['first_name'], $account->get('field_first_name')->getString());
    $this->assertSame($row['last_name'], $account->get('field_last_name')->getString());
  }

  /**
   * Requires explicit override for privileged and cross-root existing users.
   */
  public function testPrivilegedAndCrossTenantGuards(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][1]];
    $id = (int) $this->jurisdiction->id();
    $skip = ['organisations', 'categories', 'statuses'];
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();
    $root = User::load(1);
    $root->setEmail('root@example.com')->save();
    $admin = User::create([
      'name' => 'Admin', 'mail' => 'admin@example.com',
      'status' => 1, 'roles' => ['administrator'],
    ]);
    $admin->save();
    $external = User::create(['name' => 'Other tenant', 'mail' => 'other@example.com', 'status' => 1]);
    $external->save();
    $other = Group::create(['type' => 'jur', 'label' => 'Other root']);
    $other->save();
    $other->addRelationship($external, 'group_membership')->save();
    foreach ([$root, $admin, $external] as $account) {
      $configuration['users'][0]['email'] = $account->getEmail();
      try {
        $this->importer->import($configuration, $id, $skip, TRUE);
        $this->fail('Sensitive account must require explicit override.');
      }
      catch (TenantImportValidationException $exception) {
        $this->assertStringContainsString('--allow-cross-tenant-users', implode(' ', $exception->getErrors()));
      }
      $this->assertFalse(GroupMembership::loadSingle($this->jurisdiction, $account));
      $result = $this->importer->import($configuration, $id, $skip, TRUE, FALSE, TRUE);
      $this->assertSame([], $result['errors']);
      $this->assertInstanceOf(GroupMembership::class, GroupMembership::loadSingle($this->jurisdiction, $account));
      $this->assertSame($account->getAccountName(), User::load($account->id())->getAccountName());
    }
    $this->assertStringContainsString('member of 1 other jurisdictions', json_encode($result['rows']));
  }

  /**
   * Preserves inheritance, inactive categories, and skipped sections.
   */
  public function testInactiveSkippedAndInheritedSelections(): void {
    $configuration = $this->exampleConfiguration();
    $id = (int) $this->jurisdiction->id();
    $this->assertSame([], $this->importer->import($configuration, $id, [], TRUE)['errors']);

    $jurisdiction = Group::load($id);
    $jurisdiction->set('field_service_categories', []);
    $jurisdiction->set('field_service_statuses', []);
    $jurisdiction->save();
    $result = $this->importer->import($configuration, $id, [], TRUE);
    $this->assertSame(['skip', 'unchanged'], array_values(array_unique(array_column($result['rows'], 'action'))));
    $jurisdiction = Group::load($id);
    $this->assertTrue($jurisdiction->get('field_service_categories')->isEmpty());
    $this->assertTrue($jurisdiction->get('field_service_statuses')->isEmpty());

    $configuration['categories'][2]['active'] = FALSE;
    $result = $this->importer->import($configuration, $id, [], TRUE);
    $this->assertSame([], $result['errors']);
    $term = $this->loadCategory('1.4');
    $this->assertNotContains((int) $term->id(), array_map('intval', array_column(Group::load($id)->get('field_service_categories')->getValue(), 'target_id')));
    $this->assertTrue($this->loadOrganisation('SWE')->get('field_service_categories')->isEmpty());
    $this->assertSame(['skip', 'unchanged'], array_values(array_unique(array_column($this->importer->import($configuration, $id)['rows'], 'action'))));

    $configuration['organisations'][0]['name'] = 'Skipped change';
    $configuration['categories'][1]['name'] = 'Updated category';
    $result = $this->importer->import($configuration, $id, ['organisations', 'users'], TRUE);
    $this->assertSame([], $result['errors']);
    $this->assertNotSame('Skipped change', $this->loadOrganisation('TBA')->label());
    $this->assertSame('Updated category', $this->loadCategory('1.1')->label());
  }

  /**
   * Rejects malformed input and scope conflicts.
   */
  public function testValidationAndScopeGuards(): void {
    $configuration = $this->exampleConfiguration();
    $invalid = $configuration;
    $invalid['users'][0]['role'] = ['invalid'];
    $invalid['organisations'][0]['code'] = ['invalid'];
    $errors = implode(' ', $this->importer->validate($invalid));
    $this->assertStringContainsString('users[0].role must be a string', $errors);
    $this->assertStringContainsString('organisations[0].code must be a string', $errors);
    $this->assertStringNotContainsString('cycle', $errors);
    $invalid['version'] = 2;
    $invalid['statuses'] = [];
    $invalid['categories'][0]['name'] = str_repeat('ü', 256);
    $invalid['categories'][0]['description'] = str_repeat('ü', 256);
    $invalid['categories'][0]['code'] = str_repeat('x', 33);
    $invalid['users'][1]['email'] = str_repeat('x', 250) . '@example.com';
    $errors = implode(' ', $this->importer->validate($invalid));
    $this->assertStringContainsString('version must be', $errors);
    $this->assertStringContainsString('exactly one item', $errors);
    $this->assertStringContainsString('.name must not exceed 255', $errors);
    $this->assertStringContainsString('.description must not exceed 255', $errors);
    $this->assertStringContainsString('.code must not exceed 32', $errors);
    $this->assertStringContainsString('.email must not exceed 254', $errors);
    $invalid = $configuration;
    $invalid['categories'][0]['attributes'] = [[
      'code' => 'broken',
      'datatype' => 'string',
      'description' => 'Broken nested properties',
      'conditions' => ['show_when' => 'not a list'],
      'validation' => ['min' => 'not a number'],
      'default_value' => [],
    ]];
    $this->assertSame([], $this->importer->validate($invalid));
    $mapped = (new TenantImportFieldMapper())->serviceDefinitionJson($invalid['categories'][0]);
    $this->assertStringNotContainsString('conditions', $mapped);
    $this->assertStringNotContainsString('validation', $mapped);
    $this->assertStringNotContainsString('default_value', $mapped);

    $child = Group::create([
      'type' => 'jur',
      'label' => 'Child',
      'field_parent_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $child->save();
    try {
      $this->importer->import($configuration, (int) $child->id(), [], TRUE);
      $this->fail('Child import must reject implicit root writes.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('root-owned', $exception->getMessage());
    }

    Term::create([
      'vid' => 'service_status',
      'name' => 'Existing initial',
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_open311_mapping' => 'initial',
    ])->save();
    try {
      $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
      $this->fail('An omitted existing initial must be rejected.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('Existing initial status', $exception->getMessage());
    }
    $this->assertSame([], Group::loadMultiple($this->container->get('entity_type.manager')->getStorage('group')->getQuery()->accessCheck(FALSE)->condition('type', 'org')->execute()));
  }

  /**
   * Repairs cross-root references even when the referenced codes are equal.
   */
  public function testReferencesUseEntityIds(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['organisations'][2]['parent_code'] = 'TBA';
    $id = (int) $this->jurisdiction->id();
    $this->assertSame([], $this->importer->import($configuration, $id, [], TRUE)['errors']);
    $other = Group::create(['type' => 'jur', 'label' => 'Other root']);
    $other->save();
    $foreignOrg = Group::create([
      'type' => 'org', 'label' => 'Other TBA',
      'field_org_code' => 'TBA', 'field_jurisdiction' => $other->id(),
    ]);
    $foreignOrg->save();
    $foreignParent = Term::create([
      'vid' => 'service_category', 'name' => 'Other streets',
      'field_service_code' => '1', 'field_jurisdiction' => $other->id(),
    ]);
    $foreignParent->save();
    $road = $this->loadCategory('1.1');
    $road->set('field_category_gid', $foreignOrg->id())->set('parent', $foreignParent->id())->save();
    $swe = $this->loadOrganisation('SWE');
    // ProtectedGroup rejects persisting this; check the plan on cached data.
    $swe->set('field_parent_org', $foreignOrg->id());
    $plan = $this->importer->import($configuration, $id);
    $this->assertStringContainsString('field_category_gid', json_encode($plan['rows']));
    $this->assertStringContainsString('field_parent_org', json_encode($plan['rows']));
    $this->assertSame([], $this->importer->import($configuration, $id, [], TRUE)['errors']);
    $this->assertSame((int) $this->loadOrganisation('TBA')->id(), (int) $this->loadCategory('1.1')->get('field_category_gid')->target_id);
    $this->assertSame((int) $this->loadCategory('1')->id(), (int) $this->loadCategory('1.1')->get('parent')->target_id);
    $this->assertSame((int) $this->loadOrganisation('TBA')->id(), (int) $this->loadOrganisation('SWE')->get('field_parent_org')->target_id);
    $this->assertSame(['skip', 'unchanged'], array_values(array_unique(array_column($this->importer->import($configuration, $id)['rows'], 'action'))));
  }

  /**
   * Merges imported attributes without deleting UI-maintained definition data.
   */
  public function testServiceDefinitionsMergeOnReimport(): void {
    $configuration = $this->exampleConfiguration();
    $id = (int) $this->jurisdiction->id();
    $this->assertSame([], $this->importer->import($configuration, $id, [], TRUE)['errors']);

    $streetlight = $this->loadCategory('1.4');
    $definition = json_decode(
      (string) $streetlight->get('field_service_definition')->value,
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $definition['attributes'][0]['description'] = 'Changed in the UI';
    $definition['attributes'][0]['media_group'] = 'streetlights';
    $definition['attributes'][0]['ui_only'] = 'keep';
    $definition['attributes'][] = [
      'code' => 'ui_attribute',
      'datatype' => 'string',
      'description' => 'Maintained only in Drupal',
      'custom_setting' => TRUE,
    ];
    $streetlight->set('field_service_definition', [
      'value' => json_encode($definition, JSON_THROW_ON_ERROR),
      'format' => 'plain_text',
    ])->save();

    $road = $this->loadCategory('1.1');
    $roadDefinition = '{"attributes":[{"code":"ui_only","datatype":"string","media_group":"roads"}]}';
    $road->set('field_service_definition', [
      'value' => $roadDefinition,
      'format' => 'plain_text',
    ])->save();
    $configuration['categories'][2]['attributes'][0]['description'] = 'Imported description wins';

    $result = $this->importer->import($configuration, $id, [], TRUE);
    $this->assertSame([], $result['errors']);
    $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->resetCache();
    $definition = json_decode(
      (string) $this->loadCategory('1.4')->get('field_service_definition')->value,
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $this->assertSame('Imported description wins', $definition['attributes'][0]['description']);
    $this->assertSame('streetlights', $definition['attributes'][0]['media_group']);
    $this->assertSame('keep', $definition['attributes'][0]['ui_only']);
    $this->assertSame('ui_attribute', $definition['attributes'][1]['code']);
    $this->assertTrue($definition['attributes'][1]['custom_setting']);
    $this->assertSame(
      $roadDefinition,
      (string) $this->loadCategory('1.1')->get('field_service_definition')->value,
    );
  }

  /**
   * Rejects a foreign tenant slug unless the explicit override is provided.
   */
  public function testSlugMismatchRequiresExplicitOverride(): void {
    $configuration = $this->exampleConfiguration();
    $this->jurisdiction->set('field_slug', 'another-tenant')->save();
    $id = (int) $this->jurisdiction->id();

    try {
      $this->importer->import($configuration, $id);
      $this->fail('A slug mismatch must fail by default.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString(
        '--allow-slug-mismatch',
        implode(' ', $exception->getErrors()),
      );
    }
    $result = $this->importer->import(
      $configuration,
      $id,
      [],
      TRUE,
      FALSE,
      FALSE,
      TRUE,
    );
    $this->assertSame([], $result['errors']);
    $this->assertSame(
      'another-tenant',
      Group::load($id)->get('field_slug')->getString(),
    );
    $this->assertStringContainsString(
      'slug mismatch explicitly allowed',
      json_encode($result['rows'], JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Rejects usernames that cannot be created and validates users before save.
   */
  public function testNewUserPreflightAndEntityValidation(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][1]];
    $skip = ['organisations', 'categories', 'statuses'];
    $id = (int) $this->jurisdiction->id();

    $configuration['users'][0]['email'] = str_repeat('a', 50) . '@example.com';
    try {
      $this->importer->import($configuration, $id, $skip);
      $this->fail('An overlong new username must fail preflight.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString(
        'users[0].email exceeds installed username maximum of 60',
        implode(' ', $exception->getErrors()),
      );
    }

    $configuration['users'][0]['email'] = 'new@example.com';
    User::create([
      'name' => 'new@example.com',
      'mail' => 'different@example.com',
      'status' => 1,
    ])->save();
    try {
      $this->importer->import($configuration, $id, $skip);
      $this->fail('An existing username must fail preflight.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString(
        'users[0].email cannot be used as a username',
        implode(' ', $exception->getErrors()),
      );
    }

    $configuration['users'][0]['email'] = 'valid-new@example.com';
    $configuration['users'][0]['first_name'] = str_repeat('x', 40);
    $result = $this->importer->import($configuration, $id, $skip, TRUE);
    $this->assertNotEmpty($result['errors']);
    $this->assertStringContainsString(
      'users[0] failed validation',
      implode(' ', $result['errors']),
    );
    $this->assertSame([], $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->loadByProperties(['mail' => 'valid-new@example.com']));
  }

  /**
   * Creates jurisdiction memberships with roles on the first and only save.
   */
  public function testMembershipRolesExistOnInsert(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][1]];
    $this->container->get('state')->delete(
      'markaspot_tenant_import_test.empty_jurisdiction_roles_on_insert',
    );
    $result = $this->importer->import(
      $configuration,
      (int) $this->jurisdiction->id(),
      ['organisations', 'categories', 'statuses'],
      TRUE,
    );
    $this->assertSame([], $result['errors']);
    $this->assertFalse($this->container->get('state')->get(
      'markaspot_tenant_import_test.empty_jurisdiction_roles_on_insert',
      FALSE,
    ));
  }

  /**
   * Protects unexpected-role and all-groups accounts and their profile fields.
   */
  public function testExpandedPrivilegedAccountGuards(): void {
    Role::create(['id' => 'api_editor', 'label' => 'API editor'])->save();
    $accounts = [
      User::create([
        'name' => 'API editor',
        'mail' => 'api-editor@example.com',
        'status' => 1,
        'roles' => ['api_editor'],
        'field_first_name' => 'Protected',
        'field_last_name' => 'API',
      ]),
      User::create([
        'name' => 'All groups',
        'mail' => 'all-groups@example.com',
        'status' => 1,
        'field_all_groups_member' => TRUE,
        'field_first_name' => 'Protected',
        'field_last_name' => 'Groups',
      ]),
    ];
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][1]];
    $skip = ['organisations', 'categories', 'statuses'];
    $id = (int) $this->jurisdiction->id();

    foreach ($accounts as $account) {
      $account->save();
      $configuration['users'][0]['email'] = $account->getEmail();
      try {
        $this->importer->import($configuration, $id, $skip);
        $this->fail('A privileged account must require the override.');
      }
      catch (TenantImportValidationException $exception) {
        $this->assertStringContainsString(
          '--allow-cross-tenant-users',
          implode(' ', $exception->getErrors()),
        );
      }
      $result = $this->importer->import(
        $configuration,
        $id,
        $skip,
        TRUE,
        FALSE,
        TRUE,
      );
      $this->assertSame([], $result['errors']);
      $reloaded = User::load($account->id());
      $this->assertInstanceOf(UserInterface::class, $reloaded);
      $this->assertSame('Protected', $reloaded->get('field_first_name')->getString());
      $this->assertNotSame(
        $configuration['users'][0]['last_name'],
        $reloaded->get('field_last_name')->getString(),
      );
      $this->assertStringContainsString(
        'profile not changed',
        json_encode($result['rows'], JSON_THROW_ON_ERROR),
      );
    }
  }

  /**
   * Reconciles the Drupal tenant_admin role from an existing membership.
   */
  public function testExistingTenantAdminRoleDriftIsReconciled(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][0]];
    $email = $configuration['users'][0]['email'];
    $account = User::create([
      'name' => $email,
      'mail' => $email,
      'status' => 1,
    ]);
    $account->save();
    $this->jurisdiction->addRelationship(
      $account,
      'group_membership',
      ['group_roles' => ['jur-member', 'jur-tenant_admin']],
    );
    $account = User::load($account->id());
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->assertTrue($account->hasRole('tenant_admin'));
    $account->removeRole('tenant_admin')->save();

    $skip = ['organisations', 'categories', 'statuses'];
    $id = (int) $this->jurisdiction->id();
    $plan = $this->importer->import($configuration, $id, $skip);
    $userRows = array_values(array_filter(
      $plan['rows'],
      static fn(array $row): bool => $row['entity'] === 'user',
    ));
    $this->assertSame('update', $userRows[0]['action']);
    $this->assertStringContainsString(
      'Drupal tenant_admin role sync',
      $userRows[0]['reason'],
    );

    $result = $this->importer->import($configuration, $id, $skip, TRUE);
    $this->assertSame([], $result['errors']);
    $account = User::load($account->id());
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->assertTrue($account->hasRole('tenant_admin'));
    $second = $this->importer->import($configuration, $id, $skip);
    $userRows = array_values(array_filter(
      $second['rows'],
      static fn(array $row): bool => $row['entity'] === 'user',
    ));
    $this->assertSame('unchanged', $userRows[0]['action']);
  }

  /**
   * Makes inherited-selection writes visible in the dry-run plan.
   */
  public function testInheritedSelectionFreezeIsPlanned(): void {
    $configuration = $this->exampleConfiguration();
    $id = (int) $this->jurisdiction->id();
    $plan = $this->importer->import($configuration, $id);
    $selectionRows = array_values(array_filter(
      $plan['rows'],
      static fn(array $row): bool => $row['entity'] === 'jurisdiction'
        && in_array($row['key'], [
          'field_service_categories',
          'field_service_statuses',
        ], TRUE),
    ));
    $this->assertCount(2, $selectionRows);
    $this->assertSame(['update', 'update'], array_column($selectionRows, 'action'));
    $this->assertStringContainsString(
      'freezes the inherited category set',
      $selectionRows[0]['reason'],
    );
    $this->assertStringContainsString(
      'freezes the inherited status set',
      $selectionRows[1]['reason'],
    );

    $this->assertSame([], $this->importer->import($configuration, $id, [], TRUE)['errors']);
    $second = $this->importer->import($configuration, $id);
    $this->assertSame([], array_values(array_filter(
      $second['rows'],
      static fn(array $row): bool => $row['entity'] === 'jurisdiction'
        && in_array($row['key'], [
          'field_service_categories',
          'field_service_statuses',
        ], TRUE),
    )));
  }

  /**
   * Lists every unsupported tenant property as an explicit skipped notice.
   */
  public function testUnknownTenantKeysAreReportedAsNotices(): void {
    $result = $this->importer->import(
      $this->exampleConfiguration(),
      (int) $this->jurisdiction->id(),
    );
    $rows = array_values(array_filter(
      $result['rows'],
      static fn(array $row): bool => $row['entity'] === 'tenant',
    ));
    $this->assertSame([
      'tenant.short_name',
      'tenant.languages',
      'tenant.contact',
      'tenant.map_center_address',
      'tenant.primary_color',
      'tenant.logo_file',
      'tenant.custom_domain',
      'tenant.legal_notice_url',
      'tenant.privacy_policy_url',
      'tenant.features',
    ], array_column($rows, 'key'));
    $this->assertSame(array_fill(0, 10, 'skip'), array_column($rows, 'action'));
    foreach ($rows as $row) {
      $this->assertSame(
        $row['key'] . ': not imported by this command',
        $row['reason'],
      );
    }
  }

  /**
   * Uses the production category and organisation code storage limits.
   */
  public function testInstalledCodeLengthLimitsAreEnforced(): void {
    $configuration = $this->exampleConfiguration();
    $organisationCode = str_repeat('o', 17);
    $categoryCode = str_repeat('c', 13);
    $configuration['organisations'][0]['code'] = $organisationCode;
    $configuration['categories'][1]['organisation_code'] = $organisationCode;
    $configuration['categories'][0]['code'] = $categoryCode;
    $configuration['categories'][1]['parent_code'] = $categoryCode;
    $configuration['categories'][2]['parent_code'] = $categoryCode;

    try {
      $this->importer->import(
        $configuration,
        (int) $this->jurisdiction->id(),
      );
      $this->fail('Installed code limits must be checked before writes.');
    }
    catch (TenantImportValidationException $exception) {
      $errors = implode(' ', $exception->getErrors());
      $this->assertStringContainsString(
        'organisations[0].code exceeds installed field maximum of 16',
        $errors,
      );
      $this->assertStringContainsString(
        'categories[0].code exceeds installed field maximum of 12',
        $errors,
      );
    }
  }

  /**
   * Rolls back every entity when one apply-time save fails.
   */
  public function testApplyFailureRollsBackAllCreatedEntities(): void {
    $this->container->get('state')->set(
      'markaspot_tenant_import_test.fail_status',
      'In Bearbeitung',
    );
    $result = $this->importer->import(
      $this->exampleConfiguration(),
      (int) $this->jurisdiction->id(),
      [],
      TRUE,
    );
    $this->container->get('state')->delete(
      'markaspot_tenant_import_test.fail_status',
    );

    $this->assertNotEmpty($result['errors']);
    $this->assertFalse($result['created_terms']);
    $this->assertSame([], array_intersect(
      ['create', 'update', 'error'],
      array_column($result['rows'], 'action'),
    ));
    $rolledBack = array_filter(
      $result['rows'],
      static fn(array $row): bool => str_starts_with(
        $row['reason'],
        'Transaction rolled back after application errors. ',
      ),
    );
    $this->assertNotEmpty($rolledBack);
    $this->assertSame(
      array_fill(0, count($rolledBack), 'skip'),
      array_column($rolledBack, 'action'),
    );

    $entityTypeManager = $this->container->get('entity_type.manager');
    foreach (['group', 'taxonomy_term', 'user', 'group_relationship'] as $type) {
      $entityTypeManager->getStorage($type)->resetCache();
    }
    $organisationIds = $entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->execute();
    $termIds = $entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $userIds = $entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 1, '>')
      ->execute();
    $membershipIds = $entityTypeManager->getStorage('group_relationship')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $this->assertSame([], array_values($organisationIds));
    $this->assertSame([], array_values($termIds));
    $this->assertSame([], array_values($userIds));
    $this->assertSame([], array_values($membershipIds));
  }

  /**
   * Sends one password-reset message per created user and never on re-import.
   */
  public function testPasswordResetMailsAreSentOnlyForCreatedUsers(): void {
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][1]];
    $skip = ['organisations', 'categories', 'statuses'];
    $id = (int) $this->jurisdiction->id();

    try {
      $this->importer->import($configuration, $id, $skip, FALSE, TRUE);
      $this->fail('--send-mails without --apply must fail.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertContains(
        '--send-mails can only be used together with --apply.',
        $exception->getErrors(),
      );
    }

    $this->config('system.site')->set('mail', 'site@example.com')->save();
    $this->container->get('state')->set('system.test_mail_collector', []);
    $first = $this->importer->import(
      $configuration,
      $id,
      $skip,
      TRUE,
      TRUE,
    );
    $this->assertSame([], $first['errors']);
    $messages = $this->container->get('state')->get(
      'system.test_mail_collector',
      [],
    );
    $passwordReset = array_values(array_filter(
      $messages,
      static fn(array $message): bool => $message['module'] === 'user'
        && $message['key'] === 'password_reset',
    ));
    $this->assertCount(1, $passwordReset);
    $this->assertSame(
      $configuration['users'][0]['email'],
      $passwordReset[0]['to'],
    );
    $this->assertStringContainsString(
      'Password-reset mail sent.',
      json_encode($first['rows'], JSON_THROW_ON_ERROR),
    );

    $second = $this->importer->import(
      $configuration,
      $id,
      $skip,
      TRUE,
      TRUE,
    );
    $this->assertSame([], $second['errors']);
    $this->assertCount(1, $this->container->get('state')->get(
      'system.test_mail_collector',
      [],
    ));
  }

  /**
   * Serializes applies, leaves dry-runs unlocked, and releases failures.
   */
  public function testImportLockingAndRelease(): void {
    $configuration = $this->exampleConfiguration();
    $lockName = 'markaspot_tenant_import.tenant_import';
    $database = $this->container->get('database');
    $competingLock = new DatabaseLockBackend($database);
    $this->assertTrue($competingLock->acquire($lockName, 3600.0));
    try {
      $this->importer->import(
        $configuration,
        (int) $this->jurisdiction->id(),
        [],
        TRUE,
      );
      $this->fail('A concurrent apply must fail.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertContains(
        'Another tenant import is already running.',
        $exception->getErrors(),
      );
    }
    $dryRun = $this->importer->import(
      $configuration,
      (int) $this->jurisdiction->id(),
    );
    $this->assertSame([], $dryRun['errors']);
    $competingLock->release($lockName);

    try {
      $this->importer->import($configuration, 999999, [], TRUE);
      $this->fail('A missing jurisdiction must fail during context preparation.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString(
        'does not exist',
        $exception->getMessage(),
      );
    }
    $afterFailure = new DatabaseLockBackend($database);
    $this->assertTrue($afterFailure->acquire($lockName, 1.0));
    $afterFailure->release($lockName);
  }

  /**
   * Validates file existence, size, JSON shape, and successful decoding.
   */
  public function testDecodeFileValidationAndHappyPath(): void {
    $missing = sys_get_temp_dir() . '/missing-tenant-config-' . uniqid() . '.json';
    try {
      $this->importer->decodeFile($missing);
      $this->fail('A missing file must fail.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString(
        'does not exist or is not readable',
        $exception->getMessage(),
      );
    }

    $path = tempnam(sys_get_temp_dir(), 'tenant-import-');
    $this->assertNotFalse($path);
    $handle = fopen($path, 'wb');
    $this->assertIsResource($handle);
    $this->assertTrue(ftruncate($handle, (10 * 1024 * 1024) + 1));
    fclose($handle);
    clearstatcache(TRUE, $path);
    try {
      $this->importer->decodeFile($path);
      $this->fail('A file over 10 MiB must fail.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('10 MiB size limit', $exception->getMessage());
    }

    file_put_contents($path, '{');
    clearstatcache(TRUE, $path);
    try {
      $this->importer->decodeFile($path);
      $this->fail('Invalid JSON must fail.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('not valid JSON', $exception->getMessage());
    }

    file_put_contents($path, '[]');
    try {
      $this->importer->decodeFile($path);
      $this->fail('A list root must fail.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString(
        'root must be a JSON object',
        $exception->getMessage(),
      );
    }
    unlink($path);

    $fixture = dirname(__DIR__, 2) . '/fixtures/tenant-config.example.json';
    $this->assertSame(
      $this->exampleConfiguration(),
      $this->importer->decodeFile($fixture),
    );
  }

  /**
   * Updates status mappings and presentation while preserving definitions.
   */
  public function testStatusUpdatesPreserveDefinition(): void {
    $configuration = $this->exampleConfiguration();
    $id = (int) $this->jurisdiction->id();
    $this->assertSame([], $this->importer->import($configuration, $id, [], TRUE)['errors']);

    $definition = '{"attributes":[{"code":"internal_note","ui_only":true}]}';
    $status = $this->loadStatus('In Bearbeitung');
    $status->set('field_status_definition', [
      'value' => $definition,
      'format' => 'plain_text',
    ])->save();
    $configuration['statuses'][0]['kind'] = 'open';
    $configuration['statuses'][1]['kind'] = 'initial';
    $configuration['statuses'][1]['hex'] = '#123456';
    $configuration['statuses'][1]['icon'] = 'i-lucide-hammer';
    $configuration['statuses'][1]['weight'] = 9;
    $configuration['statuses'][1]['description'] = 'Updated status description.';
    $configuration['statuses'][1]['notify_citizen'] = FALSE;

    $result = $this->importer->import($configuration, $id, [], TRUE);
    $this->assertSame([], $result['errors']);
    $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->resetCache();
    $status = $this->loadStatus('In Bearbeitung');
    $this->assertSame('#123456', strtoupper((string) $status->get('field_status_hex')->color));
    $this->assertSame('i-lucide-hammer', $status->get('field_status_icon')->getString());
    $this->assertSame(9, (int) $status->getWeight());
    $this->assertSame('Updated status description.', $status->getDescription());
    $this->assertSame('initial', $status->get('field_open311_mapping')->getString());
    $this->assertTrue($status->get('field_notification_key')->isEmpty());
    $this->assertSame($definition, (string) $status->get('field_status_definition')->value);
    $this->assertSame('open', $this->loadStatus('Erfasst')->get('field_open311_mapping')->getString());

    $initialStatuses = array_filter(
      Term::loadMultiple(),
      static fn(TermInterface $term): bool => $term->bundle() === 'service_status'
        && $term->get('field_open311_mapping')->getString() === 'initial',
    );
    $this->assertCount(1, $initialStatuses);
    $this->assertSame(
      ['skip', 'unchanged'],
      array_values(array_unique(array_column(
        $this->importer->import($configuration, $id)['rows'],
        'action',
      ))),
    );
  }

  /**
   * Allows child-only tenant fields and users while rejecting shared sections.
   */
  public function testChildJurisdictionScopePaths(): void {
    $configuration = $this->exampleConfiguration();
    $child = Group::create([
      'type' => 'jur',
      'label' => 'Child',
      'field_slug' => 'erfurt',
      'field_parent_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $child->save();
    try {
      $this->importer->import($configuration, (int) $child->id(), [], TRUE);
      $this->fail('A child must reject root-owned sections.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('root-owned', $exception->getMessage());
    }

    $configuration['users'] = [$configuration['users'][1]];
    $configuration['tenant']['platform_name'] = 'Child platform';
    $result = $this->importer->import(
      $configuration,
      (int) $child->id(),
      ['organisations', 'categories', 'statuses'],
      TRUE,
    );
    $this->assertSame([], $result['errors']);
    $child = Group::load($child->id());
    $this->assertInstanceOf(GroupInterface::class, $child);
    $this->assertSame('Child platform', $child->get('field_platform_name')->getString());
    $moderator = $this->loadUser($configuration['users'][0]['email']);
    $this->assertSame(['jur-moderator'], $this->storedMembershipRoles($child, $moderator));
    $this->assertSame([], array_values($this->container->get('entity_type.manager')
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute()));
    $this->assertCount(2, Group::loadMultiple());
  }

  /**
   * Does not treat a sibling child in the same root tree as cross-tenant.
   */
  public function testSiblingChildMembershipIsNotCrossTenant(): void {
    $siblings = [];
    foreach (['First child', 'Second child'] as $label) {
      $sibling = Group::create([
        'type' => 'jur',
        'label' => $label,
        'field_slug' => 'erfurt',
        'field_parent_jurisdiction' => $this->jurisdiction->id(),
      ]);
      $sibling->save();
      $siblings[] = $sibling;
    }
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [$configuration['users'][1]];
    $account = User::create([
      'name' => 'Sibling member',
      'mail' => $configuration['users'][0]['email'],
      'status' => 1,
    ]);
    $account->save();
    $siblings[0]->addRelationship(
      $account,
      'group_membership',
      ['group_roles' => ['jur-moderator']],
    );

    $result = $this->importer->import(
      $configuration,
      (int) $siblings[1]->id(),
      ['organisations', 'categories', 'statuses'],
      TRUE,
    );
    $this->assertSame([], $result['errors']);
    $this->assertInstanceOf(
      GroupMembership::class,
      GroupMembership::loadSingle($siblings[1], $account),
    );
    $this->assertStringNotContainsString(
      'profile not changed',
      json_encode($result['rows'], JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Creates the fields used by the importer on a reduced kernel install.
   */
  private function createModelFields(): void {
    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    $this->createField('user', 'user', 'field_first_name', 'string', ['max_length' => 32]);
    $this->createField('user', 'user', 'field_last_name', 'string', ['max_length' => 32]);

    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->createField('group', 'org', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->createField('group', 'jur', 'field_service_statuses', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->createField('group', 'jur', 'field_platform_name', 'string');
    $this->createField('group', 'jur', 'field_jurisdiction_e_mail', 'email', [], -1);
    $this->createField('group', 'jur', 'field_jurisdiction_address', 'address');
    $this->createField('group', 'jur', 'field_legal_notice', 'text_long');
    $this->createField('group', 'jur', 'field_privacy_policy', 'text_long');

    $this->createField('group', 'org', 'field_org_code', 'string', ['max_length' => 16]);
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'org', 'field_parent_org', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'org', 'field_head_organisation_e_mail', 'email');

    foreach (['service_category', 'service_status'] as $bundle) {
      $this->createField('taxonomy_term', $bundle, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_category', 'field_service_code', 'string', ['max_length' => 12]);
    $this->createField('taxonomy_term', 'service_category', 'field_category_gid', 'entity_reference', ['target_type' => 'group']);
    $this->createField('taxonomy_term', 'service_category', 'field_category_hex', 'color_field_type');
    $this->createField('taxonomy_term', 'service_category', 'field_category_icon', 'string');
    $this->createField('taxonomy_term', 'service_category', 'field_service_definition', 'text_long');
    $this->createField('taxonomy_term', 'service_status', 'field_status_hex', 'color_field_type');
    $this->createField('taxonomy_term', 'service_status', 'field_status_icon', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'list_string', [
      'allowed_values' => [
        'open' => 'Open',
        'closed' => 'Closed',
        'initial' => 'Initial',
      ],
    ]);
    $this->createField('taxonomy_term', 'service_status', 'field_notification_key', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_status_definition', 'text_long');
  }

  /**
   * Creates shared field storage and one bundle field.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $bundle
   *   Bundle ID.
   * @param string $fieldName
   *   Field name.
   * @param string $type
   *   Field type plugin ID.
   * @param array<string, mixed> $settings
   *   Field storage settings.
   * @param int $cardinality
   *   Field cardinality.
   */
  private function createField(
    string $entityType,
    string $bundle,
    string $fieldName,
    string $type,
    array $settings = [],
    int $cardinality = 1,
  ): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
    ])->save();
  }

  /**
   * Loads the unmodified current v1 example.
   *
   * @return array<string, mixed>
   *   Valid configuration based on the full example fixture.
   */
  private function exampleConfiguration(): array {
    $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/tenant-config.example.json');
    $this->assertNotFalse($contents);
    $configuration = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($configuration);
    return $configuration;
  }

  /**
   * Loads an imported organisation by code.
   */
  private function loadOrganisation(string $code): GroupInterface {
    $groups = Group::loadMultiple();
    foreach ($groups as $group) {
      if ($group->bundle() === 'org' && $group->get('field_org_code')->getString() === $code) {
        return $group;
      }
    }
    throw new \RuntimeException(sprintf('Organisation %s not found.', $code));
  }

  /**
   * Loads an imported category by service code.
   */
  private function loadCategory(string $code): TermInterface {
    $terms = Term::loadMultiple();
    foreach ($terms as $term) {
      if ($term->bundle() === 'service_category' && $term->get('field_service_code')->getString() === $code) {
        return $term;
      }
    }
    throw new \RuntimeException(sprintf('Category %s not found.', $code));
  }

  /**
   * Loads an imported status by name.
   */
  private function loadStatus(string $name): TermInterface {
    $terms = Term::loadMultiple();
    foreach ($terms as $term) {
      if ($term->bundle() === 'service_status' && $term->label() === $name) {
        return $term;
      }
    }
    throw new \RuntimeException(sprintf('Status %s not found.', $name));
  }

  /**
   * Loads an imported user by mail.
   */
  private function loadUser(string $email): UserInterface {
    $users = User::loadMultiple();
    foreach ($users as $user) {
      if ($user->getEmail() === $email) {
        return $user;
      }
    }
    throw new \RuntimeException(sprintf('User %s not found.', $email));
  }

  /**
   * Returns sorted explicitly stored membership role IDs.
   *
   * @return string[]
   *   Stored individual role IDs.
   */
  private function storedMembershipRoles(GroupInterface $group, UserInterface $user): array {
    $membership = GroupMembership::loadSingle($group, $user);
    $this->assertInstanceOf(GroupMembership::class, $membership);
    $roles = array_column(
      $membership->get('group_roles')->getValue(),
      'target_id',
    );
    sort($roles);
    return $roles;
  }

}
