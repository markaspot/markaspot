<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;

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

  use StringTranslationTrait;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * The Georeport Processor.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorService
   */
  protected $georeportProcessor;

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
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\markaspot_open311\Service\GeoreportProcessorService $georeport_processor
   *   The processor service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config,
    TranslationInterface $string_translation,
    TimeInterface $time,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
    GeoreportProcessorService $georeport_processor
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->config = $config->get('markaspot_open311.settings');
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->georeportProcessor = $georeport_processor;

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
      $container->get('config.factory'),
      $container->get('string_translation'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_open311.processor')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = new RouteCollection();

    $definition = $this->getPluginDefinition();
    $canonical_path = $definition['uri_paths']['canonical'] ?? '/' . strtr($this->pluginId, ':', '/');
    $create_path = $definition['uri_paths']['https://www.drupal.org/link-relations/create'] ?? '/' . strtr($this->pluginId, ':', '/');
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
    $request_time = $this->time->getRequestTime();

    $parameters = UrlHelper::filterQueryParameters($this->requestStack->getCurrentRequest()->query->all());

    // Filtering the configured content type.
    $bundle = $this->config->get('bundle');
    $bundle = (isset($bundle)) ? $bundle : 'service_request';
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('changed', $request_time, '<')
      ->condition('type', $bundle);

    if (in_array('administrator', $this->currentUser->getRoles()) || $this->currentUser->hasPermission('access open311 advanced properties')) {
      $query->condition('status', [0, 1], 'IN');
    }
    else {
      $query->condition('status', 1);
    }

    // Checking for a limit parameter:
    // Handle limit parameters for user one and other users.
    $limit = (isset($parameters['limit']) && $parameters['limit'] <= 200) ? $parameters['limit'] : 100;
    if (isset($parameters['nids'])) {
      $nids = explode(',', $parameters['nids']);
      $query->condition('nid', $nids, 'IN');
      $limit = NULL;
    }

    if (isset($limit)) {
      $query->pager($limit);
    }

    // Process params to Drupal.
    if (isset($parameters['sort']) && strcasecmp($parameters['sort'], 'DESC') == 0) {
      $sort = 'DESC';
    }
    else {
      $sort = 'ASC';
    }

    if (isset($parameters['updated'])) {
      // $seconds = strtotime($parameters['updated']);
      $query->condition('changed', $request_time - ($request_time - strtotime($parameters['updated'])), '>=');
      $query->sort('changed', 'DESC');
    }
    else {
      $query->sort('created', $sort);
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
    if (isset($parameters['bbox'])) {
      $bbox = explode(',', $parameters['bbox']);
      $minLon = $bbox[0];
      $minLat = $bbox[1];
      $maxLon = $bbox[2];
      $maxLat = $bbox[3];

      $query->condition('field_geolocation.lat', $minLat, '>')
        ->condition('field_geolocation.lat', $maxLat, '<')
        ->condition('field_geolocation.lng', $minLon, '>')
        ->condition('field_geolocation.lng', $maxLon, '<');
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

    if (!isset($parameters['nids']) && (!isset($parameters['updated']))) {
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
      $tids = $this->georeportProcessor->statusMapTax($parameters['status']);
      $or = $query->orConditionGroup();
      foreach ($tids as $tid) {
        $or->condition('field_status', $tid);
      }
      $query->condition($or);
    }
    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['service_code'])) {
      // Get the service of the current node:
      $service_codes = explode(',', $parameters['service_code']);
      $or = $query->orConditionGroup();
      foreach ($service_codes as $service_code) {
        $tid = $this->georeportProcessor->serviceMapTax($service_code);
        $or->condition('field_category', $tid);
      }
      $query->condition($or);
    }

    $debug_query = $query->__toString();

    return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters);
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
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If node has not been saved.
   */
  public function createNode(array $request_data) {

    $values = $this->georeportProcessor->requestMapNode($request_data, 'create');
    $node = $this->entityTypeManager->getStorage('node')
      ->create($values);

    // Make sure it's a content entity.
    if ($node instanceof ContentEntityInterface) {
      $validation = $this->validate($node);
      if ($validation === TRUE) {
        // Add an initial paragraph on valid post.
        $status_open = $this->config->get('status_open_start');
        // @todo put this in config.
        $status_note_initial = $this->t('The service request has been created.');

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

        $node->field_status_notes = [
          [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ],
        ];
        if (isset($node->field_gdpr)) {
          $node->field_gdpr->value = 1;
        }

        // Set the referenced term field.
        $node->field_status = [
          [
            'target_id' => $status_open,
          ],
        ];

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
    }
  }

  /**
   * Verifies that the whole entity does not violate any validation constraints.
   *
   * @param object $node
   *   The node object.
   *
   * @return \http\Exception
   *   return exception.
   */
  protected function validate(object $node) {
    $violations = $node->validate();
    if (count($violations) > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[substr($violation->getPropertyPath(), 6)] = $violation->getMessage();
        $this->logger->error('Node validation error: @message', ['@message' => $violation->getMessage()]);
      }

      // Throw new BadRequestHttpException($message);
      $exception = new GeoreportException();
      $exception->setViolations($violations);
      $exception->setCode(400);
      throw $exception;
      // Return $messages;.
    }
    else {
      return TRUE;
    }
  }

}
