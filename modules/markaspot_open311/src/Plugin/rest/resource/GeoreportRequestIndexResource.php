<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_request_index_resource",
 *   label = @Translation("Georeport requests index"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/requests",
 *     "https://www.drupal.org/link-relations/create" = "/georeport/v2/requests",
 *     "defaults"  = {"_format": "json"},
 *   }
 * )
 */
class GeoreportRequestIndexResource extends ResourceBase {
  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The devel.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

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
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->config = $config->get('markaspot_open311.settings');
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
      $container->get('current_user'),
      $container->get('config.factory')
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
        case 'POST':
          $georeport_formats = array('json', 'xml', 'form');
          foreach ($georeport_formats as $format) {
            $format_route = clone $route;

            $format_route->setPath($create_path . '.' . $format);
            $format_route->setRequirement('_access_rest_csrf', 'FALSE');

            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.
            $format_route->addRequirements(array('_content_type_format' => implode('|', $this->serializerFormats)));
            $collection->add("$route_name.$method.$format", $format_route);
          }
          break;

        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format) {
            $georeport_formats = array('json', 'xml');
            foreach ($georeport_formats as $format) {

              // Expose one route per available format.
              $format_route = clone $route;
              // Create path with format.name.
              $format_route->setPath($format_route->getPath() . '.' . $format);
              $collection->add("$route_name.$method.$format", $format_route);
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
   * @param string $canonical_path
   * @param string $method
   *
   * @return \Symfony\Component\Routing\Route
   */
  protected function getBaseRoute($canonical_path, $method) {
    $lower_method = strtolower($method);

    $route = new Route($canonical_path, array(
      '_controller' => 'Drupal\markaspot_open311\GeoreportRequestHandler::handle',
      // Pass the resource plugin ID along as default property.
      '_plugin' => $this->pluginId,
    ), array(
      '_permission' => "restful $lower_method $this->pluginId",
    ),
      array(),
      '',
      array(),
      // The HTTP method is a requirement for this route.
      array($method)
    );
    return $route;
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

    /*
     * todo: Check if permission check is needed

    $permission = 'access GET georeport resource';
    if(!$this->currentUser->hasPermission($permission)) {
    throw new AccessDeniedHttpException
    ("Unauthorized can't proceed with create_request.");
    }
     */
    $parameters = UrlHelper::filterQueryParameters(\Drupal::request()->query->all());

    // Filtering the configured content type.
    $bundle = $this->config->get('bundle');
    $bundle = (isset($bundle)) ? $bundle : 'service_request';
    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('changed', REQUEST_TIME, '<')
      ->condition('type', $bundle);

    $query->sort('changed', 'desc');

    // Checking for a limit parameter:
    if (isset($parameters['key'])) {
      $is_admin = ($parameters['key'] == \Drupal::state()
        ->get('system.cron_key'));
    }

    // Handle limit parameters for user one and other users.
    $limit = (isset($is_admin)) ? NULL : 25;
    $query_limit = (isset($parameters['limit'])) ? $parameters['limit'] : NULL;
    $limit = (isset($query_limit) && $query_limit <= 50) ? $query_limit : $limit;

    if (isset($parameters['nids'])) {
      $nids = explode(',', $parameters['nids']);
      $query->condition('nid', $nids, 'IN');
      $limit = $this->config->get('limit-nids');
    }

    if ($limit) {
      $query->pager($limit);
    }

    // Process params to Drupal.
    $map = new GeoreportProcessor();

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['service_code'])) {
      // Get the service of the current node:
      $tid = $map->serviceMapTax($parameters['service_code']);
      $query->condition('field_category.entity.tid', $tid);
    }

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['id'])) {
      // Get the service of the current node:
      $query->condition('uuid', $parameters['id']);
    }

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['q'])) {
      // Get the service of the current node:
      $group = $query->orConditionGroup()
        ->condition('uuid', '%' . $parameters['q'] . '%', 'LIKE')
        ->condition('body', '%' . $parameters['q'] . '%', 'LIKE')
        ->condition('title', '%' . $parameters['q'] . '%', 'LIKE');

      $query->condition($group);
    }

    // start_date param or travel back to 1970.
    $start_timestamp = (isset($parameters['start_date']) && $parameters['start_date'] != '') ? strtotime($parameters['start_date']) : strtotime('01-01-1970');
    $query->condition('created', $start_timestamp, '>=');

    // End_date param or create a timestamp now:
    $end_timestamp = (isset($parameters['end_date']) && $parameters['end_date'] != '') ? strtotime($parameters['end_date']) : time();
    $query->condition('created', $end_timestamp, '<=');
    $query->sort('created', $direction = 'DESC');

    // Checking for status-parameter and map the code with taxonomy terms:
    if (isset($parameters['status'])) {
      // Get the service of the current node:
      $tid = $map->statusMapTax($parameters['status']);
      // var_dump($tids);
      $query->condition('field_status.entity.tid', $tid);
    }

    $nids = $query->execute();
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($nids);
    // Extensions.
    $extended_role = NULL;

    if (isset($parameters['extensions'])) {

      $extended_role = 'anonymous';

      if ($this->currentUser->hasPermission('access open311 extension')) {
        $extended_role = 'user';
      }
      if ($this->currentUser->hasPermission('access open311 advanced properties')) {
        $extended_role = 'manager';
      }
    }

    // Building requests array.
    $uuid = \Drupal::moduleHandler()->moduleExists('markaspot_uuid');

    foreach ($nodes as $node) {
      $service_requests[] = $map->nodeMapRequest($node, $extended_role, $uuid);
    }
    if (!empty($service_requests)) {
      $response = new ResourceResponse($service_requests, 200);
      $response->addCacheableDependency($service_requests);

      return $response;
    }
    else {
      throw new HttpException(404, "No Service requests found");
    }
  }

  /**
   * Responds to POST requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($request_data) {
    try {

      $map = new GeoreportProcessor();
      $values = $map->requestMapNode($request_data);

      $node = \Drupal::entityTypeManager()->getStorage('node')->create($values);

      // Make sure it's a content entity.
      if ($node instanceof ContentEntityInterface) {
        if ($this->validate($node)) {
          // Add an intitial paragraph on valid post.
          $status_open = array_values($this->config->get('status_open_start'));
          // todo: put this in config.
          $status_note_initial = t('The service request has been created.');

          $paragraph = Paragraph::create([
            'type' => 'status',
            'field_status_note' => array(
              "value"  => $status_note_initial,
              "format" => "full_html",
            ),
            'field_status_term' => array(
              "target_id"  => $status_open[0],
            ),
          ]);
          $paragraph->save();
        }

      }

      $node->field_status_notes = array(
        array(
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ),
      );

      // Save the node and prepare response;.
      $node->save();
      // Get the UUID to put it into the response.
      $uuid = $node->uuid();

      $service_request = [];
      if (isset($node)) {
        $service_request['service_requests']['request']['service_request_id'] = $uuid;
      }

      $this->logger->notice('Created entity %type with ID %uuid.', array(
        '%type' => $node->getEntityTypeId(),
        '%uuid' => $node->uuid(),
      ));

      // 201 Created responses return the newly created entity in the response.
      // $url = $entity->urlInfo('canonical', [
      //  'absolute' => TRUE])->toString(TRUE);
      $response = new ResourceResponse($service_request, 201);
      $response->addCacheableDependency($service_request);

      // Responses after creating an entity are not cacheable, so we add no
      // cacheability metadata here.
      return $response;
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }

  }

  /**
   * Verifies that the whole entity does not violate any validation constraints.
   * Those are defined in markaspot_validate module.
   */
  protected function validate($node) {
    $violations = NULL;
    // Remove violations of inaccessible fields as they cannot stem from our
    // changes.
    /*
    if (!\Drupal::service('email.validator')->isValid($request_data['email'])){
    $this->processsServicesError('E-mail not valid', 400);
    }
     */
    $violations = $node->validate();
    if (count($violations) > 0) {
      $message = '';
      foreach ($violations as $violation) {
        $message .= $violation->getMessage() . '\n';
      }
      throw new HttpException(400, $message, $e);

      // Throw new BadRequestHttpException($message);
    }
    else {
      return TRUE;
    }
  }

}
