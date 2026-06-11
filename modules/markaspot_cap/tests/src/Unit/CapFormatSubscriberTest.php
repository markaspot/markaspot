<?php

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\markaspot_cap\EventSubscriber\CapFormatSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the CapFormatSubscriber event subscriber.
 *
 * The emergency-mode gate was moved to EmergencyActiveAccessCheck;
 * the subscriber now only sets the 'cap' format for matched routes.
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\EventSubscriber\CapFormatSubscriber
 */
class CapFormatSubscriberTest extends UnitTestCase {

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
    $this->kernel = $this->createMock(HttpKernelInterface::class);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = CapFormatSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('onKernelRequest', $events[KernelEvents::REQUEST][0]);
    // Priority must be < 32 (router runs at 32) so _route attribute is set.
    $this->assertLessThan(32, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testIgnoresNonCapRoutes(): void {
    $subscriber = new CapFormatSubscriber();
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $request->attributes->set('_route', 'markaspot_georeport.requests');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $subscriber->onKernelRequest($event);

    $this->assertNotEquals('cap', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testSetsCapFormatForCapIndexRoute(): void {
    $subscriber = new CapFormatSubscriber();
    $request = Request::create('/api/cap/v1/alerts', 'GET');
    $request->attributes->set('_route', 'markaspot_cap.alerts.index');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $subscriber->onKernelRequest($event);

    $this->assertEquals('cap', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testSetsCapFormatForCapShowRoute(): void {
    $subscriber = new CapFormatSubscriber();
    $request = Request::create('/api/cap/v1/alerts/123', 'GET');
    $request->attributes->set('_route', 'markaspot_cap.alerts.show');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $subscriber->onKernelRequest($event);

    $this->assertEquals('cap', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testIgnoresSubrequests(): void {
    $subscriber = new CapFormatSubscriber();
    $request = Request::create('/api/cap/v1/alerts', 'GET');
    $request->attributes->set('_route', 'markaspot_cap.alerts.index');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

    $subscriber->onKernelRequest($event);

    // SUB_REQUEST should not have format set.
    $this->assertNotEquals('cap', $request->getRequestFormat());
  }

}
