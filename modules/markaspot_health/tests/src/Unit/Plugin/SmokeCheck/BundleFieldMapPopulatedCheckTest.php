<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\SmokeCheck;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\markaspot_health\Plugin\SmokeCheck\BundleFieldMapPopulatedCheck;
use Drupal\markaspot_health\SmokeCheckResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the bundle-field-map smoke check.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\SmokeCheck\BundleFieldMapPopulatedCheck
 */
class BundleFieldMapPopulatedCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'bundle_field_map_populated',
    'label' => 'Bundle field map populated',
    'severity' => 'error',
    'category' => 'drupal_internal',
    'mutates' => FALSE,
  ];

  /**
   * @covers ::run
   */
  public function testPassesWhenAllEntitiesWithFieldsHaveBundleEntries(): void {
    $node = $this->mockEntityType('node', TRUE, 'node_type');
    $media = $this->mockEntityType('media', TRUE, 'media_type');

    $entityTypeManager = $this->mockEntityTypeManager(
      ['node' => $node, 'media' => $media],
      ['node' => 3, 'media' => 2],
    );

    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('get')->willReturnMap([
      ['node', NULL, ['field_body' => ['type' => 'text_long']]],
      ['media', NULL, ['field_media_image' => ['type' => 'image']]],
    ]);

    $plugin = new BundleFieldMapPopulatedCheck(
      [],
      'bundle_field_map_populated',
      $this->definition,
      $entityTypeManager,
      $this->mockKeyValueFactory($store),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_PASS, $result->status);
    $this->assertStringContainsString('2 entity types', $result->message);
  }

  /**
   * Catches the field-map corruption case.
   *
   * Entity type has field_config entries but bundle_field_map is empty: this
   * is the exact 11.9.72 hotfix trigger for media:request_image.
   *
   * @covers ::run
   */
  public function testFailsWhenEntityHasFieldsButEmptyMap(): void {
    $node = $this->mockEntityType('node', TRUE, 'node_type');
    $media = $this->mockEntityType('media', TRUE, 'media_type');

    $entityTypeManager = $this->mockEntityTypeManager(
      ['node' => $node, 'media' => $media],
      ['node' => 3, 'media' => 2],
    );

    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('get')->willReturnMap([
      ['node', NULL, ['field_body' => ['type' => 'text_long']]],
      ['media', NULL, []],
    ]);

    $plugin = new BundleFieldMapPopulatedCheck(
      [],
      'bundle_field_map_populated',
      $this->definition,
      $entityTypeManager,
      $this->mockKeyValueFactory($store),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_FAIL, $result->status);
    $this->assertSame(1, $result->count);
    $this->assertSame('media', $result->evidence['offenders'][0]['entity_type']);
    $this->assertSame(2, $result->evidence['offenders'][0]['field_config_count']);
    $this->assertSame('empty_map_with_configured_fields', $result->evidence['offenders'][0]['reason']);
  }

  /**
   * Entity types with no field_config must not be flagged.
   *
   * Bundle entity type that legitimately has zero configured fields (e.g.
   * a fresh contact_message with no contact forms) ends up with an empty
   * map. That is normal, not corruption.
   *
   * @covers ::run
   */
  public function testIgnoresEntitiesWithNoFieldConfig(): void {
    $contactMessage = $this->mockEntityType('contact_message', TRUE, 'contact_form');

    $entityTypeManager = $this->mockEntityTypeManager(
      ['contact_message' => $contactMessage],
      ['contact_message' => 0],
    );

    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->expects($this->never())->method('get');

    $plugin = new BundleFieldMapPopulatedCheck(
      [],
      'bundle_field_map_populated',
      $this->definition,
      $entityTypeManager,
      $this->mockKeyValueFactory($store),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_PASS, $result->status);
  }

  /**
   * Non-fieldable bundle entities (e.g. menu) must not be checked.
   *
   * @covers ::run
   */
  public function testSkipsNonFieldableEntityTypes(): void {
    $menu = $this->mockEntityType('menu', FALSE, NULL);

    $entityTypeManager = $this->mockEntityTypeManager(['menu' => $menu], []);

    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->expects($this->never())->method('get');

    $plugin = new BundleFieldMapPopulatedCheck(
      [],
      'bundle_field_map_populated',
      $this->definition,
      $entityTypeManager,
      $this->mockKeyValueFactory($store),
    );
    $result = $plugin->run();

    $this->assertSame(SmokeCheckResult::STATUS_PASS, $result->status);
  }

  /**
   * Helper: build an EntityTypeInterface mock.
   */
  private function mockEntityType(string $id, bool $fieldable, ?string $bundleEntityType): EntityTypeInterface {
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->method('id')->willReturn($id);
    $entityType->method('entityClassImplements')
      ->with('Drupal\Core\Entity\FieldableEntityInterface')
      ->willReturn($fieldable);
    $entityType->method('getBundleEntityType')->willReturn($bundleEntityType);
    return $entityType;
  }

  /**
   * Helper: build an EntityTypeManager mock with field_config storage.
   *
   * @param array<string, EntityTypeInterface> $definitions
   *   Entity type definitions to return from getDefinitions().
   * @param array<string, int> $fieldConfigCounts
   *   Per-entity-type number of field_config entries to return from
   *   loadByProperties(['entity_type' => $id]).
   */
  private function mockEntityTypeManager(array $definitions, array $fieldConfigCounts): EntityTypeManagerInterface {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getDefinitions')->willReturn($definitions);
    $manager->method('hasDefinition')->with('field_config')->willReturn(TRUE);

    $fieldConfigStorage = $this->createMock(EntityStorageInterface::class);
    $fieldConfigStorage->method('loadByProperties')
      ->willReturnCallback(static function (array $properties) use ($fieldConfigCounts): array {
        $entityType = $properties['entity_type'] ?? '';
        $count = $fieldConfigCounts[$entityType] ?? 0;
        return array_fill(0, $count, NULL);
      });

    $manager->method('getStorage')->with('field_config')->willReturn($fieldConfigStorage);
    return $manager;
  }

  /**
   * Helper: mock the keyvalue factory returning a fixed store.
   */
  private function mockKeyValueFactory(KeyValueStoreInterface $store): KeyValueFactoryInterface {
    $factory = $this->createMock(KeyValueFactoryInterface::class);
    $factory->method('get')->with('entity.definitions.bundle_field_map')->willReturn($store);
    return $factory;
  }

}
