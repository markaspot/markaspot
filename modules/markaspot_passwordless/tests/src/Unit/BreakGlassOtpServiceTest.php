<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_passwordless\Service\BreakGlassOtpService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Service/BreakGlassOtpServiceInterface.php';
require_once dirname(__DIR__, 3) . '/src/Service/BreakGlassOtpService.php';

/**
 * Tests the Core-maintenance recovery OTP isolation contract.
 *
 * @group markaspot_passwordless
 * @coversDefaultClass \Drupal\markaspot_passwordless\Service\BreakGlassOtpService
 */
class BreakGlassOtpServiceTest extends UnitTestCase {

  /**
   * The recovery service under test.
   *
   * @var \Drupal\markaspot_passwordless\Service\BreakGlassOtpService
   */
  protected $service;

  /**
   * The mocked Core state store.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

  /**
   * The mocked user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $userStorage;

  /**
   * The mocked isolated recovery-code store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $store;

  /**
   * The mocked recovery-code lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $lock;

  /**
   * The mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mail;

  /**
   * The mocked recovery logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('get')->with('system.maintenance_mode', FALSE)->willReturn(TRUE);

    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($this->userStorage);

    $this->store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $key_value = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value->method('get')
      ->with('markaspot_passwordless_break_glass_codes')
      ->willReturn($this->store);

    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->method('acquire')->willReturn(TRUE);

    $this->mail = $this->createMock(MailManagerInterface::class);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['code_lifetime', 600],
      ['max_attempts', 3],
    ]);
    $site = $this->createMock(ImmutableConfig::class);
    $site->method('get')->with('name')->willReturn('Mark-a-Spot');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnMap([
      ['markaspot_passwordless.settings', $settings],
      ['system.site', $site],
    ]);

    $this->logger = $this->createMock(LoggerInterface::class);
    $this->service = new BreakGlassOtpService(
      $this->state,
      $entity_type_manager,
      $key_value,
      $this->lock,
      $this->mail,
      $config_factory,
      $this->logger,
    );
  }

  /**
   * An eligible existing account receives the generic result and a scoped code.
   */
  public function testEligibleActiveMaintenanceUserReceivesRecoveryCode(): void {
    $user = $this->eligibleUser();
    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['mail' => 'operator@example.com'])
      ->willReturn([$user]);

    $key = hash('sha256', 'operator@example.com');
    $this->store->expects($this->once())
      ->method('setWithExpire')
      ->with(
        $key,
        $this->callback(static function (array $record): bool {
          return $record['uid'] === 42
            && $record['email'] === 'operator@example.com'
            && $record['attempts'] === 0
            && password_get_info($record['code'])['algoName'] === 'bcrypt';
        }),
        600,
      );
    $this->mail->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_passwordless',
        'verification_code',
        'operator@example.com',
        '',
        $this->isType('array'),
        NULL,
        TRUE,
      )
      ->willReturn(['result' => TRUE]);

    self::assertSame($this->genericRequestResult(), $this->service->requestCode('operator@example.com'));
  }

  /**
   * Missing or nonexempt accounts must not receive mail or a stored code.
   */
  public function testNoneligibleUserGetsTheSameGenericRequestResult(): void {
    $user = $this->eligibleUser(FALSE);
    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['mail' => 'noneligible@example.com'])
      ->willReturn([$user]);
    $this->store->expects($this->never())->method('setWithExpire');
    $this->mail->expects($this->never())->method('mail');

    self::assertSame($this->genericRequestResult(), $this->service->requestCode('noneligible@example.com'));
  }

  /**
   * Recovery cannot issue a code outside Core maintenance mode.
   */
  public function testNormalModeDoesNotIssueBreakGlassCodes(): void {
    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('get')->with('system.maintenance_mode', FALSE)->willReturn(FALSE);
    $this->replaceState($this->state);
    $this->userStorage->expects($this->never())->method('loadByProperties');
    $this->store->expects($this->never())->method('setWithExpire');
    $this->mail->expects($this->never())->method('mail');

    self::assertSame($this->genericRequestResult(), $this->service->requestCode('operator@example.com'));
  }

  /**
   * Mail transport failure is logged but never changes the generic response.
   */
  public function testMailFailureIsLoggedWithoutBecomingAnEligibilityOracle(): void {
    $user = $this->eligibleUser();
    $this->userStorage->method('loadByProperties')->willReturn([$user]);
    $this->mail->expects($this->once())->method('mail')->willReturn(['result' => FALSE]);
    $this->logger->expects($this->once())
      ->method('error')
      ->with('Break-glass OTP mail transport reported failure.');

    self::assertSame($this->genericRequestResult(), $this->service->requestCode('operator@example.com'));
  }

  /**
   * The account must still have the Core permission when the code is consumed.
   */
  public function testVerificationRechecksMaintenanceEligibility(): void {
    $key = hash('sha256', 'operator@example.com');
    $this->store->method('get')
      ->with($key)
      ->willReturn($this->record());
    $this->store->expects($this->once())->method('delete')->with($key);

    $noneligible = $this->eligibleUser(FALSE);
    $this->userStorage->expects($this->once())
      ->method('load')
      ->with(42)
      ->willReturn($noneligible);

    self::assertNull($this->service->verifyCode('operator@example.com', '123456'));
  }

  /**
   * A current active account with the Core permission may consume its code.
   */
  public function testVerificationReturnsOnlyAnEligibleExistingAccount(): void {
    $key = hash('sha256', 'operator@example.com');
    $this->store->method('get')
      ->with($key)
      ->willReturn($this->record());
    $this->store->expects($this->once())->method('delete')->with($key);

    $eligible = $this->eligibleUser();
    $this->userStorage->expects($this->once())
      ->method('load')
      ->with(42)
      ->willReturn($eligible);

    self::assertSame($eligible, $this->service->verifyCode('operator@example.com', '123456'));
  }

  /**
   * Returns a real active user with configurable maintenance permission.
   */
  private function eligibleUser(bool $hasPermission = TRUE): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);
    $user->method('getEmail')->willReturn('operator@example.com');
    $user->method('isActive')->willReturn(TRUE);
    $user->method('hasPermission')
      ->with('access site in maintenance mode')
      ->willReturn($hasPermission);
    return $user;
  }

  /**
   * Builds a valid record for the known test code.
   *
   * @return array{uid: int, email: string, code: string, attempts: int, expires: int}
   *   A live recovery-code record.
   */
  private function record(): array {
    return [
      'uid' => 42,
      'email' => 'operator@example.com',
      'code' => password_hash('123456', PASSWORD_BCRYPT),
      'attempts' => 0,
      'expires' => time() + 600,
    ];
  }

  /**
   * Provides the externally stable response for every valid request.
   *
   * @return array{success: bool, message: string, expiresIn: int}
   *   The generic response payload.
   */
  private function genericRequestResult(): array {
    return [
      'success' => TRUE,
      'message' => 'If the account is eligible, a verification code has been sent.',
      'expiresIn' => 600,
    ];
  }

  /**
   * Rebuilds the service with a replacement state mock.
   */
  private function replaceState(StateInterface $state): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($this->userStorage);
    $key_value = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value->method('get')
      ->with('markaspot_passwordless_break_glass_codes')
      ->willReturn($this->store);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['code_lifetime', 600],
      ['max_attempts', 3],
    ]);
    $site = $this->createMock(ImmutableConfig::class);
    $site->method('get')->with('name')->willReturn('Mark-a-Spot');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnMap([
      ['markaspot_passwordless.settings', $settings],
      ['system.site', $site],
    ]);

    $this->service = new BreakGlassOtpService(
      $state,
      $entity_type_manager,
      $key_value,
      $this->lock,
      $this->mail,
      $config_factory,
      $this->logger,
    );
  }

}
