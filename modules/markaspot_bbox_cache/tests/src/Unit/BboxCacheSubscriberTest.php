<?php

namespace Drupal\Tests\markaspot_bbox_cache\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\markaspot_bbox_cache\EventSubscriber\BboxCacheSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the BboxCacheSubscriber event subscriber.
 *
 * @group markaspot_bbox_cache
 * @coversDefaultClass \Drupal\markaspot_bbox_cache\EventSubscriber\BboxCacheSubscriber
 */
class BboxCacheSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_bbox_cache\EventSubscriber\BboxCacheSubscriber
   */
  protected BboxCacheSubscriber $subscriber;

  /**
   * Mocked cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cache;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * Mocked HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $kernel;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->kernel = $this->createMock(HttpKernelInterface::class);

    $this->configFactory->method('get')
      ->willReturn($this->config);

    $this->config->method('get')
      ->willReturnMap([
        ['cache_time', 180],
        ['cache_by_zoom', FALSE],
        ['exclude_params', []],
      ]);

    $this->subscriber = new BboxCacheSubscriber(
      $this->cache,
      $this->configFactory
    );
  }

  /**
   * Credentials must neither retrieve nor populate the anonymous cache.
   */
  public function testCredentialedRequestsBypassCache(): void {
    $requests = [];
    foreach (['Cookie', 'Authorization', 'api_key', 'api-key', 'apikey', 'x-api-key'] as $header) {
      $request = Request::create('/georeport/v2/requests.json?bbox=1,2,3,4');
      $request->headers->set($header, 'synthetic-credential');
      $requests[] = $request;
    }
    $requests[] = Request::create('/georeport/v2/requests.json?bbox=1,2,3,4&api_key=synthetic');
    $this->cache->expects($this->never())->method('get');
    $this->cache->expects($this->never())->method('set');
    foreach ($requests as $request) {
      $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
      $this->subscriber->onRequest($event);
      $this->assertNull($event->getResponse());
      $response = new Response('private report', 200, ['Cache-Control' => 'public, max-age=60']);
      $this->subscriber->onResponse(new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
      $this->assertFalse($response->headers->has('X-Bbox-Cache'));
    }
  }

  /**
   * The subscriber must preserve restrictive response cache policies.
   */
  public function testPrivateResponsesAreNeverStored(): void {
    $request = Request::create('/georeport/v2/requests.json?bbox=1,2,3,4');
    $this->cache->expects($this->never())->method('set');
    foreach (['private, max-age=60', 'public, no-store', 'no-cache'] as $policy) {
      $response = new Response('private report', 200, ['Cache-Control' => $policy]);
      $before = $response->headers->get('Cache-Control');
      $this->subscriber->onResponse(new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
      $this->assertSame($before, $response->headers->get('Cache-Control'));
    }
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = BboxCacheSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    $this->assertEquals('onRequest', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(100, $events[KernelEvents::REQUEST][1]);
    $this->assertEquals('onResponse', $events[KernelEvents::RESPONSE][0]);
    $this->assertEquals(-100, $events[KernelEvents::RESPONSE][1]);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestIgnoresNonGeoreportPaths(): void {
    $request = Request::create('/node/1', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->cache->expects($this->never())->method('get');

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestIgnoresPostRequests(): void {
    $request = Request::create('/georeport/v2/requests', 'POST');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->cache->expects($this->never())->method('get');

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestIgnoresDebugParameter(): void {
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'bbox' => '1,2,3,4',
      'debug' => '1',
    ]);
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->cache->expects($this->never())->method('get');

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestIgnoresNonBboxRequests(): void {
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'service_code' => '123',
    ]);
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->cache->expects($this->never())->method('get');

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestReturnsCachedResponse(): void {
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'bbox' => '1,2,3,4',
    ]);
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $cachedData = (object) [
      'valid' => TRUE,
      'data' => [
        'content' => '{"test": "data"}',
        'headers' => ['content-type' => ['application/json']],
      ],
    ];

    $this->cache->method('get')->willReturn($cachedData);

    $this->subscriber->onRequest($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertEquals('{"test": "data"}', $response->getContent());
    $this->assertEquals('HIT', $response->headers->get('X-Bbox-Cache'));
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestCacheMiss(): void {
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'bbox' => '1,2,3,4',
    ]);
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->cache->method('get')->willReturn(FALSE);

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseIgnoresNonGeoreportPaths(): void {
    $request = Request::create('/node/1', 'GET');
    $response = new Response('test', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->cache->expects($this->never())->method('set');

    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseIgnoresNon200Responses(): void {
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'bbox' => '1,2,3,4',
    ]);
    $response = new Response('error', 500);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->cache->expects($this->never())->method('set');

    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseSkipsAlreadyCachedResponse(): void {
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'bbox' => '1,2,3,4',
    ]);
    $response = new Response('data', 200);
    $response->headers->set('X-Bbox-Cache', 'HIT');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->cache->expects($this->never())->method('set');

    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseCachesSuccessfulBboxResponse(): void {
    // Need to trigger onRequest first to set startTime.
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'bbox' => '1,2,3,4',
    ]);

    $requestEvent = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    $this->cache->method('get')->willReturn(FALSE);
    $this->subscriber->onRequest($requestEvent);

    $response = new Response('{"results": []}', 200, ['Cache-Control' => 'public, max-age=60']);
    $responseEvent = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->cache->expects($this->once())
      ->method('set')
      ->with(
        $this->stringStartsWith('bbox_request:v2:'),
        $this->isType('array'),
        $this->isType('int'),
        $this->callback(function ($tags) {
          return in_array('markaspot_bbox_cache', $tags)
            && in_array('group_list', $tags)
            && in_array('node_list:service_request', $tags);
        })
      );

    $this->subscriber->onResponse($responseEvent);

    $this->assertEquals('MISS', $response->headers->get('X-Bbox-Cache'));
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseAddsServiceCodeCacheTag(): void {
    $request = Request::create('/georeport/v2/requests', 'GET', [
      'bbox' => '1,2,3,4',
      'service_code' => '456',
    ]);

    $requestEvent = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    $this->cache->method('get')->willReturn(FALSE);
    $this->subscriber->onRequest($requestEvent);

    $response = new Response('{"results": []}', 200, ['Cache-Control' => 'public, max-age=60']);
    $responseEvent = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->cache->expects($this->once())
      ->method('set')
      ->with(
        $this->anything(),
        $this->anything(),
        $this->anything(),
        $this->callback(function ($tags) {
          return in_array('service_code:456', $tags)
            && in_array('taxonomy_term_list:service_category', $tags);
        })
      );

    $this->subscriber->onResponse($responseEvent);
  }

}
