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
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "georeport_request_resource",
 *   label = @Translation("Georeport request"),
 *   serialization_class = "Drupal\Core\Entity\Entity",
 *   uri_paths = {
 *     "canonical" = "/georeport/v2/requests/{id}",
 *     "https://www.drupal.org/link-relations/create" =
 *   "/georeport/v2/requests/{id}",
 *     "defaults"  = {"_format": "json"},
 *   }
 * )
 */
class GeoreportRequestResource extends ResourceBase {

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
    $canonical_path = $definition['uri_paths']['canonical'] ?? '/' . strtr($this->pluginId, ':', '/') . '/{id}';
    $route_name = strtr($this->pluginId, ':', '.');

    $methods = $this->availableMethods();
    foreach ($methods as $method) {
      $route = $this->getBaseRoute($canonical_path, $method);
      switch ($method) {

        case 'POST':
          foreach ($this->serializerFormats as $format_name) {
            $format_route = clone $route;
            $format_route->setRequirement('_access_rest_csrf', 'FALSE');
            // Restrict the incoming HTTP Content-type header to the known
            // serialization formats.
            $format_route->addRequirements(
              [
                '_content_type_format' =>
                implode('|', $this->serializerFormats),
              ]);
            $collection->add("$route_name.$method.$format_name", $format_route);
          }
          break;

        case 'GET':
          // Restrict GET and HEAD requests to the media type specified in the
          // HTTP Accept headers.
          foreach ($this->serializerFormats as $format_name) {

            // Expose one route per available format.
            $format_route = clone $route;
            $format_route->addOptions(['_format' => $format_name]);
            $collection->add("$route_name.$method.$format_name", $format_route);

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
   * @param string $id
   *   The Service Request ID.
   *
   * @return array
   *   Returns the Service Request matching the ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function get(string $id) {

    $parameters = UrlHelper::filterQueryParameters($this->requestStack->getCurrentRequest()->query->all());
    // Filtering the configured content type.
    $bundle = $this->config->get('bundle');
    $bundle = (isset($bundle)) ? $bundle : 'service_request';
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $bundle);
    if ($id != "") {
      $query->condition('request_id', $this->getRequestId($id));
    }

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['id'])) {
      // Get the service of the current node:
      $query->condition('request_id', $parameters['id']);
    }
    $query->accessCheck(FALSE);

    return $this->georeportProcessor->getResults($query, $this->currentUser, $parameters);
  }

  /**
   * Responds to POST requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($id, $request_data) {

    try {
      if (!$this->currentUser->hasPermission('access open311 advanced properties')) {
        throw new AccessDeniedHttpException();
      }

      // Return result to handler for formatting and response.
      return $this->updateNode($id, $request_data);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }

  }

  /**
   * Update Drupal Node.
   *
   * @param string $id
   *   Node ID (nid) of that node.
   * @param array $request_data
   *   The request data array.
   *
   * @return array
   *   The service request array with id.
   *
   * @throws \Exception
   */
  public function updateNode(string $id, array $request_data): array {
    $request_id = $this->getRequestId($id);

    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['request_id' => $request_id]);

    foreach ($nodes as $node) {
      if ($node instanceof ContentEntityInterface) {
        $request_data['service_request_id'] = $request_id;
        $values = $this->georeportProcessor->requestMapNode($request_data, 'update');
      }
    }
    if (empty($nodes)) {
      throw new NotFoundHttpException('Service-Request not found');
    }
    $revisionLogMessage = isset($values['revision_log_message']) ?? '';
    unset($values['revision_log_message']);

    foreach (array_keys($values) as $field_name) {
      // Status notes need special care as they are paragraphs.
      $field_type = $node->get($field_name)->getFieldDefinition()->getType();
      if ($field_name == 'type') {
        $node->set($field_name, $values[$field_name]);
      }

      elseif ($field_type == 'entity_reference' && $field_name != 'field_request_media') {
        $node->set($field_name, ['target_id' => $values[$field_name]]);
      }
      elseif ($field_name == 'field_status_notes') {
        // Replace status only if new status is set.
        $status = $values['field_status'] ?? $node->get('field_status')
          ->getValue();
        $paragraphData = [$status, $values['field_status_notes']];
        $paragraph = $this->georeportProcessor->createParagraph($paragraphData);

        $current = $node->get('field_status_notes')->getValue();
        $current[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
        $node->set('field_status_notes', $current);
      }
      elseif ($field_name == 'revision_log_message') {
        // Create a revision if set in open311 config.
        if ($this->config->get('revisions')) {
          $node->setNewRevision(TRUE);
          // Set data for the revision.
          $node->setRevisionLogMessage($revisionLogMessage);
          // $node->setRevisionUserId();
          $node->setRevisionCreationTime($this->time->getRequestTime());
        }
      }
      else {
        // Set fields the usual way.
        $node->set($field_name, $values[$field_name]);
      }
    }
    // Validate the node only if the user does not have the 'bypass mas validation' permission
    $validation = TRUE;
    if (!$this->currentUser->hasPermission('bypass mas validation')) {
      $validation = $this->validate($node);
    }

    $service_request = [];

    if ($validation === TRUE) {
      $node->save();
      $this->logger->notice('Updated entity %type with ID %request_id.', [
        '%type' => $node->getEntityTypeId(),
        '%request_id' => $node->request_id->value,
      ]);
      // Save the node and prepare response;.
      $request_id = $node->request_id->value;

      if (isset($node)) {
        $service_request['service_requests']['request']['service_request_id'] = $request_id;
      }
    }
    return $service_request;

  }

  /**
   * Return the service_request_id.
   *
   * @param string $id_param
   *   The first part of service_request_id.format uri.
   *
   * @return string
   *   The Request ID
   */
  public function getRequestId($id_param) {
    $param = explode('.', $id_param);
    $id = $param[0];
    return $id;
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
