<?php

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\RoleInterface;
use Psr\Log\LoggerInterface;

/**
 * Test double for module installer service mocks.
 */
class MarkaspotUpdateHooksModuleInstallerDouble {

  public function install(array $modules): void {
  }

  public function uninstall(array $modules): void {
  }

}

/**
 * Test double for group entity mocks.
 */
class MarkaspotUpdateHooksGroupDouble {

  public function hasField(string $field): bool {
    return FALSE;
  }

  public function set(string $field, mixed $value): static {
    return $this;
  }

  public function save(): void {
  }

  public function label(): string {
    return '';
  }

}

/**
 * Test double for user permission handler mocks.
 */
class MarkaspotUpdateHooksPermissionHandlerDouble {

  public function getPermissions(): array {
    return [];
  }

}

/**
 * Tests the Mark-a-Spot installation profile update helpers.
 *
 * @group markaspot
 */
class MarkaspotUpdateHooksTest extends UnitTestCase {

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleHandler;

  /**
   * Mocked module installer.
   *
   * @var object|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleInstaller;

  /**
   * Mocked transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $transliteration;

  /**
   * Mocked key value factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $keyValueFactory;

  /**
   * Mocked system.schema key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $schemaStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/markaspot.install';

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->moduleInstaller = $this->getMockBuilder(MarkaspotUpdateHooksModuleInstallerDouble::class)
      ->onlyMethods(['install', 'uninstall'])
      ->getMock();
    $this->transliteration = $this->createMock(TransliterationInterface::class);
    $this->keyValueFactory = $this->createMock(KeyValueFactoryInterface::class);
    $this->schemaStore = $this->createMock(KeyValueStoreInterface::class);

    $this->keyValueFactory->method('get')
      ->with('system.schema')
      ->willReturn($this->schemaStore);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static function (string $string, array $args = []) {
        return $args ? strtr($string, $args) : $string;
      });

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->willReturn($logger);

    $entityTypeRepository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entityTypeRepository->method('getEntityTypeFromClass')
      ->willReturnCallback(static fn(string $class) => match ($class) {
        'Drupal\user\Entity\Role' => 'user_role',
        default => throw new \InvalidArgumentException("Unknown entity class: $class"),
      });

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('entity_type.repository', $entityTypeRepository);
    $container->set('module_handler', $this->moduleHandler);
    $container->set('module_installer', $this->moduleInstaller);
    $container->set('transliteration', $this->transliteration);
    $container->set('keyvalue', $this->keyValueFactory);
    $container->set('string_translation', $translation);
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Tests json_form_widget installation for existing Nuxt sites.
   */
  public function testUpdate11800InstallsJsonFormWidget(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module) => match ($module) {
        'markaspot_nuxt' => TRUE,
        'json_form_widget' => FALSE,
        default => FALSE,
      });

    $this->moduleInstaller->expects($this->once())
      ->method('install')
      ->with(['json_form_widget']);

    markaspot_update_11800();
  }

  /**
   * Tests default jurisdiction creation preserves zero coordinates.
   */
  public function testCreateDefaultJurisdictionKeepsZeroCoordinates(): void {
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')
      ->willReturnCallback(static fn(string $key) => $key === 'name' ? 'Zero City' : NULL);

    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('isNew')->willReturn(FALSE);
    $nuxtConfig->method('get')
      ->willReturnCallback(static fn(string $key) => match ($key) {
        'center_lat' => 0.0,
        'center_lng' => 0.0,
        'zoom_initial' => 9,
        default => NULL,
      });

    $this->configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'system.site' => $siteConfig,
        'markaspot_nuxt.settings' => $nuxtConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $this->transliteration->method('transliterate')
      ->with('Zero City')
      ->willReturn('Zero City');

    $captured = [];
    $group = $this->getMockBuilder(MarkaspotUpdateHooksGroupDouble::class)
      ->onlyMethods(['hasField', 'set', 'save', 'label'])
      ->getMock();
    $group->method('hasField')
      ->willReturnCallback(static fn(string $field) => in_array($field, ['field_slug', 'field_nuxt_config'], TRUE));
    $group->method('set')
      ->willReturnCallback(function (string $field, $value) use (&$captured, $group) {
        $captured[$field] = $value;
        return $group;
      });
    $group->expects($this->once())->method('save');
    $group->method('label')->willReturn('Zero City');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('create')
      ->with([
        'type' => 'jur',
        'label' => 'Zero City',
        'status' => 1,
      ])
      ->willReturn($group);

    $created = _markaspot_create_default_jurisdiction('jur', $groupStorage);

    $this->assertSame($group, $created);
    $this->assertSame('zero-city', $captured['field_slug']);
    $this->assertArrayHasKey('field_nuxt_config', $captured);

    $nuxtJson = json_decode($captured['field_nuxt_config'], TRUE);
    $this->assertSame([0, 0], $nuxtJson['map']['center']);
    $this->assertSame(9, $nuxtJson['map']['zoom']);
  }

  /**
   * Tests legacy group-type update configuration and default creation.
   */
  public function testUpdate11801ConfiguresLegacyGroupTypes(): void {
    $configUpdates = [];
    $editableConfig = $this->createMock(Config::class);
    $editableConfig->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(function (string $key, $value) use (&$configUpdates, $editableConfig) {
        $configUpdates[$key] = $value;
        return $editableConfig;
      });
    $editableConfig->expects($this->once())->method('save');

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')
      ->willReturnCallback(static fn(string $key) => $key === 'name' ? 'Legacy City' : NULL);

    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('isNew')->willReturn(FALSE);
    $nuxtConfig->method('get')
      ->willReturnCallback(static fn(string $key) => match ($key) {
        'center_lat' => 50.94,
        'center_lng' => 6.96,
        'zoom_initial' => 13,
        default => NULL,
      });

    $this->configFactory->method('getEditable')
      ->with('markaspot_open311.settings')
      ->willReturn($editableConfig);
    $this->configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'system.site' => $siteConfig,
        'markaspot_nuxt.settings' => $nuxtConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $this->transliteration->method('transliterate')
      ->with('Legacy City')
      ->willReturn('Legacy City');

    $groupTypeStorage = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage->method('load')
      ->willReturnCallback(static fn(string $id) => in_array($id, ['organisation', 'jurisdiction'], TRUE) ? new \stdClass() : NULL);

    $createdGroup = $this->getMockBuilder(MarkaspotUpdateHooksGroupDouble::class)
      ->onlyMethods(['hasField', 'set', 'save', 'label'])
      ->getMock();
    $createdGroup->method('hasField')->willReturn(FALSE);
    $createdGroup->expects($this->once())->method('save');
    $createdGroup->method('label')->willReturn('Legacy City');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['type' => 'jurisdiction'])
      ->willReturn([]);
    $groupStorage->expects($this->once())
      ->method('create')
      ->willReturn($createdGroup);

    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $entityType) => match ($entityType) {
        'group_type' => $groupTypeStorage,
        'group' => $groupStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    markaspot_update_11801();

    $this->assertSame('organisation', $configUpdates['group_filter_type']);
    $this->assertSame('jurisdiction', $configUpdates['jurisdiction_group_type']);
  }

  /**
   * Tests markaspot_group installation resets schema for follow-up updates.
   */
  public function testUpdate11802InstallsGroupModuleAndResetsSchema(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('markaspot_group')
      ->willReturn(FALSE);

    $this->moduleInstaller->expects($this->once())
      ->method('install')
      ->with(['markaspot_group']);

    $this->schemaStore->expects($this->once())
      ->method('set')
      ->with('markaspot_group', 11801);

    $fieldStorageConfig = $this->createMock(EntityStorageInterface::class);
    $fieldStorageConfig->method('load')->willReturn(new \stdClass());

    $fieldConfig = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage->method('load')->willReturn(NULL);

    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $entityType) => match ($entityType) {
        'field_storage_config' => $fieldStorageConfig,
        'field_config' => $fieldConfig,
        'group_type' => $groupTypeStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    markaspot_update_11802();
  }

  /**
   * Tests that 11903 installs both modules when neither exists.
   */
  public function testUpdate11903InstallsBothModules(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module) => match ($module) {
        'markaspot_dashboard' => FALSE,
        'markaspot_escalation' => FALSE,
        default => FALSE,
      });

    $installed = [];
    $this->moduleInstaller->expects($this->exactly(2))
      ->method('install')
      ->willReturnCallback(function (array $modules) use (&$installed) {
        $installed[] = $modules;
      });

    markaspot_update_11903();

    $this->assertSame([['markaspot_dashboard'], ['markaspot_escalation']], $installed);
  }

  /**
   * Tests that 11903 skips modules that are already installed.
   */
  public function testUpdate11903SkipsAlreadyInstalledModules(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module) => match ($module) {
        'markaspot_dashboard' => TRUE,
        'markaspot_escalation' => TRUE,
        default => FALSE,
      });

    $this->moduleInstaller->expects($this->never())
      ->method('install');

    markaspot_update_11903();
  }

  /**
   * Tests that 11903 installs only the missing module.
   */
  public function testUpdate11903InstallsOnlyMissingModule(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module) => match ($module) {
        'markaspot_dashboard' => TRUE,
        'markaspot_escalation' => FALSE,
        default => FALSE,
      });

    $this->moduleInstaller->expects($this->once())
      ->method('install')
      ->with(['markaspot_escalation']);

    markaspot_update_11903();
  }

  /**
   * Tests that 11904 grants permissions to existing roles.
   */
  public function testUpdate11904GrantsPermissionsToExistingRoles(): void {
    $grantedPerms = [];
    $savedRoles = [];

    $createRole = function (string $rid) use (&$grantedPerms, &$savedRoles) {
      $role = $this->createMock(RoleInterface::class);
      $role->method('grantPermission')
        ->willReturnCallback(function (string $perm) use ($rid, &$grantedPerms, $role) {
          $grantedPerms[$rid][] = $perm;
          return $role;
        });
      $role->method('save')
        ->willReturnCallback(function () use ($rid, &$savedRoles) {
          $savedRoles[] = $rid;
          return 1;
        });
      return $role;
    };

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->willReturnCallback(function (string $rid) use ($createRole) {
        return match ($rid) {
          'tenant_admin', 'editorial_board', 'moderator' => $createRole($rid),
          default => NULL,
        };
      });

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    markaspot_update_11904();

    $expectedPerms = ['view own unpublished content', 'view own unpublished media'];
    foreach (['tenant_admin', 'editorial_board', 'moderator'] as $rid) {
      $this->assertSame($expectedPerms, $grantedPerms[$rid]);
    }
    $this->assertCount(3, $savedRoles);
  }

  /**
   * Tests that 11904 silently skips roles that do not exist.
   */
  public function testUpdate11904SkipsMissingRoles(): void {
    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')->willReturn(NULL);
    // save() should never be called when all roles are missing.
    $roleStorage->expects($this->never())->method('save');

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    // No exception expected; the hook just logs and continues.
    markaspot_update_11904();
  }

  /**
   * Tests that 11904 is idempotent when permissions are already granted.
   */
  public function testUpdate11904IdempotentWithExistingPermissions(): void {
    // grantPermission is always called regardless; Drupal's Role handles
    // idempotency internally. Just verify save is called without error.
    $role = $this->createMock(RoleInterface::class);
    $role->method('grantPermission')->willReturnSelf();
    $role->expects($this->exactly(3))->method('save');

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->willReturnCallback(fn(string $rid) => match ($rid) {
        'tenant_admin', 'editorial_board', 'moderator' => $role,
        default => NULL,
      });

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    markaspot_update_11904();
  }

  /**
   * Tests that 11906 sets read_only to FALSE when it is TRUE.
   */
  public function testUpdate11906SetsReadOnlyToFalse(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('read_only')
      ->willReturn(TRUE);
    $config->expects($this->once())
      ->method('set')
      ->with('read_only', FALSE)
      ->willReturnSelf();
    $config->expects($this->once())->method('save');

    $this->configFactory->method('getEditable')
      ->with('jsonapi.settings')
      ->willReturn($config);

    markaspot_update_11906();
  }

  /**
   * Tests that 11906 does not save when read_only is already FALSE.
   */
  public function testUpdate11906SkipsWhenAlreadyFalse(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('read_only')
      ->willReturn(FALSE);
    $config->expects($this->never())->method('set');
    $config->expects($this->never())->method('save');

    $this->configFactory->method('getEditable')
      ->with('jsonapi.settings')
      ->willReturn($config);

    markaspot_update_11906();
  }

  /**
   * Tests that 11907 creates tenant_admin role from YAML when it does not exist.
   */
  public function testUpdate11907CreatesRoleFromYaml(): void {
    $grantedPerms = [];
    $saveCount = 0;
    $role = $this->createMock(RoleInterface::class);
    $role->method('hasPermission')->willReturn(FALSE);
    $role->method('grantPermission')
      ->willReturnCallback(function (string $perm) use (&$grantedPerms, $role) {
        $grantedPerms[] = $perm;
        return $role;
      });
    $role->method('save')
      ->willReturnCallback(function () use (&$saveCount) {
        $saveCount++;
        return 1;
      });

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('tenant_admin')
      ->willReturn(NULL);
    $roleStorage->method('create')
      ->with([
        'id' => 'tenant_admin',
        'label' => 'Tenant Admin',
        'weight' => 4,
      ])
      ->willReturn($role);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    // Point to the real profile config directory.
    $profilePath = dirname(__DIR__, 3);
    $profileExtensionList = $this->createMock(ProfileExtensionList::class);
    $profileExtensionList->method('getPath')
      ->with('markaspot')
      ->willReturn($profilePath);

    // Permission handler: return all permissions from the YAML as valid.
    $yamlFile = $profilePath . '/config/install/user.role.tenant_admin.yml';
    $this->assertFileExists($yamlFile, 'tenant_admin YAML config must exist');
    $yaml = \Drupal\Component\Serialization\Yaml::decode(file_get_contents($yamlFile));
    $yamlPerms = $yaml['permissions'] ?? [];

    $permissionHandler = $this->getMockBuilder(MarkaspotUpdateHooksPermissionHandlerDouble::class)
      ->onlyMethods(['getPermissions'])
      ->getMock();
    $allPermsKeyed = array_fill_keys($yamlPerms, ['title' => 'test']);
    $permissionHandler->method('getPermissions')->willReturn($allPermsKeyed);

    $container = \Drupal::getContainer();
    $container->set('extension.list.profile', $profileExtensionList);
    $container->set('user.permissions', $permissionHandler);

    markaspot_update_11907();

    // Role was created (save #1) and permissions added (save #2).
    $this->assertSame(2, $saveCount);
    // All YAML permissions should have been granted.
    $this->assertSame($yamlPerms, $grantedPerms);
  }

  /**
   * Tests that 11907 skips creation when tenant_admin role already exists.
   */
  public function testUpdate11907SkipsExistingRole(): void {
    $role = $this->createMock(RoleInterface::class);
    $role->method('hasPermission')->willReturn(TRUE);
    $role->method('grantPermission')->willReturnSelf();
    // All permissions already present, so save should never be called.
    $role->expects($this->never())->method('save');

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('tenant_admin')
      ->willReturn($role);
    $roleStorage->expects($this->never())->method('create');

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    $profilePath = dirname(__DIR__, 3);
    $profileExtensionList = $this->createMock(ProfileExtensionList::class);
    $profileExtensionList->method('getPath')
      ->with('markaspot')
      ->willReturn($profilePath);

    $yamlFile = $profilePath . '/config/install/user.role.tenant_admin.yml';
    $yaml = \Drupal\Component\Serialization\Yaml::decode(file_get_contents($yamlFile));
    $yamlPerms = $yaml['permissions'] ?? [];
    $allPermsKeyed = array_fill_keys($yamlPerms, ['title' => 'test']);

    $permissionHandler = $this->getMockBuilder(MarkaspotUpdateHooksPermissionHandlerDouble::class)
      ->onlyMethods(['getPermissions'])
      ->getMock();
    $permissionHandler->method('getPermissions')->willReturn($allPermsKeyed);

    $container = \Drupal::getContainer();
    $container->set('extension.list.profile', $profileExtensionList);
    $container->set('user.permissions', $permissionHandler);

    markaspot_update_11907();
  }

  /**
   * Tests 11907 when YAML file is missing: role created with zero permissions.
   *
   * Finding: when the YAML file does not exist, the hook still creates the
   * role entity but adds no permissions. This is correct defensive behavior
   * since the role at minimum needs to exist for other hooks (11904) to work.
   */
  public function testUpdate11907MissingYamlCreatesRoleWithNoPermissions(): void {
    $saveCount = 0;
    $grantedPerms = [];
    $role = $this->createMock(RoleInterface::class);
    $role->method('hasPermission')->willReturn(FALSE);
    $role->method('grantPermission')
      ->willReturnCallback(function (string $perm) use (&$grantedPerms, $role) {
        $grantedPerms[] = $perm;
        return $role;
      });
    $role->method('save')
      ->willReturnCallback(function () use (&$saveCount) {
        $saveCount++;
        return 1;
      });

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('tenant_admin')
      ->willReturn(NULL);
    $roleStorage->method('create')->willReturn($role);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    // Point to a non-existent path so the YAML file cannot be found.
    $profileExtensionList = $this->createMock(ProfileExtensionList::class);
    $profileExtensionList->method('getPath')
      ->with('markaspot')
      ->willReturn('/tmp/nonexistent-profile-path');

    $permissionHandler = $this->getMockBuilder(MarkaspotUpdateHooksPermissionHandlerDouble::class)
      ->onlyMethods(['getPermissions'])
      ->getMock();
    $permissionHandler->method('getPermissions')->willReturn([]);

    $container = \Drupal::getContainer();
    $container->set('extension.list.profile', $profileExtensionList);
    $container->set('user.permissions', $permissionHandler);

    markaspot_update_11907();

    // Role created and saved once (initial save), but no permissions added.
    $this->assertSame(1, $saveCount);
    $this->assertEmpty($grantedPerms);
  }

  /**
   * Tests that 11908 grants permissions to the authenticated role.
   */
  public function testUpdate11908GrantsPermissionsToAuthenticated(): void {
    $grantedPerms = [];
    $role = $this->createMock(RoleInterface::class);
    $role->method('hasPermission')->willReturn(FALSE);
    $role->method('grantPermission')
      ->willReturnCallback(function (string $perm) use (&$grantedPerms, $role) {
        $grantedPerms[] = $perm;
        return $role;
      });
    $role->expects($this->once())->method('save')->willReturn(1);

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('authenticated')
      ->willReturn($role);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    $validPerms = [
      'edit own field_e_mail' => ['title' => 'test'],
      'edit terms in service_category' => ['title' => 'test'],
    ];
    $permissionHandler = $this->getMockBuilder(MarkaspotUpdateHooksPermissionHandlerDouble::class)
      ->onlyMethods(['getPermissions'])
      ->getMock();
    $permissionHandler->method('getPermissions')->willReturn($validPerms);

    $container = \Drupal::getContainer();
    $container->set('user.permissions', $permissionHandler);

    markaspot_update_11908();

    $this->assertSame(['edit own field_e_mail', 'edit terms in service_category'], $grantedPerms);
  }

  /**
   * Tests that 11908 returns early when authenticated role is missing.
   */
  public function testUpdate11908ReturnsEarlyWhenRoleMissing(): void {
    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('authenticated')
      ->willReturn(NULL);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    // No exception, no calls to permission handler.
    markaspot_update_11908();
  }

  /**
   * Tests that 11908 is idempotent when permissions already exist.
   */
  public function testUpdate11908IdempotentWithExistingPermissions(): void {
    $role = $this->createMock(RoleInterface::class);
    $role->method('hasPermission')->willReturn(TRUE);
    $role->method('grantPermission')->willReturnSelf();
    // No save expected since added count stays at 0.
    $role->expects($this->never())->method('save');

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('authenticated')
      ->willReturn($role);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    $validPerms = [
      'edit own field_e_mail' => ['title' => 'test'],
      'edit terms in service_category' => ['title' => 'test'],
    ];
    $permissionHandler = $this->getMockBuilder(MarkaspotUpdateHooksPermissionHandlerDouble::class)
      ->onlyMethods(['getPermissions'])
      ->getMock();
    $permissionHandler->method('getPermissions')->willReturn($validPerms);

    $container = \Drupal::getContainer();
    $container->set('user.permissions', $permissionHandler);

    markaspot_update_11908();
  }

  /**
   * Tests that 11911 uninstalls all three legacy modules when enabled.
   */
  public function testUpdate11911UninstallsAllLegacyModules(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module) => match ($module) {
        'markaspot_front', 'markaspot_privacy', 'markaspot_trend' => TRUE,
        default => FALSE,
      });

    $uninstalled = [];
    $this->moduleInstaller->expects($this->exactly(3))
      ->method('uninstall')
      ->willReturnCallback(function (array $modules) use (&$uninstalled) {
        $uninstalled[] = $modules[0];
      });

    $result = markaspot_update_11911();

    $this->assertSame(['markaspot_front', 'markaspot_privacy', 'markaspot_trend'], $uninstalled);
    $this->assertStringContainsString('markaspot_front', $result);
    $this->assertStringContainsString('markaspot_privacy', $result);
    $this->assertStringContainsString('markaspot_trend', $result);
  }

  /**
   * Tests that 11911 skips when no legacy modules are enabled.
   */
  public function testUpdate11911SkipsWhenNoLegacyModulesEnabled(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturn(FALSE);

    $this->moduleInstaller->expects($this->never())
      ->method('uninstall');

    $result = markaspot_update_11911();

    $this->assertSame('No legacy modules found to uninstall.', $result);
  }

  /**
   * Tests that 11911 uninstalls only the enabled legacy module.
   */
  public function testUpdate11911UninstallsOnlyEnabledModule(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module) => match ($module) {
        'markaspot_privacy' => TRUE,
        default => FALSE,
      });

    $this->moduleInstaller->expects($this->once())
      ->method('uninstall')
      ->with(['markaspot_privacy']);

    $result = markaspot_update_11911();

    $this->assertStringContainsString('markaspot_privacy', $result);
    $this->assertStringNotContainsString('markaspot_front', $result);
    $this->assertStringNotContainsString('markaspot_trend', $result);
  }

  /**
   * Tests that 11918 grants missing anonymous public report permissions.
   */
  public function testUpdate11918GrantsMissingAnonymousPublicReportPermissions(): void {
    $existingPermissions = [
      'access content' => TRUE,
      'create field_request_media' => TRUE,
    ];
    $grantedPermissions = [];

    $role = $this->createMock(RoleInterface::class);
    $role->method('hasPermission')
      ->willReturnCallback(static fn(string $permission) => isset($existingPermissions[$permission]));
    $role->method('grantPermission')
      ->willReturnCallback(function (string $permission) use (&$grantedPermissions, $role) {
        $grantedPermissions[] = $permission;
        return $role;
      });
    $role->expects($this->once())->method('save');

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('anonymous')
      ->willReturn($role);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    $result = markaspot_update_11918();

    $this->assertContains('create media', $grantedPermissions);
    $this->assertContains('create request_image media', $grantedPermissions);
    $this->assertContains('create service_request content', $grantedPermissions);
    $this->assertContains('view media', $grantedPermissions);
    $this->assertContains('view own unpublished media', $grantedPermissions);
    $this->assertStringContainsString('Granted anonymous public report permissions', $result);
  }

  /**
   * Tests that 11918 is idempotent when anonymous role is already aligned.
   */
  public function testUpdate11918SkipsWhenAnonymousPermissionsAlreadyAligned(): void {
    $role = $this->createMock(RoleInterface::class);
    $role->method('hasPermission')->willReturn(TRUE);
    $role->expects($this->never())->method('grantPermission');
    $role->expects($this->never())->method('save');

    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('anonymous')
      ->willReturn($role);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    $result = markaspot_update_11918();

    $this->assertSame('Anonymous public report permissions already aligned.', $result);
  }

  /**
   * Tests that 11918 returns early when anonymous role is missing.
   */
  public function testUpdate11918SkipsWhenAnonymousRoleIsMissing(): void {
    $roleStorage = $this->createMock(EntityStorageInterface::class);
    $roleStorage->method('load')
      ->with('anonymous')
      ->willReturn(NULL);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($roleStorage);

    $result = markaspot_update_11918();

    $this->assertSame('Anonymous role not found; no permissions changed.', $result);
  }

}
