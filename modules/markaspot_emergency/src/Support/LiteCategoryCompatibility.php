<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Support;

use Drupal\taxonomy\TermInterface;

/**
 * Determines whether a service category can be represented by the Lite form.
 *
 * Lite deliberately omits dynamic, required Open311 service attributes. The
 * same predicate is used by the public status contract and the pinned
 * submission guard so that a client cannot choose a category the Lite form
 * cannot submit completely.
 */
final class LiteCategoryCompatibility {

  /**
   * Checks a category entity's service definition.
   */
  public static function isCompatibleTerm(TermInterface $term): bool {
    if (!$term->hasField('field_service_definition')
      || $term->get('field_service_definition')->isEmpty()) {
      return TRUE;
    }

    return self::isCompatibleDefinition(
      (string) $term->get('field_service_definition')->value,
    );
  }

  /**
   * Checks one stored service-definition value.
   */
  public static function isCompatibleDefinition(string $raw): bool {
    try {
      $decoded = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      // A non-empty invalid definition cannot be represented safely in Lite.
      return FALSE;
    }

    $attributes = is_array($decoded) && array_is_list($decoded)
      ? $decoded
      : ($decoded['attributes'] ?? NULL);
    if (!is_array($attributes)) {
      return FALSE;
    }

    foreach ($attributes as $attribute) {
      if (is_array($attribute)
        && ($attribute['variable'] ?? FALSE) === TRUE
        && ($attribute['required'] ?? FALSE) === TRUE) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
