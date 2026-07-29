<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Plugin\Action;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_mail\Plugin\Action\SendTermNotificationMail;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests status-term notification dispatch.
 */
#[CoversClass(\Drupal\markaspot_mail\Plugin\Action\SendTermNotificationMail::class)]
#[Group('markaspot_mail')]
final class SendTermNotificationMailTest extends UnitTestCase {

  /**
   * Empty keys are a silent no-op and never consume flood capacity.
   */
  public function testEmptyNotificationKeySkipsSilently(): void {
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $flood->expects($this->never())->method('register');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->never())->method('info');

    $action = $this->buildAction(
      mailManager: $mail_manager,
      flood: $flood,
      logger: $logger,
    );
    $action->execute($this->buildNode(''));
  }

  /**
   * A term key uses the shared mail path and per-recipient throttle.
   */
  public function testNotificationKeySendsThroughThrottle(): void {
    $node = $this->buildNode('status_closed', 'de');

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturn('citizen@example.com');

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('markaspot_mail.notification_recipient', 5, 3600, 'citizen@example.com')
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('markaspot_mail.notification_recipient', 3600, 'citizen@example.com');

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_mail',
        'notification_status_closed',
        'citizen@example.com',
        'de',
        $this->callback(
          static fn (array $params): bool => $params['node'] === $node
            && $params['notification_key'] === 'status_closed',
        ),
      )
      ->willReturn(['result' => TRUE]);

    $action = $this->buildAction(
      mailManager: $mail_manager,
      token: $token,
      flood: $flood,
    );
    $action->execute($node);
  }

  /**
   * Non-node values are ignored before field access.
   */
  public function testNonNodeSkips(): void {
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');

    $this->buildAction(mailManager: $mail_manager, flood: $flood)->execute(NULL);
  }

  /**
   * Nodes without field_status are ignored.
   */
  public function testMissingStatusFieldSkips(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_status')->willReturn(FALSE);

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');

    $this->buildAction(mailManager: $mail_manager, flood: $flood)->execute($node);
  }

  /**
   * Builds a service request with a referenced status term.
   */
  private function buildNode(string $notificationKey, string $langcode = 'en'): NodeInterface {
    $notification_field = $this->createMock(FieldItemListInterface::class);
    $notification_field->method('isEmpty')->willReturn($notificationKey === '');
    $notification_field->method('getValue')->willReturn(
      $notificationKey === '' ? [] : [['value' => $notificationKey]],
    );

    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')
      ->with('field_notification_key')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_notification_key')
      ->willReturn($notification_field);

    $status_field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $status_field->method('isEmpty')->willReturn(FALSE);
    $status_field->method('referencedEntities')->willReturn([$term]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->method('hasField')->with('field_status')->willReturn(TRUE);
    $node->method('get')->with('field_status')->willReturn($status_field);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    $node->method('language')->willReturn($language);
    return $node;
  }

  /**
   * Builds the action with default or injected collaborators.
   */
  private function buildAction(
    ?MailManagerInterface $mailManager = NULL,
    ?Token $token = NULL,
    ?FloodInterface $flood = NULL,
    ?LoggerInterface $logger = NULL,
  ): SendTermNotificationMail {
    if ($token === NULL) {
      $token = $this->createMock(Token::class);
      $token->method('replace')->willReturn('citizen@example.com');
    }

    if ($mailManager === NULL) {
      $mailManager = $this->createMock(MailManagerInterface::class);
      $mailManager->method('mail')->willReturn(['result' => TRUE]);
    }

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['recipient_flood.limit', 5],
      ['recipient_flood.window', 3600],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('markaspot_mail.settings')
      ->willReturn($settings);

    if ($flood === NULL) {
      $flood = $this->createMock(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }

    return new SendTermNotificationMail(
      [
        'object' => 'entity',
        'recipient' => '[node:field_e_mail:value]',
      ],
      'markaspot_mail_send_term_notification',
      ['id' => 'markaspot_mail_send_term_notification'],
      $token,
      $mailManager,
      $config_factory,
      $flood,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
