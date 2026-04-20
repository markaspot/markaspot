<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\Builder\PasswordlessOtpBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(\Drupal\markaspot_mail\Mail\Builder\PasswordlessOtpBuilder::class)]
#[Group('markaspot_mail')]
final class PasswordlessOtpBuilderTest extends UnitTestCase {

  public function testGetTypeReturnsPasswordlessOtp(): void {
    $this->assertSame(MailType::PASSWORDLESS_OTP, $this->buildBuilder()->getType());
  }

  public function testSupportsOnlyVerificationCodeKey(): void {
    $builder = $this->buildBuilder();
    $this->assertTrue($builder->supports('markaspot_passwordless', 'verification_code'));
    $this->assertFalse($builder->supports('markaspot_passwordless', 'login_code'));
    $this->assertFalse($builder->supports('user', 'password_reset'));
    $this->assertFalse($builder->supports('other', 'verification_code'));
  }

  public function testBuildReturnsNullWhenCodeMissing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $builder = $this->buildBuilder(logger: $logger);

    $ctx = $this->buildContext(['expires_in' => 10]);
    $this->assertNull($builder->build($ctx));
  }

  public function testBuildUsesHeroCodeVariant(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'code' => '156428',
      'expires_in' => 10,
      'platform_name' => 'Mark-a-Spot',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('hero_code', $msg->variant);
    // Code is passed through unchanged; letter-spacing in the template provides visual separation.
    $this->assertSame('156428', $msg->content['code']);
    $this->assertStringContainsString('156428', $msg->plainText);
    $this->assertStringContainsString('10', $msg->plainText);
    $this->assertArrayHasKey('preheader', $msg->content);
    $this->assertArrayHasKey('headline', $msg->content);
  }

  public function testBuildPreservesNonSixDigitCodeShape(): void {
    $builder = $this->buildBuilder();

    // 8-digit alphanumeric stays as-is.
    $ctx = $this->buildContext([
      'code' => 'A1B2C3D4',
      'expires_in' => 10,
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertSame('A1B2C3D4', $msg->content['code']);

    // 4-digit code stays as-is.
    $ctx = $this->buildContext([
      'code' => '4242',
      'expires_in' => 10,
    ]);
    $msg = $builder->build($ctx);
    $this->assertNotNull($msg);
    $this->assertSame('4242', $msg->content['code']);
  }

  public function testBuildStaysInPlatformModeWhenNoJurisdictionId(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'code' => '156428',
      'expires_in' => 10,
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('platform', $msg->mode);
    $this->assertNull($msg->jurisdictionId);
  }

  public function testBuildSwitchesToJurisdictionModeWhenIdProvided(): void {
    $builder = $this->buildBuilder();
    $ctx = $this->buildContext([
      'code' => '156428',
      'expires_in' => 10,
      'jurisdiction_id' => 7,
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    $this->assertSame('jurisdiction', $msg->mode);
    $this->assertSame(7, $msg->jurisdictionId);
  }

  public function testBuildIgnoresNonPositiveJurisdictionId(): void {
    $builder = $this->buildBuilder();
    foreach ([0, -1, NULL, ''] as $raw) {
      $ctx = $this->buildContext([
        'code' => '156428',
        'expires_in' => 10,
        'jurisdiction_id' => $raw,
      ]);
      $msg = $builder->build($ctx);
      $this->assertNotNull($msg);
      $this->assertSame('platform', $msg->mode, 'Non-positive jurisdiction_id must fall back to platform mode.');
    }
  }

  /**
   * Regression guard: even if an admin sets the subject template to
   * include @code, the OTP value must NOT appear in the mail-subject
   * header. @code stays literal in the rendered subject; only body +
   * plainText substitute it.
   */
  public function testBuildExcludesCodePlaceholderFromSubject(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn (string $key): ?array => $key === 'verification_code'
        ? ['subject' => '@platform_name code: @code']
        : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $override = $this->createMock(LanguageConfigOverride::class);
    $override->method('get')->willReturn(NULL);
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')->willReturn($override);

    $builder = $this->buildBuilder(
      configFactory: $configFactory,
      languageManager: $languageManager,
    );
    $ctx = $this->buildContext([
      'code' => '156428',
      'expires_in' => '10',
      'platform_name' => 'CivicSpot',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    // Subject keeps @code literal: the admin's template is respected
    // for every placeholder EXCEPT the OTP value.
    $this->assertSame('CivicSpot code: @code', $msg->subject);
    $this->assertStringNotContainsString('156428', $msg->subject);
    // But the body + plainText do substitute @code (no regression
    // of the OTP being visible in the mail at all).
    $this->assertSame('156428', $msg->content['code']);
    $this->assertStringContainsString('156428', $msg->plainText);
  }

  public function testBuildAppliesConfigSubjectTemplateViaPlaceholders(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn (string $key): ?array => $key === 'verification_code'
        ? ['subject' => '@platform_name (expires in @expires_in min)']
        : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $override = $this->createMock(LanguageConfigOverride::class);
    $override->method('get')->willReturn(NULL);
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')->willReturn($override);

    $builder = $this->buildBuilder(
      configFactory: $configFactory,
      languageManager: $languageManager,
    );
    $ctx = $this->buildContext([
      'code' => '156428',
      'expires_in' => '10',
      'platform_name' => 'CivicSpot',
    ]);
    $msg = $builder->build($ctx);

    $this->assertNotNull($msg);
    // @platform_name and @expires_in substitute; @code never reaches
    // the subject slot (see testBuildExcludesCodePlaceholderFromSubject).
    $this->assertSame('CivicSpot (expires in 10 min)', $msg->subject);
  }

  /**
   * Builds a MailContext with sensible test defaults.
   */
  private function buildContext(array $params): MailContext {
    return new MailContext(
      module: 'markaspot_passwordless',
      key: 'verification_code',
      langcode: 'en',
      params: $params,
      to: 'user@example.com',
    );
  }

  /**
   * Builds the subject with optional dep injection.
   */
  private function buildBuilder(
    ?ConfigFactoryInterface $configFactory = NULL,
    ?ConfigurableLanguageManagerInterface $languageManager = NULL,
    ?LoggerInterface $logger = NULL,
  ): PasswordlessOtpBuilder {
    if ($configFactory === NULL) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturn(NULL);
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->willReturn($config);
    }
    if ($languageManager === NULL) {
      $override = $this->createMock(LanguageConfigOverride::class);
      $override->method('get')->willReturn(NULL);
      $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
      $languageManager->method('getLanguageConfigOverride')->willReturn($override);
    }
    $builder = new PasswordlessOtpBuilder(
      $configFactory,
      $languageManager,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

}
