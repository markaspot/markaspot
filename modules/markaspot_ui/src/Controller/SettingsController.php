<?php

namespace Drupal\markaspot_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Mark-a-Spot settings overview page.
 */
class SettingsController extends ControllerBase {

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
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a SettingsController object.
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
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('extension.list.module'),
      $container->get('router.route_provider')
    );
  }

  /**
   * Displays an overview of all Mark-a-Spot settings pages.
   *
   * @return array
   *   A render array.
   */
  public function overview() {
    $items = [];
    
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
        $this->addModuleSettingsItem($items, $module_name);
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
            $items[] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['markaspot-setting-item']],
              'icon' => [
                '#type' => 'html_tag',
                '#tag' => 'div',
                '#attributes' => ['class' => ['markaspot-icon', "markaspot-$module_name-icon"]],
                '#value' => '',
              ],
              'content' => [
                '#type' => 'container',
                'title' => [
                  '#type' => 'link',
                  '#title' => $this->t('@name', ['@name' => $info['name']]),
                  '#url' => Url::fromRoute($route_name),
                  '#attributes' => ['class' => ['markaspot-setting-title']],
                ],
                'description' => [
                  '#type' => 'html_tag',
                  '#tag' => 'div',
                  '#value' => $this->t('@description', ['@description' => !empty($info['description']) ? $info['description'] : '']),
                  '#attributes' => ['class' => ['markaspot-setting-description']],
                ],
              ],
            ];
          }
        }
        catch (\Exception $e) {
          // Skip if route not found.
        }
      }
    }

    // Sort items alphabetically by title.
    usort($items, function($a, $b) {
      return strcasecmp($a['content']['title']['#title'], $b['content']['title']['#title']);
    });

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['markaspot-settings-overview']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Mark-a-Spot Settings'),
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Configure the various settings for your Mark-a-Spot installation.'),
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['markaspot-settings-list']],
        'items' => $items,
      ],
      '#attached' => [
        'library' => ['markaspot_ui/settings'],
      ],
    ];

    // Make the render array cacheable and invalidate on module changes.
    $build['#cache'] = [
      'tags' => ['module_list', 'markaspot_ui'],
      'contexts' => ['user.permissions'],
      'max-age' => 3600, // Cache for 1 hour
    ];

    return $build;
  }

  /**
   * Adds a module settings item to the items array if it has a valid settings route.
   *
   * @param array &$items
   *   The items array to add to.
   * @param string $module_name
   *   The module name.
   */
  protected function addModuleSettingsItem(array &$items, $module_name) {
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
        // Skip the overview page route itself to avoid circular reference.
        if ($pattern === 'markaspot_ui.settings') {
          continue;
        }
        $route_name = $pattern;
        break;
      }
      catch (\Exception $e) {
        // Route not found, try next pattern.
      }
    }
    
    // If we found a valid route, add the item.
    if ($route_name) {
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['markaspot-setting-item']],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['markaspot-icon', "markaspot-$module_name-icon"]],
          '#value' => '',
        ],
        'content' => [
          '#type' => 'container',
          'title' => [
            '#type' => 'link',
            '#title' => $this->t('@name', ['@name' => $info['name']]),
            '#url' => Url::fromRoute($route_name),
            '#attributes' => ['class' => ['markaspot-setting-title']],
          ],
          'description' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $this->t('@description', ['@description' => !empty($info['description']) ? $info['description'] : '']),
            '#attributes' => ['class' => ['markaspot-setting-description']],
          ],
        ],
      ];
    }
  }
}