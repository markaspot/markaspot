<?php

namespace Drupal\markaspot_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for listing settings pages of Mark-a-Spot modules.
 */
class SettingsListController extends ControllerBase {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The extension list service.
   *
   * @var \Drupal\Core\Extension\ExtensionList
   */
  protected $extensionList;

  /**
   * The route provider service.
   *
   * @var \Drupal\Core\Router\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a SettingsListController.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ExtensionList $extension_list
   *   The extension list.
   * @param \Drupal\Core\Router\RouteProviderInterface $route_provider
   *   The route provider.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ExtensionList $extension_list, RouteProviderInterface $route_provider) {
    $this->moduleHandler = $module_handler;
    $this->extensionList = $extension_list;
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
   * Lists the settings pages for all Mark-a-Spot profile modules.
   *
   * @return array
   *   A render array.
   */
  public function listSettings() {
    // Define markaspot module routes.
    $markaspot_modules = [
      'markaspot_map' => 'markaspot_map.settings',
      'markaspot_request_id' => 'markaspot_request_id.settings',
      'markaspot_open311' => 'markaspot_open311.settings',
      'markaspot_validation' => 'markaspot_validation.settings',
      'markaspot_privacy' => 'markaspot_privacy.settings',
      'markaspot_archive' => 'markaspot_archive.settings',
      'markaspot_publisher' => 'markaspot_publisher.settings_form',
      'markaspot_resubmission' => 'markaspot_resubmission.settings',
      'markaspot_feedback' => 'markaspot_feedback.settings',
      'markaspot_vision' => 'markaspot_vision.settings',
      'markaspot_geocoder' => 'markaspot_geocoder.settings',
      'services_api_key_auth' => 'entity.api_key.collection',
    ];

    $links = [];

    // Collect settings links from all enabled Mark-a-Spot modules.
    foreach ($markaspot_modules as $module => $route_name) {
      if ($this->moduleHandler->moduleExists($module)) {
        $info = $this->extensionList->getExtensionInfo($module);
        if (!empty($info)) {
          try {
            // Verify route exists.
            $this->routeProvider->getRouteByName($route_name);

            // Create link.
            $links[] = [
              '#type' => 'link',
              '#title' => $this->t($info['name']),
              '#url' => Url::fromRoute($route_name),
              '#attributes' => [
                'class' => ['admin-item'],
                'title' => !empty($info['description']) ? $this->t($info['description']) : '',
              ],
            ];
          }
          catch (\Exception $e) {
            // Skip routes that don't exist.
          }
        }
      }
    }

    // Add custom modules from the web/modules/custom directory.
    $custom_modules = [
      'markaspot_vision' => 'markaspot_vision.settings',
      'markaspot_geocoder' => 'markaspot_geocoder.settings',
    ];

    foreach ($custom_modules as $module => $route_name) {
      // Only add if not already added and module exists.
      if (!isset($markaspot_modules[$module]) && $this->moduleHandler->moduleExists($module)) {
        $info = $this->extensionList->getExtensionInfo($module);
        if (!empty($info)) {
          try {
            // Verify route exists.
            $this->routeProvider->getRouteByName($route_name);

            // Create link.
            $links[] = [
              '#type' => 'link',
              '#title' => $this->t($info['name']),
              '#url' => Url::fromRoute($route_name),
              '#attributes' => [
                'class' => ['admin-item'],
                'title' => !empty($info['description']) ? $this->t($info['description']) : '',
              ],
            ];
          }
          catch (\Exception $e) {
            // Skip routes that don't exist.
          }
        }
      }
    }

    // Sort links alphabetically by title.
    usort($links, function ($a, $b) {
      return strcasecmp($a['#title'], $b['#title']);
    });

    $build = [
      '#theme' => 'item_list',
      '#title' => $this->t('Mark-a-Spot module settings'),
      '#items' => $links,
      '#attributes' => ['class' => ['admin-list']],
      '#wrapper_attributes' => ['class' => ['container-inline']],
    ];

    // Make the render array cacheable but invalidate on module changes.
    $cache_metadata = new CacheableMetadata();
    $cache_metadata->setCacheTags(['markaspot_ui', 'module_list']);
    // 24 hours
    $cache_metadata->setCacheMaxAge(86400);
    $cache_metadata->applyTo($build);

    return $build;
  }

}
