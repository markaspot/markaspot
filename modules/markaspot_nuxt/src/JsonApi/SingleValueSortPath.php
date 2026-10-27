<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\JsonApi;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Recognizes dotted sorts with at most one distinct value per entity/language.
 */
final class SingleValueSortPath {

  /**
   * Checks one scalar property or one single-valued entity-reference hop.
   */
  public static function isSafe(string $entity_type, array $sort, EntityFieldManagerInterface $fields): bool {
    $parts = explode('.', (string) ($sort['field'] ?? ''));
    $definitions = $fields->getFieldStorageDefinitions($entity_type);
    $definition = $definitions[array_shift($parts)] ?? NULL;
    if (!$definition instanceof FieldStorageDefinitionInterface
      || $definition->isMultiple() || $definition->hasCustomStorage()) {
      return FALSE;
    }

    if ($definition->getType() === 'entity_reference') {
      $target_type = $definition->getSetting('target_type');
      if (!is_string($target_type) || $target_type === '') {
        return FALSE;
      }
      // JSON:API resolves relationship paths to "entity:<target type>"; a
      // hand-written entity query uses plain "entity". Do not broaden the
      // guard for arbitrary reference properties.
      $hop = array_shift($parts);
      if (($hop !== 'entity' && $hop !== 'entity:' . $target_type) || count($parts) < 1 || count($parts) > 2) {
        return FALSE;
      }
      if ($definition->isTranslatable() && empty($sort['langcode'])) {
        return FALSE;
      }
      $target_definitions = $fields->getFieldStorageDefinitions($target_type);
      $definition = $target_definitions[array_shift($parts)] ?? NULL;
    }

    if (!$definition instanceof FieldStorageDefinitionInterface
      || $definition->isMultiple() || $definition->hasCustomStorage()
      || ($definition->isTranslatable() && empty($sort['langcode']))
      || count($parts) > 1) {
      return FALSE;
    }
    $property = $parts[0] ?? $definition->getMainPropertyName();
    $data_type = $definition->getPropertyDefinitions()[$property] ?? NULL;
    return $data_type !== NULL && in_array($data_type->getDataType(), [
      'string', 'integer', 'float', 'boolean', 'timestamp', 'datetime_iso8601',
    ], TRUE);
  }

}
