<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Returns a receipt when a form-only submitter cannot read the created report.
 */
final class FormOnlySubmissionReceiptSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly WorkspaceVisibilityInterface $visibility,
  ) {}

  /**
   * Reduces the successful JSON:API creation response after serialization.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $response = $event->getResponse();
    if (!$event->isMainRequest() || !$request->isMethod('POST')
      || $request->attributes->get('_route') !== 'jsonapi.node--service_request.collection.post'
      || $response->getStatusCode() !== 201) {
      return;
    }

    $document = json_decode((string) $response->getContent(), TRUE);
    $uuid = $document['data']['id'] ?? NULL;
    if (!is_string($uuid) || $uuid === '') {
      $this->rejectUnverifiableResponse($response);
      return;
    }
    // Read the server's response UUID, never a client-supplied report lookup.
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);
    $node = reset($nodes);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
      $this->rejectUnverifiableResponse($response);
      return;
    }
    $jurisdiction_ids = \_markaspot_group_workspace_visibility_jurisdiction_ids($node);
    $organisation_ids = $node->hasField('field_organisation')
      ? array_map('intval', array_column($node->get('field_organisation')->getValue(), 'target_id')) : [];
    $receipt_only = FALSE;
    foreach ($jurisdiction_ids as $jurisdiction_id) {
      if ($this->visibility->getVisibility($jurisdiction_id) === 'form_only'
        && !$this->visibility->allowsReportReadFor($this->currentUser, $jurisdiction_id, $organisation_ids)) {
        $receipt_only = TRUE;
        break;
      }
    }
    if (!$receipt_only) {
      return;
    }

    $attributes = [];
    if ($node->hasField('request_id')) {
      $attributes['request_id'] = $node->get('request_id')->value;
    }
    if ($node->hasField('field_add_data')) {
      $attributes['field_add_data'] = (bool) $node->get('field_add_data')->value;
    }
    $response->setContent(json_encode([
      'data' => [
        'id' => $uuid,
        'type' => 'node--service_request',
        'attributes' => (object) $attributes,
      ],
    ], JSON_THROW_ON_ERROR));
    $this->makePrivate($response);
  }

  /**
   * Fails closed when serialization cannot be tied to the persisted report.
   */
  private function rejectUnverifiableResponse(Response $response): void {
    $response->setStatusCode(500);
    $response->setContent(json_encode(['errors' => [[
      'status' => '500',
      'title' => 'The submission receipt could not be verified.',
    ]]], JSON_THROW_ON_ERROR));
    $this->makePrivate($response);
  }

  /**
   * Prevents report links and response validators from escaping the receipt.
   */
  private function makePrivate(Response $response): void {
    foreach (['Location', 'Link', 'ETag', 'Last-Modified'] as $header) {
      $response->headers->remove($header);
    }
    $response->headers->set('Cache-Control', 'private, no-store');
    if ($response instanceof CacheableResponseInterface) {
      $response->getCacheableMetadata()->setCacheMaxAge(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // JSON:API serializes at 128. Strip includes before response caches run.
    return [KernelEvents::RESPONSE => ['onResponse', 100]];
  }

}
