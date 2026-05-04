<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\markaspot_open311\EventSubscriber\GeoreportCacheSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the GeoreportCacheSubscriber event subscriber.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\EventSubscriber\GeoreportCacheSubscriber
 */
class GeoreportCacheSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_open311\EventSubscriber\GeoreportCacheSubscriber
   */
  protected GeoreportCacheSubscriber $subscriber;

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

    $this->subscriber = new GeoreportCacheSubscriber();
    $this->kernel = $this->createMock(HttpKernelInterface::class);

    // Set up container for Cache::mergeContexts() calls.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = GeoreportCacheSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    $this->assertEquals(['onResponse', 100], $events[KernelEvents::RESPONSE][0]);
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresNonGeoreportPaths(): void {
    $request = Request::create('/node/1', 'GET');
    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertNotContains('Accept-Language', $response->getVary());
  }

  /**
   * @covers ::onResponse
   */
  public function testAddsVaryHeaderForGeoreportPaths(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertContains('Accept-Language', $response->getVary());
  }

  /**
   * @covers ::onResponse
   */
  public function testSetsCacheControlHeader(): void {
    $request = Request::create('/georeport/v2/services.json', 'GET');
    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertStringContainsString('max-age=60', $response->headers->get('Cache-Control'));
    $this->assertSame('public, max-age=60', $response->headers->get('X-Cache-Policy'));
  }

  /**
   * @covers ::onResponse
   */
  public function testApiKeyRequestsArePrivateNoStore(): void {
    $request = Request::create('/georeport/v2/requests.json?api_key=secret', 'GET');
    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertSame('private, no-store', $response->headers->get('X-Cache-Policy'));
  }

  /**
   * @covers ::onResponse
   */
  public function testCredentialHeadersArePrivateNoStore(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $request->headers->set('api-key', 'secret');
    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertContains('Authorization', $response->getVary());
    $this->assertContains('Cookie', $response->getVary());
  }

  /**
   * @covers ::onResponse
   */
  public function testConfiguredUnderscoreHeaderServerVariableIsPrivateNoStore(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET', [], [], [], [
      'HTTP_API_KEY' => 'secret',
    ]);
    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertSame('private, no-store', $response->headers->get('X-Cache-Policy'));
  }

  /**
   * @covers ::onResponse
   */
  public function testAddsCacheContextsForCacheableResponse(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET');

    $response = new CacheableJsonResponse(['data' => 'test']);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $contexts = $response->getCacheableMetadata()->getCacheContexts();
    $this->assertContains('languages:language_content', $contexts);
    $this->assertContains('url.query_args:langcode', $contexts);
  }

}
