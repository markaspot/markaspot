<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Updates a service request's responsible organisations atomically.
 */
final class RequestResponsibilityController extends ControllerBase {

  /**
   * Maximum accepted JSON request body size.
   */
  private const MAX_REQUEST_BODY_BYTES = 8192;

  /**
   * Constructs a RequestResponsibilityController.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $requestConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Replaces the complete responsibility relationship.
   */
  public function update(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'service_request' || !$node->hasField('field_organisation')) {
      return new JsonResponse(['error' => 'Service request not found.'], 404);
    }

    if (!$node->get('field_organisation')->access('edit')) {
      return new JsonResponse(['error' => 'The responsibility may not be updated.'], 403);
    }

    $content_length = $request->headers->get('Content-Length');
    if (is_numeric($content_length) && (int) $content_length > self::MAX_REQUEST_BODY_BYTES) {
      return new JsonResponse(['error' => 'The request body is too large.'], 413);
    }

    $content = $request->getContent();
    if (strlen($content) > self::MAX_REQUEST_BODY_BYTES) {
      return new JsonResponse(['error' => 'The request body is too large.'], 413);
    }

    $payload = json_decode($content, TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'The request body must contain valid JSON.'], 400);
    }
    $organisation_uuids = $payload['organisation_uuids'] ?? NULL;
    if (!is_array($organisation_uuids)) {
      return new JsonResponse(['error' => 'organisation_uuids must be an array.'], 400);
    }

    $organisation_uuids = array_values(array_unique(array_map(
      static fn(mixed $uuid): string => is_string($uuid) ? trim($uuid) : '',
      $organisation_uuids,
    )));
    if (in_array('', $organisation_uuids, TRUE)) {
      return new JsonResponse(['error' => 'Every organisation UUID must be a non-empty string.'], 400);
    }

    $field_definition = $node->getFieldDefinition('field_organisation');
    if ($organisation_uuids === [] && $field_definition->isRequired()) {
      return new JsonResponse(['error' => 'At least one organisation is required.'], 422);
    }

    $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();
    $single_assignment = $this->requestConfigFactory
      ->get('markaspot_group.settings')
      ->get('single_organisation_assignment') === TRUE;
    if (($single_assignment && count($organisation_uuids) > 1)
      || ($cardinality > 0 && count($organisation_uuids) > $cardinality)) {
      return new JsonResponse(['error' => 'Too many organisations were selected.'], 422);
    }

    $groups = \_markaspot_group_request_assignable_organisation_groups($node);
    $groups_by_uuid = [];
    foreach ($groups as $group) {
      if ($group instanceof GroupInterface) {
        $groups_by_uuid[$group->uuid()] = $group;
      }
    }
    if (array_diff($organisation_uuids, array_keys($groups_by_uuid)) !== []) {
      return new JsonResponse(['error' => 'An organisation is missing or invalid.'], 422);
    }

    $organisation_ids = array_map(
      static fn(string $uuid): int => (int) $groups_by_uuid[$uuid]->id(),
      $organisation_uuids,
    );
    sort($organisation_ids, SORT_NUMERIC);

    $node->set('field_organisation', array_map(
      static fn(int $id): array => ['target_id' => $id],
      $organisation_ids,
    ));

    // Some SMTP transports print connection errors directly while node-save
    // hooks dispatch responsibility notifications. That output would corrupt
    // this JSON response even though the entity save succeeded. Keep the API
    // contract atomic from the caller's perspective and log metadata only.
    $buffer_level = ob_get_level();
    ob_start();
    try {
      $node->save();
    }
    catch (EntityStorageException) {
      return new JsonResponse(['error' => 'The responsibility could not be updated.'], 422);
    }
    finally {
      $leaked_output = '';
      while (ob_get_level() > $buffer_level) {
        $leaked_output .= (string) ob_get_clean();
      }
      if ($leaked_output !== '') {
        $this->getLogger('markaspot_group')->warning(
          'Discarded @bytes bytes of transport output while updating responsibility for node @nid.',
          [
            '@bytes' => strlen($leaked_output),
            '@nid' => $node->id(),
          ],
        );
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'organisation_uuids' => $organisation_uuids,
    ]);
  }

}
