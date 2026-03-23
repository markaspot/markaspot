<?php

namespace Drupal\Tests\markaspot_ui\Unit;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_ui\EventSubscriber\NodeFormAccessSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the NodeFormAccessSubscriber event subscriber.
 *
 * @group markaspot_ui
 * @coversDefaultClass \Drupal\markaspot_ui\EventSubscriber\NodeFormAccessSubscriber
 */
class NodeFormAccessSubscriberTest extends UnitTestCase {

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

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

    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->kernel = $this->createMock(HttpKernelInterface::class);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = NodeFormAccessSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals(['onRequest', 28], $events[KernelEvents::REQUEST][0]);
  }

  /**
   * @covers ::onRequest
   */
  public function testAllowsAuthenticatedUsers(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->routeMatch->method('getRouteName')->willReturn('node.add');

    $subscriber = new NodeFormAccessSubscriber($this->currentUser, $this->routeMatch);

    $request = Request::create('/node/add/service_request', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    // Should not throw.
    $subscriber->onRequest($event);
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::onRequest
   */
  public function testBlocksAnonymousOnNodeAddPage(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);
    $this->routeMatch->method('getRouteName')->willReturn('node.add_page');

    $subscriber = new NodeFormAccessSubscriber($this->currentUser, $this->routeMatch);

    $request = Request::create('/node/add', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Anonymous users cannot access content creation forms');

    $subscriber->onRequest($event);
  }

  /**
   * @covers ::onRequest
   */
  public function testBlocksAnonymousOnNodeAdd(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);
    $this->routeMatch->method('getRouteName')->willReturn('node.add');

    $subscriber = new NodeFormAccessSubscriber($this->currentUser, $this->routeMatch);

    $request = Request::create('/node/add/service_request', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->expectException(AccessDeniedHttpException::class);

    $subscriber->onRequest($event);
  }

  /**
   * @covers ::onRequest
   */
  public function testAllowsAnonymousOnOtherRoutes(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');

    $subscriber = new NodeFormAccessSubscriber($this->currentUser, $this->routeMatch);

    $request = Request::create('/node/1', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    // Should not throw.
    $subscriber->onRequest($event);
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::onRequest
   */
  public function testIgnoresSubrequests(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);
    $this->routeMatch->method('getRouteName')->willReturn('node.add');

    $subscriber = new NodeFormAccessSubscriber($this->currentUser, $this->routeMatch);

    $request = Request::create('/node/add/service_request', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

    // Should not throw for subrequests.
    $subscriber->onRequest($event);
    $this->addToAssertionCount(1);
  }

}
