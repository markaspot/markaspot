<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Plugin\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_mail\Plugin\Action\SendNotificationMail;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit coverage for the per-recipient flood throttle in SendNotificationMail.
 */
#[CoversClass(\Drupal\markaspot_mail\Plugin\Action\SendNotificationMail::class)]
#[Group('markaspot_mail')]
final class SendNotificationMailTest extends UnitTestCase {

  /**
   * Non-node entities are ignored without touching mail or flood.
   */
  public function testExecuteSkipsWhenEntityIsNotNode(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');

    $action = $this->buildAction(['notification_key' => 'status_open'], mailManager: $mailManager, logger: $logger);
    $action->execute(NULL);
  }

  /**
   * A missing notification key skips the send silently.
   */
  public function testExecuteSkipsWhenNotificationKeyMissing(): void {
    $node = $this->buildNode();

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');

    $action = $this->buildAction(['notification_key' => ''], mailManager: $mailManager, logger: $logger);
    $action->execute($node);
  }

  /**
   * An unresolvable recipient logs a warning and sends nothing.
   */
  public function testExecuteSkipsWhenRecipientResolvesEmpty(): void {
    $node = $this->buildNode();

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturn('');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');

    $action = $this->buildAction(
      ['notification_key' => 'status_open', 'recipient' => '[node:field_e_mail:value]'],
      mailManager: $mailManager,
      token: $token,
      logger: $logger,
    );
    $action->execute($node);
  }

  /**
   * The happy path mails with the node langcode and registers the flood event.
   */
  public function testExecuteSendsWithNodeLangcodeAndConfiguredKey(): void {
    $node = $this->buildNode(langcode: 'de');

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

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_mail',
        'notification_status_closed',
        'citizen@example.com',
        'de',
        $this->callback(fn (array $params): bool => $params['node'] === $node && $params['notification_key'] === 'status_closed'),
      )
      ->willReturn(['result' => TRUE]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('info');

    $action = $this->buildAction(
      ['notification_key' => 'status_closed', 'recipient' => '[node:field_e_mail:value]'],
      mailManager: $mailManager,
      token: $token,
      flood: $flood,
      logger: $logger,
    );
    $action->execute($node);
  }

  /**
   * Tests denied recipients are not sent another notification.
   */
  public function testExecuteSkipsFloodedRecipientWithoutThrowing(): void {
    $node = $this->buildNode();

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturn('Citizen@Example.COM');

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('markaspot_mail.notification_recipient', 5, 3600, 'citizen@example.com')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->never())->method('mail');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('address hash'),
        $this->callback(static fn (array $context): bool => ($context['@recipient_hash'] ?? '') === substr(hash('sha256', 'citizen@example.com'), 0, 12)
          && !in_array('Citizen@Example.COM', $context, TRUE)
          && !in_array('citizen@example.com', $context, TRUE)),
      );

    $action = $this->buildAction(
      ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
      mailManager: $mailManager,
      token: $token,
      flood: $flood,
      logger: $logger,
    );

    $action->execute($node);
  }

  /**
   * Tests recipient casing shares one flood bucket.
   */
  public function testRecipientCasingUsesSameFloodIdentifier(): void {
    $node = $this->buildNode();

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnOnConsecutiveCalls(
      'User@Example.ORG',
      'user@example.org',
    );

    $checked_identifiers = [];
    $registered_identifiers = [];
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function ($event, $limit, $window, $identifier) use (&$checked_identifiers): bool {
        $checked_identifiers[] = [$event, $limit, $window, $identifier];
        return TRUE;
      });
    $flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(static function ($event, $window, $identifier) use (&$registered_identifiers): void {
        $registered_identifiers[] = [$event, $window, $identifier];
      });

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->exactly(2))
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $action = $this->buildAction(
      ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
      mailManager: $mailManager,
      token: $token,
      flood: $flood,
    );
    $action->execute($node);
    $action->execute($node);

    $this->assertSame([
      ['markaspot_mail.notification_recipient', 5, 3600, 'user@example.org'],
      ['markaspot_mail.notification_recipient', 5, 3600, 'user@example.org'],
    ], $checked_identifiers);
    $this->assertSame([
      ['markaspot_mail.notification_recipient', 3600, 'user@example.org'],
      ['markaspot_mail.notification_recipient', 3600, 'user@example.org'],
    ], $registered_identifiers);
  }

  /**
   * Tests missing and empty flood settings use safe defaults.
   */
  public function testMissingOrEmptyFloodConfigurationUsesDefaults(): void {
    foreach ([NULL, []] as $recipient_flood) {
      $flood = $this->createMock(FloodInterface::class);
      $flood->expects($this->once())
        ->method('isAllowed')
        ->with('markaspot_mail.notification_recipient', 5, 3600, 'citizen@example.com')
        ->willReturn(TRUE);
      $flood->expects($this->once())
        ->method('register')
        ->with('markaspot_mail.notification_recipient', 3600, 'citizen@example.com');

      $action = $this->buildAction(
        ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
        flood: $flood,
        configFactory: $this->buildConfigFactory($recipient_flood),
      );
      $action->execute($this->buildNode());
    }
  }

  /**
   * Tests each address in a recipient list uses its own stable bucket.
   */
  public function testRecipientListsAreThrottledPerAddress(): void {
    $node = $this->buildNode();

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnOnConsecutiveCalls(
      'Victim@Example.org, Other@example.net',
      'third@example.net, "Doe, Victim" <victim@example.ORG>',
    );

    $checked_identifiers = [];
    $registered_identifiers = [];
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(4))
      ->method('isAllowed')
      ->willReturnCallback(static function ($event, $limit, $window, $identifier) use (&$checked_identifiers): bool {
        $checked_identifiers[] = $identifier;
        return TRUE;
      });
    $flood->expects($this->exactly(4))
      ->method('register')
      ->willReturnCallback(static function ($event, $window, $identifier) use (&$registered_identifiers): void {
        $registered_identifiers[] = $identifier;
      });

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->exactly(2))
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $action = $this->buildAction(
      ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
      mailManager: $mail_manager,
      token: $token,
      flood: $flood,
    );
    $action->execute($node);
    $action->execute($node);

    $this->assertSame([
      'victim@example.org',
      'other@example.net',
      'third@example.net',
      'victim@example.org',
    ], $checked_identifiers);
    $this->assertSame($checked_identifiers, $registered_identifiers);
  }

  /**
   * Tests long addresses cannot exceed the flood backend identifier width.
   */
  public function testLongRecipientUsesFixedLengthFloodIdentifier(): void {
    $recipient = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.example.org';
    $identifier = 'sha256:' . hash('sha256', mb_strtolower($recipient));

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturn($recipient);

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('markaspot_mail.notification_recipient', 5, 3600, $identifier)
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('markaspot_mail.notification_recipient', 3600, $identifier);

    $action = $this->buildAction(
      ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
      token: $token,
      flood: $flood,
    );
    $action->execute($this->buildNode());

    $this->assertLessThanOrEqual(128, strlen($identifier));
  }

  /**
   * Tests failed delivery does not consume a recipient flood slot.
   */
  public function testFailedMailDoesNotRegisterFloodEvent(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->willReturn(TRUE);
    $flood->expects($this->never())->method('register');

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => FALSE]);

    $action = $this->buildAction(
      ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
      mailManager: $mail_manager,
      flood: $flood,
    );
    $action->execute($this->buildNode());
  }

  /**
   * Tests flood backend failures cannot escape into report creation.
   */
  public function testFloodBackendFailuresDoNotEscape(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->willThrowException(new \RuntimeException('backend exposed citizen@example.com'));
    $flood->expects($this->once())
      ->method('register')
      ->willThrowException(new \RuntimeException('backend exposed citizen@example.com'));

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))
      ->method('warning')
      ->with(
        $this->logicalNot($this->stringContains('citizen@example.com')),
        $this->callback(static fn (array $context): bool => !in_array('citizen@example.com', $context, TRUE)),
      );

    $action = $this->buildAction(
      ['notification_key' => 'report_confirmation', 'recipient' => '[node:field_e_mail:value]'],
      mailManager: $mail_manager,
      flood: $flood,
      logger: $logger,
    );
    $action->execute($this->buildNode());
  }

  /**
   * The action itself never restricts access; ECA conditions gate execution.
   */
  public function testAccessAlwaysAllowed(): void {
    $action = $this->buildAction(['notification_key' => 'status_open']);
    $this->assertTrue($action->access(NULL));
  }

  /**
   * Builds a mock service_request node.
   */
  private function buildNode(string $langcode = 'en'): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    $node->method('language')->willReturn($language);
    return $node;
  }

  /**
   * Builds the action plugin with default or injected mocks.
   */
  private function buildAction(
    array $configuration,
    ?MailManagerInterface $mailManager = NULL,
    ?Token $token = NULL,
    ?LoggerInterface $logger = NULL,
    ?ConfigFactoryInterface $configFactory = NULL,
    ?FloodInterface $flood = NULL,
  ): SendNotificationMail {
    if ($token === NULL) {
      $token = $this->createMock(Token::class);
      $token->method('replace')->willReturn('citizen@example.com');
    }
    if ($mailManager === NULL) {
      $mailManager = $this->createMock(MailManagerInterface::class);
      $mailManager->method('mail')->willReturn(['result' => TRUE]);
    }
    if ($configFactory === NULL) {
      $configFactory = $this->buildConfigFactory([
        'limit' => 5,
        'window' => 3600,
      ]);
    }
    if ($flood === NULL) {
      $flood = $this->createMock(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }
    $action = new SendNotificationMail(
      $configuration,
      'markaspot_mail_send_notification',
      ['id' => 'markaspot_mail_send_notification'],
      $token,
      $mailManager,
      $configFactory,
      $flood,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    return $action;
  }

  /**
   * Builds config mocks with optional recipient flood values.
   */
  private function buildConfigFactory(?array $recipient_flood): ConfigFactoryInterface {
    $texts = $this->createMock(ImmutableConfig::class);
    $texts->method('get')->willReturn([
      'report_confirmation' => [],
      'status_open' => [],
      'status_closed' => [],
      'status_not_responsible' => [],
    ]);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')
      ->willReturnCallback(static function (string $key) use ($recipient_flood): mixed {
        if ($recipient_flood === NULL) {
          return NULL;
        }
        return match ($key) {
          'recipient_flood.limit' => $recipient_flood['limit'] ?? NULL,
          'recipient_flood.window' => $recipient_flood['window'] ?? NULL,
          default => NULL,
        };
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnCallback(static fn (string $name): ImmutableConfig => $name === 'markaspot_mail.settings' ? $settings : $texts);
    return $config_factory;
  }

}
