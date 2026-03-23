<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\markaspot_open311\EventSubscriber\RemoveCookieSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the RemoveCookieSubscriber event subscriber.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\EventSubscriber\RemoveCookieSubscriber
 */
class RemoveCookieSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_open311\EventSubscriber\RemoveCookieSubscriber
   */
  protected RemoveCookieSubscriber $subscriber;

  /**
   * Mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

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

    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->kernel = $this->createMock(HttpKernelInterface::class);

    $this->subscriber = new RemoveCookieSubscriber($this->messenger);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = RemoveCookieSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    $this->assertEquals(['onRespond', 2], $events[KernelEvents::RESPONSE][0]);
  }

  /**
   * @covers ::onRespond
   */
  public function testDoesNothingWithoutApiKey(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);

    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $session->expects($this->never())->method('clear');

    $this->subscriber->onRespond($event);
  }

  /**
   * @covers ::onRespond
   */
  public function testClearsSessionForAnonymousWithQueryApiKey(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET', [
      'api_key' => 'test-key',
    ]);
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);

    $session->method('get')->with('uid')->willReturn(0);
    $session->expects($this->once())->method('clear');

    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onRespond($event);
  }

  /**
   * @covers ::onRespond
   */
  public function testClearsSessionForAnonymousWithHeaderApiKey(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $request->headers->set('apikey', 'test-key');
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);

    $session->method('get')->with('uid')->willReturn(NULL);
    $session->expects($this->once())->method('clear');

    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onRespond($event);
  }

  /**
   * @covers ::onRespond
   */
  public function testPreservesSessionForAuthenticatedWithApiKey(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET', [
      'api_key' => 'test-key',
    ]);
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);

    // Authenticated user has a non-zero uid in session.
    $session->method('get')->with('uid')->willReturn(42);
    $session->expects($this->never())->method('clear');

    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onRespond($event);
  }

  /**
   * @covers ::onRespond
   */
  public function testClearsSessionForAnonymousWithFormApiKey(): void {
    $request = Request::create('/georeport/v2/requests.json', 'POST', [], [], [], [], '');
    $request->request->set('api_key', 'test-key');
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);

    $session->method('get')->with('uid')->willReturn(0);
    $session->expects($this->once())->method('clear');

    $response = new Response('content', 200);
    $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onRespond($event);
  }

}
