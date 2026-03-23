<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\markaspot_open311\EventSubscriber\RequestFormatSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the RequestFormatSubscriber event subscriber.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\EventSubscriber\RequestFormatSubscriber
 */
class RequestFormatSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_open311\EventSubscriber\RequestFormatSubscriber
   */
  protected RequestFormatSubscriber $subscriber;

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

    $this->subscriber = new RequestFormatSubscriber();
    $this->kernel = $this->createMock(HttpKernelInterface::class);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = RequestFormatSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('onKernelRequest', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(100, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testSetsJsonFormat(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onKernelRequest($event);

    $this->assertEquals('json', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testSetsXmlFormat(): void {
    $request = Request::create('/georeport/v2/services.xml', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onKernelRequest($event);

    $this->assertEquals('xml', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testIgnoresNonGeoreportPaths(): void {
    $request = Request::create('/node/1.json', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onKernelRequest($event);

    // Default format is html, should remain unchanged.
    $this->assertEquals('html', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testIgnoresGeoreportPathsWithoutExtension(): void {
    $request = Request::create('/georeport/v2/requests', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onKernelRequest($event);

    $this->assertEquals('html', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testHandlesRequestsWithIdAndExtension(): void {
    $request = Request::create('/georeport/v2/requests/12345.json', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onKernelRequest($event);

    $this->assertEquals('json', $request->getRequestFormat());
  }

}
