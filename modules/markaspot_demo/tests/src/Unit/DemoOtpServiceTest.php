<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_demo\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_demo\Service\DemoOtpService;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the demo OTP service.
 *
 * @group markaspot_demo
 * @coversDefaultClass \Drupal\markaspot_demo\Service\DemoOtpService
 */
class DemoOtpServiceTest extends UnitTestCase {

  /**
   * Tests the demo request path on a single-jurisdiction installation.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeAllowsSingleJurisdictionDemoPath(): void {
    $service = $this->buildService(1);

    $result = $service->requestCode('demo@example.com', 1);

    $this->assertTrue($result['success']);
    $this->assertTrue($result['demo']);
    $this->assertSame('Demo mode: Use code 123456', $result['message']);
  }

  /**
   * Tests the guard uses the configured jurisdiction group type.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeRejectsConfiguredJurisdictionGroupType(): void {
    $service = $this->buildService(2, 'jurisdiction');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('markaspot_demo must not run on multi-tenant installations.');

    $service->requestCode('demo@example.com', 1);
  }

  /**
   * Tests the demo request path rejects multi-tenant installations.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeRejectsMultiTenantInstallation(): void {
    $service = $this->buildService(2);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('markaspot_demo must not run on multi-tenant installations.');

    $service->requestCode('demo@example.com', 1);
  }

  /**
   * Tests the demo verify path rejects multi-tenant installations.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeRejectsMultiTenantInstallation(): void {
    $service = $this->buildService(2);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('markaspot_demo must not run on multi-tenant installations.');

    $service->verifyCode('demo@example.com', DemoOtpService::DEMO_CODE, 1);
  }

  /**
   * Builds a demo OTP service with a configured jurisdiction count.
   *
   * @param int $jurisdictionCount
   *   The active jurisdiction count returned by entity query.
   * @param string $jurisdictionGroupType
   *   The configured jurisdiction group type.
   *
   * @return \Drupal\markaspot_demo\Service\DemoOtpService
   *   The demo OTP service under test.
   */
  protected function buildService(int $jurisdictionCount, string $jurisdictionGroupType = 'jur'): DemoOtpService {
    $inner = $this->createMock(OtpService::class);
    $inner->expects($this->never())
      ->method('requestCode');
    $inner->expects($this->never())
      ->method('verifyCode');

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')
      ->with('demo_emails')
      ->willReturn(['demo@example.com']);

    $open311Settings = $this->createMock(ImmutableConfig::class);
    $open311Settings->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn($jurisdictionGroupType);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([
        ['markaspot_demo.settings', $settings],
        ['markaspot_open311.settings', $open311Settings],
      ]);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    return new DemoOtpService(
      $inner,
      $this->createMock(Connection::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(LoggerInterface::class),
      $configFactory,
      $this->createEntityTypeManager($jurisdictionCount, $jurisdictionGroupType),
      $this->createMock(LanguageManagerInterface::class),
      $moduleHandler,
    );
  }

  /**
   * Creates an entity type manager that counts active jur groups.
   *
   * @param int $jurisdictionCount
   *   The active jurisdiction count returned by entity query.
   * @param string $jurisdictionGroupType
   *   The configured jurisdiction group type.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager mock.
   */
  protected function createEntityTypeManager(int $jurisdictionCount, string $jurisdictionGroupType): EntityTypeManagerInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('accessCheck')
      ->with(FALSE)
      ->willReturnSelf();
    $query->expects($this->exactly(2))
      ->method('condition')
      ->willReturnCallback(static function (string $field, mixed $value) use ($query, $jurisdictionGroupType): QueryInterface {
        self::assertContains([$field, $value], [
          ['type', $jurisdictionGroupType],
          ['status', 1],
        ]);
        return $query;
      });
    $query->expects($this->once())
      ->method('count')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('execute')
      ->willReturn($jurisdictionCount);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('getQuery')
      ->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    return $entityTypeManager;
  }

}
