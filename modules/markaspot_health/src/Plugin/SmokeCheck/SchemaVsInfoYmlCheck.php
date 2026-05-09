<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects modules whose installed schema is behind the bundled .install file.
 *
 * Goes further than HealthCheck/ModuleSchemaDriftCheck which only flags
 * installed === 0. This smoke check also catches installed=8, expected=10:
 * the case where hook_update_N additions were added in a release but the
 * tenant didn't run drush updatedb. That's a different class of drift,
 * and produces hard-to-diagnose downstream symptoms (e.g. ECA workflows
 * referencing fields that were renamed in update 9100).
 *
 * @SmokeCheck(
 *   id = "schema_vs_info_yml",
 *   label = @Translation("Module schema vs .install file"),
 *   severity = "error",
 *   category = "drupal_internal",
 *   description = @Translation("Compares system.schema for each enabled module against the highest hook_update_N declared in <module>.install."),
 *   fix_hint = @Translation("Run drush updatedb -y. For installed=0 cases, see drush markaspot:health:repair-schema --apply."),
 * )
 */
class SchemaVsInfoYmlCheck extends SmokeCheckPluginBase {

  /**
   * Maximum number of offender entries kept in evidence to avoid bloat.
   */
  protected const EVIDENCE_LIMIT = 20;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected UpdateHookRegistry $updateHookRegistry,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('update.update_hook_registry'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);
    $modules = (array) $this->configFactory->get('core.extension')->get('module');
    if ($modules === []) {
      return $this->pass('core.extension lists no modules; check skipped.', [], $mode);
    }

    // getAvailableUpdates() reflects only PHP functions currently loaded;
    // hook_update_N implementations live in <module>.install which Drupal
    // does not require for normal page requests. Force-load every install
    // file once so the registry can see the full update catalog.
    foreach (array_keys($modules) as $module) {
      $this->moduleHandler->loadInclude($module, 'install');
    }

    $offenders = [];
    foreach (array_keys($modules) as $module) {
      $installed = (int) $this->updateHookRegistry->getInstalledVersion($module);
      // getAvailableUpdates() returns the sorted list of hook_update_N
      // version numbers declared by the module. Take max() to get the
      // expected baseline, or 0 when the module declares no updates.
      $availableUpdates = $this->updateHookRegistry->getAvailableUpdates($module);
      $available = $availableUpdates === [] ? 0 : (int) max($availableUpdates);

      // Available === 0 means no hook_update_N was ever declared for this
      // module; a 0/0 pair is normal for fresh modules with no update path.
      if ($available === 0 && $installed === 0) {
        continue;
      }

      if ($installed < $available) {
        $offenders[] = [
          'module' => $module,
          'installed' => $installed,
          'expected' => $available,
        ];
      }
    }

    if ($offenders === []) {
      return $this->pass(sprintf('%d module(s) checked, schema in sync with .install files.', count($modules)), [], $mode);
    }

    $evidence = ['offenders' => array_slice($offenders, 0, self::EVIDENCE_LIMIT)];
    return $this->fail(
      count($offenders),
      sprintf('%d module(s) behind their .install schema baseline.', count($offenders)),
      $evidence,
      $mode,
    );
  }

}
