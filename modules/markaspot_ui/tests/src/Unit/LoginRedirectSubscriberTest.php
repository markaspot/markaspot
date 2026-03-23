<?php

namespace Drupal\Tests\markaspot_ui\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_ui\EventSubscriber\LoginRedirectSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the LoginRedirectSubscriber event subscriber.
 *
 * @group markaspot_ui
 * @coversDefaultClass \Drupal\markaspot_ui\EventSubscriber\LoginRedirectSubscriber
 */
class LoginRedirectSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_ui\EventSubscriber\LoginRedirectSubscriber
   */
  protected LoginRedirectSubscriber $subscriber;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

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

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->kernel = $this->createMock(HttpKernelInterface::class);

    $this->configFactory->method('get')
      ->with('markaspot_ui.settings')
      ->willReturn($this->config);

    $this->subscriber = new LoginRedirectSubscriber(
      $this->configFactory,
      $this->currentUser,
      $this->requestStack
    );
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = LoginRedirectSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    $this->assertEquals(['onResponse', 10], $events[KernelEvents::RESPONSE][0]);
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresSubrequests(): void {
    $request = Request::create('/user/login', 'POST');
    $response = new RedirectResponse('/user/1');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

    $this->subscriber->onResponse($event);

    // Response should not be changed.
    $this->assertSame($response, $event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresNonRedirectResponses(): void {
    $request = Request::create('/user/login', 'POST');
    $request->attributes->set('_route', 'user.login');
    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertSame($response, $event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresNonLoginRoutes(): void {
    $request = Request::create('/node/1', 'GET');
    $request->attributes->set('_route', 'entity.node.canonical');
    $response = new RedirectResponse('/');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertSame($response, $event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresGetRequestsOnLoginRoute(): void {
    $request = Request::create('/user/login', 'GET');
    $request->attributes->set('_route', 'user.login');
    $response = new RedirectResponse('/');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onResponse($event);

    $this->assertSame($response, $event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresWhenUserNotAuthenticated(): void {
    $request = Request::create('/user/login', 'POST');
    $request->attributes->set('_route', 'user.login');
    $response = new RedirectResponse('/');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);

    $this->subscriber->onResponse($event);

    $this->assertSame($response, $event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresWhenRedirectDisabled(): void {
    $request = Request::create('/user/login', 'POST');
    $request->attributes->set('_route', 'user.login');
    $response = new RedirectResponse('/');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->config->method('get')
      ->willReturnMap([
        ['login_redirect_enabled', FALSE],
      ]);

    $this->subscriber->onResponse($event);

    $this->assertSame($response, $event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresPasswordResetLogin(): void {
    $session = $this->createMock(SessionInterface::class);
    $session->method('has')
      ->with('pass_reset_42')
      ->willReturn(TRUE);

    $request = Request::create('/user/login', 'POST');
    $request->attributes->set('_route', 'user.login');
    $request->setSession($session);
    $response = new RedirectResponse('/');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->currentUser->method('id')->willReturn(42);
    $this->config->method('get')
      ->willReturnMap([
        ['login_redirect_enabled', TRUE],
      ]);

    $this->subscriber->onResponse($event);

    $this->assertSame($response, $event->getResponse());
  }

  /**
   * @covers ::onResponse
   */
  public function testIgnoresWhenDestinationParamExists(): void {
    $request = Request::create('/user/login', 'POST', [], [], [], [
      'QUERY_STRING' => 'destination=/admin/content',
    ]);
    $request->query->set('destination', '/admin/content');
    $request->attributes->set('_route', 'user.login');

    $session = $this->createMock(SessionInterface::class);
    $session->method('has')->willReturn(FALSE);
    $request->setSession($session);

    $response = new RedirectResponse('/');
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->currentUser->method('id')->willReturn(5);
    $this->config->method('get')
      ->willReturnMap([
        ['login_redirect_enabled', TRUE],
      ]);

    $this->subscriber->onResponse($event);

    $this->assertSame($response, $event->getResponse());
  }

}
