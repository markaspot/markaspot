<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verifies the route table contains a sane number of routes.
 *
 * A nearly-empty route table is a signature of a broken routing rebuild,
 * typically caused by a controller class throwing during route discovery
 * (the discovery layer swallows exceptions and ends up registering only a
 * partial table). Mark-a-Spot stock has ~250 routes; the threshold of 100
 * leaves plenty of headroom for a stripped-down install while still
 * catching catastrophic discovery failures.
 *
 * @SmokeCheck(
 *   id = "route_table_fresh",
 *   label = @Translation("Route table count"),
 *   severity = "error",
 *   category = "drupal_internal",
 *   description = @Translation("Asserts the route provider returns more than the threshold of 100 routes."),
 *   fix_hint = @Translation("Run drush cr; if the count stays low, search the watchdog for routing.builder errors."),
 * )
 */
class RouteTableFreshCheck extends SmokeCheckPluginBase {

  /**
   * Minimum acceptable number of routes for a Mark-a-Spot install.
   */
  protected const THRESHOLD = 100;

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected RouteProviderInterface $routeProvider,
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
      $container->get('router.route_provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);

    $iterator = $this->routeProvider->getAllRoutes();
    $count = is_countable($iterator) ? count($iterator) : iterator_count($iterator);
    $evidence = [
      'route_count' => $count,
      'threshold' => self::THRESHOLD,
    ];

    if ($count > self::THRESHOLD) {
      return $this->pass(sprintf('Route table holds %d routes (> %d).', $count, self::THRESHOLD), $evidence, $mode);
    }
    return $this->fail(
      1,
      sprintf('Route table holds only %d routes, expected > %d.', $count, self::THRESHOLD),
      $evidence,
      $mode,
    );
  }

}
