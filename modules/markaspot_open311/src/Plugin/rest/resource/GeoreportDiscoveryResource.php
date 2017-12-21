<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_discovery_resource",
 *   label = @Translation("Georeport service discovery"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/discovery",
 *     "https://www.drupal.org/link-relations/create" = "/georeport/v2/discovery",
 *     "defaults"  = {"_format": "json"},
 *   }
 * )
 */
class GeoreportDiscoveryResource extends ResourceBase {
  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->config = \Drupal::configFactory()
      ->getEditable('markaspot_open311.settings');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('markaspot_open311'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = new RouteCollection();

    $definition = $this->getPluginDefinition();
    $canonical_path = isset($definition['uri_paths']['canonical']) ? $definition['uri_paths']['canonical'] : '/' . strtr($this->pluginId, ':', '/');
    $create_path = isset($definition['uri_paths']['https://www.drupal.org/link-relations/create']) ? $definition['uri_paths']['https://www.drupal.org/link-relations/create'] : '/' . strtr($this->pluginId, ':', '/');
    $route_name = strtr($this->pluginId, ':', '.');

    $methods = $this->availableMethods();
    foreach ($methods as $method) {
      $route = $this->getBaseRoute($canonical_path, $method);
      switch ($method) {
        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format) {
            $georeport_formats = array('json', 'xml');
            foreach ($georeport_formats as $geo_format) {

              // Expose one route per available format.
              $format_route = clone $route;
              // Create path with format.name.
              $format_route->setPath($format_route->getPath() . '.' . $geo_format);
              $collection->add("$route_name.$method.$geo_format", $format_route);
            }

          }
          break;

        default:
          $collection->add("$route_name.$method", $route);
          break;
      }
    }
    return $collection;
  }

  /**
   * Responds to GET requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get() {

    $map = new GeoreportProcessor();
    $discovery = $map->getDiscovery();
    if (!empty($discovery)) {
      $response = new ResourceResponse($discovery, 200);
      $response->addCacheableDependency($discovery);
      return $response;
    }
    else {
      throw new HttpException(404, "Service Discovery not found");
    }
  }

}
