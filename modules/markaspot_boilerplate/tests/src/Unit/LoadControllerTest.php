<?php

namespace Drupal\Tests\markaspot_boilerplate\Unit;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_boilerplate\Controller\LoadController;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests boilerplate response access.
 */
#[Group('markaspot_boilerplate')]
class LoadControllerTest extends UnitTestCase {

  /**
   * Numeric node IDs satisfy the route requirement.
   */
  public function testRouteAcceptsNumericNodeId(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_boilerplate.routing.yml');
    $requirement = $routes['markaspot_boilerplate.load_controller_load']['requirements']['nid'];
    $this->assertSame('\d+', $requirement);
    $this->assertSame(1, preg_match('#^' . $requirement . '$#', '123'));
    $this->assertSame(0, preg_match('#^' . $requirement . '$#', 'abc'));
  }

  /**
   * Wrong bundles are indistinguishable from missing content.
   */
  public function testWrongBundleIsNotFound(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $controller = $this->createController($node);
    $this->expectException(NotFoundHttpException::class);
    $controller->load(1);
  }

  /**
   * Inaccessible boilerplates are not returned.
   */
  public function testInaccessibleBoilerplateIsNotFound(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('boilerplate');
    $access = $this->createMockForIntersectionOfInterfaces([
      AccessResultInterface::class,
      CacheableDependencyInterface::class,
    ]);
    $access->method('isAllowed')->willReturn(FALSE);
    $node->method('access')->with('view', NULL, TRUE)->willReturn($access);
    $controller = $this->createController($node);
    $this->expectException(NotFoundHttpException::class);
    $controller->load(1);
  }

  /**
   * Accessible boilerplates return cacheable content.
   */
  public function testAccessibleBoilerplateReturnsCacheableResponse(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('boilerplate');
    $access = $this->createMockForIntersectionOfInterfaces([
      AccessResultInterface::class,
      CacheableDependencyInterface::class,
    ]);
    $access->method('isAllowed')->willReturn(TRUE);
    $access->method('getCacheContexts')->willReturn([]);
    $access->method('getCacheTags')->willReturn(['node_access']);
    $access->method('getCacheMaxAge')->willReturn(-1);
    $node->method('access')->with('view', NULL, TRUE)->willReturn($access);
    $body = $this->createMock(FieldItemListInterface::class);
    $body->method('getValue')->willReturn([['value' => 'Public boilerplate']]);
    $field_access = $this->createMockForIntersectionOfInterfaces([
      AccessResultInterface::class,
      CacheableDependencyInterface::class,
    ]);
    $field_access->method('isAllowed')->willReturn(TRUE);
    $field_access->method('getCacheContexts')->willReturn([]);
    $field_access->method('getCacheTags')->willReturn(['field_access']);
    $field_access->method('getCacheMaxAge')->willReturn(-1);
    $body->method('access')->with('view', NULL, TRUE)->willReturn($field_access);
    $node->method('get')->with('body')->willReturn($body);
    $node->method('getCacheTags')->willReturn(['node:1']);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    $controller = $this->createController($node);

    $response = $controller->load(1);
    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $this->assertSame('"Public boilerplate"', $response->getContent());
    $this->assertContains('node_access', $response->getCacheableMetadata()->getCacheTags());
    $this->assertContains('field_access', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * A hidden body field is not exposed through the JSON endpoint.
   */
  public function testInaccessibleBodyFieldIsNotFound(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('boilerplate');
    $node_access = $this->createMockForIntersectionOfInterfaces([
      AccessResultInterface::class,
      CacheableDependencyInterface::class,
    ]);
    $node_access->method('isAllowed')->willReturn(TRUE);
    $node->method('access')->with('view', NULL, TRUE)->willReturn($node_access);
    $body = $this->createMock(FieldItemListInterface::class);
    $field_access = $this->createMockForIntersectionOfInterfaces([
      AccessResultInterface::class,
      CacheableDependencyInterface::class,
    ]);
    $field_access->method('isAllowed')->willReturn(FALSE);
    $body->method('access')->with('view', NULL, TRUE)->willReturn($field_access);
    $node->method('get')->with('body')->willReturn($body);

    $this->expectException(NotFoundHttpException::class);
    $this->createController($node)->load(1);
  }

  /**
   * Creates the controller with a fixed storage result.
   */
  private function createController(NodeInterface $node): LoadController {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($node);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);
    return new LoadController($manager);
  }

}
