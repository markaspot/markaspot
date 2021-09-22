<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
   * The markaspot_open311.settings config object.
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config object.
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
          $georeport_formats = ['json', 'xml', 'form'];
          foreach ($georeport_formats as $format) {
            $format_route = clone $route;

            $format_route->setPath($create_path . '.' . $format);
            $format_route->setRequirement('_access_rest_csrf', 'FALSE');

            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.
            $format_route->addRequirements(
              [
                '_content_type_format'
                => implode('|', $this->serializerFormats),
              ]);
            $collection->add("$route_name.$method.$format", $format_route);
          }
          break;

        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format) {
            $georeport_formats = ['json', 'xml'];
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
   * {@inheritdoc}
   */
  protected function getBaseRoute($canonical_path, $method) {
    $lower_method = strtolower($method);
    $route = new Route($canonical_path, [
      '_controller' => 'Drupal\markaspot_open311\GeoreportRequestHandler::handle',
      // Pass the resource plugin ID along as default property.
      '_plugin' => $this->pluginId,
    ], [
      '_permission' => "restful $lower_method $this->pluginId",
    ],
      [],
      '',
      [],
      // The HTTP method is a requirement for this route.
      [$method]
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
    $request_time = \Drupal::time()->getRequestTime();

    $parameters = UrlHelper::filterQueryParameters(\Drupal::request()->query->all());
    // Filtering the configured content type.
    $bundle = $this->config->get('bundle');
    $bundle = (isset($bundle)) ? $bundle : 'service_request';
    $query = \Drupal::entityQuery('node')
      ->condition('changed', $request_time, '<')
      ->condition('type', $bundle);

    if (in_array('administrator', $this->currentUser->getRoles()) || $this->currentUser->hasPermission('access open311 advanced properties')) {
      $query->condition('status', [0, 1], 'IN');
    }
    else {
      $query->condition('status', 1);
    }

    // Checking for a limit parameter:
    if (isset($parameters['key'])) {
      $is_admin = ($parameters['key'] == \Drupal::state()
        ->get('system.cron_key'));
    }

    // Handle limit parameters for user one and other users.
    $limit = (isset($is_admin)) ? NULL : 1000;
    $query_limit = (isset($parameters['limit'])) ? $parameters['limit'] : NULL;
    $limit = (isset($query_limit) && $query_limit <= 1000) ? $query_limit : $limit;

    if (isset($parameters['nids'])) {
      $nids = explode(',', $parameters['nids']);
      $query->condition('nid', $nids, 'IN');
      // $limit = $this->config->get('limit-nids');
      $limit = NULL;
    }

    if (isset($limit)) {
      $query->pager($limit);
    }

    // Process params to Drupal.
    $map = new GeoreportProcessor();

    if (isset($parameters['sort']) && $parameters['sort'] == "desc") {
      $sort = 'DESC';
    }
    else {
      $sort = 'ASC';
    }

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['id'])) {
      // Get the service of the current node:
      $query->condition('request_id', $parameters['id']);
    }

    // Check for field_* arguments.
    $fields = array_filter(
      $parameters,
      function ($key) {
        return(strpos($key, 'field_') !== FALSE);
      },
      ARRAY_FILTER_USE_KEY
    );
    if (isset($fields)) {
      foreach ($fields as $field => $value) {
        $query->condition($field, $value, '=');
      }
    }

    if (isset($parameters['updated'])) {
      $seconds = strtotime($parameters['updated']);
      $query->condition('changed', $request_time - ($request_time - strtotime($parameters['updated'])), '>=');
      $query->sort('changed', $direction = 'DESC');
    }
    else {
      $query->sort('created', $direction = $sort);

    }
    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['q'])) {
      // Get the service of the current node:
      $group = $query->orConditionGroup()
        ->condition('request_id', '%' . $parameters['q'] . '%', 'LIKE')
        ->condition('body', '%' . $parameters['q'] . '%', 'LIKE')
        ->condition('title', '%' . $parameters['q'] . '%', 'LIKE');

      $query->condition($group);
    }

    if (!isset($parameters['nids'])) {
      // start_date param or max 90days.
      $start_timestamp = (isset($parameters['start_date']) && $parameters['start_date'] != '') ? strtotime($parameters['start_date']) : strtotime("- 90days");
      $query->condition('created', $start_timestamp, '>=');

      // End_date param or create a timestamp now:
      $end_timestamp = (isset($parameters['end_date']) && $parameters['end_date'] != '') ? strtotime($parameters['end_date']) : time();
      $query->condition('created', $end_timestamp, '<=');
    }
    $query->accessCheck(FALSE);

    // Checking for status-parameter and map the code with taxonomy terms:
    if (isset($parameters['status'])) {
      // Get the service of the current node:
      $tids = $map->statusMapTax($parameters['status']);
      $or = $query->orConditionGroup();
      foreach ($tids as $tid) {
        $or->condition('field_status', $tid);
      }
      $query->condition($or);
    }
    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['service_code'])) {
      // Get the service of the current node:
      $tids = $map->serviceMapTax($parameters['service_code']);
      $or = $query->orConditionGroup();
      foreach ($tids as $tid) {
        $or->condition('field_category', reset($tid));
      }
      $query->condition($or);
    }

    $map = new GeoreportProcessor();
    return $map->getResults($query, $this->currentUser, $parameters);
  }

  /**
   * Responds to POST requests.
   *
   * Returns request id for created service_request.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($request_data) {
    try {
      // Return result to handler for formatting and response.
      return $this->createNode($request_data);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }

  }

  /**
   * Create Node in Drupal from request data.
   *
   * @param array $request_data
   *   The request data as defined in open311 POST method.
   *
   * @return array
   *   Return the service_request object with the applied service_request_id
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createNode(array $request_data) {
    $map = new GeoreportProcessor();
    $values = $map->requestMapNode($request_data);

    $node = \Drupal::entityTypeManager()->getStorage('node')->create($values);

    // Make sure it's a content entity.
    if ($node instanceof ContentEntityInterface) {
      if ($this->validate($node)) {
        // Add an initial paragraph on valid post.
        $status_open = $this->config->get('status_open_start');
        // @todo put this in config.
        $status_note_initial = t('The service request has been created.');

        $paragraph = Paragraph::create([
          'type' => 'status',
          'field_status_note' => [
            "value"  => $status_note_initial,
            "format" => "full_html",
          ],
          'field_status_term' => [
            "target_id"  => $status_open[0],
          ],
        ]);
        $paragraph->save();
      }

    }

    $node->field_status_notes = [
      [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ],
    ];
    if (isset($node->field_gdpr)) {
      $node->field_gdpr->value = 1;
    }
    // Save the node and prepare response.
    $node->save();

    $this->logger->notice('Created entity %type with ID %request_id.', [
      '%type' => $node->getEntityTypeId(),
      '%request_id' => $node->request_id->value,
    ]);

    // Get the UUID to put it into the response.
    $request_id = $node->request_id->value;

    $service_request = [];
    if (isset($node)) {
      $service_request['service_requests']['request']['service_request_id'] = $request_id;
    }
    return $service_request;
  }

  /**
   * Verifies that the whole entity does not violate any validation constraints.
   *
   * @param object $node
   *   The node object.
   *
   * @return bool
   *   return exception or TRUE if valid.
   */
  protected function validate(object $node) {
    $violations = $node->validate();
    if (count($violations) > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[substr($violation->getPropertyPath(), 6)] = $violation->getMessage();
      }
      $message = json_encode($messages);

      throw new BadRequestHttpException($message);
    }
    else {
      return TRUE;
    }
  }

}
