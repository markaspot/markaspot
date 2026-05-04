<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests slug cache tag invalidation hooks.
 */
#[Group('markaspot_nuxt')]
class GroupSlugCacheInvalidationTest extends UnitTestCase {

  /**
   * Tests group slug updates invalidate jurisdiction routing caches.
   */
  public function testGroupSlugUpdateInvalidatesRoutingCacheTags(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_nuxt.module';

    $original = $this->createGroup(42, 'amsterdam');
    $group = $this->createGroup(42, 'rotterdam', $original);

    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['group:42', 'group_list']);

    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $invalidator);
    \Drupal::setContainer($container);

    markaspot_nuxt_group_update($group);
  }

  /**
   * Tests unchanged group slugs do not explicitly invalidate routing tags.
   */
  public function testUnchangedGroupSlugDoesNotInvalidateRoutingCacheTags(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_nuxt.module';

    $original = $this->createGroup(42, 'amsterdam');
    $group = $this->createGroup(42, 'amsterdam', $original);

    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->expects($this->never())->method('invalidateTags');

    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $invalidator);
    \Drupal::setContainer($container);

    markaspot_nuxt_group_update($group);
  }

  /**
   * Creates a group mock with a field_slug value.
   */
  private function createGroup(int $id, string $slug, ?GroupInterface $original = NULL): GroupInterface {
    $slugField = $this->createMock(FieldItemListInterface::class);
    $slugField->method('getString')->willReturn($slug);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('hasField')->with('field_slug')->willReturn(TRUE);
    $group->method('get')->with('field_slug')->willReturn($slugField);
    $group->method('getOriginal')->willReturn($original);

    return $group;
  }

}
