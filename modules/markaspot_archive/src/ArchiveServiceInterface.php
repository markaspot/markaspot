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
   * Anonymizes a node whose status changes to the archived status.
   *
   * Runs on presave, so every way into the archive is covered: the queue
   * worker, a manual status change and Open311 status updates. Entities the
   * worker or the staff command anonymized in this request are skipped.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being saved.
   *
   * @return array<string, array{type: string, value: int|string}>
   *   Anonymized values keyed by field machine name, empty if nothing changed.
   */
  public function anonymizeOnArchiveTransition(NodeInterface $node): array;

  /**
   * Anonymizes previous revisions after an archive transition was saved.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The saved node.
   *
   * @return int
   *   Number of revision field rows updated.
   */
  public function finishArchiveTransition(NodeInterface $node): int;

  /**
   * Anonymizes archived requests that still hold plain contact data.
   *
   * Picks up requests archived before transition anonymization existed or
   * while anonymization was disabled, including plain values left in previous
   * revisions. E-mail, telephone and string fields reveal plain data; the
   * changed time of the requests is kept.
   *
   * @param int $limit
   *   Maximum number of nodes to process.
   * @param int $time_budget
   *   Seconds after which no further node is started.
   *
   * @return int
   *   Number of anonymized nodes.
   */
  public function backfillArchived(int $limit, int $time_budget): int;

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
