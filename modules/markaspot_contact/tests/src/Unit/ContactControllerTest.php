<?php

namespace Drupal\Tests\markaspot_contact\Unit;

use Drupal\markaspot_contact\ContactServiceInterface;
use Drupal\markaspot_contact\Controller\ContactController;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ContactController.
 *
 * @group markaspot_contact
 * @coversDefaultClass \Drupal\markaspot_contact\Controller\ContactController
 */
class ContactControllerTest extends UnitTestCase {

  /**
   * Mocked contact service.
   *
   * @var \Drupal\markaspot_contact\ContactServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $contactService;

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_contact\Controller\ContactController
   */
  protected ContactController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->contactService = $this->createMock(ContactServiceInterface::class);

    // Use reflection to instantiate without the container.
    $this->controller = new ContactController($this->contactService);
  }

  /**
   * @covers ::submit
   */
  public function testSubmitReturns400WhenNoData(): void {
    $request = Request::create('/api/contact', 'POST', [], [], [], [], '');

    $response = $this->controller->submit($request);

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('No data provided.', $data['message']);
  }

  /**
   * @covers ::submit
   */
  public function testSubmitReturnsSuccessResponse(): void {
    $this->contactService->method('submitContactForm')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Contact form submitted successfully.',
      ]);

    $request = Request::create('/api/contact', 'POST', [], [], [], [], json_encode([
      'name' => 'Test User',
      'email' => 'test@example.com',
      'message' => 'Hello',
    ]));

    $response = $this->controller->submit($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
  }

  /**
   * @covers ::submit
   */
  public function testSubmitReturnsErrorWithValidationErrors(): void {
    $this->contactService->method('submitContactForm')
      ->willReturn([
        'success' => FALSE,
        'message' => 'Validation failed.',
        'code' => 422,
        'errors' => ['email' => 'Invalid email address.'],
      ]);

    $request = Request::create('/api/contact', 'POST', [], [], [], [], json_encode([
      'name' => 'Test',
      'email' => 'invalid',
    ]));

    $response = $this->controller->submit($request);

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Validation failed.', $data['message']);
    $this->assertArrayHasKey('errors', $data);
  }

  /**
   * @covers ::submit
   */
  public function testSubmitReturns500OnServiceError(): void {
    $this->contactService->method('submitContactForm')
      ->willReturn([
        'success' => FALSE,
        'message' => 'Internal error.',
      ]);

    $request = Request::create('/api/contact', 'POST', [], [], [], [], json_encode([
      'name' => 'Test',
    ]));

    $response = $this->controller->submit($request);

    $this->assertEquals(500, $response->getStatusCode());
  }

  /**
   * @covers ::info
   */
  public function testInfoReturnsFormMetadata(): void {
    $formInfo = [
      'fields' => ['name', 'email', 'message'],
      'required' => ['name', 'email'],
    ];

    $this->contactService->method('getFormInfo')
      ->willReturn($formInfo);

    $response = $this->controller->info();

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals($formInfo, $data);
  }

}
