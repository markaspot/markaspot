<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_facility\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_facility\Service\FacilityManager;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for the facility catalogue storage reconciliation.
 *
 * @group markaspot_facility
 *
 * @coversDefaultClass \Drupal\markaspot_facility\Service\FacilityManager
 */
#[RunTestsInSeparateProcesses]
class FacilityManagerStorageKernelTest extends KernelTestBase {

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
    'markaspot_facility',
  ];

  /**
   * Facility manager under test.
   */
  private FacilityManager $manager;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('markaspot_facility_category');
    $this->installEntitySchema('markaspot_facility');
    $this->installConfig(['system', 'user', 'field', 'filter', 'group']);

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $this->createGroupField('field_facilities', 'text_long');

    $hierarchy_resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy_resolver->method('getRootJurisdictionId')
      ->willReturnCallback(static fn(int $gid): int => $gid);
    $this->container->set('markaspot_group.hierarchy_resolver', $hierarchy_resolver);

    $feature_scope_resolver = $this->createMock(FeatureScopeResolver::class);
    $feature_scope_resolver->method('isEnabledEffective')->willReturn(TRUE);
    $this->container->set('markaspot_nuxt.feature_scope_resolver', $feature_scope_resolver);

    $this->manager = $this->container->get('markaspot_facility.manager');
  }

  /**
   * Save migrates legacy JSON items into facility entities.
   *
   * @covers ::saveDashboardSettings
   * @covers ::getDashboardSettings
   */
  public function testSaveDashboardSettingsMigratesLegacyItemsToEntities(): void {
    $group = $this->createJurisdiction([
      'enabled' => TRUE,
      'mode' => 'exclusive',
      'hideMapPicker' => TRUE,
      'items' => [
        [
          'id' => 'legacy_school',
          'label' => 'Legacy School',
          'lat' => 51.0,
          'lng' => 7.0,
          'active' => TRUE,
        ],
      ],
    ]);

    $payload = $this->payload([
      [
        'id' => 'school_a',
        'label' => 'School A',
        'lat' => 50.1,
        'lng' => 8.1,
        'address' => [
          'address_line1' => 'A Street 1',
          'country_code' => 'DE',
          'locality' => 'Cologne',
          'postal_code' => '50667',
        ],
        'organisationId' => 'org-a',
        'categoryId' => 'schools',
        'active' => TRUE,
        'icon' => 'i-lucide-school',
        'description' => 'Primary school.',
        'url' => 'https://example.org/school-a',
      ],
      [
        'id' => 'school_b',
        'label' => 'School B',
        'lat' => 50.2,
        'lng' => 8.2,
        'active' => FALSE,
      ],
    ]);
    $payload['categories'] = [
      [
        'id' => 'schools',
        'label' => 'Schools',
        'icon' => 'lucide:school',
      ],
    ];
    $this->manager->saveDashboardSettings($group, $payload);

    $entities = $this->loadFacilityEntities($group);
    $this->assertSame(['school_a', 'school_b'], array_keys($entities));
    $this->assertSame('School A', $entities['school_a']->label());
    $this->assertSame('org-a', $entities['school_a']->get('organisation_id')->value);
    $this->assertSame('i-lucide-school', $entities['school_a']->get('icon')->value);
    $this->assertSame('https://example.org/school-a', $entities['school_a']->get('url')->value);
    $category = $entities['school_a']->get('category_id')->entity;
    $this->assertSame('schools', $category->get('machine_name')->getString());
    $this->assertSame('i-lucide-school', $category->get('icon')->getString());

    $stored_settings = $this->storedFacilitiesSettings($group);
    $this->assertSame([], $stored_settings['items']);
    $this->assertSame('exclusive', $stored_settings['mode']);

    $dashboard = $this->manager->getDashboardSettings($this->reloadGroup($group));
    $this->assertSame(['school_a', 'school_b'], array_column($dashboard['items'], 'id'));
    $this->assertSame('A Street 1', $dashboard['items'][0]['address']['address_line1']);
    $this->assertSame('schools', $dashboard['items'][0]['categoryId']);
    $this->assertSame('schools', $dashboard['categories'][0]['id']);

    $public = $this->manager->getPublicSettings($this->reloadGroup($group));
    $this->assertSame(['school_a'], array_column($public['items'], 'id'));
    $this->assertSame('schools', $public['categories'][0]['id']);
  }

  /**
   * Category deletion leaves affected facilities uncategorized.
   *
   * @covers ::saveDashboardSettings
   */
  public function testCategoryDeletionClearsFacilityReference(): void {
    $group = $this->createJurisdiction();
    $payload = $this->payload([[
      'id' => 'school_a',
      'label' => 'School A',
      'lat' => 50.1,
      'lng' => 8.1,
      'active' => TRUE,
      'categoryId' => 'schools',
    ]]);
    $payload['categories'] = [[
      'id' => 'schools',
      'label' => 'Schools',
      'icon' => 'i-lucide-school',
    ]];
    $this->manager->saveDashboardSettings($group, $payload);

    $payload['categories'] = [];
    unset($payload['items'][0]['categoryId']);
    $this->manager->saveDashboardSettings($this->reloadGroup($group), $payload);

    $facility = $this->loadFacilityEntities($group)['school_a'];
    $this->assertTrue($facility->get('category_id')->isEmpty());
    $this->assertSame([], $this->manager->getDashboardSettings($this->reloadGroup($group))['categories']);
  }

  /**
   * Category keys are unique only within their jurisdiction.
   */
  public function testCategoryMachineKeyIsTenantScopedUnique(): void {
    $first_group = $this->createJurisdiction();
    $second_group = $this->createJurisdiction();
    $storage = $this->container->get('entity_type.manager')->getStorage('markaspot_facility_category');
    $category_values = [
      'machine_name' => 'schools',
      'label' => 'Schools',
      'icon' => 'i-lucide-school',
    ];
    $storage->create($category_values + ['jurisdiction_id' => $first_group->id()])->save();
    $storage->create($category_values + ['jurisdiction_id' => $second_group->id()])->save();

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('already exists for jurisdiction');
    $storage->create($category_values + ['jurisdiction_id' => $first_group->id()])->save();
  }

  /**
   * Facility category references cannot cross tenant boundaries.
   */
  public function testFacilityCategoryReferenceMustMatchJurisdiction(): void {
    $facility_group = $this->createJurisdiction();
    $foreign_group = $this->createJurisdiction();
    $category = $this->container->get('entity_type.manager')
      ->getStorage('markaspot_facility_category')
      ->create([
        'jurisdiction_id' => $foreign_group->id(),
        'machine_name' => 'schools',
        'label' => 'Schools',
        'icon' => 'i-lucide-school',
      ]);
    $category->save();

    $facility = $this->container->get('entity_type.manager')
      ->getStorage('markaspot_facility')
      ->create([
        'jurisdiction_id' => $facility_group->id(),
        'machine_name' => 'school-a',
        'label' => 'School A',
        'lat' => 51.0,
        'lng' => 7.0,
        'category_id' => $category->id(),
      ]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('same jurisdiction');
    $facility->save();
  }

  /**
   * Save reconciles updates and per-row deletes without a full clear flag.
   *
   * @covers ::saveDashboardSettings
   */
  public function testSaveDashboardSettingsReconcilesUpdateAndDelete(): void {
    $group = $this->createJurisdiction();

    $this->manager->saveDashboardSettings($group, $this->payload([
      [
        'id' => 'school_a',
        'label' => 'School A',
        'lat' => 50.1,
        'lng' => 8.1,
        'active' => TRUE,
      ],
      [
        'id' => 'school_b',
        'label' => 'School B',
        'lat' => 50.2,
        'lng' => 8.2,
        'active' => TRUE,
      ],
    ]));

    $this->manager->saveDashboardSettings($this->reloadGroup($group), $this->payload([
      [
        'id' => 'school_a',
        'label' => 'School A Updated',
        'lat' => 51.1,
        'lng' => 9.1,
        'active' => TRUE,
      ],
    ]));

    $entities = $this->loadFacilityEntities($group);
    $this->assertSame(['school_a'], array_keys($entities));
    $this->assertSame('School A Updated', $entities['school_a']->label());
    $this->assertSame('51.1', $entities['school_a']->get('lat')->getString());
  }

  /**
   * Empty saves cannot clear an existing entity catalogue by accident.
   *
   * @covers ::saveDashboardSettings
   */
  public function testEmptySaveRequiresClearFlagForEntityCatalogue(): void {
    $group = $this->createJurisdiction();
    $this->manager->saveDashboardSettings($group, $this->payload([
      [
        'id' => 'school_a',
        'label' => 'School A',
        'lat' => 50.1,
        'lng' => 8.1,
        'active' => TRUE,
      ],
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Refusing to clear the facility catalogue without clearItems=true.');

    try {
      $this->manager->saveDashboardSettings($this->reloadGroup($group), $this->payload([]));
    }
    finally {
      $entities = $this->loadFacilityEntities($group);
      $this->assertSame(['school_a'], array_keys($entities));
    }
  }

  /**
   * Empty saves cannot clear a legacy JSON catalogue before normalization.
   *
   * @covers ::saveDashboardSettings
   */
  public function testEmptySaveRequiresClearFlagForLegacyCatalogue(): void {
    $group = $this->createJurisdiction([
      'enabled' => TRUE,
      'mode' => 'exclusive',
      'hideMapPicker' => TRUE,
      'items' => [
        [
          'id' => 'legacy_school',
          'label' => 'Legacy School',
          'lat' => 51.0,
          'lng' => 7.0,
          'active' => TRUE,
        ],
      ],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Refusing to clear the facility catalogue without clearItems=true.');

    try {
      $this->manager->saveDashboardSettings($group, $this->payload([]));
    }
    finally {
      $stored_settings = $this->storedFacilitiesSettings($group);
      $this->assertSame(['legacy_school'], array_column($stored_settings['items'], 'id'));
      $this->assertSame([], $this->loadFacilityEntities($group));
    }
  }

  /**
   * Explicit clear removes the entity catalogue and leaves settings intact.
   *
   * @covers ::saveDashboardSettings
   */
  public function testExplicitClearFlagClearsEntityCatalogue(): void {
    $group = $this->createJurisdiction();
    $this->manager->saveDashboardSettings($group, $this->payload([
      [
        'id' => 'school_a',
        'label' => 'School A',
        'lat' => 50.1,
        'lng' => 8.1,
        'active' => TRUE,
      ],
    ]));

    $payload = $this->payload([]);
    $payload['clearItems'] = TRUE;
    $payload['enabled'] = FALSE;
    $payload['mode'] = 'disabled';

    $this->manager->saveDashboardSettings($this->reloadGroup($group), $payload);

    $this->assertSame([], $this->loadFacilityEntities($group));
    $stored_settings = $this->storedFacilitiesSettings($group);
    $this->assertFalse($stored_settings['enabled']);
    $this->assertSame('disabled', $stored_settings['mode']);
    $this->assertSame([], $stored_settings['items']);
  }

  /**
   * Creates a jurisdiction group with optional raw facility settings.
   */
  private function createJurisdiction(?array $facilities = NULL): Group {
    $values = [
      'type' => 'jur',
      'label' => 'City',
    ];
    if ($facilities !== NULL) {
      $values['field_facilities'] = json_encode($facilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $group = Group::create($values);
    $group->save();
    return $group;
  }

  /**
   * Builds a full dashboard save payload.
   *
   * @param array<int, array<string, mixed>> $items
   *   Facility items.
   *
   * @return array<string, mixed>
   *   Dashboard save payload.
   */
  private function payload(array $items): array {
    return [
      'enabled' => TRUE,
      'mode' => 'exclusive',
      'hideMapPicker' => TRUE,
      'label' => [
        'singular' => 'School',
        'plural' => 'Schools',
      ],
      'items' => $items,
    ];
  }

  /**
   * Loads markaspot_facility entities keyed by machine name.
   *
   * @return array<string, \Drupal\Core\Entity\ContentEntityInterface>
   *   Facility entities keyed by machine name.
   */
  private function loadFacilityEntities(Group $group): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('markaspot_facility');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('jurisdiction_id', (int) $group->id())
      ->sort('machine_name')
      ->execute();

    $entities = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $entities[$entity->get('machine_name')->getString()] = $entity;
    }
    ksort($entities);
    return $entities;
  }

  /**
   * Returns decoded field_facilities JSON from a reloaded group.
   *
   * @return array<string, mixed>
   *   Decoded settings.
   */
  private function storedFacilitiesSettings(Group $group): array {
    $reloaded = $this->reloadGroup($group);
    $raw = (string) $reloaded->get('field_facilities')->value;
    $decoded = json_decode($raw, TRUE);
    $this->assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Reloads a group from storage.
   */
  private function reloadGroup(Group $group): Group {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache([(int) $group->id()]);
    $reloaded = $storage->load((int) $group->id());
    $this->assertInstanceOf(Group::class, $reloaded);
    return $reloaded;
  }

  /**
   * Creates a configurable field on group.jur.
   */
  private function createGroupField(string $name, string $type): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'group',
      'type' => $type,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => $name,
      'required' => FALSE,
    ])->save();
  }

}
