<?php

namespace Drupal\markaspot_ui;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for building the Mark-a-Spot toolbar tray.
 */
class ToolbarBuilder implements TrustedCallbackInterface {

  use StringTranslationTrait;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * The route provider service.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a new ToolbarBuilder.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module extension list service.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider service.
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    ModuleExtensionList $module_list,
    RouteProviderInterface $route_provider
  ) {
    $this->moduleHandler = $module_handler;
    $this->moduleList = $module_list;
    $this->routeProvider = $route_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['buildToolbarTray'];
  }

  /**
   * Lazy builder callback to build the toolbar tray.
   *
   * @return array
   *   A render array for the toolbar tray.
   */
  public function buildToolbarTray() {
    $links = $this->getMarkaspotModuleLinks();

    $build = [
      '#theme' => 'links__toolbar_markaspot',
      '#links' => $links,
      '#attributes' => ['class' => ['toolbar-menu']],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['module_list', 'markaspot_ui'],
        'max-age' => 3600, // Cache for 1 hour
      ],
    ];
    
    $cacheable_metadata = new CacheableMetadata();
    $cacheable_metadata->addCacheTags(['module_list', 'markaspot_ui']);
    $cacheable_metadata->addCacheContexts(['user.permissions']);
    $cacheable_metadata->setCacheMaxAge(3600);
    $cacheable_metadata->applyTo($build);
    
    return $build;
  }

  /**
   * Gets links to all available Mark-a-Spot module settings pages.
   *
   * @return array
   *   An array of toolbar links.
   */
  protected function getMarkaspotModuleLinks() {
    $links = [];

    // First, add the main settings link.
    $links['markaspot_settings'] = [
      'title' => $this->t('Settings overview'),
      'url' => Url::fromRoute('markaspot_ui.settings'),
      'attributes' => [
        'class' => ['markaspot-toolbar-item', 'markaspot-settings'],
      ],
    ];

    // Add UI Settings link.
    $links['markaspot_ui_settings'] = [
      'title' => $this->t('Mark-a-Spot UI'),
      'url' => Url::fromRoute('markaspot_ui.settings_form'),
      'attributes' => [
        'class' => ['markaspot-toolbar-item', 'markaspot-ui-settings'],
      ],
    ];

    // Get all installed modules.
    $modules = $this->moduleHandler->getModuleList();
    
    // Additional modules that might have settings but don't follow markaspot_ prefix.
    $additional_modules = [
      'services_api_key_auth' => 'entity.api_key.collection',
    ];
    
    // Check each markaspot_ module for settings pages.
    foreach ($modules as $module_name => $module) {
      // Only process markaspot_ modules.
      if (strpos($module_name, 'markaspot_') === 0) {
        $this->addModuleLink($links, $module_name);
      }
    }
    
    // Add additional related modules.
    foreach ($additional_modules as $module_name => $route_name) {
      if ($this->moduleHandler->moduleExists($module_name)) {
        try {
          // Check if the route exists.
          $this->routeProvider->getRouteByName($route_name);
          
          $info = $this->moduleList->getExtensionInfo($module_name);
          if (!empty($info)) {
            $links[$module_name] = [
              'title' => $this->t('@name', ['@name' => $info['name']]),
              'url' => Url::fromRoute($route_name),
              'attributes' => [
                'title' => !empty($info['description']) ? $this->t('@description', ['@description' => $info['description']]) : '',
                'class' => ['markaspot-toolbar-item', 'markaspot-' . $module_name],
              ],
            ];
          }
        }
        catch (\Exception $e) {
          // Skip if route not found.
        }
      }
    }

    return $links;
  }

  /**
   * Adds a module link to the links array if it has a valid settings route.
   *
   * @param array &$links
   *   The links array to add to.
   * @param string $module_name
   *   The module name.
   */
  protected function addModuleLink(array &$links, $module_name) {
    // Skip the UI module itself as we already added its settings link.
    if ($module_name === 'markaspot_ui') {
      return;
    }
    
    // Common patterns for settings routes.
    $route_patterns = [
      $module_name . '.settings',
      $module_name . '.settings_form',
      $module_name . '.admin',
      $module_name . '.admin_settings',
      $module_name . '.config',
    ];
    
    $info = $this->moduleList->getExtensionInfo($module_name);
    if (empty($info)) {
      return;
    }
    
    // Try to find a valid settings route.
    $route_name = NULL;
    foreach ($route_patterns as $pattern) {
      try {
        $this->routeProvider->getRouteByName($pattern);
        $route_name = $pattern;
        break;
      }
      catch (\Exception $e) {
        // Route not found, try next pattern.
      }
    }
    
    // If we found a valid route, add the link.
    if ($route_name) {
      $links[$module_name] = [
        'title' => $this->t('@name', ['@name' => $info['name']]),
        'url' => Url::fromRoute($route_name),
        'attributes' => [
          'title' => !empty($info['description']) ? $this->t('@description', ['@description' => $info['description']]) : '',
          'class' => ['markaspot-toolbar-item', 'markaspot-' . $module_name],
        ],
      ];
    }
  }
}