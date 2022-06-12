<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

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
    $canonical_path = isset($definition['uri_paths']['canonical']) ? $definition['uri_paths']['canonical'] : '/' . strtr($this->pluginId, ':', '/') . '/{id}';
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
  public function get($id) {

    $parameters = UrlHelper::filterQueryParameters(\Drupal::request()->query->all());
    // Filtering the configured content type.
    $bundle = $this->config->get('bundle');
    $bundle = (isset($bundle)) ? $bundle : 'service_request';
    $query = \Drupal::entityQuery('node')
      ->condition('type', $bundle);
    if ($id != "") {
      $query->condition('request_id', $this->getRequestId($id));
    }

    // Checking for service_code and map the code with taxonomy terms:
    if (isset($parameters['id'])) {
      // Get the service of the current node:
      $query->condition('request_id', $parameters['id']);
    }
    $map = new GeoreportProcessor();
    return $map->getResults($query, $this->currentUser, $parameters);
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
        Throw new AccessDeniedHttpException();
      }

      $context = new RenderContext();
      /** @var \Drupal\Core\Cache\CacheableDependencyInterface $result */

      $result = \Drupal::service('renderer')->executeInRenderContext(
        $context, function () use ($id, $request_data) {
          return $this->updateNode($id, $request_data);
        }
      );

      if (!$context->isEmpty()) {
        $bubbleable_metadata = $context->pop();
        BubbleableMetadata::createFromObject($result)
          ->merge($bubbleable_metadata);
      }
      // Return result to handler for formatting and response.
      return $result;

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

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['request_id' => $request_id]);

    $map = new GeoreportProcessor();
    foreach ($nodes as $node) {
      if ($node instanceof ContentEntityInterface) {
        $request_data['service_request_id'] = $request_id;
        $values = $map->requestMapNode($request_data, 'update');
      }
    }
    if (empty($nodes)) {
      throw new NotFoundHttpException('Service-Request not found');
    }
    $revisionLogMessage = isset($values['revision_log_message']) ?? '';
    unset($values['revision_log_message']);



    foreach (array_keys($values) as $field_name) {
      // Status notes need special care as they are paragraphs.
      if ($field_name == 'field_status_note') {
        // Replace status only if new status is set.
        $status = $values['field_status'] ?? $node->get('field_status')
            ->getValue();
        $paragraphData = [$status, $values['field_status_notes']];
        $paragraph = $map->create_paragraph($paragraphData);

        $current = $node->get('field_status_notes')->getValue();
        $current[] = array(
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        );
        $node->set('field_status_notes', $current);
      } else if ($field_name == 'revision_log_message') {
        // Create a revision if set in open311 config
        if ($this->config->get('revisions')) {
          $node->setNewRevision(TRUE);
          // Set data for the revision
          $node->setRevisionLogMessage($revisionLogMessage);
          // $node->setRevisionUserId();
          $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        }
      } else {
        // Set fields the usual way.
        $node->set($field_name, $values[$field_name]);
      }
    }

    if ($this->validate($node)) {
      $node->save();
    }
    // Save the node and prepare response;.
    $request_id = $node->request_id->value;

    $this->logger->notice('Updated node with ID %request_id.', [
      '%request_id' => $node->request_id->value,
    ]);

    $service_request = [];
    if (isset($node)) {
      $service_request['service_requests']['request']['service_request_id'] = $request_id;
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
   * @return bool
   *   return exception or TRUE if valid.
   */
  protected function validate(object $node) {
    $violations = NULL;

    $violations = $node->validate();
    if (count($violations) > 0) {
      $message = "Unprocessable Entity: validation failed.\n";
      foreach ($violations as $violation) {
        // We strip every HTML from the error message to have a nicer to read
        // message on REST responses.
        $message .= $violation->getPropertyPath() . ': ' . PlainTextOutput::renderFromHtml($violation->getMessage()) . "\n";
      }
      throw new BadRequestHttpException($message);
    }
    else {
      return TRUE;
    }
  }

}
