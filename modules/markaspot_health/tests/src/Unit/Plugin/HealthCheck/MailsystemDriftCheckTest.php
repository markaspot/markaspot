<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\MailsystemDriftCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the mailsystem-drift detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\MailsystemDriftCheck
 */
class MailsystemDriftCheckTest extends UnitTestCase
{
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
    'fix_url' => null,
    ];

  /**
   * @covers ::run
   */
    public function testRunFailsWhenConfigMissing(): void
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('isNew')->willReturn(true);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->with('mailsystem.settings')->willReturn($config);

        $plugin = $this->createPlugin($factory);
        $result = $plugin->run();

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('mailsystem.settings missing', $result->message);
    }

  /**
   * @covers ::run
   */
    public function testRunPassesWhenConfigIsCorrect(): void
    {
        $mailsystem = $this->createMock(ImmutableConfig::class);
        $mailsystem->method('isNew')->willReturn(false);
        $mailsystem->method('get')->willReturnMap([
        ['defaults.sender', 'phpmailer_smtp'],
        ['defaults.formatter', 'phpmailer_smtp'],
        ]);
        $systemMail = $this->createMock(ImmutableConfig::class);
        $systemMail->method('isNew')->willReturn(false);
        $systemMail->method('get')->willReturnMap([
        ['interface.default', 'phpmailer_smtp'],
        ]);
        $markaspotMail = $this->createMock(ImmutableConfig::class);
        $markaspotMail->method('isNew')->willReturn(false);
        $markaspotMail->method('get')->willReturnMap([
        ['attachments.enabled', true],
        ]);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturnMap([
        ['mailsystem.settings', $mailsystem],
        ['system.mail', $systemMail],
        ['markaspot_mail.settings', $markaspotMail],
        ]);

        $plugin = $this->createPlugin($factory);
        $result = $plugin->run();

        $this->assertTrue($result->passed);
    }

  /**
   * @covers ::run
   */
    public function testRunFailsWhenMailBackendAndAttachmentsDrift(): void
    {
        $mailsystem = $this->createMock(ImmutableConfig::class);
        $mailsystem->method('isNew')->willReturn(false);
        $mailsystem->method('get')->willReturnMap([
        ['defaults.sender', 'php_mail'],
        ['defaults.formatter', 'php_mail'],
        ]);
        $systemMail = $this->createMock(ImmutableConfig::class);
        $systemMail->method('isNew')->willReturn(false);
        $systemMail->method('get')->willReturnMap([
        ['interface.default', 'maillog'],
        ]);
        $markaspotMail = $this->createMock(ImmutableConfig::class);
        $markaspotMail->method('isNew')->willReturn(false);
        $markaspotMail->method('get')->willReturnMap([
        ['attachments.enabled', false],
        ]);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturnMap([
        ['mailsystem.settings', $mailsystem],
        ['system.mail', $systemMail],
        ['markaspot_mail.settings', $markaspotMail],
        ]);

        $plugin = $this->createPlugin($factory);
        $result = $plugin->run();

        $this->assertFalse($result->passed);
        $this->assertSame(6, $result->count);
        $this->assertStringContainsString('php_mail', $result->message);
        $this->assertStringContainsString('maillog', $result->message);
        $this->assertStringContainsString('defaults.formatter', $result->message);
        $this->assertStringContainsString('attachments.enabled=false', $result->message);
    }

  /**
   * Tests smtp legacy backend remains supported when consistently configured.
   *
   * @covers ::run
   */
    public function testRunPassesForLegacySmtpBackend(): void
    {
        $mailsystem = $this->createMock(ImmutableConfig::class);
        $mailsystem->method('isNew')->willReturn(false);
        $mailsystem->method('get')->willReturnMap([
        ['defaults.sender', 'smtp'],
        ['defaults.formatter', 'smtp'],
        ]);
        $systemMail = $this->createMock(ImmutableConfig::class);
        $systemMail->method('isNew')->willReturn(false);
        $systemMail->method('get')->willReturnMap([
        ['interface.default', 'smtp'],
        ]);
        $markaspotMail = $this->createMock(ImmutableConfig::class);
        $markaspotMail->method('isNew')->willReturn(false);
        $markaspotMail->method('get')->willReturnMap([
        ['attachments.enabled', true],
        ]);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturnMap([
        ['mailsystem.settings', $mailsystem],
        ['system.mail', $systemMail],
        ['markaspot_mail.settings', $markaspotMail],
        ]);

        $plugin = $this->createPlugin($factory, ['mailsystem', 'smtp']);
        $result = $plugin->run();

        $this->assertTrue($result->passed);
    }

  /**
   * Tests missing configured mail plugin modules are reported.
   *
   * @covers ::run
   */
    public function testRunFailsWhenConfiguredMailBackendModuleIsDisabled(): void
    {
        $mailsystem = $this->createMock(ImmutableConfig::class);
        $mailsystem->method('isNew')->willReturn(false);
        $mailsystem->method('get')->willReturnMap([
        ['defaults.sender', 'phpmailer_smtp'],
        ['defaults.formatter', 'phpmailer_smtp'],
        ]);
        $systemMail = $this->createMock(ImmutableConfig::class);
        $systemMail->method('isNew')->willReturn(false);
        $systemMail->method('get')->willReturnMap([
        ['interface.default', 'phpmailer_smtp'],
        ]);
        $markaspotMail = $this->createMock(ImmutableConfig::class);
        $markaspotMail->method('isNew')->willReturn(false);
        $markaspotMail->method('get')->willReturnMap([
        ['attachments.enabled', true],
        ]);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturnMap([
        ['mailsystem.settings', $mailsystem],
        ['system.mail', $systemMail],
        ['markaspot_mail.settings', $markaspotMail],
        ]);

        $plugin = $this->createPlugin($factory, ['mailsystem']);
        $result = $plugin->run();

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('phpmailer_smtp module disabled', $result->message);
    }

  /**
   * Creates the plugin under test.
   *
   * @param list<string> $enabledModules
   *   Enabled module IDs.
   */
    private function createPlugin(
        ConfigFactoryInterface $factory,
        array $enabledModules = ['mailsystem', 'phpmailer_smtp'],
    ): MailsystemDriftCheck {
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('moduleExists')
        ->willReturnCallback(static fn(string $module): bool => in_array($module, $enabledModules, true));

        return new MailsystemDriftCheck([], 'mailsystem_drift', $this->definition, $factory, $moduleHandler);
    }
}
