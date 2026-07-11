<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_emergency\Service\EmergencySubmissionReplayResponder;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the JSON:API-compatible replay response shape.
 *
 * @group markaspot_emergency
 * @coversDefaultClass \Drupal\markaspot_emergency\Service\EmergencySubmissionReplayResponder
 */
class EmergencySubmissionReplayResponderTest extends UnitTestCase {

  /**
   * @covers ::buildResponse
   */
  public function testBuildsNoStoreJsonApiReplayForPersistedServiceRequest(): void {
    $nodeUuid = '55555555-5555-4555-8555-555555555555';
    $node = $this->createMock(NodeInterface::class);
    $node->method('isNew')->willReturn(FALSE);
    $node->method('uuid')->willReturn($nodeUuid);
    $node->method('id')->willReturn(703);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'uuid' => $nodeUuid,
        'type' => 'service_request',
      ])
      ->willReturn([703 => $node]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);
    $responder = new EmergencySubmissionReplayResponder($entityTypeManager);
    $request = Request::create('https://example.test/jsonapi/node/service_request', 'POST');

    $response = $responder->buildResponse($request, $nodeUuid);
    $document = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame('node--service_request', $document['data']['type']);
    $this->assertSame($nodeUuid, $document['data']['id']);
    $this->assertSame(703, $document['data']['attributes']['drupal_internal__nid']);
    $this->assertTrue($document['meta']['markaspot_idempotent_replay']);
  }

}
