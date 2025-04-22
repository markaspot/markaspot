<?php

namespace Drupal\markaspot_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Routing\RouteProviderInterface;

/**
 * Controller for Mark-a-Spot UI settings list.
 */
class MarkaspotUiController extends ControllerBase {

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
  protected $moduleExtensionList;

  /**
   * The route provider service.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a MarkaspotUiController object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider service.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ModuleExtensionList $module_extension_list, RouteProviderInterface $route_provider) {
    $this->moduleHandler = $module_handler;
    $this->moduleExtensionList = $module_extension_list;
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
   * Generates a list of Mark-a-Spot module settings pages.
   *
   * @return array
   *   A render array containing the Mark-a-Spot settings list.
   */
  public function settingsList() {
    $links = [];
    
    foreach ($this->moduleHandler->getModuleList() as $module => $info) {
      $ext = $this->moduleExtensionList->getExtensionInfo($module);
      if (empty($ext['path']) || strpos($ext['path'], 'profiles/contrib/markaspot/modules/') !== 0) {
        continue;
      }
      
      foreach ([$module . '.settings', $module . '.settings_form'] as $route_name) {
        try {
          $routes = $this->routeProvider->getRoutesByNames([$route_name]);
          if (!empty($routes) && isset($routes[$route_name])) {
            $route = $routes[$route_name];
            $title = $route->getDefault('_title') ?? $module;
            $links[] = Link::fromTextAndUrl($title, Url::fromRoute($route_name));
          }
        } 
        catch (\Exception $e) {
          // Route not found, continue to the next one.
        }
      }
    }
    
    usort($links, function ($a, $b) {
      return strcmp($a->toString(), $b->toString());
    });
    
    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Mark-a-Spot module settings'),
      '#items' => $links,
      '#cache' => ['max-age' => 0],
    ];
  }
}