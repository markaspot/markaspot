<?php

declare(strict_types=1);

namespace Drupal\markaspot_notification\Plugin\rest\resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\markaspot_notification\NotificationCollector;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides a drain endpoint for ECA side-effect notifications.
 *
 * Returns all pending notifications for a service_request node UUID and clears
 * them from the store (drain semantics). The frontend calls this after a
 * JSON:API PATCH to discover what side effects occurred (mail sent, group
 * assigned, etc.).
 *
 * @RestResource(
 *   id = "mas_notifications",
 *   label = @Translation("MaS ECA Notifications"),
 *   uri_paths = {
 *     "canonical" = "/api/mas-notifications/{uuid}"
 *   }
 * )
 */
class NotificationsResource extends ResourceBase {

  /**
   * The notification collector service.
   *
   * @var \Drupal\markaspot_notification\NotificationCollector
   */
  private NotificationCollector $collector;

  /**
   * Constructs a NotificationsResource.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param array $serializer_formats
   *   Supported serializer formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\markaspot_notification\NotificationCollector $collector
   *   The notification collector service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    NotificationCollector $collector,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->collector = $collector;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('markaspot_notification.collector'),
    );
  }

  /**
   * Drains and returns all pending notifications for a node UUID.
   *
   * @param string $uuid
   *   The service_request node UUID.
   *
   * @return \Drupal\rest\ResourceResponse
   *   JSON response with a 'data' array of notification entries.
   */
  public function get(string $uuid): ResourceResponse {
    $this->logger->debug('Draining notifications for node @uuid', ['@uuid' => $uuid]);
    $notifications = $this->collector->drain($uuid);
    $response = new ResourceResponse(['data' => $notifications]);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

  /**
   * {@inheritdoc}
   *
   * No auto-generated REST permission — access is gated on authentication only.
   */
  public function permissions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Requires authentication. UUID format is enforced via route requirement.
   */
  protected function getBaseRoute($canonical_path, $method): Route {
    $route = parent::getBaseRoute($canonical_path, $method);
    $route->setRequirement('_user_is_logged_in', 'TRUE');
    $route->setRequirement('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
    return $route;
  }

}
