<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Path\CurrentPathStack;
use Drupal\markaspot_open311\EventSubscriber\GeoreportEventSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Tests the GeoreportEventSubscriber.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\EventSubscriber\GeoreportEventSubscriber
 */
class GeoreportEventSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_open311\EventSubscriber\GeoreportEventSubscriber
   */
  protected GeoreportEventSubscriber $subscriber;

  /**
   * Mocked serializer.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $serializer;

  /**
   * Mocked current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentPath;

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

    $this->serializer = $this->createMock(SerializerInterface::class);
    $this->currentPath = $this->createMock(CurrentPathStack::class);
    $this->kernel = $this->createMock(HttpKernelInterface::class);

    $this->subscriber = new GeoreportEventSubscriber(
      $this->serializer,
      $this->currentPath
    );
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = GeoreportEventSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
    $this->assertEquals(['onException'], $events[KernelEvents::EXCEPTION][0]);
  }

  /**
   * @covers ::onException
   */
  public function testIgnoresNonGeoreportPaths(): void {
    $this->currentPath->method('getPath')->willReturn('/node/1');

    $exception = new \RuntimeException('Test error');
    $request = Request::create('/node/1');
    $event = new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    $this->subscriber->onException($event);

    // No response should be set for non-georeport paths.
    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onException
   */
  public function testHandlesGenericExceptionOnGeoreportPath(): void {
    $this->currentPath->method('getPath')
      ->willReturn('/georeport/v2/requests.json');

    $exception = new \RuntimeException('Something went wrong', 500);

    $request = Request::create('/georeport/v2/requests.json');
    $event = new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    $this->serializer->method('serialize')
      ->willReturn('{"error":{"code":500,"description":"Something went wrong"}}');

    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertEquals(500, $response->getStatusCode());
    $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
  }

  /**
   * @covers ::onException
   */
  public function testHandlesXmlFormatException(): void {
    $this->currentPath->method('getPath')
      ->willReturn('/georeport/v2/requests.xml');

    $exception = new \RuntimeException('Bad XML', 400);

    $request = Request::create('/georeport/v2/requests.xml');
    $event = new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    $this->serializer->method('serialize')
      ->with($this->anything(), 'xml')
      ->willReturn('<error><code>400</code></error>');

    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
  }

  /**
   * @covers ::onException
   */
  public function testHandlesZeroExceptionCodeAs500(): void {
    $this->currentPath->method('getPath')
      ->willReturn('/georeport/v2/requests.json');

    $exception = new \RuntimeException('Unknown error', 0);

    $request = Request::create('/georeport/v2/requests.json');
    $event = new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    $this->serializer->method('serialize')
      ->willReturn('{"error":"Unknown error"}');

    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertEquals(500, $response->getStatusCode());
  }

}
