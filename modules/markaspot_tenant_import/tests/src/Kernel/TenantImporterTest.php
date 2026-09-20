<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Kernel;

use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\markaspot_tenant_import\Service\TenantSetup;
use Drupal\markaspot_tenant_import\Service\TenantSetupSchema;
use Consolidation\OutputFormatters\FormatterManager;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Site\Settings;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_tenant_import\Exception\TenantImportValidationException;
use Drupal\markaspot_tenant_import\Drush\Commands\TenantBootstrapCommands;
use Drupal\markaspot_tenant_import\Drush\Commands\TenantSetupCommands;
use Drupal\node\Entity\NodeType;
use Drush\Attributes\DefaultTableFields;
use Drush\Config\DrushConfig;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Drupal\markaspot_tenant_import\Service\TenantImportFieldMapper;
use Drupal\markaspot_tenant_import\Service\TenantImporter;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\user\PermissionHandlerInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests tenant configuration planning, application, and validation.
 *
 * @group markaspot_tenant_import
 */
#[RunTestsInSeparateProcesses]
final class TenantImporterTest extends KernelTestBase {

  /**
   * Nuxt is omitted from this fixture with its JSON:API service dependencies.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = ['markaspot_nuxt.settings'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'language',
    'services_api_key_auth',
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
    'node',
    'markaspot_validation',
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
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
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
    Role::create(['id' => 'contractor', 'label' => 'Contractor'])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    foreach ([
      'jur-member' => ['jur', 'individual', NULL],
      'jur-tenant_admin' => ['jur', 'individual', NULL],
      'jur-moderator' => ['jur', 'individual', NULL],
      'jur-editorial' => ['jur', 'individual', NULL],
      'jur-org_member' => ['jur', 'individual', NULL],
      'org-member' => ['org', 'insider', 'authenticated'],
      'org-contractor' => ['org', 'insider', 'contractor'],
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
   * The public setup service repairs real page config and preserves repeats.
   */
  public function testSetupRepairsPageConfigurationWithRealInstaller(): void {
    $this->enableModules(['node', 'gnode']);
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_jurisdiction',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    $config = $this->container->get('config.factory');
    $config->getEditable('core.extension')->set('profile', 'markaspot')->save();
    $uuid = $this->container->get('uuid')->generate();
    $config->getEditable('system.site')->set('uuid', $uuid)->save();
    $this->container->get('state')->set('markaspot_cloud.permissions_initialized', $uuid);
    // This fixture deliberately installs only page prerequisites. Keep its
    // real ConfigInstaller proof separate from the complete reporting schema.
    $schema = $this->createMock(TenantSetupSchema::class);
    $schema->method('prepare')->willReturn([
      'required' => [],
      'applicable' => [],
      'missing' => [],
      'created' => [],
      'unavailable_optional' => [],
    ]);
    $service = $this->getMockBuilder(TenantSetup::class)
      ->setConstructorArgs([
        $config,
        $this->container->get('state'),
        $this->container->get('entity_type.manager'),
        $this->container->get('entity_field.manager'),
        $this->container->get('lock'),
        $this->container->get('extension.list.module'),
        $this->container->get('extension.list.profile'),
        $this->container->get('config.installer'),
        $this->container->get('config.storage'),
        $this->container->get('user.permissions'),
        $this->container->getParameter('app.root'),
        $this->container->get('kernel'),
      ])
      ->onlyMethods(['schema'])
      ->getMock();
    $service->method('schema')->willReturn($schema);
    $this->container->set('markaspot_tenant_import.tenant_setup', $service);
    $preview = $service->prepare($uuid);
    $this->assertFalse($preview['applied']);
    $this->assertCount(2, $preview['page_configuration_missing']);
    $this->assertNull(FieldConfig::load('node.page.field_jurisdiction'));
    $result = $service->prepare($uuid, FALSE, TRUE);
    $this->assertCount(2, $result['page_configuration_created']);
    $field = FieldConfig::load('node.page.field_jurisdiction');
    $this->assertNotNull($field);
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('group_relationship_type')->load('jur-group_node-page'));
    $field->setLabel('Locally customized')->save();
    $repeat = $service->prepare($uuid, FALSE, TRUE);
    $this->assertSame([], $repeat['page_configuration_created']);
    $this->assertSame('Locally customized', FieldConfig::load('node.page.field_jurisdiction')->label());
    $command = TenantSetupCommands::create($this->container);
    $output = new BufferedOutput();
    $formatter = new FormatterManager();
    $formatter->addDefaultFormatters();
    $formatter->write($output, 'json', $command->status(), new FormatterOptions());
    $decoded = json_decode($output->fetch(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertCount(1, $decoded);
    $this->assertSame(1, $decoded[0]['contract_version']);
    $this->assertSame($uuid, $decoded[0]['site_uuid']);
  }

  /**
   * Canonical remark schema installs with no escalation or SaaS module enabled.
   */
  public function testSetupRepairsCanonicalRemarkSchemaWithRealInstaller(): void {
    $this->enableModules(['node', 'entity_reference_revisions', 'paragraphs', 'field_permissions']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    NodeType::create(['type' => 'service_request', 'name' => 'Request'])->save();
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $profile = $this->container->getParameter('app.root') . '/' . $this->container->get('extension.list.profile')->getPath('markaspot');
    $source = new MemoryStorage();
    foreach ([
      'config/install' => 'field.storage.node.field_internal_remark',
      'config/optional' => 'field.field.node.service_request.field_internal_remark',
    ] as $directory => $name) {
      $data = (new FileStorage($profile . '/' . $directory))->read($name);
      $this->assertIsArray($data);
      $source->write($name, $data);
    }
    $schema = new TenantSetupSchema(
      $this->container->get('config.storage'),
      $this->container->get('config.installer'),
      [
        [
          'storage' => new FileStorage($profile . '/modules/markaspot_status_paragraph/config/install'),
          'required' => TRUE,
        ],
        ['storage' => $source, 'required' => TRUE],
      ],
      array_keys($this->container->get('module_handler')->getModuleList()),
    );
    $this->assertNotEmpty($schema->prepare()['missing']);
    $this->assertNull(FieldConfig::load('node.service_request.field_internal_remark'));
    $this->assertNotEmpty($schema->prepare(TRUE)['created']);
    $this->assertNotNull(FieldConfig::load('node.service_request.field_internal_remark'));
    $this->assertNotNull(FieldConfig::load('paragraph.status.field_author'));
    $remark = Paragraph::create([
      'type' => 'internal_remark',
      'field_internal_remark_text' => ['value' => 'Synthetic internal remark', 'format' => 'plain_text'],
      'field_author' => 1,
    ]);
    $remark->save();
    $this->assertSame('Synthetic internal remark', $remark->get('field_internal_remark_text')->value);
    $this->assertSame([], $schema->prepare(TRUE)['created']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('markaspot_escalation'));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('markaspot_fastmap'));
  }

  /**
   * Organisation moderation grants only contractor and the declared scope.
   */
  public function testOrgModeratorImportIsScopedAndIdempotent(): void {
    $configuration = $this->orgModeratorConfiguration();
    $first = $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
    $this->assertSame([], $first['errors']);
    $user = user_load_by_mail($configuration['users'][0]['email']);
    $this->assertInstanceOf(UserInterface::class, $user);
    $this->assertSame(['authenticated', 'contractor'], $user->getRoles());
    $this->assertSame(['jur-org_member'], $this->storedMembershipRoles($this->jurisdiction, $user));
    $memberships = GroupMembership::loadByUser($user);
    $this->assertCount(2, $memberships);
    $organisation = NULL;
    foreach ($memberships as $membership) {
      if ($membership->getGroup()->bundle() === 'org') {
        $organisation = $membership->getGroup();
      }
    }
    $this->assertInstanceOf(GroupInterface::class, $organisation);
    $this->assertSame('SWE', $organisation->get('field_org_code')->getString());
    $this->assertSame([], $this->storedMembershipRoles($organisation, $user));
    $this->assertArrayHasKey('org-contractor', $organisation->getMember($user)->getRoles());
    $password = $user->getPassword();
    $second = $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
    $this->assertSame([], $second['errors']);
    $this->assertNotContains('create', array_column($second['rows'], 'action'));
    $this->assertNotContains('update', array_column($second['rows'], 'action'));
    $this->assertSame($password, User::load($user->id())->getPassword());
    $this->assertCount(2, GroupMembership::loadByUser($user));
  }

  /**
   * Missing organisation and dependencies are rejected before any writes.
   */
  public function testOrgModeratorRequiresOrganisationAndRoles(): void {
    $configuration = $this->orgModeratorConfiguration();
    $configuration['users'][0]['organisation_code'] = NULL;
    $this->assertContains('users[0].organisation_code is required for role org_moderator.', $this->importer->validate($configuration));
    $configuration = $this->orgModeratorConfiguration();
    Role::load('contractor')->delete();
    // Deleting the global role also deletes the dependent insider role.
    try {
      $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
      $this->fail('Missing contractor model must reject the import.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('Drupal role contractor is required', $exception->getMessage());
    }
    $this->assertCount(1, Group::loadMultiple());
    $this->assertSame([], Term::loadMultiple());
  }

  /**
   * Overrides never reuse broader accounts or silently remove their authority.
   */
  #[DataProvider('orgModeratorAuthorityProvider')]
  public function testOrgModeratorRejectsExistingAuthority(string $authority): void {
    $configuration = $this->orgModeratorConfiguration();
    $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
    $user = user_load_by_mail($configuration['users'][0]['email']);
    $this->assertInstanceOf(UserInterface::class, $user);
    if (str_starts_with($authority, 'global:')) {
      $roleId = substr($authority, 7);
      if (!Role::load($roleId)) {
        Role::create(['id' => $roleId, 'label' => $roleId])->save();
      }
      $user->addRole($roleId)->save();
    }
    elseif ($authority === 'uid1') {
      $user = User::load(1);
      $user->setEmail($configuration['users'][0]['email'])->save();
      // Keep email lookup unambiguous so the authority check is exercised.
      $oldUser = User::load(2);
      $oldUser->setEmail('replaced@example.invalid')->save();
    }
    elseif ($authority === 'all_groups') {
      $user->set('field_all_groups_member', TRUE)->save();
    }
    elseif ($authority === 'org_member') {
      $user->removeRole('contractor')->save();
    }
    elseif ($authority === 'org_elevated') {
      GroupRole::create([
        'id' => 'org-manager', 'label' => 'Manager',
        'group_type' => 'org', 'scope' => 'individual',
      ])->save();
      $membership = GroupMembership::loadSingle($this->loadOrganisation('SWE'), $user);
      $membership->set('group_roles', ['org-manager'])->save();
    }
    elseif ($authority === 'other_org' || $authority === 'other_root') {
      $group = Group::create([
        'type' => $authority === 'other_org' ? 'org' : 'jur',
        'label' => 'Outside declared scope',
      ]);
      if ($authority === 'other_org') {
        $group->set('field_jurisdiction', $this->jurisdiction->id());
      }
      $group->save();
      $group->addMember($user);
    }
    else {
      $membership = GroupMembership::loadSingle($this->jurisdiction, $user);
      $membership->set('group_roles', ['jur-org_member', $authority])->save();
    }
    $rolesBefore = $user->getRoles();
    $membershipsBefore = array_map(static fn ($membership) => $membership->toArray(), GroupMembership::loadByUser($user));
    $groupCount = count(Group::loadMultiple());
    $configuration['organisations'][] = ['code' => 'NEW', 'name' => 'Must not be created'];
    foreach ([FALSE, TRUE] as $override) {
      try {
        $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE, FALSE, $override);
        $this->fail('Existing broader authority must reject org_moderator.');
      }
      catch (TenantImportValidationException $exception) {
        $this->assertStringContainsString('cannot be imported as org_moderator', $exception->getMessage());
      }
    }
    $this->assertCount($groupCount, Group::loadMultiple());
    $this->assertSame($rolesBefore, User::load($user->id())->getRoles());
    $this->assertSame($membershipsBefore, array_map(static fn ($membership) => $membership->toArray(), GroupMembership::loadByUser($user)));
  }

  /**
   * Existing authority that must never be folded into organisation moderation.
   */
  public static function orgModeratorAuthorityProvider(): array {
    return array_map(static fn ($value) => [$value], [
      'global:administrator', 'global:tenant_admin', 'global:moderator',
      'global:editorial_board', 'uid1', 'all_groups', 'other_org', 'other_root',
      'jur-tenant_admin', 'jur-moderator', 'jur-editorial', 'org_member', 'org_elevated',
    ]);
  }

  /**
   * Creating scoped accounts remains part of the whole-import transaction.
   */
  public function testOrgModeratorImportRollsBack(): void {
    $configuration = $this->orgModeratorConfiguration();
    $this->container->get('state')->set('markaspot_tenant_import_test.fail_status', $configuration['statuses'][0]['name']);
    $result = $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
    $this->assertNotEmpty($result['errors']);
    $this->assertFalse(user_load_by_mail($configuration['users'][0]['email']));
    $this->assertCount(1, Group::loadMultiple());
    $this->assertSame([], GroupMembership::loadMultiple());
    $this->assertSame([], Term::loadMultiple());
  }

  /**
   * Missing or wrongly mapped contractor insider roles cannot widen authority.
   */
  public function testOrgModeratorRejectsMissingOrInvalidInsiderRole(): void {
    $configuration = $this->orgModeratorConfiguration();
    $this->mockContractorPermissionDefinitions();
    $contractor = Role::load('contractor');
    $contractor->set('is_admin', TRUE)->save();
    try {
      $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
      $this->fail('Administrative Drupal contractor role must be rejected.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('contractor must not grant administrative access', $exception->getMessage());
    }
    $contractor->set('is_admin', FALSE)->save();
    foreach ([
      'administer nodes',
      'bypass node access',
      'administer group',
      'administer users',
      'administer permissions',
      'access platform admin',
      'administer site configuration',
      'switch users',
      'custom restricted capability',
      'administer unmarked custom capability',
    ] as $permission) {
      // Inject config drift even when its provider (e.g. node) is not enabled.
      $contractor->setSyncing(TRUE);
      $contractor->grantPermission($permission)->save();
      $this->assertContains($permission, Role::load('contractor')->getPermissions());
      try {
        $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
        $this->fail('Elevated contractor permissions must be rejected.');
      }
      catch (TenantImportValidationException $exception) {
        $this->assertStringContainsString('contractor must not grant administrative access', $exception->getMessage());
      }
      $this->assertCount(1, Group::loadMultiple());
      $this->assertFalse(user_load_by_mail($configuration['users'][0]['email']));
      $contractor->revokePermission($permission)->save();
    }
    foreach (['add dashboard status notes', 'use service request management form'] as $permission) {
      foreach ([
        NULL,
        ['restrict access' => TRUE],
        ['provider' => 'system', 'restrict access' => TRUE],
      ] as $definition) {
        $this->mockContractorPermissionDefinitions([$permission => $definition]);
        $contractor->grantPermission($permission)->save();
        try {
          $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
          $this->fail('Missing or unexpected scoped permission provider must reject.');
        }
        catch (TenantImportValidationException $exception) {
          $this->assertStringContainsString('contractor must not grant administrative access', $exception->getMessage());
        }
        $this->assertCount(1, Group::loadMultiple());
        $this->assertFalse(user_load_by_mail($configuration['users'][0]['email']));
        $contractor->revokePermission($permission)->save();
      }
    }
    $this->mockContractorPermissionDefinitions();
    $role = GroupRole::load('org-contractor');
    $role->set('global_role', 'tenant_admin')->save();
    try {
      $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
      $this->fail('Incorrect insider mapping must be rejected.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('org-contractor must map', $exception->getMessage());
    }
    $role->set('global_role', 'contractor')->set('admin', TRUE)->save();
    try {
      $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
      $this->fail('Administrative insider role must be rejected.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('without administrative group access', $exception->getMessage());
    }
    $role->delete();
    try {
      $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
      $this->fail('Missing insider role must be rejected.');
    }
    catch (TenantImportValidationException $exception) {
      $this->assertStringContainsString('Required group role org-contractor does not exist', $exception->getMessage());
    }
    $this->assertCount(1, Group::loadMultiple());
    $this->assertFalse(user_load_by_mail($configuration['users'][0]['email']));
  }

  /**
   * An existing account gains only the declared scope and keeps its identity.
   */
  public function testOrgModeratorAssignsExistingUnprivilegedAccount(): void {
    $configuration = $this->orgModeratorConfiguration();
    $this->mockContractorPermissionDefinitions();
    // Preserve the two shipped, restricted but organisation-scoped abilities.
    Role::load('contractor')
      ->grantPermission('add dashboard status notes')
      ->grantPermission('use service request management form')
      ->save();
    $user = User::create([
      'name' => 'existing-login',
      'mail' => $configuration['users'][0]['email'],
      'status' => 1,
      'pass' => 'existing-password-not-changed',
      'timezone' => 'Europe/Paris',
      'field_first_name' => 'Existing first name',
    ]);
    $user->save();
    $uid = $user->id();
    $password = $user->getPassword();
    $this->assertSame([], GroupMembership::loadByUser($user));
    $result = $this->importer->import($configuration, (int) $this->jurisdiction->id(), [], TRUE);
    $this->assertSame([], $result['errors']);
    $user = $this->loadUser($configuration['users'][0]['email']);
    $this->assertSame($uid, $user->id());
    $this->assertSame('existing-login', $user->getAccountName());
    $this->assertSame($password, $user->getPassword());
    $this->assertSame('Europe/Paris', $user->getTimeZone());
    $this->assertSame('Existing first name', $user->get('field_first_name')->getString());
    $this->assertSame('Moderation', $user->get('field_last_name')->getString());
    $this->assertSame(['authenticated', 'contractor'], $user->getRoles());
    $this->assertCount(2, GroupMembership::loadByUser($user));
    $this->assertSame(['jur-org_member'], $this->storedMembershipRoles($this->jurisdiction, $user));
    $this->assertSame([], $this->storedMembershipRoles($this->loadOrganisation('SWE'), $user));
  }

  /**
   * Supplies permission metadata from modules outside the minimal fixture.
   */
  private function mockContractorPermissionDefinitions(array $overrides = []): void {
    $definitions = $this->container->get('user.permissions')->getPermissions();
    foreach ([
      'bypass node access',
      'access platform admin',
      'switch users',
      'custom restricted capability',
      'add dashboard status notes',
      'use service request management form',
    ] as $permission) {
      $definitions[$permission] = [
        'title' => $permission,
        'provider' => match ($permission) {
          'add dashboard status notes' => 'markaspot_dashboard',
          'use service request management form' => 'markaspot_ui',
          default => 'system',
        },
        'restrict access' => TRUE,
      ];
    }
    foreach ($overrides as $permission => $definition) {
      if ($definition === NULL) {
        unset($definitions[$permission]);
      }
      else {
        $definitions[$permission] = $definition;
      }
    }
    $permissionHandler = $this->createMock(PermissionHandlerInterface::class);
    $permissionHandler->method('getPermissions')->willReturn($definitions);
    $this->container->set('user.permissions', $permissionHandler);
    // Recreate the importer so it receives the replacement dependency too.
    $this->container->set('markaspot_tenant_import.tenant_importer', NULL);
    $this->importer = $this->container->get('markaspot_tenant_import.tenant_importer');
  }

  /**
   * Supplies one explicitly scoped organisation moderator.
   */
  private function orgModeratorConfiguration(): array {
    $configuration = $this->exampleConfiguration();
    $configuration['users'] = [[
      'email' => 'org-moderator@example.invalid',
      'first_name' => 'Example',
      'last_name' => 'Moderation',
      'role' => 'org_moderator',
      'organisation_code' => 'SWE',
    ]];
    return $configuration;
  }

  /**
   * A new root has no writes in preview and converges on repeated apply.
   */
  public function testDedicatedBootstrapIsReadOnlyThenIdempotent(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    $validation = $this->container->get('config.factory');
    $originalValidation = $validation->get('markaspot_validation.settings')->getRawData();
    $service = $this->container->get('markaspot_tenant_import.tenant_bootstrapper');
    $preview = $service->bootstrap($configuration);
    $this->assertNull($preview['jurisdiction_id']);
    $this->assertSame('clear_packaged_example', $preview['validation_defaults']['action']);
    $this->assertSame($originalValidation, $validation->get('markaspot_validation.settings')->getRawData());
    $this->assertSame([], Group::loadMultiple());
    $this->assertSame([], Term::loadMultiple());
    $this->assertNull($this->container->get('keyvalue')->get('markaspot_tenant_import.bootstrap')->get('root'));
    $first = $service->bootstrap($configuration, NULL, TRUE);
    $this->assertSame('', $validation->get('markaspot_validation.settings')->get('wkt'));
    $this->assertSame([], $validation->get('markaspot_validation.settings')->get('locality'));
    // A later operator choice must survive reruns, even if it is the example.
    $validation->getEditable('markaspot_validation.settings')->setData($originalValidation)->save();
    $initialGroup = Group::load($first['jurisdiction_id']);
    GroupMembership::loadSingle($initialGroup, User::load(2))->set('group_roles', [])->save();
    $second = $service->bootstrap($configuration, NULL, TRUE);
    $this->assertSame('preserve_initialized', $second['validation_defaults']['action']);
    $this->assertSame($originalValidation, $validation->get('markaspot_validation.settings')->getRawData());
    $this->assertSame($first['jurisdiction_id'], $second['jurisdiction_id']);
    $this->assertSame('resume', $second['action']);
    $this->assertNotContains('create', array_column($second['rows'], 'action'));
    $group = Group::load($first['jurisdiction_id']);
    $membership = GroupMembership::loadSingle($group, User::load(2));
    $this->assertNotNull($membership);
    $this->assertSame(['jur-member'], array_column($membership->get('group_roles')->getValue(), 'target_id'));
    $runtime = json_decode($group->get('field_nuxt_config')->getString(), TRUE);
    $this->assertSame([11, 50], $runtime['map']['center']);
    $this->assertSame('#1F3E5D', $runtime['theme']['primary']);
  }

  /**
   * AI assistance cannot grant permissions without its provider module.
   */
  public function testDedicatedAiRequiresInstalledModuleBeforeWrites(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    $configuration['tenant']['features']['aiProcessing'] = TRUE;
    try {
      $this->container->get('markaspot_tenant_import.tenant_bootstrapper')->bootstrap($configuration, NULL, TRUE);
      $this->fail('Missing AI module was accepted.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('Install markaspot_ai', $exception->getMessage());
    }
    $this->assertSame([], Group::loadMultiple());
    $this->assertFalse(Role::load('tenant_admin')->hasPermission('use markaspot ai assist'));
  }

  /**
   * Initial configuration controls Fachadmin analytics without an AI module.
   */
  public function testDedicatedAnalyticsPermissionFromConfiguration(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    // Register the real permission provider without unrelated HTTP services.
    $handler = $this->container->get('module_handler');
    $handler->setModuleList($handler->getModuleList() + [
      'markaspot_dashboard' => new \Drupal\Core\Extension\Extension(DRUPAL_ROOT, 'module', 'profiles/contrib/markaspot/modules/markaspot_dashboard/markaspot_dashboard.info.yml'),
    ]);
    $this->container->set('user.permissions', NULL);
    $configuration['tenant']['features']['operationsDashboard'] = TRUE;
    $service = $this->container->get('markaspot_tenant_import.tenant_bootstrapper');
    $service->bootstrap($configuration);
    $this->assertFalse(Role::load('tenant_admin')->hasPermission('access dashboard kpis'));
    $service->bootstrap($configuration, NULL, TRUE);
    $this->assertTrue(Role::load('tenant_admin')->hasPermission('access dashboard kpis'));
    $configuration['tenant']['features']['operationsDashboard'] = FALSE;
    $service->bootstrap($configuration, NULL, TRUE);
    $this->assertFalse(Role::load('tenant_admin')->hasPermission('access dashboard kpis'));
  }

  /**
   * Missing analytics provider fails before creating the jurisdiction.
   */
  public function testDedicatedAnalyticsRequiresProvider(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    $configuration['tenant']['features']['operationsDashboard'] = TRUE;
    try {
      $this->container->get('markaspot_tenant_import.tenant_bootstrapper')->bootstrap($configuration, NULL, TRUE);
      $this->fail('Missing analytics provider was accepted.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('Install markaspot_dashboard', $exception->getMessage());
    }
    $this->assertSame([], Group::loadMultiple());
  }

  /**
   * Dedicated platform policy is read-only in preview and converges on repeat.
   */
  public function testDedicatedPlatformPolicyPreviewAndRepeat(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    $configuration['tenant']['features']['privacyBlockOnFlag'] = TRUE;
    $factory = $this->container->get('config.factory');
    $before = $factory->get('markaspot_nuxt.settings')->getRawData();
    $service = $this->container->get('markaspot_tenant_import.tenant_bootstrapper');
    $service->bootstrap($configuration);
    $this->assertSame($before, $factory->get('markaspot_nuxt.settings')->getRawData());
    $service->bootstrap($configuration, NULL, TRUE);
    $this->assertTrue($factory->get('markaspot_nuxt.settings')->get('platform_features.privacyBlockOnFlag'));
    $configuration['tenant']['features']['privacyBlockOnFlag'] = FALSE;
    $service->bootstrap($configuration, NULL, TRUE);
    $this->assertFalse($factory->get('markaspot_nuxt.settings')->get('platform_features.privacyBlockOnFlag'));
  }

  /**
   * Existing roots may never be silently adopted.
   */
  public function testDedicatedBootstrapRejectsUnownedRoot(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    Group::create(['type' => 'jur', 'label' => 'Existing', 'field_slug' => 'erfurt'])->save();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Refusing adoption');
    $this->container->get('markaspot_tenant_import.tenant_bootstrapper')->bootstrap($configuration, NULL, TRUE);
  }

  /**
   * Import failure rolls back the root, memberships and all child entities.
   */
  public function testDedicatedBootstrapRollsBackImportFailure(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    $validationBefore = $this->container->get('config.factory')->get('markaspot_validation.settings')->getRawData();
    $this->container->get('state')->set('markaspot_tenant_import_test.fail_status', $configuration['statuses'][0]['name']);
    try {
      $this->container->get('markaspot_tenant_import.tenant_bootstrapper')->bootstrap($configuration, NULL, TRUE);
      $this->fail('Expected injected import failure.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('Tenant import failed', $exception->getMessage());
    }
    $this->assertSame([], Group::loadMultiple());
    $this->assertSame([], Term::loadMultiple());
    $this->assertNull($this->container->get('keyvalue')->get('markaspot_tenant_import.bootstrap')->get('root'));
    $this->assertSame($validationBefore, $this->container->get('config.factory')->get('markaspot_validation.settings')->getRawData());
  }

  /**
   * New dedicated stacks must not misrepresent an unsupported private policy.
   */
  public function testDedicatedBootstrapRejectsUnsupportedPrivatePolicy(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    $configuration['tenant']['features']['publicReports'] = FALSE;
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('publicReports=false cannot bootstrap');
    $this->container->get('markaspot_tenant_import.tenant_bootstrapper')->bootstrap($configuration, NULL, TRUE);
  }

  /**
   * Asset imports converge and failed final saves remove new logo bytes.
   */
  public function testDedicatedBootstrapLogoIdempotenceAndRollback(): void {
    $configuration = $this->prepareDedicatedBootstrap();
    $directory = sys_get_temp_dir() . '/bootstrap-logo-' . bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory . '/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2uoAAAAASUVORK5CYII='));
    $configuration['tenant']['logo_file'] = 'logo.png';
    $service = $this->container->get('markaspot_tenant_import.tenant_bootstrapper');
    try {
      $this->container->get('state')->set('markaspot_tenant_import_test.fail_logo', TRUE);
      try {
        $service->bootstrap($configuration, $directory, TRUE);
        $this->fail('Expected final logo save failure.');
      }
      catch (\Exception $exception) {
        $this->assertStringContainsString('Injected logo save failure', $exception->getMessage());
      }
      $this->assertSame([], Group::loadMultiple());
      $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());
      $this->assertSame([], $this->container->get('file_system')->scanDirectory('public://jurisdiction/bootstrap', '/\.png$/'));
      $this->container->get('state')->set('markaspot_tenant_import_test.fail_logo', FALSE);
      $first = $service->bootstrap($configuration, $directory, TRUE);
      $second = $service->bootstrap($configuration, $directory, TRUE);
      $this->assertSame($first['jurisdiction_id'], $second['jurisdiction_id']);
      $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());
      $group = Group::load($first['jurisdiction_id']);
      $this->assertFalse($group->get('field_logo_light')->isEmpty());
      $this->assertSame($group->get('field_logo_light')->target_id, $group->get('field_logo_dark')->target_id);
      $originalLogoId = $group->get('field_logo_light')->target_id;
      // A new PNG byte snapshot must update both previously linked variants.
      file_put_contents($directory . '/logo.png', "\n", FILE_APPEND);
      $service->bootstrap($configuration, $directory, TRUE);
      $group = Group::load($first['jurisdiction_id']);
      $this->assertNotSame($originalLogoId, $group->get('field_logo_light')->target_id);
      $this->assertSame($group->get('field_logo_light')->target_id, $group->get('field_logo_dark')->target_id);

      $customDark = $this->container->get('entity_type.manager')->getStorage('file')->create([
        'uri' => 'public://custom-dark-logo.png',
        'status' => 1,
      ]);
      $customDark->save();
      $group->set('field_logo_dark', ['target_id' => $customDark->id()])->save();
      $service->bootstrap($configuration, $directory, TRUE);
      $group = Group::load($first['jurisdiction_id']);
      $this->assertSame((string) $customDark->id(), (string) $group->get('field_logo_dark')->target_id);
    }
    finally {
      unlink($directory . '/logo.png');
      rmdir($directory);
    }
  }

  /**
   * The real command renders readable tables and preserves structured JSON.
   */
  public function testDedicatedBootstrapCommandFormats(): void {
    if (!class_exists('Drush\\Style\\DrushStyle')) {
      class_alias(SymfonyStyle::class, 'Drush\\Style\\DrushStyle');
    }
    $configuration = $this->prepareDedicatedBootstrap();
    $service = $this->container->get('markaspot_tenant_import.tenant_bootstrapper');
    $service->bootstrap($configuration, NULL, TRUE);
    $path = tempnam(sys_get_temp_dir(), 'bootstrap-input-');
    file_put_contents($path, json_encode($configuration, JSON_THROW_ON_ERROR));
    try {
      $formatter = new FormatterManager();
      $formatter->addDefaultFormatters();
      $reflection = new \ReflectionMethod(TenantBootstrapCommands::class, 'bootstrap');
      $fields = $reflection->getAttributes(DefaultTableFields::class)[0]->newInstance()->fields;
      foreach (['table', 'json'] as $format) {
        $output = new BufferedOutput();
        $command = new TenantBootstrapCommands($this->importer, $service, $this->container->get('account_switcher'), $this->container->get('entity_type.manager'));
        $config = $this->createMock(DrushConfig::class);
        $config->method('cwd')->willReturn('/tmp');
        $command->setConfig($config);
        $command->setInput(new ArrayInput([]));
        $command->setOutput($output);
        $command->setLogger($this->createMock(DrushLoggerManager::class));
        $result = $command->bootstrap($path, ['format' => $format]);
        $formatter->write($output, $format, $result, new FormatterOptions(['default-table-fields' => $fields]));
        $rendered = $output->fetch();
        if ($format === 'json') {
          $decoded = json_decode($rendered, TRUE, 512, JSON_THROW_ON_ERROR);
          $this->assertIsArray($decoded[0]['warnings']);
          $this->assertIsArray($decoded[0]['rows']);
          $this->assertFalse($decoded[0]['applied']);
        }
        else {
          $this->assertStringContainsString('Entity', $rendered);
          $this->assertStringContainsString('resume', $rendered);
          $this->assertStringNotContainsString('Array', $rendered);
        }
      }
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Models dedicated profile prerequisites without touching live data.
   */
  private function prepareDedicatedBootstrap(): array {
    $this->installEntitySchema('node');
    $validationSource = new FileStorage(DRUPAL_ROOT . '/' . $this->container->get('module_handler')->getModule('markaspot_validation')->getPath() . '/config/install');
    $validationDefaults = $validationSource->read('markaspot_validation.settings');
    // The legacy default is numeric while its existing schema expects string.
    // Preserve its value without expanding this test's geography-only scope.
    $validationDefaults['radius'] = (string) $validationDefaults['radius'];
    $this->container->get('config.factory')->getEditable('markaspot_validation.settings')->setData($validationDefaults)->save();
    $this->jurisdiction->delete();
    new Settings(['markaspot_operating_mode' => 'self_hosted'] + Settings::getAll());
    ConfigurableLanguage::createFromLangcode('de')->save();
    Role::create(['id' => 'api_user', 'label' => 'API user'])->save();
    $apiUser = User::create(['uid' => 2, 'name' => 'api_user', 'status' => 1, 'roles' => ['api_user']]);
    $apiUser->save();
    $this->container->get('config.factory')->getEditable('services_api_key_auth.api_key.nuxt')->set('user_uuid', $apiUser->uuid())->set('key', str_repeat('a', 64))->save();
    $configuration = $this->exampleConfiguration();
    unset($configuration['tenant']['logo_file']);
    $configuration['tenant']['map_center'] = [11.0, 50.0];
    $configuration['tenant']['map_zoom'] = 12;
    return $configuration;
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
    $this->createField('group', 'jur', 'field_nuxt_config', 'text_long');
    $this->createField('group', 'jur', 'field_logo_light', 'file', ['uri_scheme' => 'public']);
    $this->createField('group', 'jur', 'field_logo_dark', 'file', ['uri_scheme' => 'public']);
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
