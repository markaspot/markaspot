<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\markaspot_open311\GeoreportRequestHandler;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

require_once dirname(__DIR__, 3) . '/src/GeoreportRequestHandler.php';

/**
 * Tests credential detection in the GeoReport request handler.
 *
 * @group markaspot_open311
 *
 * @coversDefaultClass \Drupal\markaspot_open311\GeoreportRequestHandler
 */
class GeoreportRequestHandlerCredentialTest extends UnitTestCase {

  /**
   * A configured custom key header prevents public caching.
   *
   * @covers ::isCredentialedRequest
   */
  public function testConfiguredCustomHeaderIsCredentialed(): void {
    $handler = $this->buildHandler();
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $request->headers->set('X-Client-Token', 'secret');

    $method = new \ReflectionMethod($handler, 'isCredentialedRequest');

    $this->assertTrue($method->invoke($handler, $request));
  }

  /**
   * An empty configured key header is not treated as authentication.
   *
   * @covers ::isCredentialedRequest
   */
  public function testEmptyConfiguredCustomHeaderIsNotCredentialed(): void {
    $handler = $this->buildHandler();
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $request->headers->set('X-Client-Token', '');

    $method = new \ReflectionMethod($handler, 'isCredentialedRequest');

    $this->assertFalse($method->invoke($handler, $request));
  }

  /**
   * Builds the request handler with custom API-key carrier configuration.
   */
  protected function buildHandler(): GeoreportRequestHandler {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => match ($key) {
        'api_key_request_header_name' => 'X-Client-Token',
        default => NULL,
      },
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('services_api_key_auth.settings')
      ->willReturn($settings);

    return new GeoreportRequestHandler(
      $this->createMock(SerializerInterface::class),
      $this->createMock(CurrentPathStack::class),
      $configFactory,
    );
  }

}
