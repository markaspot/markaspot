<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that update 11916 exposes status and langcode on boilerplates.
 */
#[Group('markaspot_nuxt')]
final class BoilerplateStatusResourceUpdateTest extends UnitTestCase {

  /**
   * A hardened resource gets both enabled; other fields stay as they are.
   */
  public function testHiddenFieldsAreExposed(): void {
    $fields = [
      'nid' => $this->field('nid', FALSE),
      'langcode' => $this->field('langcode', TRUE),
      'status' => $this->field('status', TRUE),
      'uid' => $this->field('uid', TRUE),
    ];
    $expected = $fields;
    $expected['langcode']['disabled'] = FALSE;
    $expected['status']['disabled'] = FALSE;

    $resource = $this->resource($fields);
    $resource->expects($this->once())->method('set')->with('resourceFields', $expected)->willReturnSelf();
    $resource->expects($this->once())->method('save');
    $this->container($resource);

    $this->assertSame('Exposed status, langcode on the node--boilerplate JSON:API resource.', markaspot_nuxt_update_11916());
  }

  /**
   * A resource without entries gets explicit enabled ones.
   */
  public function testMissingEntriesAreAdded(): void {
    $resource = $this->resource(['nid' => $this->field('nid', FALSE)]);
    $added = [
      'fieldName' => 'status',
      'publicName' => 'status',
      'enhancer' => ['id' => ''],
      'disabled' => FALSE,
    ];
    $resource->expects($this->once())->method('set')->with('resourceFields', $this->callback(
      fn (array $fields): bool => $fields['status'] === $added
        && $fields['langcode'] === ['fieldName' => 'langcode', 'publicName' => 'langcode'] + $added,
    ))->willReturnSelf();
    $resource->expects($this->once())->method('save');
    $this->container($resource);

    markaspot_nuxt_update_11916();
  }

  /**
   * Exposed fields and a tenant alias of one are left alone.
   */
  public function testExposedFieldsAreNotSavedAgain(): void {
    $resource = $this->resource([
      'status' => $this->field('status', FALSE, 'published'),
      'langcode' => $this->field('langcode', FALSE),
    ]);
    $resource->expects($this->never())->method('set');
    $resource->expects($this->never())->method('save');
    $this->container($resource);

    $this->assertSame('node--boilerplate JSON:API resource already exposes status and langcode.', markaspot_nuxt_update_11916());
  }

  /**
   * Tenants without the resource config or without jsonapi_extras skip.
   */
  public function testTenantsWithoutResourceSkip(): void {
    $this->container(NULL);
    $this->assertStringStartsWith('Skipped', markaspot_nuxt_update_11916());

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->with('jsonapi_resource_config')->willReturn(FALSE);
    $manager->expects($this->never())->method('getStorage');
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    \Drupal::setContainer($container);
    $this->assertStringStartsWith('Skipped', markaspot_nuxt_update_11916());
  }

  /**
   * Builds one resourceFields entry in the order jsonapi_extras exports it.
   */
  private function field(string $name, bool $disabled, ?string $publicName = NULL): array {
    return [
      'disabled' => $disabled,
      'fieldName' => $name,
      'publicName' => $publicName ?? $name,
      'enhancer' => ['id' => ''],
    ];
  }

  /**
   * Builds a resource config mock with the given resource fields.
   */
  private function resource(array $fields): ConfigEntityInterface {
    $resource = $this->createMock(ConfigEntityInterface::class);
    $resource->method('get')->with('resourceFields')->willReturn($fields);
    return $resource;
  }

  /**
   * Registers an entity type manager that loads the given resource.
   */
  private function container(?ConfigEntityInterface $resource): void {
    require_once dirname(__DIR__, 3) . '/markaspot_nuxt.install';
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('load')->with('node--boilerplate')->willReturn($resource);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->with('jsonapi_resource_config')->willReturn(TRUE);
    $manager->method('getStorage')->with('jsonapi_resource_config')->willReturn($storage);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    \Drupal::setContainer($container);
  }

}
