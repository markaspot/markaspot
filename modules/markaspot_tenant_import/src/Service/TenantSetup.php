<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleInterface;

/**
 * Prepares Drupal prerequisites independently of the deployment provider.
 *
 * This is a trusted operator API, not an HTTP or chat-facing endpoint. The
 * caller owns host/database selection and authorization of first provisioning.
 */
class TenantSetup {

  // Preserve existing installations' markers across the ownership refactor.
  private const COMPLETED = 'markaspot_cloud.permissions_initialized';
  private const STARTED = 'markaspot_cloud.permissions_initialization_started';
  private const PAGE_CONFIG = [
    'field.field.node.page.field_jurisdiction',
    'group.relationship_type.jur-group_node-page',
  ];

  /**
   * Constructs the Drupal setup service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LockBackendInterface $lock,
    private readonly ModuleExtensionList $modules,
    private readonly ProfileExtensionList $profiles,
    private readonly ConfigInstallerInterface $configInstaller,
    private readonly StorageInterface $activeStorage,
    private readonly PermissionHandlerInterface $permissions,
    private readonly string $appRoot,
  ) {}

  /**
   * Reports versioned, read-only installation markers without credentials.
   */
  public function status(): array {
    $this->state->resetCache();
    return [
      'contract_version' => 1,
      'site_uuid' => $this->configFactory->get('system.site')->get('uuid'),
      'permissions_initialized' => $this->state->get(self::COMPLETED),
      'permissions_initialization_started' => $this->state->get(self::STARTED),
    ];
  }

  /**
   * Plans or applies missing prerequisites, without importing municipal data.
   */
  public function prepare(string $expectedSiteUuid, bool $initializePermissions = FALSE, bool $apply = FALSE): array {
    // Serialize with dedicated bootstrap. The caller must additionally exclude
    // other writers (including generic import) for the whole setup operation.
    $lockName = 'markaspot_tenant_import.bootstrap';
    if ($apply && !$this->lock->acquire($lockName, 3600.0)) {
      throw new \RuntimeException('Another dedicated setup operation is running.');
    }
    try {
      $record = $this->status();
      if ($expectedSiteUuid === '' || $record['site_uuid'] !== $expectedSiteUuid || $this->configFactory->get('core.extension')->get('profile') !== 'markaspot') {
        throw new \RuntimeException('Setup requires the expected Mark-a-Spot site identity.');
      }
      $completed = $record['permissions_initialized'];
      if ($record['permissions_initialization_started'] !== NULL || ($completed !== NULL && $completed !== $expectedSiteUuid)) {
        throw new \RuntimeException('Role initialization needs explicit recovery; defaults will not be granted again.');
      }
      if ($completed === NULL && !$initializePermissions) {
        throw new \RuntimeException('First provisioning requires explicit permission initialization.');
      }
      $missing = array_values(array_filter(self::PAGE_CONFIG, fn(string $name): bool => !$this->activeStorage->exists($name)));
      $result = [
        'contract_version' => 1,
        'site_uuid' => $expectedSiteUuid,
        'applied' => FALSE,
        'page_configuration_missing' => $missing,
        'page_configuration_created' => [],
        'permissions_initialized' => $completed === $expectedSiteUuid,
        'schema' => $this->schema()->prepare(),
        'permission_exceptions' => [],
        'permission_action' => $completed === $expectedSiteUuid ? 'already_initialized' : 'initialize',
      ];
      if (!$apply) {
        // Preview validates the shipped prerequisites without granting rights.
        $this->pageSource($missing);
        if ($completed === NULL) {
          $this->roleBaseline($result['permission_exceptions']);
        }
        return $result;
      }
      if ($completed === NULL) {
        $this->roleBaseline($result['permission_exceptions']);
      }
      $this->ensurePageConfiguration($missing);
      $result['schema'] = $this->schema()->prepare(TRUE);
      $this->entityFieldManager->clearCachedFieldDefinitions();
      foreach ($result['schema']['applicable'] as $name) {
        [, , $entityType, $bundle, $field] = explode('.', $name, 5);
        if (!isset($this->entityFieldManager->getFieldDefinitions($entityType, $bundle)[$field])) {
          throw new \RuntimeException('Reporting field definition is unavailable: ' . $name);
        }
      }
      if ($completed === NULL) {
        // A failed helper or postcondition leaves the marker deliberately set.
        // A retry must not silently repeat a partially applied rights repair.
        $this->state->set(self::STARTED, $expectedSiteUuid);
        $this->repairRolePermissions();
        $this->verifyRolePermissions($result['permission_exceptions']);
        $this->state->set(self::COMPLETED, $expectedSiteUuid);
        $this->state->delete(self::STARTED);
        $result['permission_action'] = 'initialized';
      }
      $result['page_configuration_created'] = $missing;
      $result['permissions_initialized'] = TRUE;
      $result['applied'] = TRUE;
      return $result;
    }
    finally {
      if ($apply) {
        $this->lock->release($lockName);
      }
    }
  }

  /**
   * Installs only the two missing shipped page configuration entities.
   */
  private function ensurePageConfiguration(array $missing): void {
    if ($missing !== []) {
      $this->configInstaller->installOptionalConfig($this->pageSource($missing));
    }
    foreach (self::PAGE_CONFIG as $name) {
      if (!$this->activeStorage->exists($name)) {
        throw new \RuntimeException('Page-scoping configuration dependencies are incomplete.');
      }
    }
    $this->entityFieldManager->clearCachedFieldDefinitions();
    if (!isset($this->entityFieldManager->getFieldDefinitions('node', 'page')['field_jurisdiction'])) {
      throw new \RuntimeException('Pages cannot be filtered by jurisdiction.');
    }
  }

  /**
   * Reads the exact missing configuration without installing it.
   */
  private function pageSource(array $missing): MemoryStorage {
    $selected = new MemoryStorage();
    if ($missing === []) {
      return $selected;
    }
    $source = new FileStorage($this->appRoot . '/' . $this->modules->getPath('markaspot_group') . '/config/optional');
    foreach ($missing as $name) {
      $data = $source->read($name);
      if (!is_array($data)) {
        throw new \RuntimeException('Shipped page-scoping configuration is unavailable.');
      }
      $selected->write($name, $data);
    }
    return $selected;
  }

  /**
   * Builds schema sources without enabling optional products.
   */
  protected function schema(): TenantSetupSchema {
    $profilePath = $this->appRoot . '/' . $this->profiles->getPath('markaspot');
    $enabled = array_keys($this->configFactory->get('core.extension')->get('module') ?? []);
    $sources = [
      ['storage' => new FileStorage($profilePath . '/config/install'), 'required' => TRUE],
    ];
    foreach ($enabled as $module) {
      // Retired privacy configuration is never part of the supported schema.
      if ($module === 'markaspot_privacy') {
        continue;
      }
      $path = $this->appRoot . '/' . $this->modules->getPath($module);
      foreach (['install', 'optional'] as $directory) {
        $sources[] = [
          'storage' => new FileStorage($path . '/config/' . $directory),
          'required' => $directory === 'install',
          'required_names' => $module === 'markaspot_group' && $directory === 'optional' ? [
            'field.field.node.service_request.field_jurisdiction',
            'field.field.node.service_request.field_organisation',
            'field.field.node.service_request.field_assignee',
            'field.field.node.service_request.field_assigned_team',
          ] : [],
        ];
      }
    }
    // Reuse the neutral shipped paragraph schema without enabling escalation
    // routing, Fastmap, or any other product merely to obtain its fields.
    $sources[] = [
      'storage' => new FileStorage($profilePath . '/modules/markaspot_status_paragraph/config/install'),
      'required' => TRUE,
      'required_names' => [
        'field.field.paragraph.status.field_author',
        'field.field.paragraph.internal_remark.field_author',
        'field.field.paragraph.internal_remark.field_internal_remark_text',
      ],
    ];
    $sources[] = [
      'storage' => new FileStorage($profilePath . '/config/optional'),
      'required' => FALSE,
      'required_names' => ['field.field.node.service_request.field_internal_remark'],
    ];
    return new TenantSetupSchema($this->activeStorage, $this->configInstaller, $sources, $enabled);
  }

  /**
   * Uses the canonical profile helper, never a second permission baseline.
   */
  protected function repairRolePermissions(): void {
    $path = $this->profiles->getPath('markaspot');
    require_once $this->appRoot . '/' . $path . '/markaspot.install';
    if (!function_exists('_markaspot_repair_all_role_permissions')) {
      throw new \RuntimeException('Profile permission initializer is unavailable.');
    }
    _markaspot_repair_all_role_permissions();
  }

  /**
   * Checks dashboard and citizen capabilities against shipped role config.
   */
  private function verifyRolePermissions(array &$exceptions): void {
    $roles = $this->entityTypeManager->getStorage('user_role');
    $roles->resetCache();
    $definitions = $this->permissions->getPermissions();
    foreach ($this->roleBaseline($exceptions) as $roleId => $requiredPermissions) {
      $role = $roles->load($roleId);
      foreach ($requiredPermissions as $permission) {
        if (!isset($definitions[$permission]) || !$role instanceof RoleInterface || !$role->hasPermission($permission)) {
          throw new \RuntimeException(sprintf('Shipped permission postcondition failed for role %s: %s.', $roleId, $permission));
        }
      }
    }
  }

  /**
   * Validates required roles and enabled providers before starting a repair.
   */
  private function roleBaseline(array &$exceptions = []): array {
    $exceptions = [];
    $definitions = $this->permissions->getPermissions();
    $roles = $this->entityTypeManager->getStorage('user_role');
    $baseline = [];
    $path = $this->appRoot . '/' . $this->profiles->getPath('markaspot');
    foreach (['tenant_admin', 'moderator', 'contractor', 'anonymous', 'authenticated'] as $roleId) {
      $shipped = FALSE;
      foreach (['config/install', 'config/optional'] as $directory) {
        $data = (new FileStorage($path . '/' . $directory))->read('user.role.' . $roleId);
        if ($data !== FALSE) {
          $shipped = $data;
        }
      }
      $role = $roles->load($roleId);
      if (!$role instanceof RoleInterface || !is_array($shipped) || !is_array($shipped['permissions'] ?? NULL)) {
        throw new \RuntimeException('Expected shipped role is unavailable.');
      }
      // Citizen reporting needs the full shipped baseline, including dynamic
      // field permissions. Resolve those definitions after the profile helper
      // refreshes caches, not during the read-only preflight.
      if (in_array($roleId, ['anonymous', 'authenticated'], TRUE)) {
        if ($shipped['permissions'] === []) {
          throw new \RuntimeException('Shipped citizen permission baseline is unavailable.');
        }
        $baseline[$roleId] = [];
        foreach ($shipped['permissions'] as $permission) {
          $exception = $this->permissionException($permission);
          if ($exception !== NULL) {
            $exceptions[$roleId][$permission] = $exception;
          }
          else {
            $baseline[$roleId][] = $permission;
          }
        }
        continue;
      }
      $dashboardPermissions = array_filter($shipped['permissions'], static fn($permission) => ($definitions[$permission]['provider'] ?? NULL) === 'markaspot_dashboard');
      if ($dashboardPermissions === []) {
        throw new \RuntimeException('Shipped dashboard permission definitions are unavailable.');
      }
      $baseline[$roleId] = $dashboardPermissions;
    }
    return $baseline;
  }

  /**
   * Explains exact legacy, optional-provider, and canonical public-field cases.
   *
   * Unregistered permissions outside these cases remain fatal postconditions.
   * In particular, existing custom/private field policies are never relaxed.
   */
  private function permissionException(string $permission): ?string {
    if (preg_match('/^(create|edit|view)( own)? field_gdpr$/', $permission)) {
      return 'Deprecated field_gdpr is excluded from required postconditions; existing legacy fields retain the canonical role initialization policy.';
    }
    foreach (['field_approved' => 'markaspot_confirm', 'field_phone' => 'telephone'] as $field => $provider) {
      if (preg_match('/^(create|edit|view)( own)? ' . $field . '$/', $permission)
        && !$this->activeStorage->exists('field.storage.node.' . $field)
        && !array_key_exists($provider, $this->configFactory->get('core.extension')->get('module') ?? [])) {
        return 'Optional field ' . $field . ' is absent and provider ' . $provider . ' is disabled.';
      }
    }
    if (in_array($permission, ['view field_status_note', 'view own field_status_note'], TRUE)) {
      $name = 'field.storage.paragraph.field_status_note';
      $active = $this->activeStorage->read($name);
      $source = new FileStorage($this->appRoot . '/' . $this->modules->getPath('service_request') . '/config/install');
      $shipped = $source->read($name);
      if (($active['third_party_settings']['field_permissions']['permission_type'] ?? NULL) === 'private') {
        throw new \RuntimeException('Private status-note visibility requires an explicit policy decision before setup.');
      }
      if (($active['third_party_settings']['field_permissions']['permission_type'] ?? NULL) === 'public'
        && ($shipped['third_party_settings']['field_permissions']['permission_type'] ?? NULL) === 'public') {
        return 'Canonical public status-note field; custom view permission is not generated.';
      }
    }
    return NULL;
  }

}
