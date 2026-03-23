<?php

namespace Drupal\Tests\markaspot_ui\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_ui\EventSubscriber\AnonymousRedirectSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the AnonymousRedirectSubscriber event subscriber.
 *
 * @group markaspot_ui
 * @coversDefaultClass \Drupal\markaspot_ui\EventSubscriber\AnonymousRedirectSubscriber
 */
class AnonymousRedirectSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_ui\EventSubscriber\AnonymousRedirectSubscriber
   */
  protected AnonymousRedirectSubscriber $subscriber;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

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
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->kernel = $this->createMock(HttpKernelInterface::class);

    $this->configFactory->method('get')
      ->with('markaspot_ui.settings')
      ->willReturn($this->config);

    $this->subscriber = new AnonymousRedirectSubscriber(
      $this->currentUser,
      $this->logger,
      $this->configFactory
    );
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = AnonymousRedirectSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals(['onRequest', 300], $events[KernelEvents::REQUEST][0]);
  }

  /**
   * @covers ::onRequest
   */
  public function testDoesNothingWhenFeatureDisabled(): void {
    $this->config->method('get')
      ->with('headless_mode_protection')
      ->willReturn(FALSE);

    $request = Request::create('/admin/content', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testAllowsAuthenticatedUsers(): void {
    $this->config->method('get')
      ->with('headless_mode_protection')
      ->willReturn(TRUE);

    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);

    $request = Request::create('/admin/content', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testIgnoresSubrequests(): void {
    $this->config->method('get')
      ->with('headless_mode_protection')
      ->willReturn(TRUE);

    $request = Request::create('/admin/content', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

    $this->subscriber->onRequest($event);

    $this->assertNull($event->getResponse());
  }

  /**
   * Tests that anonymous access to non-protected paths is allowed.
   *
   * @covers ::onRequest
   */
  public function testAllowsNonProtectedPaths(): void {
    $this->config->method('get')
      ->with('headless_mode_protection')
      ->willReturn(TRUE);

    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);

    $allowedPaths = [
      '/jsonapi/node/service_request',
      '/user/login',
      '/user/password',
      '/georeport/v2/requests.json',
      '/sites/default/files/image.jpg',
    ];

    foreach ($allowedPaths as $path) {
      $request = Request::create($path, 'GET');
      $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

      $this->subscriber->onRequest($event);

      $this->assertNull($event->getResponse(), "Path $path should not be redirected");
    }
  }

  /**
   * Tests that protected paths trigger a redirect attempt.
   *
   * Since Url::fromRoute() requires the Drupal container, we verify that
   * the path matching works correctly by catching the expected exception
   * from the missing container. The logger call proves the path was matched.
   *
   * @covers ::onRequest
   * @dataProvider protectedPathsProvider
   */
  public function testIdentifiesProtectedPaths(string $path): void {
    $this->config->method('get')
      ->with('headless_mode_protection')
      ->willReturn(TRUE);

    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);

    $loggerCalled = FALSE;
    $this->logger->expects($this->once())
      ->method('warning')
      ->willReturnCallback(function () use (&$loggerCalled) {
        $loggerCalled = TRUE;
      });

    $request = Request::create($path, 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    // Url::fromRoute needs a container; catch the expected exception.
    try {
      $this->subscriber->onRequest($event);
    }
    catch (\Exception $e) {
      // Expected: ContainerNotInitializedException from Url::fromRoute().
    }

    // The logger was called, confirming the path was matched as protected.
    $this->assertTrue($loggerCalled, "Path $path should be matched as protected");
  }

  /**
   * Provides protected paths for testing.
   *
   * @return array
   *   Array of path strings.
   */
  public static function protectedPathsProvider(): array {
    return [
      'admin path' => ['/admin/content'],
      'node path' => ['/node/1'],
      'node add' => ['/node/add/service_request'],
      'user register' => ['/user/register'],
      'comment' => ['/comment/1'],
      'group' => ['/group/1'],
      'media' => ['/media/1'],
      'taxonomy' => ['/taxonomy/term/1'],
    ];
  }

}
