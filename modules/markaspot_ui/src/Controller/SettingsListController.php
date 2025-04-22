<?php

namespace Drupal\markaspot_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
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
    $links = [];
    // Iterate over enabled modules.
    foreach ($this->moduleHandler->getModuleList() as $module => $info) {
      $extension = $this->extensionList->getExtensionInfo($module);
      // Only include modules in the Mark-a-Spot profile path.
      if (empty($extension['path']) || strpos($extension['path'], 'profiles/contrib/markaspot/modules/') !== 0) {
        continue;
      }
      // Try to find a settings route: modulename.settings or modulename.settings_form.
      $route_name = NULL;
      foreach ([$module . '.settings', $module . '.settings_form'] as $candidate) {
        try {
          $route = $this->routeProvider->getDefinition($candidate);
          if ($route->hasDefault('_form')) {
            $route_name = $candidate;
            break;
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }
      if (!$route_name) {
        continue;
      }
      $title = $route->getDefault('_title');
      $links[] = Link::fromTextAndUrl($title, Url::fromRoute($route_name));
    }
    // Sort links by title.
    usort($links, function($a, $b) {
      return strcmp($a->toString(), $b->toString());
    });
    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Mark‑a‑Spot module settings'),
      '#items' => $links,
      '#attributes' => ['class' => ['markaspot-ui-settings-list']],
      '#cache' => ['max-age' => 0],
    ];
  }
}