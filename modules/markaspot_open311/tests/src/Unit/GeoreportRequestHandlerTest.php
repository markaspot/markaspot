<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_open311\GeoreportRequestHandler;
use Drupal\rest\RestResourceConfigInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Tests GeoReport response cache isolation.
 *
 * @coversDefaultClass \Drupal\markaspot_open311\GeoreportRequestHandler
 * @group markaspot_open311
 */
class GeoreportRequestHandlerTest extends UnitTestCase {

  /**
   * Tests anonymous priming followed by a manager request to the same URL.
   *
   * @covers ::handle
   */
  public function testAnonymousCacheCannotServeAuthenticatedResponse(): void {
    $serializer = $this->createMock(SerializerInterface::class);
    $serializer->expects($this->exactly(2))
      ->method('serialize')
      ->willReturn('{"status":"ok"}');

    $currentPath = $this->createMock(CurrentPathStack::class);
    $currentPath->method('getPath')
      ->willReturn('/georeport/v2/requests.json');

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->expects($this->exactly(2))
      ->method('isAnonymous')
      ->willReturnOnConsecutiveCalls(TRUE, FALSE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('api_key_request_header_name')
      ->willReturn('apikey');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('services_api_key_auth.settings')
      ->willReturn($config);

    $resource = new class() {

      /**
       * Returns a minimal GET result.
       */
      public function get(array $requestData, Request $request): array {
        return ['status' => 'ok'];
      }

    };

    $resourceConfig = $this->createMock(RestResourceConfigInterface::class);
    $resourceConfig->method('getResourcePlugin')->willReturn($resource);

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameters')->willReturn(new ParameterBag());

    $handler = new GeoreportRequestHandler($serializer, $currentPath, $currentUser, $configFactory);
    $request = Request::create('/georeport/v2/requests.json', 'GET');

    $anonymousResponse = $handler->handle($routeMatch, $request, $resourceConfig);
    $managerResponse = $handler->handle($routeMatch, $request, $resourceConfig);

    $this->assertTrue($anonymousResponse->headers->hasCacheControlDirective('public'));
    $this->assertSame('180', $anonymousResponse->headers->getCacheControlDirective('max-age'));
    $this->assertSame('180', $anonymousResponse->headers->getCacheControlDirective('s-maxage'));
    $this->assertSame('public, max-age=180', $anonymousResponse->headers->get('X-Cache-Policy'));
    $this->assertSame(['Cookie', 'apikey'], $anonymousResponse->getVary());
    $this->assertTrue($managerResponse->headers->hasCacheControlDirective('private'));
    $this->assertTrue($managerResponse->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('private, no-store', $managerResponse->headers->get('X-Cache-Policy'));
  }

  /**
   * Tests that debug responses are never shared-cacheable.
   *
   * @covers ::handle
   */
  public function testAnonymousDebugResponseIsPrivate(): void {
    $serializer = $this->createMock(SerializerInterface::class);
    $serializer->method('serialize')->willReturn('{"status":"ok"}');

    $currentPath = $this->createMock(CurrentPathStack::class);
    $currentPath->method('getPath')
      ->willReturn('/georeport/v2/requests.json');

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('isAnonymous')->willReturn(TRUE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('api_key_request_header_name')
      ->willReturn('apikey');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('services_api_key_auth.settings')
      ->willReturn($config);

    $resource = new class() {

      /**
       * Returns a minimal GET result.
       */
      public function get(array $requestData, Request $request): array {
        return ['status' => 'ok'];
      }

    };

    $resourceConfig = $this->createMock(RestResourceConfigInterface::class);
    $resourceConfig->method('getResourcePlugin')->willReturn($resource);

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameters')->willReturn(new ParameterBag());

    $handler = new GeoreportRequestHandler($serializer, $currentPath, $currentUser, $configFactory);
    $request = Request::create('/georeport/v2/requests.json?debug=1', 'GET');
    $response = $handler->handle($routeMatch, $request, $resourceConfig);

    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('private, no-store', $response->headers->get('X-Cache-Policy'));
  }

}
