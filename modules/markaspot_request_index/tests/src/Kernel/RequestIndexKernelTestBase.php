<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_index\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Shared fixture: a root and a child jurisdiction, two organisations, terms.
 *
 * All names, addresses and contact values are fictional.
 */
abstract class RequestIndexKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'text',
    'filter',
    'address',
    'taxonomy',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_request_index',
  ];

  /**
   * Root jurisdiction.
   */
  protected Group $rootJurisdiction;

  /**
   * Child jurisdiction of the root.
   */
  protected Group $childJurisdiction;

  /**
   * Organisation A in the root jurisdiction.
   */
  protected Group $organisationA;

  /**
   * Organisation B in the root jurisdiction.
   */
  protected Group $organisationB;

  /**
   * Status term mapped to Open311 "open".
   */
  protected Term $statusOpen;

  /**
   * Category term with a service code.
   */
  protected Term $category;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper', 'taxonomy_term'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('markaspot_request_index', [
      'markaspot_request_index',
      'markaspot_request_index_org',
      'markaspot_request_index_jur',
    ]);
    $this->installConfig(['system', 'field', 'node', 'group']);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $root_user = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root_user->save();
    $this->container->get('current_user')->setAccount($root_user);

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Service status'])->save();
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Service category'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur', 'org'] as $group_type) {
      // Group creates the membership type itself.
      if (!$relationship_types->load($group_type . '-group_node-service_request')) {
        $relationship_types->createFromPlugin(GroupType::load($group_type), 'group_node:service_request')->save();
      }
    }
    $this->createFields();

    $this->rootJurisdiction = Group::create(['type' => 'jur', 'label' => 'Fictional root']);
    $this->rootJurisdiction->save();
    $this->childJurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Fictional district',
      'field_parent_jurisdiction' => $this->rootJurisdiction->id(),
    ]);
    $this->childJurisdiction->save();
    $this->statusOpen = Term::create([
      'vid' => 'service_status',
      'name' => 'Received',
      'field_open311_mapping' => 'open',
    ]);
    $this->statusOpen->save();
    $this->category = Term::create([
      'vid' => 'service_category',
      'name' => 'Damaged bench',
      'field_service_code' => 'BENCH-1',
    ]);
    $this->category->save();
    $this->organisationA = Group::create([
      'type' => 'org',
      'label' => 'Road works unit',
      'field_jurisdiction' => $this->rootJurisdiction->id(),
    ]);
    $this->organisationA->save();
    $this->organisationB = Group::create([
      'type' => 'org',
      'label' => 'Parks unit',
      'field_jurisdiction' => $this->rootJurisdiction->id(),
    ]);
    $this->organisationB->save();
  }

  /**
   * Creates a service request with fictional values.
   *
   * @param array<string, mixed> $values
   *   Values overriding the defaults.
   */
  protected function createRequest(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'Bench with a loose slat',
      'status' => 1,
      'uid' => 1,
      'field_jurisdiction' => [$this->childJurisdiction->id()],
      'field_organisation' => [$this->organisationA->id()],
      'field_status' => $this->statusOpen->id(),
      'field_category' => $this->category->id(),
      'field_address' => [
        'country_code' => 'DE',
        'address_line1' => 'Beispielweg 12',
        'postal_code' => '00099',
        'locality' => 'Musterstadt',
      ],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Returns the stored index row of a request, or NULL.
   *
   * @return array<string, mixed>|null
   *   The row.
   */
  protected function row(int $nid): ?array {
    $row = $this->database()->select('markaspot_request_index', 'i')
      ->fields('i')
      ->condition('i.nid', $nid)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Returns the sorted group IDs of a child table for a request.
   *
   * @return list<int>
   *   Group IDs.
   */
  protected function children(string $suffix, int $nid): array {
    $gids = array_map('intval', $this->database()->select('markaspot_request_index_' . $suffix, 'c')
      ->fields('c', ['gid'])
      ->condition('c.nid', $nid)
      ->execute()
      ->fetchCol());
    sort($gids);
    return $gids;
  }

  /**
   * Returns the database connection.
   */
  protected function database(): Connection {
    return $this->container->get('database');
  }

  /**
   * Creates the group, term and service request fields of the fixture.
   */
  private function createFields(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'All groups member',
    ])->save();

    $this->createReferenceField('group', 'jur', 'field_parent_jurisdiction', 'group', 1);
    $this->createReferenceField('group', 'org', 'field_jurisdiction', 'group', 1);
    // Queried by the organisation derivation of markaspot_group; left empty.
    $this->createReferenceField('group', 'org', 'field_service_categories', 'taxonomy_term', -1, ['service_category' => 'service_category']);
    $this->createReferenceField('node', 'service_request', 'field_jurisdiction', 'group', -1);
    $this->createReferenceField('node', 'service_request', 'field_organisation', 'group', -1, ['org' => 'org'], 'custom');
    $this->createReferenceField('node', 'service_request', 'field_status', 'taxonomy_term', 1, ['service_status' => 'service_status']);
    $this->createReferenceField('node', 'service_request', 'field_category', 'taxonomy_term', 1, ['service_category' => 'service_category']);

    // Translatability as shipped: field_open311_mapping storage untranslatable
    // (markaspot_open311), field_service_code instance untranslatable
    // (service_request).
    FieldStorageConfig::create([
      'field_name' => 'field_open311_mapping',
      'entity_type' => 'taxonomy_term',
      'type' => 'list_string',
      'translatable' => FALSE,
      'settings' => [
        'allowed_values' => ['open' => 'Open', 'closed' => 'Closed'],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_open311_mapping',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'service_status',
      'label' => 'Open311 mapping',
    ])->save();
    $this->createSimpleField('taxonomy_term', 'service_category', 'field_service_code', 'string', 'public', FALSE);

    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'node',
      'type' => 'address',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Address',
    ])->save();
    // Contact fields: private, never projected.
    $this->createSimpleField('node', 'service_request', 'field_e_mail', 'email', 'custom');
    $this->createSimpleField('node', 'service_request', 'field_phone', 'string', 'custom');
    $this->createSimpleField('node', 'service_request', 'field_first_name', 'string', 'custom');
    $this->createSimpleField('node', 'service_request', 'field_last_name', 'string', 'custom');
    // One optional column with a source field; field_district stays absent.
    $this->createSimpleField('node', 'service_request', 'field_facility', 'string');

    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Creates an entity reference field.
   *
   * @param string $entity_type
   *   Entity type of the field.
   * @param string $bundle
   *   Bundle of the field.
   * @param string $field_name
   *   Field name.
   * @param string $target_type
   *   Referenced entity type.
   * @param int $cardinality
   *   Field cardinality.
   * @param array<string, string> $target_bundles
   *   Target bundles.
   * @param string $permission_type
   *   The field_permissions type.
   */
  private function createReferenceField(string $entity_type, string $bundle, string $field_name, string $target_type, int $cardinality, array $target_bundles = [], string $permission_type = 'public'): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'entity_reference',
        'cardinality' => $cardinality,
        'settings' => ['target_type' => $target_type],
        'third_party_settings' => [
          'field_permissions' => ['permission_type' => $permission_type],
        ],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $field_name,
      'settings' => $target_bundles === [] ? [] : [
        'handler' => 'default:' . $target_type,
        'handler_settings' => ['target_bundles' => $target_bundles],
      ],
    ])->save();
  }

  /**
   * Creates a single-value field; $translatable sets the field instance.
   */
  private function createSimpleField(string $entity_type, string $bundle, string $field_name, string $type, string $permission_type = 'public', bool $translatable = TRUE): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => $permission_type],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $field_name,
      'translatable' => $translatable,
    ])->save();
  }

}
