<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\node\NodeInterface;
use Drupal\service_request\RevisionLog;
use Drupal\Tests\UnitTestCase;

/**
 * Protects the shared language-neutral revision log contract.
 *
 * @group service_request
 */
final class RevisionLogTest extends UnitTestCase {

  /**
   * Structured actions retain the exact manual reason and reject invalid data.
   */
  public function testVersionedActionsPreserveOriginalReasons(): void {
    $entry = [
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [[
        'field' => 'responsibility',
        'before' => [['id' => '1', 'label' => 'Tiefbau']],
        'after' => [['id' => '2', 'label' => 'Stadtgrün']],
      ]],
      'message' => 'Bitte fachlich prüfen. Please review.',
    ];
    $value = json_encode($entry, JSON_THROW_ON_ERROR);
    $this->assertSame($entry, RevisionLog::decode($value));
    $this->assertSame($entry['message'], RevisionLog::message($value));
    $this->assertSame('Legacy reason', RevisionLog::message('Legacy reason'));
    $entry['changes'][0]['after'][0]['label'] = ['unexpected'];
    $this->assertNull(RevisionLog::decode(json_encode($entry, JSON_THROW_ON_ERROR)));
    $entry['changes'] = [['field' => 'private_data', 'before' => [], 'after' => []]];
    $this->assertNull(RevisionLog::decode(json_encode($entry, JSON_THROW_ON_ERROR)));
  }

  /**
   * An explicit repeated reason differs from a copied previous log.
   */
  public function testInheritedLegacyReasonAndDeliberatelyRepeatedReasonDiffer(): void {
    $original = $this->createMock(NodeInterface::class);
    $original->method('getRevisionLogMessage')->willReturn('Repeated original reason');
    $entity = $this->createMock(NodeInterface::class);
    $entity->method('getOriginal')->willReturn($original);
    $entity->method('getRevisionLogMessage')->willReturn('Repeated original reason');
    $entity->method('hasField')->willReturn(FALSE);
    $this->assertSame('', RevisionLog::message(RevisionLog::build($entity, [])));
    RevisionLog::setMessage($entity, 'Repeated original reason');
    $this->assertSame('Repeated original reason', RevisionLog::pendingMessage($entity));
    $this->assertSame('Repeated original reason', RevisionLog::message(RevisionLog::build($entity, [])));
    $this->assertNull(RevisionLog::pendingMessage($entity));
  }

  /**
   * Automatic codes are deduplicated and consumed without copying prose.
   */
  public function testAutomaticEventsAreIndependentOfHumanReasons(): void {
    $entity = $this->createMock(NodeInterface::class);
    $entity->method('hasField')->willReturn(FALSE);
    RevisionLog::setEvent($entity, 'delegated');
    RevisionLog::setEvent($entity, 'delegated');
    RevisionLog::setMessage($entity, 'Bitte prüfen. Please review.');
    $entry = RevisionLog::decode(RevisionLog::build($entity, []));
    $this->assertSame(['delegated'], $entry['events']);
    $this->assertSame('Bitte prüfen. Please review.', $entry['message']);
    $this->assertSame([], RevisionLog::decode(RevisionLog::build($entity, []))['events']);
    $entry['events'] = ['translated prose'];
    $decoded = RevisionLog::decode(json_encode($entry, JSON_THROW_ON_ERROR));
    $this->assertSame([], $decoded['events']);
    $this->assertSame($entry['message'], $decoded['message']);
    $this->assertSame($entry['changes'], $decoded['changes']);
    $entry['events'] = 'invalid shape';
    $this->assertNull(RevisionLog::decode(json_encode($entry, JSON_THROW_ON_ERROR)));
    $this->expectException(\InvalidArgumentException::class);
    RevisionLog::setEvent($entity, 'unknown');
  }

}
