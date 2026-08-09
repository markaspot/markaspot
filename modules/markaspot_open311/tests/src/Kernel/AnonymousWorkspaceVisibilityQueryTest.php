<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/markaspot_open311.module';

/**
 * Tests SQL exclusion for multi-value workspace assignments.
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class AnonymousWorkspaceVisibilityQueryTest extends KernelTestBase {

  use ContentTypeCreationTrait {
    createContentType as drupalCreateContentType;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
  ];

  /**
   * Public jurisdiction target ID.
   */
  private int $publicJurisdictionId;

  /**
   * Restricted jurisdiction target ID.
   */
  private int $restrictedJurisdictionId;

  /**
   * Request node IDs under test.
   *
   * @var int[]
   */
  private array $requestIds;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'user']);
    $this->drupalCreateContentType(['type' => 'service_request']);

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Jurisdiction',
    ])->save();

    $this->publicJurisdictionId = $this->createNode('Public jurisdiction');
    $this->restrictedJurisdictionId = $this->createNode('Restricted jurisdiction');
    $this->requestIds = [
      $this->createNode('Unassigned request'),
      $this->createNode('Public request', [$this->publicJurisdictionId]),
      $this->createNode('Restricted request', [$this->restrictedJurisdictionId]),
      $this->createNode('Mixed request', [
        $this->publicJurisdictionId,
        $this->restrictedJurisdictionId,
      ]),
    ];
  }

  /**
   * Off-platform keeps unassigned and public-only requests.
   */
  public function testOffPlatformAntiSubqueryExcludesAnyRestrictedTarget(): void {
    $query = $this->baseQuery();
    markaspot_open311_query_markaspot_open311_workspace_visibility_alter($query);

    $this->assertSame(array_slice($this->requestIds, 0, 2), array_map(
      'intval',
      $query->execute()->fetchCol(),
    ));
  }

  /**
   * Self-service positive scope still rejects a mixed restricted target.
   */
  public function testSelfServicePositiveScopeAlsoExcludesMixedTarget(): void {
    $query = $this->baseQuery();
    [$fieldTable, $targetColumn] = $this->jurisdictionStorageMapping();
    $query->innerJoin(
      $fieldTable,
      'visible_workspace',
      '[base_table].[nid] = [visible_workspace].[entity_id]',
    );
    $query->condition(
      'visible_workspace.' . $targetColumn,
      [$this->publicJurisdictionId],
      'IN',
    );
    markaspot_open311_query_markaspot_open311_workspace_visibility_alter($query);

    $this->assertSame([$this->requestIds[1]], array_map(
      'intval',
      $query->execute()->fetchCol(),
    ));
  }

  /**
   * A direct ID lookup cannot return a restricted request.
   */
  public function testDirectIdLookupExcludesRestrictedRequest(): void {
    $query = $this->baseQuery();
    $query->condition('base_table.nid', $this->requestIds[2]);
    markaspot_open311_query_markaspot_open311_workspace_visibility_alter($query);

    $this->assertSame([], $query->execute()->fetchCol());
  }

  /**
   * A missing mapped field table fails closed instead of throwing.
   */
  public function testMissingMappedFieldTableFailsClosed(): void {
    [$fieldTable] = $this->jurisdictionStorageMapping();
    $missingTable = $fieldTable . '_temporarily_missing';
    $schema = $this->container->get('database')->schema();
    $schema->renameTable($fieldTable, $missingTable);

    try {
      $query = $this->baseQuery();
      markaspot_open311_query_markaspot_open311_workspace_visibility_alter($query);
      $this->assertSame([], $query->execute()->fetchCol());
    }
    finally {
      $schema->renameTable($missingTable, $fieldTable);
    }
  }

  /**
   * A missing physical target column fails closed instead of throwing.
   */
  public function testMissingMappedTargetColumnFailsClosed(): void {
    [$fieldTable, $targetColumn] = $this->jurisdictionStorageMapping();
    $schema = $this->container->get('database')->schema();
    $schema->dropField($fieldTable, $targetColumn);

    try {
      $query = $this->baseQuery();
      markaspot_open311_query_markaspot_open311_workspace_visibility_alter($query);
      $this->assertSame([], $query->execute()->fetchCol());
    }
    finally {
      $schema->addField($fieldTable, $targetColumn, [
        'type' => 'int',
        'unsigned' => TRUE,
        'not null' => FALSE,
      ]);
    }
  }

  /**
   * Builds a tagged-query equivalent with one restricted workspace.
   */
  private function baseQuery(): object {
    $query = $this->container->get('database')
      ->select('node', 'base_table');
    $query->addField('base_table', 'nid');
    $query->addMetaData('entity_type', 'node');
    $query->addMetaData(
      'markaspot_open311_restricted_jurisdiction_ids',
      [$this->restrictedJurisdictionId],
    );
    $query->condition('base_table.nid', $this->requestIds, 'IN');
    $query->orderBy('base_table.nid');
    return $query;
  }

  /**
   * Creates a service request node with optional jurisdiction targets.
   *
   * @param string $title
   *   Node title.
   * @param int[] $jurisdictionIds
   *   Optional jurisdiction target IDs.
   */
  private function createNode(string $title, array $jurisdictionIds = []): int {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $title,
      'status' => 1,
      'uid' => 0,
      'field_jurisdiction' => array_map(
        static fn(int $id): array => ['target_id' => $id],
        $jurisdictionIds,
      ),
    ]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Resolves the real Field API table and target column.
   *
   * @return string[]
   *   Field table and target column.
   */
  private function jurisdictionStorageMapping(): array {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('node');
    $mapping = $storage->getTableMapping();
    $definition = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('node')['field_jurisdiction'];
    return [
      $mapping->getFieldTableName('field_jurisdiction'),
      $mapping->getFieldColumnName($definition, 'target_id'),
    ];
  }

}
