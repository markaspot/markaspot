<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/src/Plugin/HealthCheck/TenantServiceCategoriesAssignedCheck.php';

/**
 * Tests inherited service category health coverage.
 *
 * @group markaspot_health
 */
#[RunTestsInSeparateProcesses]
final class TenantServiceCategoriesAssignedCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'taxonomy',
    'entity',
    'flexible_permissions',
    'group',
    'markaspot_group',
    'markaspot_health',
  ];

  /**
   * Root jurisdiction with seven owned categories.
   */
  private Group $rootJurisdiction;

  /**
   * Child jurisdictions inheriting the root catalog.
   *
   * @var \Drupal\group\Entity\Group[]
   */
  private array $children;

  /**
   * Jurisdiction with no resolvable categories.
   */
  private Group $emptyJurisdiction;

  /**
   * Root-owned service category IDs.
   *
   * @var int[]
   */
  private array $categoryIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['system', 'user', 'field', 'taxonomy', 'group']);

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
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    Vocabulary::create([
      'vid' => 'service_category',
      'name' => 'Service category',
    ])->save();

    $this->createReferenceField(
      'group',
      'jur',
      'field_parent_jurisdiction',
      'group',
    );
    $this->createReferenceField(
      'group',
      'jur',
      'field_service_categories',
      'taxonomy_term',
    );
    $this->createReferenceField(
      'taxonomy_term',
      'service_category',
      'field_jurisdiction',
      'group',
    );
    $this->container->get('entity_field.manager')
      ->clearCachedFieldDefinitions();

    $this->rootJurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Portfolio root',
    ]);
    $this->rootJurisdiction->save();
    $this->children = [
      Group::create([
        'type' => 'jur',
        'label' => 'Community A',
        'field_parent_jurisdiction' => $this->rootJurisdiction->id(),
      ]),
      Group::create([
        'type' => 'jur',
        'label' => 'Community B',
        'field_parent_jurisdiction' => $this->rootJurisdiction->id(),
      ]),
    ];
    foreach ($this->children as $child) {
      $child->save();
    }
    $this->emptyJurisdiction = Group::create([
      'type' => 'jur',
      'label' => 'Empty portfolio',
    ]);
    $this->emptyJurisdiction->save();

    for ($index = 1; $index <= 7; $index++) {
      $term = Term::create([
        'vid' => 'service_category',
        'name' => 'Category ' . $index,
        'field_jurisdiction' => $this->rootJurisdiction->id(),
      ]);
      $term->save();
      $this->categoryIds[] = (int) $term->id();
    }
  }

  /**
   * Tests both children have inherited category coverage.
   */
  public function testChildrenResolveRootCategoriesAsCoverage(): void {
    foreach ($this->children as $child) {
      $result = $this->plugin()->run([
        'jurisdiction' => (int) $child->id(),
      ]);

      $this->assertTrue($result->passed);
      $this->assertSame(0, $result->count);
      $this->assertStringContainsString('1 use inheritance', $result->message);
    }
  }

  /**
   * Tests an explicit child allow-list resolves its root-owned subset.
   */
  public function testChildAllowListHasCoverage(): void {
    $child = $this->children[0];
    $child->set('field_service_categories', array_slice($this->categoryIds, 0, 2));
    $child->save();

    $result = $this->plugin()->run([
      'jurisdiction' => (int) $child->id(),
    ]);

    $this->assertTrue($result->passed);
    $this->assertSame(0, $result->count);
    $this->assertStringContainsString('direct coverage', $result->message);

    $unpublished = Term::create([
      'vid' => 'service_category',
      'name' => 'Unpublished category',
      'status' => 0,
      'field_jurisdiction' => $this->rootJurisdiction->id(),
    ]);
    $unpublished->save();
    $child->set('field_service_categories', [(int) $unpublished->id()]);
    $child->save();

    $result = $this->plugin()->run([
      'jurisdiction' => (int) $child->id(),
    ]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertSame(0, $result->details[0]['resolved_count']);
  }

  /**
   * Tests a jurisdiction without categories remains an error.
   */
  public function testJurisdictionWithoutResolvedCategoriesErrors(): void {
    $result = $this->plugin()->run([
      'jurisdiction' => (int) $this->emptyJurisdiction->id(),
    ]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertSame(1, $result->count);
    $this->assertSame(0, $result->details[0]['resolved_count']);
  }

  /**
   * Creates an unlimited entity reference field.
   */
  private function createReferenceField(
    string $entityType,
    string $bundle,
    string $fieldName,
    string $targetType,
  ): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => 'entity_reference',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        'settings' => ['target_type' => $targetType],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
      'settings' => [
        'handler' => 'default:' . $targetType,
        'handler_settings' => [],
      ],
    ])->save();
  }

  /**
   * Creates the health check through the real plugin manager.
   */
  private function plugin(): object {
    return $this->container
      ->get('plugin.manager.markaspot_health_check')
      ->createInstance('tenant_service_categories_assigned');
  }

}
