<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_facility\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the anonymous facility validation and repeated-save lifecycle.
 *
 * @group markaspot_facility
 */
#[RunTestsInSeparateProcesses]
class FacilityRequestLifecycleKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'entity',
    'flexible_permissions',
    'group',
    'address',
    'node',
    'taxonomy',
    'markaspot_facility',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('markaspot_group.hierarchy_resolver', JurisdictionHierarchyResolverInterface::class)
      ->setSynthetic(TRUE)
      ->setPublic(TRUE);
    $container->register('markaspot_nuxt.feature_scope_resolver', FeatureScopeResolver::class)
      ->setSynthetic(TRUE)
      ->setPublic(TRUE);
  }

  /**
   * A validated child facility survives boundary routing to a nested child.
   */
  public function testAnonymousChildFacilitySurvivesSecondSave(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'group', 'node']);

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Service category'])->save();
    $this->createField('group', 'jur', 'field_facilities', 'text_long');
    $this->createField('taxonomy_term', 'service_category', 'field_jurisdiction', 'entity_reference', [
      'target_type' => 'group',
    ]);
    $this->createField('node', 'service_request', 'field_facility', 'string');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', [
      'target_type' => 'group',
    ], [
      'handler' => 'default:group',
      'handler_settings' => [
        'target_bundles' => ['jur' => 'jur'],
      ],
    ]);
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ]);

    $root = $this->createJurisdiction('Root');
    $owner = $this->createJurisdiction('Facility owner');
    $nested = $this->createJurisdiction('Nested boundary');
    $root_id = (int) $root->id();
    $owner_id = (int) $owner->id();
    $nested_id = (int) $nested->id();

    $hierarchy_resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy_resolver->method('getRootJurisdictionId')
      ->willReturnCallback(static fn(int $id): ?int => in_array($id, [
        $root_id,
        $owner_id,
        $nested_id,
      ], TRUE) ? $root_id : NULL);
    $hierarchy_resolver->method('getDescendantIds')
      ->willReturnCallback(static fn(int $id): array => match ($id) {
        $root_id => [$root_id, $owner_id, $nested_id],
        $owner_id => [$owner_id, $nested_id],
        $nested_id => [$nested_id],
        default => [],
      });
    $this->container->set('markaspot_group.hierarchy_resolver', $hierarchy_resolver);

    $feature_scope_resolver = $this->createMock(FeatureScopeResolver::class);
    $feature_scope_resolver->method('isEnabledEffective')->willReturn(TRUE);
    $this->container->set('markaspot_nuxt.feature_scope_resolver', $feature_scope_resolver);

    $owner->set('field_facilities', json_encode([
      'enabled' => TRUE,
      'mode' => 'exclusive',
      'hideMapPicker' => TRUE,
      'items' => [[
        'id' => 'child_playground',
        'label' => 'Child playground',
        'lat' => 51.33,
        'lng' => 6.56,
        'active' => TRUE,
      ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $owner->save();

    $category = Term::create([
      'vid' => 'service_category',
      'name' => 'Playgrounds',
      'field_jurisdiction' => ['target_id' => $root_id],
    ]);
    $category->save();

    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Anonymous facility report',
      'field_facility' => 'child_playground',
      'field_category' => ['target_id' => $category->id()],
    ]);
    $this->assertCount(0, $node->validate());

    // First presave stamps the uniquely validated owner before insert.
    $node->save();
    $this->assertSame($owner_id, (int) $node->get('field_jurisdiction')->target_id);
    $this->assertSame('child_playground', $node->get('field_facility')->value);

    // Simulate most-specific boundary routing in hook_node_insert followed by
    // its second save. The nested child is inside the validated owner's tree.
    $node->set('field_jurisdiction', ['target_id' => $nested_id]);
    $node->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([(int) $node->id()]);
    $saved = $storage->load((int) $node->id());
    $this->assertInstanceOf(Node::class, $saved);
    $this->assertSame($nested_id, (int) $saved->get('field_jurisdiction')->target_id);
    $this->assertSame('child_playground', $saved->get('field_facility')->value);

    // A later request receives a new Node object and an empty request-local
    // owner cache. Validation and presave must rediscover the ancestor owner
    // instead of rejecting or silently clearing the facility association.
    $this->assertCount(0, $saved->validate());
    $saved->setTitle('Later unrelated edit');
    $saved->save();
    $storage->resetCache([(int) $saved->id()]);
    $reloaded = $storage->load((int) $saved->id());
    $this->assertInstanceOf(Node::class, $reloaded);
    $this->assertSame($nested_id, (int) $reloaded->get('field_jurisdiction')->target_id);
    $this->assertSame('child_playground', $reloaded->get('field_facility')->value);

    // Open311 creates include the canonical jurisdiction_id before entity
    // validation. A root-scoped category must still resolve the uniquely owned
    // child facility instead of checking only the root catalogue.
    $open311 = Node::create([
      'type' => 'service_request',
      'title' => 'Open311 facility report',
      'field_facility' => 'child_playground',
      'field_category' => ['target_id' => $category->id()],
      'field_jurisdiction' => ['target_id' => $root_id],
    ]);
    $open311_violations = $open311->validate();
    $this->assertSame([], $this->facilityViolationPaths($open311_violations));
    $open311->save();
    $this->assertSame('child_playground', $open311->get('field_facility')->value);

    // Once the catalogue is disabled and its item inactive, a fresh assignment
    // fails but the already persisted association survives an unrelated edit.
    $owner->set('field_facilities', json_encode([
      'enabled' => FALSE,
      'mode' => 'disabled',
      'hideMapPicker' => TRUE,
      'items' => [[
        'id' => 'child_playground',
        'label' => 'Child playground',
        'lat' => 51.33,
        'lng' => 6.56,
        'active' => FALSE,
      ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $owner->save();

    $new_assignment = Node::create([
      'type' => 'service_request',
      'title' => 'Unavailable facility report',
      'field_facility' => 'child_playground',
      'field_category' => ['target_id' => $category->id()],
      'field_jurisdiction' => ['target_id' => $root_id],
    ]);
    $this->assertSame(
      ['field_facility'],
      $this->facilityViolationPaths($new_assignment->validate()),
    );

    $storage->resetCache([(int) $reloaded->id()]);
    $historical = $storage->load((int) $reloaded->id());
    $this->assertInstanceOf(Node::class, $historical);
    $historical->setTitle('Historical facility status update');
    $this->assertCount(0, $historical->validate());
    $historical->save();
    $storage->resetCache([(int) $historical->id()]);
    $preserved = $storage->load((int) $historical->id());
    $this->assertInstanceOf(Node::class, $preserved);
    $this->assertSame('child_playground', $preserved->get('field_facility')->value);
  }

  /**
   * Creates one jurisdiction group.
   */
  private function createJurisdiction(string $label): Group {
    $group = Group::create(['type' => 'jur', 'label' => $label]);
    $group->save();
    return $group;
  }

  /**
   * Returns only facility ownership violations from full entity validation.
   *
   * New group references are not referenceable to the anonymous kernel user,
   * while Open311 performs access checks before building the node. Filtering
   * keeps this test focused on the FacilityOwnership contract without hiding
   * any violation emitted for field_facility itself.
   *
   * @return string[]
   *   Facility violation property paths.
   */
  private function facilityViolationPaths(iterable $violations): array {
    $paths = [];
    foreach ($violations as $violation) {
      $path = $violation->getPropertyPath();
      if (str_contains($path, 'field_facility')) {
        $paths[] = 'field_facility';
      }
    }
    return $paths;
  }

  /**
   * Creates a configurable field.
   */
  private function createField(
    string $entity_type,
    string $bundle,
    string $name,
    string $type,
    array $settings = [],
    array $field_settings = [],
  ): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $name,
      'required' => FALSE,
      'settings' => $field_settings,
    ])->save();
  }

}
