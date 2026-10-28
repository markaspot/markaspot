<?php

declare(strict_types=1);

namespace Drupal\markaspot_request_index;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\node\NodeInterface;

/**
 * Maps a service request node to its request index record.
 *
 * This is the only place that knows the mapping. It reads node-side facts
 * only: field_organisation is the authority for organisations, never the
 * Group relation, and contact fields (e-mail, phone, names) never enter the
 * row or search_text.
 */
final class RequestIndexProjector {

  /**
   * The projected node bundle.
   */
  public const BUNDLE = 'service_request';

  /**
   * Varchar columns and their maximum length in the schema.
   */
  public const STRING_LENGTHS = [
    'service_code' => 64,
    'open311_mapping' => 32,
    'request_id' => 64,
    'address_line1' => 255,
    'postal_code' => 255,
    'locality' => 255,
    'facility_id' => 128,
    'source' => 255,
    'sentiment' => 255,
  ];

  /**
   * Nullable columns: column => [source field, property, integer column].
   *
   * Each stays NULL when the tenant's bundle lacks the source field.
   */
  public const OPTIONAL_COLUMNS = [
    'district_tid' => ['field_district', 'target_id', TRUE],
    'sublocality_tid' => ['field_sublocality', 'target_id', TRUE],
    'facility_id' => ['field_facility', 'value', FALSE],
    'source' => ['field_source', 'value', FALSE],
    'assignee_uid' => ['field_assignee', 'target_id', TRUE],
    'hazard_level' => ['field_hazard_level', 'value', TRUE],
    'sentiment' => ['field_sentiment', 'value', FALSE],
  ];

  /**
   * Field names of the service_request bundle, keyed by name.
   *
   * @var array<string, true>|null
   */
  private ?array $fieldNames = NULL;

  /**
   * Term field values keyed by "tid:field".
   *
   * @var array<string, string>
   */
  private array $termValues = [];

  /**
   * Constructs the projector.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {}

  /**
   * Checks whether an entity is projected at all.
   */
  public function applies(EntityInterface $entity): bool {
    return $entity instanceof NodeInterface && $entity->bundle() === self::BUNDLE;
  }

  /**
   * Checks whether the tenant's service_request bundle has a field.
   */
  public function hasSourceField(string $field): bool {
    $this->fieldNames ??= array_fill_keys(
      array_keys($this->entityFieldManager->getFieldDefinitions('node', self::BUNDLE)),
      TRUE,
    );
    return isset($this->fieldNames[$field]);
  }

  /**
   * Forgets memoized field definitions and term values.
   */
  public function reset(): void {
    $this->fieldNames = NULL;
    $this->termValues = [];
  }

  /**
   * Builds the record of a service request from its default translation.
   */
  public function build(NodeInterface $node): RequestIndexRecord {
    $node = $node->getUntranslated();
    $jurisdictions = $this->targetIds($node, 'field_jurisdiction');
    $jurGid = $this->mostSpecificJurisdiction($jurisdictions);
    $statusTid = $this->targetIds($node, 'field_status')[0] ?? 0;
    $categoryTid = $this->targetIds($node, 'field_category')[0] ?? 0;
    $address = $this->firstItem($node, 'field_address');

    $facts = [
      'nid' => (int) $node->id(),
      'uid' => (int) $node->getOwnerId(),
      'published' => $node->isPublished(),
      'jurisdictions' => $jurisdictions,
      'organisations' => $this->targetIds($node, 'field_organisation'),
      'jur_gid' => $jurGid,
      'root_gid' => $jurGid > 0 ? ($this->hierarchyResolver->getRootJurisdictionId($jurGid) ?? 0) : 0,
      'status_tid' => $statusTid,
      'category_tid' => $categoryTid,
      'service_code' => $this->termValue($categoryTid, 'field_service_code'),
      'open311_mapping' => $this->termValue($statusTid, 'field_open311_mapping'),
      'created' => (int) $node->getCreatedTime(),
      'changed' => (int) $node->getChangedTime(),
      'title' => (string) $node->getTitle(),
      'request_id' => (string) ($this->firstItem($node, 'request_id')['value'] ?? ''),
      'address_line1' => (string) ($address['address_line1'] ?? ''),
      'postal_code' => (string) ($address['postal_code'] ?? ''),
      'locality' => (string) ($address['locality'] ?? ''),
    ];
    foreach (self::OPTIONAL_COLUMNS as $column => [$field, $property]) {
      $facts[$column] = $this->firstItem($node, $field)[$property] ?? NULL;
    }

    return self::compose($facts);
  }

  /**
   * Composes a record from plain facts; pure, no entity or database access.
   *
   * @param array<string, mixed> $facts
   *   Facts as collected by build(): nid, uid, published, jurisdictions,
   *   organisations, jur_gid, root_gid, status_tid, category_tid,
   *   service_code, open311_mapping, created, changed, title, request_id,
   *   address_line1, postal_code, locality and the optional columns.
   */
  public static function compose(array $facts): RequestIndexRecord {
    $organisations = self::distinctIds($facts['organisations'] ?? []);
    $jurisdictions = self::distinctIds($facts['jurisdictions'] ?? []);

    $row = [
      'uid' => (int) ($facts['uid'] ?? 0),
      'published' => empty($facts['published']) ? 0 : 1,
      'jur_gid' => (int) ($facts['jur_gid'] ?? ($jurisdictions[0] ?? 0)),
      'root_gid' => (int) ($facts['root_gid'] ?? 0),
      'org_gid' => $organisations[0] ?? 0,
      'org_multi' => count($organisations) > 1 ? 1 : 0,
      'jur_multi' => count($jurisdictions) > 1 ? 1 : 0,
      'status_tid' => (int) ($facts['status_tid'] ?? 0),
      'category_tid' => (int) ($facts['category_tid'] ?? 0),
      'created' => (int) ($facts['created'] ?? 0),
      'changed' => (int) ($facts['changed'] ?? 0),
    ];
    foreach (['service_code', 'open311_mapping', 'request_id', 'address_line1', 'postal_code', 'locality'] as $column) {
      $row[$column] = self::text((string) ($facts[$column] ?? ''), $column);
    }
    foreach (self::OPTIONAL_COLUMNS as $column => [, , $integer]) {
      $value = $facts[$column] ?? NULL;
      if ($value === NULL || $value === '') {
        $row[$column] = NULL;
        continue;
      }
      $row[$column] = $integer ? (int) $value : self::text((string) $value, $column);
    }
    // Contact fields are deliberately not part of this list.
    $row['search_text'] = self::searchText([
      (string) ($facts['title'] ?? ''),
      $row['request_id'],
      $row['address_line1'],
      $row['postal_code'],
      $row['locality'],
    ]);

    return new RequestIndexRecord((int) ($facts['nid'] ?? 0), $row, $organisations, $jurisdictions);
  }

  /**
   * Picks the most specific jurisdiction of a request.
   *
   * A value that has another value of the list among its descendants is less
   * specific; ties keep delta order. A single value is returned as is.
   *
   * @param list<int> $jurisdictionIds
   *   Distinct field_jurisdiction group IDs in delta order.
   */
  public function mostSpecificJurisdiction(array $jurisdictionIds): int {
    if ($jurisdictionIds === []) {
      return 0;
    }
    if (count($jurisdictionIds) === 1) {
      return $jurisdictionIds[0];
    }
    foreach ($jurisdictionIds as $candidate) {
      $descendants = array_map('intval', $this->hierarchyResolver->getDescendantIds($candidate));
      $others = array_diff($jurisdictionIds, [$candidate]);
      if (array_intersect($others, $descendants) === []) {
        return $candidate;
      }
    }
    return $jurisdictionIds[0];
  }

  /**
   * Returns distinct positive IDs in their original order.
   *
   * @param array<mixed> $ids
   *   Raw IDs.
   *
   * @return list<int>
   *   Distinct positive integer IDs.
   */
  private static function distinctIds(array $ids): array {
    $distinct = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      if ($id > 0 && !in_array($id, $distinct, TRUE)) {
        $distinct[] = $id;
      }
    }
    return $distinct;
  }

  /**
   * Trims a value to the column length.
   */
  private static function text(string $value, string $column): string {
    return mb_substr(trim($value), 0, self::STRING_LENGTHS[$column]);
  }

  /**
   * Joins the searchable parts into one whitespace-normalised string.
   *
   * @param list<string> $parts
   *   Searchable values.
   */
  private static function searchText(array $parts): string {
    $normalised = [];
    foreach ($parts as $part) {
      $part = trim((string) preg_replace('/\s+/u', ' ', $part));
      if ($part !== '' && !in_array($part, $normalised, TRUE)) {
        $normalised[] = $part;
      }
    }
    return implode(' ', $normalised);
  }

  /**
   * Returns the distinct target IDs of a reference field in delta order.
   *
   * @return list<int>
   *   Target IDs, empty when the bundle lacks the field.
   */
  private function targetIds(NodeInterface $node, string $field): array {
    if (!$this->hasSourceField($field) || !$node->hasField($field)) {
      return [];
    }
    return self::distinctIds(array_column($node->get($field)->getValue(), 'target_id'));
  }

  /**
   * Returns the property values of a field's first item.
   *
   * @return array<string, mixed>
   *   Property values, empty when the field is missing or empty.
   */
  private function firstItem(NodeInterface $node, string $field): array {
    if (!$this->hasSourceField($field) || !$node->hasField($field)) {
      return [];
    }
    $item = $node->get($field)->first();
    return $item === NULL ? [] : (array) $item->getValue();
  }

  /**
   * Returns a single-value field of a taxonomy term, memoized per term.
   */
  private function termValue(int $tid, string $field): string {
    if ($tid <= 0) {
      return '';
    }
    $key = $tid . ':' . $field;
    if (!array_key_exists($key, $this->termValues)) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      $value = '';
      if ($term instanceof FieldableEntityInterface && $term->hasField($field) && !$term->get($field)->isEmpty()) {
        $value = (string) ($term->get($field)->first()?->getValue()['value'] ?? '');
      }
      $this->termValues[$key] = $value;
    }
    return $this->termValues[$key];
  }

}
