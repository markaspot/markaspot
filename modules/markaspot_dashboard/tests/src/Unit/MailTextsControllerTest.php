<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_dashboard\Controller\MailTextsController;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsConflictException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsForbiddenException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsNotFoundException;
use Drupal\markaspot_dashboard\Service\MailTextsServiceInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests MailTextsController's exception-to-status-code mapping.
 *
 * Everything else (validation, config I/O, token catalog, ECA scanning)
 * lives in MailTextsService and is covered by MailTextsServiceTest; this
 * suite only asserts the thin controller wires requests/responses and
 * status codes correctly.
 *
 * @group markaspot_dashboard
 * @coversDefaultClass \Drupal\markaspot_dashboard\Controller\MailTextsController
 */
class MailTextsControllerTest extends UnitTestCase {

  /**
   * Mocked mail texts service.
   *
   * @var \Drupal\markaspot_dashboard\Service\MailTextsServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mailTextsService;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mailTextsService = $this->createMock(MailTextsServiceInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
  }

  /**
   * Builds the controller under test.
   */
  protected function buildController(): MailTextsController {
    return new MailTextsController($this->mailTextsService, $this->currentUser);
  }

  /**
   * @covers ::catalog
   */
  public function testCatalogReturnsServicePayload(): void {
    $this->mailTextsService->method('getCatalog')->willReturn(['texts' => [], 'standard_keys' => [], 'tokens' => []]);

    $response = $this->buildController()->catalog();

    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * @covers ::save
   */
  public function testSaveReturns422OnValidationFailure(): void {
    $this->mailTextsService->method('saveText')->willThrowException(new \InvalidArgumentException('subject exceeds the maximum length of 200 characters.'));

    $request = Request::create('/api/dashboard/mail-texts/custom_key', 'PUT', content: json_encode(['subject' => str_repeat('x', 201)]));
    $response = $this->buildController()->save('custom_key', $request);

    self::assertSame(422, $response->getStatusCode());
    self::assertSame(
      'subject exceeds the maximum length of 200 characters.',
      json_decode((string) $response->getContent(), TRUE)['error'],
    );
  }

  /**
   * @covers ::save
   */
  public function testSaveReturns400OnInvalidJsonBody(): void {
    $request = Request::create('/api/dashboard/mail-texts/custom_key', 'PUT', content: '{not json');
    $response = $this->buildController()->save('custom_key', $request);

    self::assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::save
   */
  public function testSaveReturns200WithCreatedFlag(): void {
    $this->mailTextsService->method('saveText')->willReturn([
      'key' => 'custom_key',
      'text' => ['subject' => '', 'standard' => FALSE],
      'created' => TRUE,
    ]);

    $request = Request::create('/api/dashboard/mail-texts/custom_key', 'PUT', content: '{}');
    $response = $this->buildController()->save('custom_key', $request);

    self::assertSame(200, $response->getStatusCode());
    self::assertTrue(json_decode((string) $response->getContent(), TRUE)['created']);
  }

  /**
   * @covers ::delete
   */
  public function testDeleteReturns404WhenKeyMissing(): void {
    $this->mailTextsService->method('deleteText')->willThrowException(new MailTextsNotFoundException('Key "x" does not exist.'));

    $response = $this->buildController()->delete('x');

    self::assertSame(404, $response->getStatusCode());
  }

  /**
   * @covers ::delete
   */
  public function testDeleteReturns403ForStandardKey(): void {
    $this->mailTextsService->method('deleteText')->willThrowException(new MailTextsForbiddenException('Standard keys cannot be deleted'));

    $response = $this->buildController()->delete('status_open');

    self::assertSame(403, $response->getStatusCode());
    self::assertSame(
      'Standard keys cannot be deleted',
      json_decode((string) $response->getContent(), TRUE)['error'],
    );
  }

  /**
   * @covers ::delete
   */
  public function testDeleteReturns409WithReferencedByOnConflict(): void {
    $this->mailTextsService->method('deleteText')->willThrowException(
      new MailTextsConflictException('Key "x" is still referenced by an ECA action.', ['process_ampel']),
    );

    $response = $this->buildController()->delete('x');
    $payload = json_decode((string) $response->getContent(), TRUE);

    self::assertSame(409, $response->getStatusCode());
    self::assertSame(['process_ampel'], $payload['referenced_by']);
  }

  /**
   * @covers ::delete
   */
  public function testDeleteReturns200OnSuccess(): void {
    $this->mailTextsService->method('deleteText')->willReturn('custom_key');

    $response = $this->buildController()->delete('custom_key');
    $payload = json_decode((string) $response->getContent(), TRUE);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame('custom_key', $payload['deleted']);
  }

  /**
   * @covers ::preview
   */
  public function testPreviewReturns200WithResolvedSlots(): void {
    $this->mailTextsService->method('preview')->willReturn([
      'subject' => 'Report #486-2026',
      'intro' => '',
      'body_blocks' => [],
      'sample_request_id' => '486-2026',
    ]);

    $request = Request::create('/api/dashboard/mail-texts/preview', 'POST', content: json_encode(['subject' => 'Report #[node:request_id]']));
    $response = $this->buildController()->preview($request);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame('486-2026', json_decode((string) $response->getContent(), TRUE)['sample_request_id']);
  }

}
