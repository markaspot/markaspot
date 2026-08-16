<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\markaspot_open311\Routing\PrivateFileRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests API-key authentication on private file routes.
 *
 * @coversDefaultClass \Drupal\markaspot_open311\Routing\PrivateFileRouteSubscriber
 * @group markaspot_open311
 */
class PrivateFileRouteSubscriberTest extends UnitTestCase {

  /**
   * Tests that only private file routes gain API-key authentication.
   *
   * @covers ::alterRoutes
   */
  public function testPrivateFileRoutesAllowApiKeyAuthentication(): void {
    $collection = new RouteCollection();
    $collection->add('system.files', new Route('/system/files/{scheme}'));
    $collection->add('system.private_file_download', new Route('/system/files/{filepath}'));
    $collection->add('system.temporary', new Route('/system/temporary'));

    $subscriber = new PrivateFileRouteSubscriber();
    $method = new \ReflectionMethod($subscriber, 'alterRoutes');
    $method->setAccessible(TRUE);
    $method->invoke($subscriber, $collection);

    $expectedProviders = ['cookie', 'api_key_auth'];
    $this->assertSame($expectedProviders, $collection->get('system.files')->getOption('_auth'));
    $this->assertSame($expectedProviders, $collection->get('system.private_file_download')->getOption('_auth'));
    $this->assertFalse($collection->get('system.temporary')->hasOption('_auth'));
  }

}
