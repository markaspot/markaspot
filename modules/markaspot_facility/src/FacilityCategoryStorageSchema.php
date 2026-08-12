<?php

declare(strict_types=1);

namespace Drupal\markaspot_facility;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines storage invariants for tenant-scoped facility categories.
 */
final class FacilityCategoryStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();
    $schema[$base_table]['unique keys']['facility_category_tenant_key'] = [
      'jurisdiction_id',
      'machine_name',
    ];

    return $schema;
  }

}
