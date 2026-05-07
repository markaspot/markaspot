<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\markaspot_nuxt\Annotation\TenantAlert;

/**
 * Plugin manager for Mark-a-Spot tenant alert plugins.
 *
 * Mirrors the markaspot_health plugin manager pattern: plugins live under
 * Plugin/TenantAlert in any module's src tree, are discovered via the.
 *
 * @TenantAlert annotation, and implement TenantAlertInterface.
 */
class TenantAlertPluginManager extends DefaultPluginManager {

  /**
   * Constructs a TenantAlertPluginManager.
   *
   * @param \Traversable $namespaces
   *   Object that implements \Traversable, containing the root paths keyed by
   *   the corresponding namespace.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/TenantAlert',
      $namespaces,
      $module_handler,
      TenantAlertInterface::class,
      TenantAlert::class,
    );

    $this->alterInfo('markaspot_tenant_alert_info');
    $this->setCacheBackend($cache_backend, 'markaspot_tenant_alert_plugins');
  }

}
