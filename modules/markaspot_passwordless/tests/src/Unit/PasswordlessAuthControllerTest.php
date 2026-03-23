<?php

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\markaspot_passwordless\Controller\PasswordlessAuthController;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the PasswordlessAuthController.
 *
 * Covers requestCode(), verifyCode(), logout(), status(), switchUser(),
 * listSwitchUsers(), generateSwitchToken(), and claimSwitchToken()
 * input validation and rate limiting logic.
 *
 * @group markaspot_passwordless
 * @coversDefaultClass \Drupal\markaspot_passwordless\Controller\PasswordlessAuthController
 */
class PasswordlessAuthControllerTest extends UnitTestCase {

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_passwordless\Controller\PasswordlessAuthController
   */
  protected $controller;

  /**
   * Mocked OTP service.
   *
   * @var \Drupal\markaspot_passwordless\Service\OtpService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $otpService;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $flood;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked session configuration.
   *
   * @var \Drupal\Core\Session\SessionConfigurationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $sessionConfiguration;

  /**
   * Mocked key-value expirable factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $keyValueExpirable;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->otpService = $this->createMock(OtpService::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->sessionConfiguration = $this->createMock(SessionConfigurationInterface::class);
    $this->keyValueExpirable = $this->createMock(KeyValueExpirableFactoryInterface::class);

    // Default passwordless config.
    $passwordlessConfig = $this->createMock(ImmutableConfig::class);
    $passwordlessConfig->method('get')
      ->willReturnMap([
        ['request_limit_per_email', 3],
        ['request_limit_per_ip', 10],
        ['verify_lockout_attempts', 5],
        ['verify_lockout_duration', 900],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['markaspot_passwordless.settings', $passwordlessConfig],
      ]);

    // Default: all flood checks pass.
    $this->flood->method('isAllowed')->willReturn(TRUE);

    // Set up container for module_handler dependency (needed by ControllerBase).
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $container = new ContainerBuilder();
    $container->set('module_handler', $moduleHandler);
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('logger.factory', $this->createLoggerFactoryStub());
    \Drupal::setContainer($container);

    $this->controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
    );
  }

  // ===========================================================================
  // Tests for requestCode().
  // ===========================================================================

  /**
   * Tests requestCode with missing email returns 400.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeMissingEmail(): void {
    $request = new Request([], [], [], [], [], [], '{}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Email is required', $data['error']);
  }

  /**
   * Tests requestCode with invalid email format returns 400.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeInvalidEmailFormat(): void {
    $request = new Request([], [], [], [], [], [], '{"email":"not-an-email"}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid email format', $data['error']);
  }

  /**
   * Tests requestCode rate limiting by email.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeRateLimitByEmail(): void {
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')
      ->willReturnCallback(function ($event) {
        // Block email-based rate limiting.
        return $event !== 'passwordless.request_code';
      });

    $this->recreateController();

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com"}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
  }

  /**
   * Tests requestCode rate limiting by IP.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeRateLimitByIp(): void {
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')
      ->willReturnCallback(function ($event) {
        // Allow email check, block IP check.
        if ($event === 'passwordless.request_code') {
          return TRUE;
        }
        return FALSE;
      });

    $this->recreateController();

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com"}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
  }

  /**
   * Tests successful requestCode flow.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeSuccess(): void {
    $this->otpService->method('requestCode')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => 600,
      ]);

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com"}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertEquals(600, $data['expiresIn']);
  }

  /**
   * Tests requestCode when OTP service fails.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeServiceFailure(): void {
    $this->otpService->method('requestCode')
      ->willReturn([
        'success' => FALSE,
        'error' => 'Failed to send verification email',
      ]);

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com"}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
  }

  // ===========================================================================
  // Tests for verifyCode().
  // ===========================================================================

  /**
   * Tests verifyCode with missing email and code returns 400.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeMissingFields(): void {
    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Tests verifyCode with invalid email format returns 400.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeInvalidEmail(): void {
    $request = new Request([], [], [], [], [], [], '{"email":"bad","code":"123456"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid email format', $data['error']);
  }

  /**
   * Tests verifyCode with invalid code format returns 400.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeInvalidCodeFormat(): void {
    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"abc"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid code format', $data['error']);
  }

  /**
   * Tests verifyCode with 5-digit code returns 400.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeTooShortCode(): void {
    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"12345"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Tests verifyCode hard lockout returns 429.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeHardLockout(): void {
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')
      ->willReturnCallback(function ($event) {
        return $event !== 'passwordless.verify.lockout';
      });

    $this->recreateController();

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"123456"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('locked', $data['error']);
  }

  /**
   * Tests verifyCode backoff returns 429.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeBackoff(): void {
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')
      ->willReturnCallback(function ($event) {
        // Lockout passes, but backoff blocks.
        if ($event === 'passwordless.verify.lockout') {
          return TRUE;
        }
        return FALSE;
      });

    $this->recreateController();

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"123456"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
  }

  /**
   * Tests verifyCode when OTP code is invalid returns 401.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeInvalidCode(): void {
    $this->otpService->method('verifyCode')
      ->willReturn([
        'success' => FALSE,
        'error' => 'Invalid verification code',
      ]);

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"123456"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
  }

  /**
   * Tests successful verifyCode flow.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeSuccess(): void {
    $this->otpService->method('verifyCode')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Authentication successful',
        'user' => [
          'uid' => 5,
          'name' => 'testuser',
          'email' => 'user@example.com',
          'roles' => ['authenticated'],
          'groups' => [],
        ],
      ]);

    $this->flood->expects($this->atLeast(1))
      ->method('clear');

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"123456"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['success']);
    $this->assertEquals(5, $data['user']['uid']);
  }

  // ===========================================================================
  // Tests for status().
  // ===========================================================================

  /**
   * Tests status returns unauthenticated for anonymous user.
   *
   * @covers ::status
   */
  public function testStatusAnonymous(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);

    $request = new Request();
    $response = $this->controller->status($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['authenticated']);
  }

  // ===========================================================================
  // Tests for switchUser().
  // ===========================================================================

  /**
   * Tests switchUser fails when Devel module is not enabled.
   *
   * @covers ::switchUser
   */
  public function testSwitchUserRequiresDevelModule(): void {
    $request = new Request([], [], [], [], [], [], '{"name":"testuser"}');
    $response = $this->controller->switchUser($request);

    $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('development environments', $data['error']);
  }

  /**
   * Tests listSwitchUsers fails when Devel module is not enabled.
   *
   * @covers ::listSwitchUsers
   */
  public function testListSwitchUsersRequiresDevelModule(): void {
    $response = $this->controller->listSwitchUsers();

    $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  // ===========================================================================
  // Tests for generateSwitchToken().
  // ===========================================================================

  /**
   * Tests generateSwitchToken fails when Devel module is not enabled.
   *
   * @covers ::generateSwitchToken
   */
  public function testGenerateSwitchTokenRequiresDevelModule(): void {
    $request = new Request([], [], [], [], [], [], '{"name":"testuser"}');
    $response = $this->controller->generateSwitchToken($request);

    $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  // ===========================================================================
  // Tests for claimSwitchToken().
  // ===========================================================================

  /**
   * Tests claimSwitchToken fails when Devel module is not enabled.
   *
   * @covers ::claimSwitchToken
   */
  public function testClaimSwitchTokenRequiresDevelModule(): void {
    $request = new Request([], [], [], [], [], [], '{"token":"abc123"}');
    $response = $this->controller->claimSwitchToken($request);

    $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Tests claimSwitchToken with missing token returns 400.
   *
   * @covers ::claimSwitchToken
   */
  public function testClaimSwitchTokenMissingToken(): void {
    $this->enableDevelModule();

    $request = new Request([], [], [], [], [], [], '{}');
    $response = $this->controller->claimSwitchToken($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Tests claimSwitchToken with invalid token format returns 400.
   *
   * @covers ::claimSwitchToken
   */
  public function testClaimSwitchTokenInvalidFormat(): void {
    $this->enableDevelModule();

    // Token must be exactly 64 hex chars.
    $request = new Request([], [], [], [], [], [], '{"token":"short"}');
    $response = $this->controller->claimSwitchToken($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid token format.', $data['error']);
  }

  /**
   * Tests claimSwitchToken with expired/invalid token returns 401.
   *
   * @covers ::claimSwitchToken
   */
  public function testClaimSwitchTokenExpiredToken(): void {
    $this->enableDevelModule();

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);
    $this->keyValueExpirable->method('get')
      ->with('markaspot_switch_tokens')
      ->willReturn($store);

    $token = str_repeat('a', 64);
    $request = new Request([], [], [], [], [], [], json_encode(['token' => $token]));
    $response = $this->controller->claimSwitchToken($request);

    $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
  }

  // ===========================================================================
  // Helper methods.
  // ===========================================================================

  /**
   * Recreates the controller with current mocks.
   *
   * Call after replacing mock objects to rebuild the controller.
   */
  protected function recreateController(): void {
    $this->controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
    );
  }

  /**
   * Sets up the container with Devel module enabled.
   */
  protected function enableDevelModule(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(fn($name) => $name === 'devel');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([['user', $userStorage]]);

    $container = new ContainerBuilder();
    $container->set('module_handler', $moduleHandler);
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('logger.factory', $this->createLoggerFactoryStub());
    \Drupal::setContainer($container);

    $this->recreateController();
  }

  /**
   * Creates a logger factory stub that returns a mock logger.
   *
   * @return object
   *   A logger factory stub.
   */
  protected function createLoggerFactoryStub(): object {
    $logger = $this->createMock(LoggerInterface::class);
    return new class($logger) {

      /**
       * The mock logger.
       *
       * @var object
       */
      private object $logger;

      /**
       * Constructs a logger factory stub.
       */
      public function __construct(object $logger) {
        $this->logger = $logger;
      }

      /**
       * Returns a logger channel.
       */
      public function get(string $channel): object {
        return $this->logger;
      }

    };
  }

}
