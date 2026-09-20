<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\user\RoleInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_tenant_import\Exception\TenantImportValidationException;
use Drupal\user\UserInterface;

/**
 * Bootstraps one permanently operated dedicated jurisdiction, without SaaS.
 */
final class TenantBootstrapper {

  /**
   * Constructs the bootstrap service.
   */
  public function __construct(
    private readonly TenantImporter $importer,
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LockBackendInterface $lock,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $modules,
    private readonly FileRepositoryInterface $files,
    private readonly FileSystemInterface $fileSystem,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Validates a fresh installation or resumes only this tool's sole root.
   */
  public function bootstrap(array $configuration, ?string $assetsDirectory = NULL, bool $apply = FALSE): array {
    $errors = $this->importer->validate($configuration);
    if ($errors !== []) {
      throw new TenantImportValidationException($errors);
    }
    $tenant = $configuration['tenant'];
    $dedicatedSettings = TenantRuntimeConfiguration::dedicatedSettings($tenant);
    $fachadminPermissions = [];
    if (isset($tenant['features']['aiProcessing'])) {
      $fachadminPermissions['use markaspot ai assist'] = $tenant['features']['aiProcessing'];
    }
    if (isset($tenant['ai']['sentiment_analysis'])) {
      $fachadminPermissions['view ai sentiment'] = $tenant['ai']['sentiment_analysis'];
    }
    if ((isset($dedicatedSettings['markaspot_ai.settings']) || in_array(TRUE, $fachadminPermissions, TRUE)) && !$this->modules->moduleExists('markaspot_ai')) {
      throw new \RuntimeException('Install markaspot_ai before configuring dedicated AI analysis.');
    }
    if (isset($tenant['features']['operationsDashboard'])) {
      if ($tenant['features']['operationsDashboard'] && !$this->modules->moduleExists('markaspot_dashboard')) {
        throw new \RuntimeException('Install markaspot_dashboard before enabling dashboard analytics.');
      }
      $fachadminPermissions['access dashboard kpis'] = $tenant['features']['operationsDashboard'];
    }
    if (($tenant['ai']['pii_provider'] ?? NULL) === 'local_nlp' && ($tenant['ai']['detect_names'] ?? FALSE) === TRUE && !$this->configFactory->get('markaspot_ai.settings')->get('nlp_service.enabled')) {
      throw new \RuntimeException('Local name detection requires an enabled NLP service in the deployment configuration.');
    }
    if (($tenant['features']['publicReports'] ?? TRUE) === FALSE) {
      throw new \RuntimeException('publicReports=false cannot bootstrap a dedicated site without a separately verified access policy.');
    }
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $tenant['slug'])) {
      throw new \RuntimeException('Bootstrap requires a lowercase URL-safe tenant.slug.');
    }
    if (Settings::get('markaspot_operating_mode') !== 'self_hosted' || $this->modules->moduleExists('markaspot_fastmap')) {
      throw new \RuntimeException('Dedicated bootstrap requires explicit self_hosted mode and no installed FastMap module.');
    }
    if (empty($tenant['map_center']) || !isset($tenant['map_zoom'])) {
      throw new \RuntimeException('Dedicated bootstrap requires explicit map_center and map_zoom; no default city or geocoding is used.');
    }
    $missingLanguages = array_diff($tenant['languages'] ?? [], array_keys($this->languageManager->getLanguages()));
    if ($missingLanguages !== []) {
      throw new \RuntimeException('Install requested Drupal languages before bootstrap: ' . implode(', ', $missingLanguages));
    }
    $assets = [
      'light' => TenantLogoAsset::read($tenant['logo_file'] ?? '', $assetsDirectory),
      'dark' => TenantLogoAsset::read($tenant['logo_dark_file'] ?? '', $assetsDirectory),
    ];
    $assets = array_filter($assets, static fn(?array $asset): bool => $asset !== NULL);
    $lockName = 'markaspot_tenant_import.bootstrap';
    if ($apply && !$this->lock->acquire($lockName, 3600.0)) {
      throw new \RuntimeException('Another dedicated bootstrap is running.');
    }
    $newFileUris = [];
    $transaction = NULL;
    try {
      $storage = $this->entities->getStorage('group');
      $storage->resetCache();
      $roots = $storage->loadByProperties(['type' => 'jur']);
      $ownership = $this->keyValue->get('markaspot_tenant_import.bootstrap')->get('root');
      $group = $roots ? reset($roots) : NULL;
      if ($roots !== [] && (count($roots) !== 1 || !$group instanceof GroupInterface || !is_array($ownership) || $ownership['uuid'] !== $group->uuid() || $ownership['slug'] !== $tenant['slug'] || $group->get('field_slug')->getString() !== $tenant['slug'])) {
        throw new \RuntimeException('Existing jurisdictions are not owned by this dedicated bootstrap. Refusing adoption.');
      }
      if ($roots === [] && $storage->getQuery()->accessCheck(FALSE)->count()->execute() > 0) {
        throw new \RuntimeException('Fresh dedicated bootstrap requires no existing groups.');
      }
      if ($roots === [] && $ownership !== NULL) {
        throw new \RuntimeException('Bootstrap ownership exists but its jurisdiction is missing. Refusing recreation.');
      }
      if (!$group instanceof GroupInterface) {
        $group = $storage->create([
          'type' => 'jur',
          'label' => $tenant['label'],
          'field_slug' => $tenant['slug'],
        ]);
      }
      $requiredFields = [
        'field_slug', 'field_nuxt_config', 'field_service_categories', 'field_service_statuses',
        ...($assets === [] ? [] : ['field_logo_light', 'field_logo_dark']),
      ];
      foreach ($requiredFields as $field) {
        if (!$group->hasField($field)) {
          throw new \RuntimeException("Fresh installation is missing required jurisdiction field $field.");
        }
      }
      if ($group->hasField('field_parent_jurisdiction') && !$group->get('field_parent_jurisdiction')->isEmpty()) {
        throw new \RuntimeException('Dedicated jurisdiction must be a root.');
      }
      $apiOwner = $this->apiOwner();
      $new = $group->isNew();
      $validationConfig = $this->configFactory->get('markaspot_validation.settings');
      $validationSource = new FileStorage(DRUPAL_ROOT . '/' . $this->modules->getModule('markaspot_validation')->getPath() . '/config/install');
      $validationPlan = TenantValidationDefaults::plan(
        $validationConfig->getRawData(),
        $validationSource->read('markaspot_validation.settings') ?: [],
        (bool) $this->entities->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute(),
        ($ownership['validation_defaults_initialized'] ?? FALSE) === TRUE,
      );
      $result = [
        'jurisdiction_id' => $new ? NULL : (int) $group->id(),
        'action' => $new ? 'create' : 'resume',
        'applied' => $apply,
        'warnings' => TenantRuntimeConfiguration::warnings($tenant),
        'rows' => [],
        'validation_defaults' => $validationPlan,
        'dedicated_settings' => $dedicatedSettings,
        'fachadmin_permissions' => $fachadminPermissions,
      ];
      // These options are applied below only after single-root ownership has
      // been verified. Generic imports report them as informational instead.
      $result['warnings'] = array_values(array_filter($result['warnings'], static fn(string $warning): bool => !str_starts_with($warning, 'tenant.ai ') && !str_starts_with($warning, 'tenant.boilerplates ')));
      if ($validationPlan['clear'] !== [] && (!$group->hasField('field_boundary') || $group->get('field_boundary')->isEmpty())) {
        $result['warnings'][] = 'Packaged example geography will be removed. Without a jurisdiction boundary, report locations are not geographically restricted.';
      }
      if (!$apply) {
        if (!$new) {
          $result['rows'] = $this->importer->import($configuration, (int) $group->id())['rows'];
        }
        else {
          $result['warnings'][] = 'Fresh-root preview validates input and prerequisites; entity-dependent import checks run transactionally during apply.';
        }
        return $result;
      }
      $transaction = $this->database->startTransaction();
      if ($new) {
        $group->save();
      }
      $result['jurisdiction_id'] = (int) $group->id();
      $import = $this->importer->import($configuration, (int) $group->id(), [], TRUE);
      if ($import['errors'] !== []) {
        throw new \RuntimeException('Tenant import failed: ' . implode(' | ', $import['errors']));
      }
      // Reload selected taxonomy and configuration after importer saves.
      $storage->resetCache([$group->id()]);
      $group = $storage->load($group->id());
      if (!$group instanceof GroupInterface) {
        throw new \RuntimeException('Imported jurisdiction could not be reloaded.');
      }
      if ($group->label() !== $tenant['label']) {
        $group->set('label', $tenant['label'])->save();
      }
      $membership = GroupMembership::loadSingle($group, $apiOwner);
      if (!$membership) {
        $group->addMember($apiOwner, ['group_roles' => ['jur-member']]);
      }
      elseif (array_column($membership->get('group_roles')->getValue(), 'target_id') !== ['jur-member']) {
        $membership->set('group_roles', ['jur-member'])->save();
      }
      $darkFollowsLight = $assets !== [] && ($ownership['logo_dark_explicit'] ?? FALSE) !== TRUE
        && ($group->get('field_logo_dark')->isEmpty()
        || $group->get('field_logo_dark')->target_id === $group->get('field_logo_light')->target_id);
      foreach ($assets as $theme => $asset) {
        $directory = 'public://jurisdiction/bootstrap/' . $group->uuid();
        $uri = $directory . '/' . $asset['hash'] . '.png';
        $existingFiles = $this->entities->getStorage('file')->loadByProperties(['uri' => $uri]);
        $file = $existingFiles ? reset($existingFiles) : NULL;
        if (!$file instanceof FileInterface) {
          if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
            throw new \RuntimeException('Logo directory could not be prepared.');
          }
          // writeData writes bytes before saving the file entity. Track new
          // paths first so an entity-save failure cannot leave orphan bytes.
          // Never claim an existing file or dangling symlink for cleanup.
          $resolvedDirectory = $this->fileSystem->realpath($directory);
          $destination = $resolvedDirectory === FALSE ? $uri : $resolvedDirectory . '/' . $asset['hash'] . '.png';
          // Local stream wrappers may hide dangling links in url_stat().
          if (is_link($destination)) {
            throw new \RuntimeException('Logo destination must not be a symbolic link.');
          }
          if (!file_exists($uri)) {
            $newFileUris[] = $uri;
          }
          $file = $this->files->writeData($asset['bytes'], $uri, FileExists::Error);
        }
        elseif (!is_file($uri) || hash_file('sha256', $uri) !== $asset['hash']) {
          throw new \RuntimeException('Stored logo asset does not match its expected content hash.');
        }
        $file->setPermanent();
        $file->save();
        $group->set('field_logo_' . $theme, ['target_id' => $file->id()]);
        // Preserve the existing light-only fallback, but never replace an
        // independent dark variant unless an explicit dark asset is supplied.
        if ($theme === 'light' && $darkFollowsLight && !isset($assets['dark'])) {
          $group->set('field_logo_dark', ['target_id' => $file->id()]);
        }
      }
      if ($assets !== []) {
        $group->save();
      }
      if ($validationPlan['clear'] !== []) {
        // This is a dedicated, empty, owned installation. Never inherit the
        // distribution's example city as the new municipality's boundary.
        $this->configFactory->getEditable('markaspot_validation.settings')
          ->set('wkt', '')
          ->set('locality', [])
          ->save();
      }
      foreach ($dedicatedSettings as $name => $values) {
        $config = $this->configFactory->getEditable($name);
        foreach ($values as $key => $value) {
          $config->set($key, $value);
        }
        $config->save();
      }
      if ($fachadminPermissions !== []) {
        $role = $this->entities->getStorage('user_role')->load('tenant_admin');
        if (!$role instanceof RoleInterface) {
          throw new \RuntimeException('Dedicated permission configuration requires the canonical tenant_admin role.');
        }
        foreach ($fachadminPermissions as $permission => $enabled) {
          if ($enabled) {
            $role->grantPermission($permission);
          }
          else {
            $role->revokePermission($permission);
          }
        }
        $role->save();
        foreach ($fachadminPermissions as $permission => $enabled) {
          if ($role->hasPermission($permission) !== $enabled) {
            throw new \RuntimeException('Fachadmin permission configuration did not persist: ' . $permission);
          }
        }
      }
      if (isset($tenant['boilerplates']) || $this->keyValue->get('markaspot_tenant_import.bootstrap')->get('boilerplates:' . $group->uuid(), []) !== []) {
        $result['boilerplates'] = TenantBoilerplates::apply(
          $tenant['boilerplates'] ?? [],
          $group,
          $this->entities,
          $this->keyValue->get('markaspot_tenant_import.bootstrap'),
          $tenant['languages'][0] ?? 'en',
        );
      }
      $this->keyValue->get('markaspot_tenant_import.bootstrap')->set('root', [
        'uuid' => $group->uuid(),
        'slug' => $tenant['slug'],
        'validation_defaults_initialized' => TRUE,
        'logo_dark_explicit' => isset($assets['dark']) || ($ownership['logo_dark_explicit'] ?? FALSE) === TRUE,
      ]);
      $result['rows'] = array_values(array_filter($import['rows'], static fn(array $row): bool => !($row['entity'] === 'runtime'
        && in_array($row['key'], ['logo_file', 'logo_dark_file'], TRUE))));
      unset($transaction);
      return $result;
    }
    catch (\Throwable $exception) {
      if ($transaction !== NULL) {
        $transaction->rollBack();
      }
      foreach (array_unique($newFileUris) as $uri) {
        $this->fileSystem->delete($uri);
      }
      foreach (['group', 'taxonomy_term', 'user', 'user_role', 'group_relationship', 'file'] as $type) {
        $this->entities->getStorage($type)->resetCache();
      }
      $this->configFactory->reset('markaspot_validation.settings');
      foreach (array_keys($dedicatedSettings) as $name) {
        $this->configFactory->reset($name);
      }
      throw $exception;
    }
    finally {
      if ($apply) {
        $this->lock->release($lockName);
      }
    }
  }

  /**
   * Requires the profile-created API owner, without printing or changing keys.
   */
  private function apiOwner(): UserInterface {
    $apiKey = $this->configFactory->get('services_api_key_auth.api_key.nuxt');
    $key = $apiKey->get('key');
    if (!is_string($key) || strlen($key) < 32 || $key === '*') {
      throw new \RuntimeException('GeoReport key must be configured from the secret store before bootstrap.');
    }
    $uuid = $apiKey->get('user_uuid');
    $users = $uuid ? $this->entities->getStorage('user')->loadByProperties(['uuid' => $uuid]) : [];
    $user = $users ? reset($users) : NULL;
    $allowedRoles = ['authenticated', 'api_user'];
    if (!$user instanceof UserInterface || !$user->isActive() || !$user->hasRole('api_user') || (int) $user->id() === 1 || array_diff($user->getRoles(), $allowedRoles) !== []) {
      throw new \RuntimeException('GeoReport key must already belong to an active, unprivileged api_user created by the profile installation.');
    }
    return $user;
  }

}
