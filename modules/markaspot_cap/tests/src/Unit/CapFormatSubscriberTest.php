<?php

namespace Drupal\Tests\markaspot_cap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\markaspot_cap\EventSubscriber\CapFormatSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the CapFormatSubscriber event subscriber.
 *
 * @group markaspot_cap
 * @coversDefaultClass \Drupal\markaspot_cap\EventSubscriber\CapFormatSubscriber
 */
class CapFormatSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_cap\EventSubscriber\CapFormatSubscriber
   */
  protected CapFormatSubscriber $subscriber;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked emergency config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $emergencyConfig;

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
    $this->emergencyConfig = $this->createMock(ImmutableConfig::class);
    $this->kernel = $this->createMock(HttpKernelInterface::class);

    $this->configFactory->method('get')
      ->with('markaspot_emergency.settings')
      ->willReturn($this->emergencyConfig);

    $this->subscriber = new CapFormatSubscriber($this->configFactory);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = CapFormatSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('onKernelRequest', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(99, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testIgnoresNonCapPaths(): void {
    $request = Request::create('/georeport/v2/requests.json', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    // Should not throw, should not change format.
    $this->subscriber->onKernelRequest($event);

    $this->assertNotEquals('cap', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testThrowsWhenEmergencyModeNotActive(): void {
    $this->emergencyConfig->method('get')
      ->with('emergency_mode.status')
      ->willReturn('off');

    $request = Request::create('/api/cap/v1/alerts', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->expectException(ServiceUnavailableHttpException::class);
    $this->expectExceptionMessage('CAP export is only available when emergency mode is active.');

    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testSetsCapFormatWhenEmergencyActive(): void {
    $this->emergencyConfig->method('get')
      ->with('emergency_mode.status')
      ->willReturn('active');

    $request = Request::create('/api/cap/v1/alerts', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onKernelRequest($event);

    $this->assertEquals('cap', $request->getRequestFormat());
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testMatchesSubpathsOfCapEndpoint(): void {
    $this->emergencyConfig->method('get')
      ->with('emergency_mode.status')
      ->willReturn('active');

    $request = Request::create('/api/cap/v1/alerts/123', 'GET');
    $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->onKernelRequest($event);

    $this->assertEquals('cap', $request->getRequestFormat());
  }

}
