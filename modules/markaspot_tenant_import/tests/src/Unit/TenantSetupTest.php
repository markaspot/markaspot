<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_tenant_import\Drush\Commands\TenantSetupCommands;
use Drupal\markaspot_tenant_import\Service\TenantSetup;
use Drupal\markaspot_tenant_import\Service\TenantSetupSchema;
use Drupal\Tests\UnitTestCase;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the setup boundary, failed initialization, and repeat preservation.
 */
#[Group('markaspot_tenant_import')]
final class TenantSetupTest extends UnitTestCase {

  /**
   * Private filesystem fixture root.
   */
  private string $fixtureRoot;

  /**
   * Persistent initialization markers.
   */
  private array $markers = [];

  /**
   * Active configuration, including operator customizations.
   */
  private array $active = [];

  /**
   * Current role permissions.
   */
  private array $granted = [];

  /**
   * Configuration names passed to the installer.
   */
  private array $installed = [];

  /**
   * Number of profile helper invocations.
   */
  private int $repairCalls = 0;

  /**
   * Whether the helper satisfies all role postconditions.
   */
  private bool $repairSucceeds = TRUE;

  /**
   * Whether citizen field permission definitions are registered after repair.
   */
  private bool $citizenPermissionDefined = TRUE;

  /**
   * Whether the profile repair restores the anonymous address permission.
   */
  private bool $citizenPermissionGranted = TRUE;

  /**
   * Whether another setup operation holds the lock.
   */
  private bool $lockAvailable = TRUE;

  /**
   * Number of mutation lock requests.
   */
  private int $lockCalls = 0;

  /**
   * Number of owned lock releases.
   */
  private int $releases = 0;

  /**
   * Installed profile identity.
   */
  private string $profile = 'markaspot';

  /**
   * Explicitly enabled optional providers.
   */
  private array $enabledModules = [];

  /**
   * Additional defined and granted permissions for custom-policy fixtures.
   */
  private array $extraPermissions = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fixtureRoot = sys_get_temp_dir() . '/tenant-setup-' . bin2hex(random_bytes(8));
    foreach (['install', 'optional'] as $directory) {
      mkdir($this->fixtureRoot . '/profile/config/' . $directory, 0700, TRUE);
      foreach (['tenant_admin', 'moderator', 'contractor', 'anonymous', 'authenticated'] as $role) {
        $permissions = $directory === 'optional' ? ['dashboard capability'] : ['obsolete permission'];
        if ($directory === 'optional' && in_array($role, ['anonymous', 'authenticated'], TRUE)) {
          $permissions = ['create field_address', 'create service_request content', 'create field_gdpr'];
        }
        file_put_contents($this->fixtureRoot . '/profile/config/' . $directory . '/user.role.' . $role . '.yml', Yaml::encode(['permissions' => $permissions]));
      }
    }
    mkdir($this->fixtureRoot . '/group/config/optional', 0700, TRUE);
    foreach (['field.field.node.page.field_jurisdiction', 'group.relationship_type.jur-group_node-page'] as $name) {
      file_put_contents($this->fixtureRoot . '/group/config/optional/' . $name . '.yml', Yaml::encode(['id' => $name]));
    }
    // Unrelated optional config must never enter the install source.
    file_put_contents($this->fixtureRoot . '/group/config/optional/unrelated.yml', 'id: unrelated');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    (new Filesystem())->remove($this->fixtureRoot);
    parent::tearDown();
  }

  /**
   * Wires stateful doubles around the real source selection and role checks.
   */
  private function service(): TenantSetup {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn($key) => $this->markers[$key] ?? NULL);
    $state->method('set')->willReturnCallback(function ($key, $value): void {
      $this->markers[$key] = $value;
    });
    $state->method('delete')->willReturnCallback(function ($key): void {
      unset($this->markers[$key]);
    });
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('load')->willReturnCallback(function ($id) {
      $role = $this->createMock(RoleInterface::class);
      $role->method('hasPermission')->willReturnCallback(fn($permission) => !empty($this->granted[$id][$permission]));
      return $role;
    });
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user_role')->willReturn($roles);
    $fields = $this->createMock(EntityFieldManagerInterface::class);
    $fields->method('getFieldDefinitions')->with('node', 'page')->willReturn(['field_jurisdiction' => $this->createMock(FieldDefinitionInterface::class)]);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (): bool {
      $this->lockCalls++;
      return $this->lockAvailable;
    });
    $lock->method('release')->willReturnCallback(function (): void {
      $this->releases++;
    });
    $modules = $this->createMock(ModuleExtensionList::class);
    $modules->method('getPath')->willReturnMap([
      ['markaspot_group', 'group'],
      ['service_request', 'profile/modules/service_request'],
    ]);
    $profiles = $this->createMock(ProfileExtensionList::class);
    $profiles->method('getPath')->with('markaspot')->willReturn('profile');
    $installer = $this->createMock(ConfigInstallerInterface::class);
    $installer->method('installOptionalConfig')->willReturnCallback(function (StorageInterface $source): void {
      $names = $source->listAll();
      $this->installed[] = $names;
      foreach ($names as $name) {
        $this->active[$name] = $source->read($name);
      }
    });
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('exists')->willReturnCallback(fn($name) => isset($this->active[$name]));
    $storage->method('read')->willReturnCallback(fn($name) => $this->active[$name] ?? FALSE);
    $permissions = $this->createMock(PermissionHandlerInterface::class);
    $permissions->method('getPermissions')->willReturnCallback(function (): array {
      $definitions = [
        'dashboard capability' => ['provider' => 'markaspot_dashboard'],
        'create service_request content' => ['provider' => 'node'],
      ];
      // Mimic dynamic field definitions appearing only after cache repair.
      if ($this->citizenPermissionDefined && $this->repairCalls > 0) {
        $definitions['create field_address'] = ['provider' => 'field_permissions'];
      }
      foreach ($this->extraPermissions as $permission) {
        $definitions[$permission] = ['provider' => 'field_permissions'];
      }
      return $definitions;
    });
    $service = $this->getMockBuilder(TenantSetup::class)
      ->setConstructorArgs([
        $this->getConfigFactoryStub([
          'system.site' => ['uuid' => 'owned'],
          'core.extension' => ['profile' => $this->profile, 'module' => $this->enabledModules],
        ]),
        $state, $entities, $fields, $lock, $modules, $profiles, $installer, $storage, $permissions, $this->fixtureRoot,
      ])
      ->onlyMethods(['repairRolePermissions', 'schema'])
      ->getMock();
    $schema = $this->createMock(TenantSetupSchema::class);
    $schema->method('prepare')->willReturn([
      'required' => [],
      'applicable' => [],
      'missing' => [],
      'created' => [],
      'unavailable_optional' => [],
    ]);
    $service->method('schema')->willReturn($schema);
    $service->method('repairRolePermissions')->willReturnCallback(function (): void {
      $this->repairCalls++;
      $this->assertSame('owned', $this->markers['markaspot_cloud.permissions_initialization_started']);
      foreach (['tenant_admin', 'moderator', 'contractor'] as $role) {
        if ($this->repairSucceeds || $role !== 'contractor') {
          $this->granted[$role]['dashboard capability'] = TRUE;
        }
      }
      foreach (['anonymous', 'authenticated'] as $role) {
        $this->granted[$role]['create service_request content'] = TRUE;
        foreach ($this->extraPermissions as $permission) {
          $this->granted[$role][$permission] = TRUE;
        }
        if ($this->citizenPermissionDefined && ($this->citizenPermissionGranted || $role !== 'anonymous')) {
          $this->granted[$role]['create field_address'] = TRUE;
        }
      }
    });
    return $service;
  }

  /**
   * Status and preview neither write nor acquire mutation locks.
   */
  public function testStatusAndPreviewAreReadOnly(): void {
    $service = $this->service();
    $this->assertSame([
      'contract_version' => 1,
      'site_uuid' => 'owned',
      'permissions_initialized' => NULL,
      'permissions_initialization_started' => NULL,
    ], $service->status());
    $preview = $service->prepare('owned', TRUE);
    $this->assertFalse($preview['applied']);
    $this->assertCount(2, $preview['page_configuration_missing']);
    $this->assertSame([], $this->markers);
    $this->assertSame([], $this->installed);
    $this->assertSame(0, $this->repairCalls);
    $this->assertSame(0, $this->lockCalls);
  }

  /**
   * Initialize once, preserve existing config, and retain later revocations.
   */
  public function testFirstApplyAndRepeatedApplyPreserveLocalDecisions(): void {
    $name = 'field.field.node.page.field_jurisdiction';
    $this->active[$name] = ['custom' => 'preserve'];
    $service = $this->service();
    $first = $service->prepare('owned', TRUE, TRUE);
    $this->assertTrue($first['applied']);
    $this->assertSame('initialized', $first['permission_action']);
    $this->assertSame([['group.relationship_type.jur-group_node-page']], $this->installed);
    $this->assertSame(['custom' => 'preserve'], $this->active[$name]);
    $this->assertSame(['markaspot_cloud.permissions_initialized' => 'owned'], $this->markers);
    $this->assertTrue($this->granted['anonymous']['create field_address']);
    $this->assertTrue($this->granted['authenticated']['create field_address']);
    $this->granted['contractor'] = [];
    $this->granted['anonymous'] = [];
    $this->granted['authenticated'] = [];
    $repeat = $service->prepare('owned', FALSE, TRUE);
    $this->assertSame('already_initialized', $repeat['permission_action']);
    $this->assertSame([], $repeat['page_configuration_created']);
    $this->assertSame([], $this->granted['contractor']);
    $this->assertSame([], $this->granted['anonymous']);
    $this->assertSame([], $this->granted['authenticated']);
    $this->assertSame(1, $this->repairCalls);
    $this->assertSame(2, $this->releases);
    // A lost host receipt is recoverable without rerunning the repair.
    $this->assertSame('already_initialized', $service->prepare('owned', TRUE, TRUE)['permission_action']);
    $this->assertSame(1, $this->repairCalls);
  }

  /**
   * Missing page config is repaired even when permissions are already done.
   */
  public function testCompletedInstallStillRepairsOnlyMissingPageConfig(): void {
    $this->markers['markaspot_cloud.permissions_initialized'] = 'owned';
    $result = $this->service()->prepare('owned', FALSE, TRUE);
    $this->assertCount(2, $result['page_configuration_created']);
    $this->assertSame(0, $this->repairCalls);
    $this->assertArrayNotHasKey('unrelated', $this->active);
  }

  /**
   * A partial role repair stays blocked even after the mutation lock releases.
   */
  public function testFailureRetainsStartedMarkerAndRejectsRetry(): void {
    $this->repairSucceeds = FALSE;
    $service = $this->service();
    try {
      $service->prepare('owned', TRUE, TRUE);
      $this->fail('Missing contractor capability must fail.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('postcondition', $error->getMessage());
    }
    $this->assertSame(['markaspot_cloud.permissions_initialization_started' => 'owned'], $this->markers);
    $this->assertSame(1, $this->repairCalls);
    $this->expectExceptionMessage('explicit recovery');
    $service->prepare('owned', TRUE, TRUE);
  }

  /**
   * An unregistered reporting permission cannot silently complete the setup.
   */
  public function testUndefinedCitizenPermissionPreventsCompletion(): void {
    $this->citizenPermissionDefined = FALSE;
    $this->assertCitizenPermissionFailure();
  }

  /**
   * A registered but ungranted reporting permission cannot complete setup.
   */
  public function testUngrantedCitizenPermissionPreventsCompletion(): void {
    $this->citizenPermissionGranted = FALSE;
    $this->assertCitizenPermissionFailure();
  }

  /**
   * Verifies both citizen failures retain evidence and reject automatic retry.
   */
  private function assertCitizenPermissionFailure(): void {
    $service = $this->service();
    try {
      $service->prepare('owned', TRUE, TRUE);
      $this->fail('Missing anonymous address capability must fail.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('postcondition failed for role anonymous: create field_address', $error->getMessage());
    }
    $this->assertSame(['markaspot_cloud.permissions_initialization_started' => 'owned'], $this->markers);
    $this->assertSame(1, $this->repairCalls);
    $this->assertSame(1, $this->releases);
    $this->expectExceptionMessage('explicit recovery');
    $service->prepare('owned', TRUE, TRUE);
  }

  /**
   * Adds role expectations without duplicating the production permission list.
   */
  private function addCitizenPermissions(array $permissions): void {
    foreach (['anonymous', 'authenticated'] as $role) {
      $path = $this->fixtureRoot . '/profile/config/optional/user.role.' . $role . '.yml';
      $data = Yaml::decode(file_get_contents($path));
      $data['permissions'] = array_merge($data['permissions'], $permissions);
      file_put_contents($path, Yaml::encode($data));
    }
  }

  /**
   * Retired and disabled feature permissions are reported in preview and apply.
   */
  public function testDisabledAbsentOptionalFieldsAreExplicitExceptions(): void {
    $this->addCitizenPermissions([
      'create field_approved', 'edit field_approved',
      'edit own field_approved', 'create field_phone',
    ]);
    $service = $this->service();
    $preview = $service->prepare('owned', TRUE);
    $exceptions = $preview['permission_exceptions']['anonymous'];
    $this->assertStringContainsString('markaspot_confirm is disabled', $exceptions['create field_approved']);
    $this->assertStringContainsString('telephone is disabled', $exceptions['create field_phone']);
    $this->assertArrayHasKey('create field_gdpr', $exceptions);
    $result = $service->prepare('owned', TRUE, TRUE);
    $this->assertSame($preview['permission_exceptions'], $result['permission_exceptions']);
    $this->assertTrue($result['permissions_initialized']);
  }

  /**
   * An enabled provider cannot hide a broken or missing field as optional.
   */
  public function testEnabledProviderMissingFieldRemainsRequired(): void {
    $this->enabledModules = ['telephone' => 0];
    $this->addCitizenPermissions(['create field_phone']);
    try {
      $this->service()->prepare('owned', TRUE, TRUE);
      $this->fail('Enabled telephone with missing field must fail.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('postcondition failed for role anonymous: create field_phone', $error->getMessage());
      $this->assertArrayNotHasKey('markaspot_cloud.permissions_initialized', $this->markers);
    }
  }

  /**
   * Existing fields remain required even when their old provider is disabled.
   */
  public function testExistingOptionalFieldPermissionsRemainRequired(): void {
    $this->active['field.storage.node.field_approved'] = ['id' => 'node.field_approved'];
    $this->addCitizenPermissions(['create field_approved']);
    $this->expectExceptionMessage('postcondition failed for role anonymous: create field_approved');
    $this->service()->prepare('owned', TRUE, TRUE);
  }

  /**
   * Loads the real shipped status-note metadata into the isolated fixture.
   */
  private function statusNotePolicy(string $policy): array {
    $relative = '/modules/service_request/config/install';
    $name = 'field.storage.paragraph.field_status_note.yml';
    $contents = file_get_contents(dirname(__DIR__, 5) . $relative . '/' . $name);
    $this->assertNotFalse($contents);
    mkdir($this->fixtureRoot . '/profile' . $relative, 0700, TRUE);
    file_put_contents($this->fixtureRoot . '/profile' . $relative . '/' . $name, $contents);
    $data = Yaml::decode($contents);
    $this->assertSame('public', $data['third_party_settings']['field_permissions']['permission_type']);
    $data['third_party_settings']['field_permissions']['permission_type'] = $policy;
    $this->active['field.storage.paragraph.field_status_note'] = $data;
    $this->addCitizenPermissions(['view field_status_note', 'view own field_status_note']);
    return $data;
  }

  /**
   * Canonical public visibility needs no custom field permission definitions.
   */
  public function testCanonicalPublicStatusNotePolicyIsRecognized(): void {
    $original = $this->statusNotePolicy('public');
    $service = $this->service();
    $preview = $service->prepare('owned', TRUE);
    $this->assertStringContainsString('Canonical public', $preview['permission_exceptions']['anonymous']['view field_status_note']);
    $result = $service->prepare('owned', TRUE, TRUE);
    $this->assertTrue($result['permissions_initialized']);
    $this->assertSame($original, $this->active['field.storage.paragraph.field_status_note']);
  }

  /**
   * Custom visibility remains permission-checked and is preserved unchanged.
   */
  public function testCustomStatusNotePolicyUsesRealPermissions(): void {
    $original = $this->statusNotePolicy('custom');
    $this->extraPermissions = ['view field_status_note', 'view own field_status_note'];
    $result = $this->service()->prepare('owned', TRUE, TRUE);
    $this->assertArrayNotHasKey('view field_status_note', $result['permission_exceptions']['anonymous']);
    $this->assertSame($original, $this->active['field.storage.paragraph.field_status_note']);
  }

  /**
   * Private visibility is not silently replaced with the shipped public policy.
   */
  public function testPrivateStatusNotePolicyFailsWithoutWidening(): void {
    $original = $this->statusNotePolicy('private');
    try {
      $this->service()->prepare('owned', TRUE, TRUE);
      $this->fail('Private status notes require an explicit policy decision.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('Private status-note visibility requires an explicit policy decision', $error->getMessage());
      $this->assertSame($original, $this->active['field.storage.paragraph.field_status_note']);
      $this->assertSame([], $this->markers);
      $this->assertSame([], $this->installed);
      $this->assertSame(0, $this->repairCalls);
    }
  }

  /**
   * Foreign, malformed, and unfinished identities cannot authorize a write.
   */
  public function testIdentityAndMarkerFailuresPrecedeMutation(): void {
    foreach ([
      ['foreign', []],
      ['', []],
      ['owned', ['markaspot_cloud.permissions_initialized' => 'foreign']],
      ['owned', ['markaspot_cloud.permissions_initialization_started' => 'owned']],
      ['owned', [
        'markaspot_cloud.permissions_initialized' => 'owned',
        'markaspot_cloud.permissions_initialization_started' => 'owned',
      ]],
    ] as [$expected, $markers]) {
      $this->markers = $markers;
      try {
        $this->service()->prepare($expected, TRUE, TRUE);
        $this->fail('Invalid identity or markers must stop setup.');
      }
      catch (\RuntimeException) {
        $this->assertSame($markers, $this->markers);
        $this->assertSame([], $this->installed);
        $this->assertSame(0, $this->repairCalls);
      }
    }
  }

  /**
   * Initialization cannot be inferred merely from absent markers.
   */
  public function testInitialPermissionsRequireExplicitIntent(): void {
    $this->expectExceptionMessage('explicit permission initialization');
    $this->service()->prepare('owned', FALSE, TRUE);
  }

  /**
   * A different install profile must never receive Mark-a-Spot repairs.
   */
  public function testOtherProfileIsRejected(): void {
    $this->profile = 'standard';
    $this->expectExceptionMessage('expected Mark-a-Spot site identity');
    $this->service()->prepare('owned', TRUE, TRUE);
  }

  /**
   * A missing source is detected in read-only planning.
   */
  public function testPreviewRejectsMissingShippedPageConfig(): void {
    unlink($this->fixtureRoot . '/group/config/optional/field.field.node.page.field_jurisdiction.yml');
    $this->expectExceptionMessage('page-scoping configuration is unavailable');
    $this->service()->prepare('owned', TRUE);
  }

  /**
   * Invalid providers are rejected before a forensic started marker is written.
   */
  public function testMissingDashboardProviderStopsBeforeMutation(): void {
    file_put_contents($this->fixtureRoot . '/profile/config/optional/user.role.contractor.yml', Yaml::encode(['permissions' => ['unknown capability']]));
    try {
      $this->service()->prepare('owned', TRUE, TRUE);
      $this->fail('Unavailable provider must fail preflight.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('permission definitions', $error->getMessage());
      $this->assertSame([], $this->markers);
      $this->assertSame([], $this->installed);
    }
  }

  /**
   * Lock contention never starts mutations or releases another owner's lock.
   */
  public function testLockContentionIsSafe(): void {
    $this->lockAvailable = FALSE;
    try {
      $this->service()->prepare('owned', TRUE, TRUE);
      $this->fail('Concurrent setup must be rejected.');
    }
    catch (\RuntimeException) {
      $this->assertSame([], $this->markers);
      $this->assertSame([], $this->installed);
      $this->assertSame(0, $this->releases);
    }
  }

  /**
   * The CLI returns structured rows and keeps apply opt-in.
   */
  public function testCommandDefaultsToPreviewAndPreservesContract(): void {
    $command = new TenantSetupCommands($this->service());
    $this->assertSame(1, iterator_to_array($command->status())[0]['contract_version']);
    $result = iterator_to_array($command->prepare(['expected-site-uuid' => 'owned', 'initialize-permissions' => TRUE]));
    $this->assertFalse($result[0]['applied']);
    $this->assertSame([], $this->markers);
    $this->expectExceptionMessage('--expected-site-uuid');
    $command->prepare();
  }

}
