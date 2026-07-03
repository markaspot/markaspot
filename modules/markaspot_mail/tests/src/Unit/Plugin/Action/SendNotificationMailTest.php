<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Plugin\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_mail\Plugin\Action\SendNotificationMail;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 *
 */
#[CoversClass(\Drupal\markaspot_mail\Plugin\Action\SendNotificationMail::class)]
#[Group('markaspot_mail')]
final class SendNotificationMailTest extends UnitTestCase {

  /**
   *
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
   *
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
   *
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
   *
   */
  public function testExecuteSendsWithNodeLangcodeAndConfiguredKey(): void {
    $node = $this->buildNode(langcode: 'de');

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturn('citizen@example.com');

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
      logger: $logger,
    );
    $action->execute($node);
  }

  /**
   *
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
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturn([
        'report_confirmation' => [],
        'status_open' => [],
        'status_closed' => [],
        'status_not_responsible' => [],
      ]);
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->willReturn($config);
    }
    $action = new SendNotificationMail(
      $configuration,
      'markaspot_mail_send_notification',
      ['id' => 'markaspot_mail_send_notification'],
      $token,
      $mailManager,
      $configFactory,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    return $action;
  }

}
