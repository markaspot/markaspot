<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests mandatory core workspace visibility wiring.
 *
 * @group markaspot_open311
 */
final class WorkspaceVisibilityWiringTest extends UnitTestCase {

  /**
   * Tests both request resources require the group-owned service.
   */
  public function testResourcesRequireCoreWorkspaceVisibilityService(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $resources = [
      $moduleRoot . '/src/Plugin/rest/resource/GeoreportRequestIndexResource.php',
      $moduleRoot . '/src/Plugin/rest/resource/GeoreportRequestResource.php',
    ];

    foreach ($resources as $file) {
      $source = (string) file_get_contents($file);
      $this->assertStringContainsString(
        'use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;',
        $source,
      );
      $this->assertStringContainsString(
        'WorkspaceVisibilityInterface $workspace_visibility',
        $source,
      );
      $this->assertStringContainsString(
        "\$container->get('markaspot_group.workspace_visibility')",
        $source,
      );
      $this->assertStringContainsString(
        "\$container->get('markaspot_nuxt.feature_scope_resolver')",
        $source,
      );
      $this->assertStringNotContainsString(
        "\$container->has('markaspot_fastmap.workspace_visibility')",
        $source,
      );
      $this->assertStringNotContainsString(
        '?object $workspace_visibility = NULL',
        $source,
      );
    }
  }

}
