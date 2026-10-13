<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai_route_test;

use Drupal\Component\Serialization\Yaml;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides the markaspot_ai routes exactly as declared in its routing file.
 *
 * Kernel tests cannot enable markaspot_ai without its whole dependency graph
 * (open311, nuxt, jsonapi_extras). This callback hands the real route
 * definitions to the router builder, which then compiles access checks and
 * parameter converters the same way it does for an enabled module.
 */
final class AiRoutes {

  /**
   * Builds the route collection from markaspot_ai.routing.yml.
   */
  public static function routes(): RouteCollection {
    $path = \Drupal::service('extension.list.module')->getPath('markaspot_ai');
    $definitions = Yaml::decode((string) file_get_contents($path . '/markaspot_ai.routing.yml'));
    $collection = new RouteCollection();
    foreach ($definitions as $name => $info) {
      if ($name === 'route_callbacks' || !isset($info['path'])) {
        continue;
      }
      $collection->add($name, new Route(
        $info['path'],
        $info['defaults'] ?? [],
        $info['requirements'] ?? [],
        $info['options'] ?? [],
        $info['host'] ?? '',
        $info['schemes'] ?? [],
        $info['methods'] ?? [],
        $info['condition'] ?? '',
      ));
    }
    return $collection;
  }

}
