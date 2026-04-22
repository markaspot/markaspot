<?php

namespace Drupal\markaspot_validation\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects validation violation causes into JSON:API error responses.
 *
 * Core's JSON:API serializer refuses to load normalizers from outside its
 * own namespace (see Drupal\jsonapi\Serializer\Serializer::__construct),
 * so custom constraints cannot ship their own error normalizer. This
 * subscriber works around that sealing by post-processing the rendered
 * 422 response: it reads structured payloads stashed by validators on the
 * request attribute bag and merges them into matching error objects as
 * JSON:API `meta`, keyed by `source.pointer`.
 *
 * The transport format on the request attribute `markaspot_validation_causes`
 * is `[property_path => array_of_scalars_or_strings]`. Validators set it
 * via ConstraintValidator helpers; this subscriber consumes and clears it.
 */
class ViolationCauseResponseSubscriber implements EventSubscriberInterface {

  /**
   * Request attribute key used to transport violation causes.
   */
  const ATTR_KEY = 'markaspot_validation_causes';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority low enough to run after JSON:API has rendered the response.
    return [
      KernelEvents::RESPONSE => ['onKernelResponse', -128],
    ];
  }

  /**
   * Merge stashed causes into the JSON:API 422 error body.
   */
  public function onKernelResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $causes = $request->attributes->get(self::ATTR_KEY);
    // Consume the stash unconditionally — before any early exit — so the
    // attribute never survives on the request object regardless of which
    // code path returns. Matches the comment below.
    $request->attributes->remove(self::ATTR_KEY);

    // Only mutate the top-level response the client actually receives.
    if (!$event->isMainRequest()) {
      return;
    }

    if (empty($causes) || !is_array($causes)) {
      return;
    }

    $response = $event->getResponse();
    if ($response->getStatusCode() !== 422) {
      return;
    }

    $content_type = (string) $response->headers->get('Content-Type', '');
    if (!str_contains($content_type, 'application/vnd.api+json')) {
      return;
    }

    $body = $response->getContent();
    if ($body === '' || $body === FALSE) {
      return;
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded) || empty($decoded['errors']) || !is_array($decoded['errors'])) {
      return;
    }

    $modified = FALSE;
    foreach ($decoded['errors'] as &$error) {
      if (!is_array($error)) {
        continue;
      }
      $pointer = $error['source']['pointer'] ?? NULL;
      if (!$pointer) {
        continue;
      }
      // Pointer shape: /data/attributes/<field_name>[/...].
      if (!preg_match('#^/data/attributes/([^/]+)#', $pointer, $m)) {
        continue;
      }
      $field = $m[1];
      if (!isset($causes[$field])) {
        continue;
      }
      $existing_meta = isset($error['meta']) && is_array($error['meta']) ? $error['meta'] : [];
      $error['meta'] = $causes[$field] + $existing_meta;
      $modified = TRUE;
    }
    unset($error);

    if (!$modified) {
      return;
    }

    $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === FALSE) {
      // Re-encoding failed (e.g. invalid UTF-8 merged in). Leave the
      // original response untouched rather than wiping a valid 422 body.
      return;
    }
    $response->setContent($encoded);
  }

}
