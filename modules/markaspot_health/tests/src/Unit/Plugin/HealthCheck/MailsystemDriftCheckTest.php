<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\markaspot_health\Plugin\HealthCheck\MailsystemDriftCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the mailsystem-drift detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\MailsystemDriftCheck
 */
class MailsystemDriftCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'mailsystem_drift',
    'label' => 'Mailsystem configuration drift',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenConfigMissing(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(TRUE);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('mailsystem.settings')->willReturn($config);

    $plugin = new MailsystemDriftCheck([], 'mailsystem_drift', $this->definition, $factory);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('not configured', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenConfigIsCorrect(): void {
    $mailsystem = $this->createMock(ImmutableConfig::class);
    $mailsystem->method('isNew')->willReturn(FALSE);
    $mailsystem->method('get')->willReturnMap([
      ['defaults.sender', 'phpmailer_smtp'],
    ]);
    $markaspotMail = $this->createMock(ImmutableConfig::class);
    $markaspotMail->method('isNew')->willReturn(FALSE);
    $markaspotMail->method('get')->willReturnMap([
      ['attachments.enabled', TRUE],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['mailsystem.settings', $mailsystem],
      ['markaspot_mail.settings', $markaspotMail],
    ]);

    $plugin = new MailsystemDriftCheck([], 'mailsystem_drift', $this->definition, $factory);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenSenderAndAttachmentsDrift(): void {
    $mailsystem = $this->createMock(ImmutableConfig::class);
    $mailsystem->method('isNew')->willReturn(FALSE);
    $mailsystem->method('get')->willReturnMap([
      ['defaults.sender', 'php_mail'],
    ]);
    $markaspotMail = $this->createMock(ImmutableConfig::class);
    $markaspotMail->method('isNew')->willReturn(FALSE);
    $markaspotMail->method('get')->willReturnMap([
      ['attachments.enabled', FALSE],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['mailsystem.settings', $mailsystem],
      ['markaspot_mail.settings', $markaspotMail],
    ]);

    $plugin = new MailsystemDriftCheck([], 'mailsystem_drift', $this->definition, $factory);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(2, $result->count);
    $this->assertStringContainsString('php_mail', $result->message);
    $this->assertStringContainsString('attachments.enabled=false', $result->message);
  }

}
