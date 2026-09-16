<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_notification\Unit\Mail;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_notification\Mail\NotifyMailManager;
use Drupal\markaspot_notification\NotificationCollector;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Tests dashboard receipts emitted by the mail manager decorator.
 *
 * @group markaspot_notification
 * @coversDefaultClass \Drupal\markaspot_notification\Mail\NotifyMailManager
 */
final class NotifyMailManagerTest extends UnitTestCase {

  /**
   * Tests that a successful assignee mail records an assignee receipt.
   *
   * @covers ::mail
   */
  public function testAssigneeNotificationRecordsAssigneeReceipt(): void {
    $inner = $this->createMock(MailManagerInterface::class);
    $inner->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_group',
        'assignee_notification',
        'assignee@example.test',
        'de',
        $this->isType('array'),
        NULL,
        TRUE,
      )
      ->willReturn(['result' => TRUE]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('uuid')->willReturn('request-uuid');
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('uuid')->willReturn('user-38');

    $collector = $this->createMock(NotificationCollector::class);
    $collector->expects($this->once())
      ->method('record')
      ->with('request-uuid', 'mail_sent', [
        'recipient_type' => 'assignee',
        'recipient_id' => 'user-38',
      ]);

    $manager = new NotifyMailManager(
      $inner,
      $collector,
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $result = $manager->mail(
      'markaspot_group',
      'assignee_notification',
      'assignee@example.test',
      'de',
      ['node' => $node, 'assignee' => $assignee],
    );

    $this->assertTrue($result['result']);
  }

  /**
   * Tests that a failed assignee mail does not record a receipt.
   *
   * @covers ::mail
   */
  public function testFailedAssigneeNotificationDoesNotRecordReceipt(): void {
    $inner = $this->createMock(MailManagerInterface::class);
    $inner->method('mail')->willReturn(['result' => FALSE]);

    $collector = $this->createMock(NotificationCollector::class);
    $collector->expects($this->never())->method('record');

    $manager = new NotifyMailManager(
      $inner,
      $collector,
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $manager->mail(
      'markaspot_group',
      'assignee_notification',
      'assignee@example.test',
      'de',
      [
        'node' => $this->createMock(NodeInterface::class),
        'assignee' => $this->createMock(UserInterface::class),
      ],
    );
  }

}
