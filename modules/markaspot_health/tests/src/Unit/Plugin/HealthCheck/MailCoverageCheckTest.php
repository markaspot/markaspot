<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\MailCoverageCheck;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderRegistry;
use Drupal\Tests\markaspot_mail\Unit\Stub\SupportingStubBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the mail-coverage detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\MailCoverageCheck
 */
class MailCoverageCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'mail_coverage',
    'label' => 'Mail template and builder coverage gaps',
    'severity' => 'error',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * Temp directory standing in for the markaspot_fastmap module path.
   */
  protected string $fastmapModulePath;

  /**
   * Temp directory standing in for the markaspot_mail module path.
   */
  protected string $mailModulePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fastmapModulePath = sys_get_temp_dir() . '/markaspot_health_mail_coverage_test_' . uniqid();
    mkdir($this->fastmapModulePath . '/config/install', 0777, TRUE);
    $this->mailModulePath = sys_get_temp_dir() . '/markaspot_health_mail_texts_test_' . uniqid();
    mkdir($this->mailModulePath . '/config/install', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $file = $this->fastmapModulePath . '/config/install/markaspot_fastmap.mail.yml';
    if (is_file($file)) {
      unlink($file);
    }
    @rmdir($this->fastmapModulePath . '/config/install');
    @rmdir($this->fastmapModulePath);

    $textsFile = $this->mailModulePath . '/config/install/markaspot_mail.texts.yml';
    if (is_file($textsFile)) {
      unlink($textsFile);
    }
    @rmdir($this->mailModulePath . '/config/install');
    @rmdir($this->mailModulePath);
    parent::tearDown();
  }

  /**
   * Writes the shipped markaspot_fastmap.mail.yml fixture used by tests.
   */
  protected function writeShippedYaml(): void {
    file_put_contents(
      $this->fastmapModulePath . '/config/install/markaspot_fastmap.mail.yml',
      <<<YAML
      langcode: en
      workspace_verification:
        subject: 'Verify @workspace_name'
        body: 'Click @verify_url'
      workspace_welcome:
        subject: 'Welcome @workspace_name'
        body: 'Go to @workspace_url'
      YAML,
    );
  }

  /**
   * Writes the shipped markaspot_mail.texts.yml fixture used by tests.
   */
  protected function writeShippedMailTextsYaml(): void {
    file_put_contents(
      $this->mailModulePath . '/config/install/markaspot_mail.texts.yml',
      <<<YAML
      langcode: en
      report_confirmation:
        subject: 'Your report has been received'
        intro: 'Thanks.'
      status_open:
        subject: 'Update on your report'
        intro: 'In progress.'
      YAML,
    );
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenAllModulesDisabled(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(FALSE);
    $extensionList = $this->createMock(ModuleExtensionList::class);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenFastmapActiveConfigMissesShippedKey(): void {
    $this->writeShippedYaml();

    // Active config only carries workspace_verification -- the exact bug
    // shape this check exists to catch (workspace_welcome predates the
    // backfill update hook).
    $mailConfig = $this->createMock(ImmutableConfig::class);
    $mailConfig->method('get')->willReturnMap([
      ['workspace_verification', ['subject' => 'Verify', 'body' => 'Click']],
      ['workspace_welcome', NULL],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['markaspot_fastmap.mail', $mailConfig],
    ]);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_fastmap',
    );

    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')->with('markaspot_fastmap')->willReturn($this->fastmapModulePath);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertSame(1, $result->count);
    $this->assertStringContainsString('workspace_welcome', $result->details[0]['key']);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenFastmapActiveConfigCoversAllShippedKeys(): void {
    $this->writeShippedYaml();

    $mailConfig = $this->createMock(ImmutableConfig::class);
    $mailConfig->method('get')->willReturnMap([
      ['workspace_verification', ['subject' => 'Verify', 'body' => 'Click']],
      ['workspace_welcome', ['subject' => 'Welcome', 'body' => 'Go']],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['markaspot_fastmap.mail', $mailConfig],
    ]);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_fastmap',
    );

    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')->with('markaspot_fastmap')->willReturn($this->fastmapModulePath);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunWarnsOnEmptySmtpEhloHostWhenPhpmailerIsSender(): void {
    $mailsystem = $this->createMock(ImmutableConfig::class);
    $mailsystem->method('get')->with('defaults.sender')->willReturn('phpmailer_smtp');
    $phpmailerSettings = $this->createMock(ImmutableConfig::class);
    $phpmailerSettings->method('get')->with('smtp_ehlo_host')->willReturn('');

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['mailsystem.settings', $mailsystem],
      ['phpmailer_smtp.settings', $phpmailerSettings],
    ]);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => in_array($module, ['mailsystem', 'phpmailer_smtp'], TRUE),
    );

    $extensionList = $this->createMock(ModuleExtensionList::class);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('warning', $result->severity);
    $this->assertStringContainsString('smtp_ehlo_host', $result->details[0]['key']);
  }

  /**
   * @covers ::run
   */
  public function testRunSkipsSmtpEhloCheckWhenSenderIsNotPhpmailer(): void {
    $mailsystem = $this->createMock(ImmutableConfig::class);
    $mailsystem->method('get')->with('defaults.sender')->willReturn('smtp');

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['mailsystem.settings', $mailsystem],
    ]);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => in_array($module, ['mailsystem', 'phpmailer_smtp'], TRUE),
    );

    $extensionList = $this->createMock(ModuleExtensionList::class);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsOnDuplicateMailTypeAcrossBuilders(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_mail',
    );
    $extensionList = $this->createMock(ModuleExtensionList::class);
    // markaspot_mail is "installed" here, so checkMarkaspotMailTextsCoverage()
    // also runs; a non-existent path makes shippedMarkaspotMailTextsKeys()
    // return [] and skip that sub-check cleanly (this test is only about
    // the builder-registry sub-check).
    $extensionList->method('getPath')->willReturn(sys_get_temp_dir() . '/markaspot_health_mail_coverage_test_nonexistent');

    $registry = new MailBuilderRegistry([
      new SupportingStubBuilder(MailType::PASSWORDLESS_OTP, 'markaspot_passwordless', 'verification_code'),
      new SupportingStubBuilder(MailType::PASSWORDLESS_OTP, 'markaspot_other', 'other_key'),
    ]);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList, $registry);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('passwordless_otp', $result->message . json_encode($result->details));
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenBuilderTypesAreUnique(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_mail',
    );
    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')->willReturn(sys_get_temp_dir() . '/markaspot_health_mail_coverage_test_nonexistent');

    $registry = new MailBuilderRegistry([
      new SupportingStubBuilder(MailType::PASSWORDLESS_OTP, 'markaspot_passwordless', 'verification_code'),
      new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'notify'),
    ]);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList, $registry);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenMarkaspotMailTextsMissesShippedKey(): void {
    $this->writeShippedMailTextsYaml();

    // Active config only carries report_confirmation -- status_open exists
    // in the shipped install YAML but is absent from active config, the
    // exact gap this sub-check exists to catch (e.g. update hook never ran).
    $textsConfig = $this->createMock(ImmutableConfig::class);
    $textsConfig->method('get')->willReturnMap([
      ['report_confirmation', ['subject' => 'Received', 'intro' => 'Thanks.', 'body_blocks' => []]],
      ['status_open', NULL],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['markaspot_mail.texts', $textsConfig],
    ]);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_mail',
    );

    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')->with('markaspot_mail')->willReturn($this->mailModulePath);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('status_open', json_encode($result->details));
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenMarkaspotMailTextsKeyHasEmptyBody(): void {
    $this->writeShippedMailTextsYaml();

    // Subject present, but neither intro nor body_blocks carries text --
    // the "empty body" half of the gate, distinct from an empty subject.
    $textsConfig = $this->createMock(ImmutableConfig::class);
    $textsConfig->method('get')->willReturnMap([
      ['report_confirmation', ['subject' => 'Received', 'intro' => '', 'body_blocks' => ['', '  ']]],
      ['status_open', ['subject' => 'Update', 'intro' => 'In progress.', 'body_blocks' => []]],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['markaspot_mail.texts', $textsConfig],
    ]);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_mail',
    );

    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')->with('markaspot_mail')->willReturn($this->mailModulePath);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertStringContainsString('report_confirmation', json_encode($result->details));
    $this->assertStringContainsString('body', json_encode($result->details));
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenMarkaspotMailTextsCoversAllShippedKeys(): void {
    $this->writeShippedMailTextsYaml();

    $textsConfig = $this->createMock(ImmutableConfig::class);
    $textsConfig->method('get')->willReturnMap([
      ['report_confirmation', ['subject' => 'Received', 'intro' => 'Thanks.', 'body_blocks' => []]],
      ['status_open', ['subject' => 'Update', 'intro' => '', 'body_blocks' => ['In progress.']]],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['markaspot_mail.texts', $textsConfig],
    ]);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_mail',
    );

    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')->with('markaspot_mail')->willReturn($this->mailModulePath);

    $plugin = new MailCoverageCheck([], 'mail_coverage', $this->definition, $factory, $modules, $extensionList);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenMailBuilderRegistryFailedToInstantiate(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'markaspot_mail',
    );
    $extensionList = $this->createMock(ModuleExtensionList::class);
    $extensionList->method('getPath')->willReturn(sys_get_temp_dir() . '/markaspot_health_mail_coverage_test_nonexistent');

    $plugin = new MailCoverageCheck(
      [],
      'mail_coverage',
      $this->definition,
      $factory,
      $modules,
      $extensionList,
      NULL,
      'Container error: some_dependency not found',
    );
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('some_dependency', $result->details[0]['issue']);
  }

}
