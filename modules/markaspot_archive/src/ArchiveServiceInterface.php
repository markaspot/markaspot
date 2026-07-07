<?php

namespace Drupal\markaspot_archive;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;

/**
 * Interface for ArchiveService.
 */
interface ArchiveServiceInterface {

  /**
   * Loads service requests that are eligible for archiving.
   *
   * @param bool $all
   *   TRUE to disable category rotation and result limits.
   * @param bool $advance_rotation
   *   TRUE to persist the next category rotation index.
   * @param bool $log
   *   TRUE to write operational notices.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Eligible service request nodes.
   */
  public function load(bool $all = FALSE, bool $advance_rotation = TRUE, bool $log = TRUE): array;

  /**
   * Reads anonymize_fields as a supported associative map or list.
   *
   * @param array $configured_fields
   *   Configured fields keyed by field machine name or stored as list values.
   *
   * @return array<string, string>
   *   Field machine names keyed by field machine name.
   */
  public function normalizeConfiguredFields(array $configured_fields): array;

  /**
   * Anonymizes supported fields on the current entity object.
   *
   * @param \Drupal\Core\Entity\EntityInterface $archivable
   *   The entity to anonymize.
   * @param array $anonymize_fields
   *   Field machine names.
   *
   * @return array<string, array{type: string, value: int|string}>
   *   Anonymized values keyed by field machine name.
   */
  public function anonymize(EntityInterface $archivable, array $anonymize_fields): array;

  /**
   * Updates previous revision field tables with anonymized values.
   *
   * @param \Drupal\Core\Entity\EntityInterface $archivable
   *   The saved entity whose previous revisions should be updated.
   * @param array $anonymized_values
   *   Values returned by ::anonymize().
   *
   * @return int
   *   Number of revision field rows updated.
   */
  public function anonymizeRevisions(EntityInterface $archivable, array $anonymized_values): int;

  /**
   * Returns non-empty supported field names for preview output.
   *
   * @param \Drupal\Core\Entity\EntityInterface $archivable
   *   The entity to inspect.
   * @param array $anonymize_fields
   *   Field machine names.
   *
   * @return string[]
   *   Field names that would be anonymized.
   */
  public function previewAnonymizeFields(EntityInterface $archivable, array $anonymize_fields): array;

  /**
   * Returns the archive retention period for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return array{days: int, source: string}
   *   Retention days and source label.
   */
  public function getArchiveRetention(NodeInterface $node): array;

}
