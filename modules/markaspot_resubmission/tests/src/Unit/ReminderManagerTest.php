<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_resubmission\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\markaspot_resubmission\Entity\ResubmissionReminder;
use Drupal\markaspot_resubmission\ReminderManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests reminder audit creation.
 *
 * @group markaspot_resubmission
 * @coversDefaultClass \Drupal\markaspot_resubmission\ReminderManager
 */
class ReminderManagerTest extends UnitTestCase {

  /**
   * Tests createReminder writes the reminder scope to the audit entity.
   *
   * @covers ::createReminder
   */
  public function testCreateReminderWritesReminderScope(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->expects($this->once())
      ->method('hasField')
      ->with('field_status')
      ->willReturn(FALSE);

    $count_query = $this->createMock(QueryInterface::class);
    $count_query->expects($this->once())
      ->method('condition')
      ->with('nid', 42)
      ->willReturnSelf();
    $count_query->expects($this->once())
      ->method('accessCheck')
      ->with(FALSE)
      ->willReturnSelf();
    $count_query->expects($this->once())
      ->method('count')
      ->willReturnSelf();
    $count_query->expects($this->once())
      ->method('execute')
      ->willReturn(0);

    $reminder = $this->createMock(ContentEntityInterface::class);
    $reminder->expects($this->once())->method('save');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('getQuery')
      ->willReturn($count_query);
    $storage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values): bool {
        return $values['nid'] === 42
          && $values['sent_timestamp'] === 123456
          && $values['recipient_email'] === 'recipient@example.com'
          && $values['status'] === 'sent'
          && $values['reminder_count'] === 1
          && $values['reminder_scope'] === ResubmissionReminder::REMINDER_SCOPE_USER
          && $values['node_status'] === ''
          && $values['error_message'] === NULL;
      }))
      ->willReturn($reminder);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->exactly(2))
      ->method('getStorage')
      ->with('resubmission_reminder')
      ->willReturn($storage);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('info');

    $time = $this->createMock(TimeInterface::class);
    $time->expects($this->once())
      ->method('getRequestTime')
      ->willReturn(123456);

    $manager = new ReminderManager(
      $entity_type_manager,
      $this->createMock(ConfigFactoryInterface::class),
      $logger,
      $time,
    );

    $this->assertSame(
      $reminder,
      $manager->createReminder(
        $node,
        'recipient@example.com',
        'sent',
        NULL,
        ResubmissionReminder::REMINDER_SCOPE_USER,
      )
    );
  }

}
