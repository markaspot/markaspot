<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestIndexResource;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/src/Plugin/rest/resource/GeoreportRequestIndexResource.php';
require_once dirname(__DIR__, 3) . '/src/Plugin/rest/resource/GeoreportRequestResource.php';

/**
 * Tests workspace visibility wiring through the compiled service container.
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class WorkspaceVisibilityWiringKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'node',
    'taxonomy',
    'serialization',
    'rest',
    'token',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_open311',
  ];

  /**
   * Tests the request collection resource receives workspace visibility.
   */
  public function testRequestIndexResourceReceivesWorkspaceVisibility(): void {
    $resource = GeoreportRequestIndexResource::create(
      $this->container,
      [],
      'georeport_request_index_resource',
      [],
    );

    $visibility = $this->workspaceVisibility($resource);
    $this->assertInstanceOf(WorkspaceVisibilityInterface::class, $visibility);
    $this->assertSame(
      $this->container->get('markaspot_group.workspace_visibility'),
      $visibility,
    );
  }

  /**
   * Tests the individual request resource receives workspace visibility.
   */
  public function testRequestResourceReceivesWorkspaceVisibility(): void {
    $resource = GeoreportRequestResource::create(
      $this->container,
      [],
      'georeport_request_resource',
      [],
    );

    $visibility = $this->workspaceVisibility($resource);
    $this->assertInstanceOf(WorkspaceVisibilityInterface::class, $visibility);
    $this->assertSame(
      $this->container->get('markaspot_group.workspace_visibility'),
      $visibility,
    );
  }

  /**
   * Reads the injected workspace visibility service from a REST resource.
   *
   * @param object $resource
   *   The instantiated REST resource.
   *
   * @return mixed
   *   The injected property value.
   */
  private function workspaceVisibility(object $resource): mixed {
    $property = new \ReflectionProperty($resource, 'workspaceVisibility');
    return $property->getValue($resource);
  }

}
