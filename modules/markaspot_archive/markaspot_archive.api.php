<?php

/**
 * @file
 * Hooks provided by the Mark-a-Spot Archive module.
 */

declare(strict_types=1);

use Drupal\Core\Entity\EntityInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Acts after a report's personal data was anonymized.
 *
 * Invoked by the archive anonymisation (queue worker and drush command) after
 * the anonymized entity was saved, and by the GDPR erasure form. The report
 * itself is kept, so hook_entity_delete() does not run: purge data derived
 * from the report's original content here.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The anonymized report.
 */
function hook_markaspot_report_anonymized(EntityInterface $entity): void {
  \Drupal::database()->delete('mymodule_derived_text')
    ->condition('entity_id', $entity->id())
    ->execute();
}

/**
 * @} End of "addtogroup hooks".
 */
