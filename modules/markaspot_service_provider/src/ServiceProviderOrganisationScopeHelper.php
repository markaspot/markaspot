<?php

declare(strict_types=1);

namespace Drupal\markaspot_service_provider;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Matches service providers against service request organisation scope.
 */
final class ServiceProviderOrganisationScopeHelper {

  /**
   * Checks whether a provider is available for a service request.
   */
  public static function providerMatchesRequest(TermInterface $provider, NodeInterface $request): bool {
    if (!$provider->hasField('field_organisation') || $provider->get('field_organisation')->isEmpty()) {
      return TRUE;
    }

    $provider_org_ids = self::targetIds($provider->get('field_organisation'));
    if ($provider_org_ids === []) {
      return TRUE;
    }

    $request_org_ids = $request->hasField('field_organisation')
      ? self::targetIds($request->get('field_organisation'))
      : [];

    return $request_org_ids !== [] && array_intersect($provider_org_ids, $request_org_ids) !== [];
  }

  /**
   * Checks whether a service-provider boilerplate is available for a request.
   */
  public static function boilerplateMatchesRequest(NodeInterface $boilerplate, NodeInterface $request): bool {
    if ($boilerplate->bundle() !== 'boilerplate') {
      return FALSE;
    }

    if (!$boilerplate->hasField('field_boilerplate_type')) {
      return FALSE;
    }

    $type_values = $boilerplate->get('field_boilerplate_type')->getValue();
    $type = (string) ($type_values[0]['value'] ?? '');
    if ($type !== 'service_provider') {
      return FALSE;
    }

    if ($boilerplate->hasField('field_jurisdiction') && !$boilerplate->get('field_jurisdiction')->isEmpty()) {
      $boilerplate_jur_ids = self::targetIds($boilerplate->get('field_jurisdiction'));
      $request_jur_ids = $request->hasField('field_jurisdiction')
        ? self::targetIds($request->get('field_jurisdiction'))
        : [];

      if ($request_jur_ids === [] || array_intersect($boilerplate_jur_ids, $request_jur_ids) === []) {
        return FALSE;
      }
    }

    if ($boilerplate->hasField('field_organisation') && !$boilerplate->get('field_organisation')->isEmpty()) {
      $boilerplate_org_ids = self::targetIds($boilerplate->get('field_organisation'));
      $request_org_ids = $request->hasField('field_organisation')
        ? self::targetIds($request->get('field_organisation'))
        : [];

      return $request_org_ids !== [] && array_intersect($boilerplate_org_ids, $request_org_ids) !== [];
    }

    return TRUE;
  }

  /**
   * Extracts positive entity reference target IDs.
   */
  public static function targetIds(FieldItemListInterface $field): array {
    $ids = [];
    foreach ($field->getValue() as $item) {
      $target_id = isset($item['target_id']) ? (int) $item['target_id'] : 0;
      if ($target_id > 0) {
        $ids[] = $target_id;
      }
    }

    return array_values(array_unique($ids));
  }

}
