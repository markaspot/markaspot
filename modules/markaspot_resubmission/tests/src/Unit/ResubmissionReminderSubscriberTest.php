<?php

namespace Drupal\Tests\markaspot_resubmission\Unit;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_resubmission\Event\ResubmissionReminderEvent;
use Drupal\markaspot_resubmission\EventSubscriber\ResubmissionReminderSubscriber;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the ResubmissionReminderSubscriber event subscriber.
 *
 * @group markaspot_resubmission
 * @coversDefaultClass \Drupal\markaspot_resubmission\EventSubscriber\ResubmissionReminderSubscriber
 */
class ResubmissionReminderSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\markaspot_resubmission\EventSubscriber\ResubmissionReminderSubscriber
   */
  protected ResubmissionReminderSubscriber $subscriber;

  /**
   * Mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mailManager;

  /**
   * Mocked language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $languageManager;

  /**
   * Mocked logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked token service.
   *
   * @var \Drupal\Core\Utility\Token|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $token;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->mailManager = $this->createMock(MailManagerInterface::class);
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->token = $this->createMock(Token::class);

    $this->subscriber = new ResubmissionReminderSubscriber(
      $this->mailManager,
      $this->languageManager,
      $this->logger,
      $this->token
    );
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = ResubmissionReminderSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(ResubmissionReminderEvent::EVENT_NAME, $events);
    $this->assertEquals('onReminderSend', $events[ResubmissionReminderEvent::EVENT_NAME][0]);
    $this->assertEquals(100, $events[ResubmissionReminderEvent::EVENT_NAME][1]);
  }

  /**
   * @covers ::onReminderSend
   */
  public function testSendsMailSuccessfully(): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');

    $node = $this->createMock(NodeInterface::class);
    $node->method('language')->willReturn($language);
    $node->method('id')->willReturn('42');
    $node->method('getTitle')->willReturn('Broken streetlight');

    $event = new ResubmissionReminderEvent(
      $node,
      'citizen@example.com',
      'Please check the status',
      2
    );

    $this->mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_resubmission',
        'resubmit_request',
        'citizen@example.com',
        'de',
        $this->callback(function ($params) {
          return $params['node_title'] === 'Broken streetlight'
            && $params['recipient'] === 'citizen@example.com'
            && $params['reminder_count'] === 2;
        }),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('Sent resubmission reminder'),
        $this->callback(function ($context) {
          return $context['@count'] === 2
            && $context['@nid'] === '42'
            && $context['@email'] === 'citizen@example.com';
        })
      );

    $this->subscriber->onReminderSend($event);
  }

  /**
   * @covers ::onReminderSend
   */
  public function testLogsErrorOnMailFailure(): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $node = $this->createMock(NodeInterface::class);
    $node->method('language')->willReturn($language);
    $node->method('id')->willReturn('99');
    $node->method('getTitle')->willReturn('Pothole');

    $event = new ResubmissionReminderEvent(
      $node,
      'user@example.com',
      'Reminder text',
      1
    );

    $this->mailManager->method('mail')
      ->willReturn(['result' => FALSE]);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to send'),
        $this->callback(function ($context) {
          return $context['@nid'] === '99'
            && $context['@email'] === 'user@example.com';
        })
      );

    $this->subscriber->onReminderSend($event);
  }

}
