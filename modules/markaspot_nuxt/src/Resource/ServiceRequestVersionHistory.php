<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeAttribute;
use Drupal\jsonapi_resources\Resource\ResourceBase;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/**
 * Lists the revision history (metadata only) for a service request node.
 *
 * Core JSON:API can serve per-revision FIELD data via the
 * `?resourceVersion=id:{vid}` query parameter, but it has no way to enumerate
 * the available revisions of a resource. That gap is tracked upstream in core
 * issue https://www.drupal.org/project/drupal/issues/3009588 ("Add a
 * version-history link relation / revisions collection to JSON:API"). This
 * custom resource closes the gap with a minimal, standards-shaped collection
 * built on the official jsonapi_resources contrib.
 *
 * Forward-compat: the response is intentionally modeled on the planned core
 * `version-history` link relation. The resource type name, the per-item `id`
 * (the numeric revision id) and the attribute set are chosen so that a future
 * migration to a core-provided endpoint is a routing/serialization swap rather
 * than a contract change for the Nuxt frontend (markaspot-ui#329). When core
 * ships #3009588 this resource can be deleted and the frontend repointed.
 *
 * Security: this endpoint exposes the revision author identity, so it is
 * staff-only. Access is gated on the route via the node revision permissions
 * (`view all revisions` OR `edit any service_request content`), aligned with
 * the staff gate used elsewhere for request author data. Author identity is
 * NEVER leaked to anonymous or reporter accounts, consistent with the
 * revision_uid / revision_log / revision_timestamp hardening applied to the
 * core node--service_request JSON:API resource (markaspot_nuxt_update_11904).
 *
 * @internal
 */
class ServiceRequestVersionHistory extends ResourceBase implements ContainerInjectionInterface {

  /**
   * The JSON:API resource type name for version-history items.
   *
   * Stable on purpose: kept identical to the shape a future core
   * version-history endpoint is expected to use (see class docblock).
   */
  protected const RESOURCE_TYPE_NAME = 'version-history';

  /**
   * Maximum number of (most-recent) revisions returned in a single response.
   *
   * Service requests accumulate a revision per status change / dashboard edit;
   * long-lived requests can exceed this. Rather than silently truncate, the
   * response carries a `truncated` meta flag and the cap is logged.
   */
  protected const MAX_REVISIONS = 50;

  /**
   * Constructs a ServiceRequestVersionHistory resource.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Psr\Log\LoggerInterface $logger
   *   The markaspot_nuxt logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerInterface $logger,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    // New self() rather than new static(): the test-only subclass constructs
    // the resource directly, so it never relies on create(), and self() keeps
    // PHPStan happy about late static binding (new.static).
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('logger.factory')->get('markaspot_nuxt'),
      $container->get('database'),
    );
  }

  /**
   * Processes the version-history request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\node\NodeInterface $entity
   *   The service request node, resolved from its UUID by the {entity} route
   *   parameter (type: entity:node).
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The JSON:API collection response, one item per revision, oldest first.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the resolved node is not a service_request bundle. The
   *   staff-only permission gate is enforced declaratively on the route.
   */
  public function process(Request $request, NodeInterface $entity): ResourceResponse {
    // Defense in depth: the route param accepts any node UUID, so confirm the
    // bundle here. Use AccessDenied (not NotFound) to avoid turning the
    // endpoint into an existence oracle for non-request node ids.
    if ($entity->bundle() !== 'service_request') {
      throw new AccessDeniedHttpException();
    }

    $cacheability = new CacheableMetadata();
    // Recompute when the user, their permissions, or the node (any revision)
    // change. The node's own cache tag covers new revisions because saving a
    // revision invalidates node:{nid}.
    $cacheability->addCacheContexts(['user', 'user.permissions']);
    $cacheability->addCacheableDependency($entity);

    $built = $this->buildRevisionItems($entity);

    $data = new ResourceObjectData($built['items']);

    $meta = ['count' => count($built['items'])];
    if ($built['truncated']) {
      $meta['truncated'] = TRUE;
    }

    $response = $this->createJsonapiResponse($data, $request, 200, [], NULL, $meta);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Builds the per-revision JSON:API resource objects, oldest first.
   *
   * Extracted from process() so the revision enumeration, ordering, attribute
   * mapping and truncation behaviour can be unit-tested without the JSON:API
   * response factory.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The service request node (default revision).
   *
   * @return array{items: \Drupal\jsonapi\JsonApiResource\ResourceObject[], truncated: bool}
   *   The resource objects (sorted oldest -> newest) and whether the result was
   *   capped at MAX_REVISIONS.
   */
  protected function buildRevisionItems(NodeInterface $entity): array {
    // Read revision METADATA in ONE query against the node_revision table,
    // rather than loading each full node revision. Loading a revision pulls
    // every field, paragraph and media reference, so the old per-vid
    // loadRevision() loop was O(n) full entity loads — a 16-revision history
    // took tens of seconds. Only revision metadata is exposed, and the route
    // already gates staff access + node 'view', so a direct read is safe.
    // node_revision rows are returned oldest -> newest by the ASC vid sort.
    $rows = $this->database->select('node_revision', 'nr')
      ->fields('nr', ['vid', 'revision_uid', 'revision_timestamp', 'revision_log'])
      ->condition('nr.nid', $entity->id())
      ->orderBy('nr.vid', 'ASC')
      ->execute()
      ->fetchAll();

    $total = count($rows);
    $truncated = FALSE;
    if ($total > static::MAX_REVISIONS) {
      $truncated = TRUE;
      // Keep the MAX_REVISIONS most-recent, preserving oldest->newest order.
      $rows = array_slice($rows, -static::MAX_REVISIONS);
      // No silent cap: surface it in the operator log so a request with an
      // unusually long history is observable.
      $this->logger->info(
        'Version-history for node @nid truncated: @total revisions, returned the @cap most recent.',
        [
          '@nid' => $entity->id(),
          '@total' => $total,
          '@cap' => static::MAX_REVISIONS,
        ]
      );
    }

    // Resolve author display labels in ONE batch load of the distinct revision
    // users (never the raw account entity, mirroring the revision_uid
    // hardening). uid 0 (system) and deleted users resolve to a null author.
    $author_uids = array_values(array_unique(array_filter(
      array_map(static fn($row): int => (int) $row->revision_uid, $rows)
    )));
    $users = $author_uids !== []
      ? $this->entityTypeManager->getStorage('user')->loadMultiple($author_uids)
      : [];

    // The default revision's vid is the current/default revision pointer.
    $current_vid = (int) $entity->getRevisionId();

    $resource_type = $this->buildResourceType();
    $items = [];
    foreach ($rows as $row) {
      $vid = (int) $row->vid;
      $author_uid = (int) $row->revision_uid;
      $author = isset($users[$author_uid]) ? $users[$author_uid]->label() : NULL;

      $created = $row->revision_timestamp;
      $timestamp = $created !== NULL
        ? $this->dateFormatter->format((int) $created, 'custom', \DateTime::ATOM)
        : NULL;

      $log_message = (string) ($row->revision_log ?? '');

      $fields = [
        'vid' => $vid,
        'author' => $author,
        'author_uid' => $author_uid,
        'timestamp' => $timestamp,
        'log_message' => $log_message,
        'is_current' => $vid === $current_vid,
      ];

      // Each item is keyed by the vid so the JSON:API `id` is stable and
      // unique within the collection, matching the planned core shape.
      $items[] = new ResourceObject(
        new CacheableMetadata(),
        $resource_type,
        (string) $vid,
        NULL,
        $fields,
        new LinkCollection([])
      );
    }

    return ['items' => $items, 'truncated' => $truncated];
  }

  /**
   * Declares the synthetic resource type served by this endpoint.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceType
   *   The single, non-entity-backed resource type used for every item.
   */
  protected function buildResourceType(): ResourceType {
    $fields = [
      'vid' => new ResourceTypeAttribute('vid'),
      'author' => new ResourceTypeAttribute('author'),
      'author_uid' => new ResourceTypeAttribute('author_uid'),
      'timestamp' => new ResourceTypeAttribute('timestamp'),
      'log_message' => new ResourceTypeAttribute('log_message'),
      'is_current' => new ResourceTypeAttribute('is_current'),
    ];

    // Non-internal, non-locatable, non-mutable, non-versionable: it is a
    // read-only synthetic collection, not a routable entity resource.
    $resource_type = new ResourceType(
      static::RESOURCE_TYPE_NAME,
      static::RESOURCE_TYPE_NAME,
      NULL,
      FALSE,
      TRUE,
      FALSE,
      FALSE,
      $fields,
      static::RESOURCE_TYPE_NAME
    );
    $resource_type->setRelatableResourceTypes([]);

    return $resource_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteResourceTypes(Route $route, string $route_name): array {
    return [$this->buildResourceType()];
  }

}
