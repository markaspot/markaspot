<?php

namespace Drupal\Tests\markaspot_confirm\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\markaspot_confirm\Controller\ConfirmController;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ConfirmController.
 *
 * @group markaspot_confirm
 * @coversDefaultClass \Drupal\markaspot_confirm\Controller\ConfirmController
 */
class ConfirmControllerTest extends UnitTestCase {

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_confirm\Controller\ConfirmController
   */
  protected ConfirmController $controller;

  /**
   * Mocked confirm service.
   *
   * @var object|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $confirmService;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->confirmService = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['load'])
      ->getMock();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);

    $this->configFactory->method('get')
      ->with('markaspot_confirm.settings')
      ->willReturn($this->config);

    $this->config->method('get')
      ->willReturnMap([
        ['api.success_message', 'Thanks for approving this request.'],
        ['api.already_confirmed_message', 'This request has already been confirmed.'],
        ['api.not_found_message', 'This request could not be found.'],
      ]);

    $this->controller = new ConfirmController($this->confirmService, $this->configFactory);

    // Set up string translation.
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnArgument(0);
    $this->controller->setStringTranslation($translation);
  }

  /**
   * @covers ::doConfirm
   */
  public function testDoConfirmReturnsNotFoundJsonWhenNoNodes(): void {
    $this->confirmService->method('load')
      ->with('test-uuid')
      ->willReturn([]);

    $request = Request::create('/confirm/test-uuid', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = $this->controller->doConfirm('test-uuid', $request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(404, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['success']);
    $this->assertEquals('not_found', $data['status']);
  }

  /**
   * @covers ::doConfirm
   */
  public function testDoConfirmReturnsMarkupWhenNoNodesAndHtmlRequest(): void {
    $this->confirmService->method('load')
      ->with('test-uuid')
      ->willReturn([]);

    $request = Request::create('/confirm/test-uuid', 'GET');

    $result = $this->controller->doConfirm('test-uuid', $request);

    $this->assertIsArray($result);
    $this->assertEquals('markup', $result['#type']);
  }

  /**
   * @covers ::doConfirm
   */
  public function testDoConfirmNewlyConfirmsNode(): void {
    $node = $this->createMock(NodeInterface::class);
    // The field_approved->value access pattern.
    $node->field_approved = (object) ['value' => 0];
    $node->expects($this->once())->method('save');

    $this->confirmService->method('load')
      ->with('test-uuid')
      ->willReturn([$node]);

    $request = Request::create('/confirm/test-uuid', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = $this->controller->doConfirm('test-uuid', $request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertEquals('confirmed', $data['status']);
    $this->assertEquals(1, $data['newly_confirmed_count']);
    $this->assertEquals(0, $data['already_confirmed_count']);
  }

  /**
   * @covers ::doConfirm
   */
  public function testDoConfirmAlreadyConfirmedNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->field_approved = (object) ['value' => 1];
    $node->expects($this->never())->method('save');

    $this->confirmService->method('load')
      ->with('test-uuid')
      ->willReturn([$node]);

    $request = Request::create('/confirm/test-uuid', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = $this->controller->doConfirm('test-uuid', $request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertEquals('already_confirmed', $data['status']);
    $this->assertTrue($data['already_confirmed']);
    $this->assertEquals(0, $data['newly_confirmed_count']);
    $this->assertEquals(1, $data['already_confirmed_count']);
  }

  /**
   * @covers ::doConfirm
   */
  public function testDoConfirmWithFormatQueryParam(): void {
    $this->confirmService->method('load')
      ->with('test-uuid')
      ->willReturn([]);

    $request = Request::create('/confirm/test-uuid', 'GET', ['_format' => 'json']);

    $response = $this->controller->doConfirm('test-uuid', $request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(404, $response->getStatusCode());
  }

}
