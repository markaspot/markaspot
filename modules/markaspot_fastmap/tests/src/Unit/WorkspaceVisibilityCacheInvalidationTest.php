<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests workspace visibility cache invalidation hooks.
 *
 * @group markaspot_fastmap
 */
class WorkspaceVisibilityCacheInvalidationTest extends UnitTestCase {

  /**
   * Tests group updates invalidate only the saved group's visibility cache.
   */
  public function testGroupUpdateInvalidatesWorkspaceVisibilityCache(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';

    $visibilityService = new class {

      /**
       * Cache reset calls captured for assertions.
       *
       * @var array<int|null>
       */
      public array $resetCalls = [];

      public function resetCache(?int $groupId = NULL): void {
        $this->resetCalls[] = $groupId;
      }

    };

    $container = new ContainerBuilder();
    $container->set('markaspot_fastmap.workspace_visibility', $visibilityService);
    Drupal::setContainer($container);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);

    markaspot_fastmap_group_update($group);

    $this->assertSame([42], $visibilityService->resetCalls);
  }

}
