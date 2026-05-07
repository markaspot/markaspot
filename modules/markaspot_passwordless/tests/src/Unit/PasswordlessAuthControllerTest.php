<?php

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_passwordless\Controller\PasswordlessAuthController;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
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
   * Mocked language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $languageManager;

  /**
   * Mocked feature flag checker.
   *
   * @var \Drupal\markaspot_nuxt\Service\FeatureFlagChecker|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $featureFlagChecker;

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
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->featureFlagChecker = $this->createMock(FeatureFlagChecker::class);
    // Default: passwordless feature enabled so tests hit the logic under test.
    $this->featureFlagChecker->method('isEnabled')->willReturn(TRUE);

    // Default passwordless config.
    $passwordlessConfig = $this->createMock(ImmutableConfig::class);
    $passwordlessConfig->method('get')
      ->willReturnMap([
        ['request_limit_per_email', 3],
        ['request_limit_per_ip', 10],
        ['verify_lockout_attempts', 5],
        ['verify_lockout_duration', 900],
      ]);
    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->willReturnMap([
        ['jurisdiction_group_type', 'jur'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['markaspot_passwordless.settings', $passwordlessConfig],
        ['markaspot_open311.settings', $open311Config],
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
    $container->set('language_manager', $this->languageManager);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureFlagChecker,
    );
  }

  // ===========================================================================
  // Tests for requestCode().
  // ===========================================================================

  /**
   * Tests requestCode returns 403 when passwordless is disabled.
   *
   * Regression guard for the production path documented in
   * MEMORY.md (bug-features-passwordless-missing): a tenant without
   * `features.passwordless=true` in field_nuxt_config must 403, not fall
   * through to OTP generation. Default is fail-closed (FALSE).
   *
   * @covers ::requestCode
   */
  public function testRequestCodeDisabledByFeatureFlag(): void {
    $this->setPasswordlessFlag(FALSE);

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com"}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Tests group payload labels use the user's preferred language.
   *
   * @covers ::getUserGroups
   * @covers ::getEntityLabelForUserLanguage
   */
  public function testGetUserGroupsTranslatesLabelsForPreferredLangcode(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getPreferredLangcode')->with(FALSE)->willReturn('de');

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('uuid')->willReturn('group-uuid');
    $group->method('bundle')->willReturn('jur');
    $group->method('label')->willReturn('Roads');
    $translatedGroup = $this->createMock(GroupInterface::class);
    $translatedGroup->method('label')->willReturn('Strassen');

    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn('jur-member');
    $role->method('label')->willReturn('Member');
    $role->method('getConfigDependencyName')->willReturn('group.role.jur-member');

    $membership = new class($group, $role) {

      public function __construct(
        private readonly GroupInterface $group,
        private readonly GroupRoleInterface $role,
      ) {}

      /**
       * Gets the membership group.
       */
      public function getGroup(): GroupInterface {
        return $this->group;
      }

      /**
       * Gets the membership roles.
       */
      public function getRoles(): array {
        return [$this->role];
      }

    };

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($user)
      ->willReturn([$membership]);

    $entityRepository = $this->createMock(EntityRepositoryInterface::class);
    $entityRepository->expects($this->once())
      ->method('getTranslationFromContext')
      ->willReturnCallback(static function ($entity, $langcode) use ($group, $translatedGroup) {
        self::assertSame('de', $langcode);
        self::assertSame($group, $entity);
        return $translatedGroup;
      });

    $override = new class {

      /**
       * Gets a language config override value.
       */
      public function get(string $key): ?string {
        return $key === 'label' ? 'Mitglied' : NULL;
      }

    };
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->expects($this->once())
      ->method('getLanguageConfigOverride')
      ->with('de', 'group.role.jur-member')
      ->willReturn($override);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);
    $container->set('group.membership_loader', $membershipLoader);
    $container->set('language_manager', $languageManager);

    $controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureFlagChecker,
      $entityRepository,
    );

    $method = new \ReflectionMethod($controller, 'getUserGroups');
    $method->setAccessible(TRUE);

    $this->assertSame([
      [
        'id' => 5,
        'uuid' => 'group-uuid',
        'label' => 'Strassen',
        'type' => 'jur',
        'slug' => NULL,
        'roles' => [
          [
            'id' => 'jur-member',
            'label' => 'Mitglied',
          ],
        ],
      ],
    ], $method->invoke($controller, $user));
  }

  /**
   * Tests configured jurisdiction groups keep the canonical auth contract.
   *
   * @covers ::getUserGroups
   */
  public function testGetUserGroupsCanonicalizesConfiguredJurisdictionType(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getPreferredLangcode')->with(FALSE)->willReturn('');

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('uuid')->willReturn('group-uuid');
    $group->method('bundle')->willReturn('jurisdiction');
    $group->method('label')->willReturn('Roads');

    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn('jurisdiction-tenant_admin');
    $role->method('label')->willReturn('Tenant admin');

    $membership = new class($group, $role) {

      public function __construct(
        private readonly GroupInterface $group,
        private readonly GroupRoleInterface $role,
      ) {}

      /**
       * Gets the membership group.
       */
      public function getGroup(): GroupInterface {
        return $this->group;
      }

      /**
       * Gets the membership roles.
       */
      public function getRoles(): array {
        return [$this->role];
      }

    };

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($user)
      ->willReturn([$membership]);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jurisdiction');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($open311Config);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);
    $container->set('group.membership_loader', $membershipLoader);

    $controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureFlagChecker,
    );

    $method = new \ReflectionMethod($controller, 'getUserGroups');
    $method->setAccessible(TRUE);

    $this->assertSame([
      [
        'id' => 5,
        'uuid' => 'group-uuid',
        'label' => 'Roads',
        'type' => 'jur',
        'slug' => NULL,
        'roles' => [
          [
            'id' => 'jur-tenant_admin',
            'label' => 'Tenant admin',
          ],
        ],
      ],
    ], $method->invoke($controller, $user));
  }

  /**
   * Tests jur groups with field_slug emit slug for client-side scope guards.
   *
   * @covers ::getUserGroups
   */
  public function testGetUserGroupsEmitsSlugForJurisdictionGroupsWithSlug(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getPreferredLangcode')->with(FALSE)->willReturn('');

    $slugFieldItem = new class {

      /**
       * The field value.
       *
       * @var string
       */
      public string $value = 'amsterdam';

      /**
       * Whether the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('uuid')->willReturn('group-uuid');
    $group->method('bundle')->willReturn('jur');
    $group->method('label')->willReturn('Amsterdam');
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name) => $name === 'field_slug');
    $group->method('get')
      ->willReturnCallback(static fn(string $name) => $name === 'field_slug' ? $slugFieldItem : NULL);

    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn('jur-tenant_admin');
    $role->method('label')->willReturn('Tenant admin');

    $membership = new class($group, $role) {

      public function __construct(
        private readonly GroupInterface $group,
        private readonly GroupRoleInterface $role,
      ) {}

      /**
       * Gets the membership group.
       */
      public function getGroup(): GroupInterface {
        return $this->group;
      }

      /**
       * Gets the membership roles.
       */
      public function getRoles(): array {
        return [$this->role];
      }

    };

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($user)
      ->willReturn([$membership]);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);
    $container->set('group.membership_loader', $membershipLoader);

    $controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureFlagChecker,
    );

    $method = new \ReflectionMethod($controller, 'getUserGroups');
    $method->setAccessible(TRUE);

    $result = $method->invoke($controller, $user);
    $this->assertSame('amsterdam', $result[0]['slug']);
    $this->assertSame('jur', $result[0]['type']);
  }

  /**
   * Tests digit-only slugs are rejected to avoid numeric-id collisions.
   *
   * @covers ::getUserGroups
   */
  public function testGetUserGroupsRejectsDigitOnlySlug(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getPreferredLangcode')->with(FALSE)->willReturn('');

    $slugFieldItem = new class {

      /**
       * The field value.
       *
       * @var string
       */
      public string $value = '42';

      /**
       * Whether the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('uuid')->willReturn('group-uuid');
    $group->method('bundle')->willReturn('jur');
    $group->method('label')->willReturn('Numeric Slug');
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name) => $name === 'field_slug');
    $group->method('get')
      ->willReturnCallback(static fn(string $name) => $name === 'field_slug' ? $slugFieldItem : NULL);

    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn('jur-tenant_admin');
    $role->method('label')->willReturn('Tenant admin');

    $membership = new class($group, $role) {

      public function __construct(
        private readonly GroupInterface $group,
        private readonly GroupRoleInterface $role,
      ) {}

      /**
       * Gets the membership group.
       */
      public function getGroup(): GroupInterface {
        return $this->group;
      }

      /**
       * Gets the membership roles.
       */
      public function getRoles(): array {
        return [$this->role];
      }

    };

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($user)
      ->willReturn([$membership]);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);
    $container->set('group.membership_loader', $membershipLoader);

    $controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureFlagChecker,
    );

    $method = new \ReflectionMethod($controller, 'getUserGroups');
    $method->setAccessible(TRUE);

    $result = $method->invoke($controller, $user);
    $this->assertNull($result[0]['slug']);
  }

  /**
   * Tests org groups never get a slug populated even if field is present.
   *
   * @covers ::getUserGroups
   */
  public function testGetUserGroupsEmitsNullSlugForOrgGroups(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getPreferredLangcode')->with(FALSE)->willReturn('');

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(7);
    $group->method('uuid')->willReturn('org-uuid');
    $group->method('bundle')->willReturn('org');
    $group->method('label')->willReturn('Roads Department');

    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn('org-member');
    $role->method('label')->willReturn('Member');

    $membership = new class($group, $role) {

      public function __construct(
        private readonly GroupInterface $group,
        private readonly GroupRoleInterface $role,
      ) {}

      /**
       * Gets the membership group.
       */
      public function getGroup(): GroupInterface {
        return $this->group;
      }

      /**
       * Gets the membership roles.
       */
      public function getRoles(): array {
        return [$this->role];
      }

    };

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($user)
      ->willReturn([$membership]);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);
    $container->set('group.membership_loader', $membershipLoader);

    $controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureFlagChecker,
    );

    $method = new \ReflectionMethod($controller, 'getUserGroups');
    $method->setAccessible(TRUE);

    $result = $method->invoke($controller, $user);
    $this->assertNull($result[0]['slug']);
    $this->assertSame('org', $result[0]['type']);
  }

  /**
   * Tests verifyCode returns 403 when passwordless is disabled.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeDisabledByFeatureFlag(): void {
    $this->setPasswordlessFlag(FALSE);

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"123456"}');
    $response = $this->controller->verifyCode($request);

    $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

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
          'tos_accepted' => TRUE,
          'tos_accepted_at' => 1714567890,
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
    $this->assertTrue($data['user']['tos_accepted']);
    $this->assertSame(1714567890, $data['user']['tos_accepted_at']);
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

  /**
   * Tests status exposes ToS acceptance from the authenticated user entity.
   *
   * @covers ::status
   * @covers ::getTosAcceptancePayload
   */
  public function testStatusAuthenticatedIncludesTosAcceptance(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->currentUser->method('id')->willReturn(42);
    $this->currentUser->method('getAccountName')->willReturn('alice');
    $this->currentUser->method('getEmail')->willReturn('alice@example.com');
    $this->currentUser->method('getRoles')->willReturn(['authenticated']);

    $tosField = $this->createMock(FieldItemListInterface::class);
    $tosField->method('getString')->willReturn('1714567890');

    $userEntity = $this->createMock(UserInterface::class);
    $userEntity->method('getPreferredLangcode')->with(FALSE)->willReturn('en');
    $userEntity->method('hasField')
      ->with('field_tos_accepted_at')
      ->willReturn(TRUE);
    $userEntity->method('get')
      ->with('field_tos_accepted_at')
      ->willReturn($tosField);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(42)->willReturn($userEntity);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('user')->willReturn($userStorage);
    \Drupal::getContainer()->set('entity_type.manager', $entityTypeManager);

    $response = $this->controller->status(new Request());

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['authenticated']);
    $this->assertTrue($data['user']['tos_accepted']);
    $this->assertSame(1714567890, $data['user']['tos_accepted_at']);
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
      $this->featureFlagChecker,
    );
  }

  /**
   * Rebuilds the controller with the passwordless feature flag toggled.
   *
   * Production default is fail-closed (flag absent = disabled), so negative
   * cases must swap the default-TRUE mock for a fresh FALSE stub.
   */
  protected function setPasswordlessFlag(bool $enabled): void {
    $this->featureFlagChecker = $this->createMock(FeatureFlagChecker::class);
    $this->featureFlagChecker->method('isEnabled')->willReturn($enabled);
    $this->recreateController();
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
    $container->set('language_manager', $this->languageManager);
    $container->set('string_translation', $this->getStringTranslationStub());
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

  // ===========================================================================
  // Tests for updatePreferences().
  // ===========================================================================

  /**
   * Tests updatePreferences with missing payload returns 400.
   *
   * @covers ::updatePreferences
   */
  public function testUpdatePreferencesMissingLangcode(): void {
    $request = new Request([], [], [], [], [], [], '{}');
    $response = $this->controller->updatePreferences($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('preferred_langcode is required', $data['error']);
  }

  /**
   * Tests updatePreferences with an unknown langcode returns 400.
   *
   * @covers ::updatePreferences
   */
  public function testUpdatePreferencesInvalidLangcode(): void {
    $deLanguage = $this->createMock(LanguageInterface::class);
    $this->languageManager
      ->method('getLanguages')
      ->with(LanguageInterface::STATE_CONFIGURABLE)
      ->willReturn(['de' => $deLanguage]);

    $request = new Request([], [], [], [], [], [], '{"preferred_langcode":"zz"}');
    $response = $this->controller->updatePreferences($request);

    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid langcode', $data['error']);
  }

  /**
   * Tests updatePreferences successfully persists a valid langcode.
   *
   * @covers ::updatePreferences
   */
  public function testUpdatePreferencesSuccess(): void {
    $deLanguage = $this->createMock(LanguageInterface::class);
    $this->languageManager
      ->method('getLanguages')
      ->with(LanguageInterface::STATE_CONFIGURABLE)
      ->willReturn(['de' => $deLanguage, 'en' => $deLanguage]);

    $this->currentUser->method('id')->willReturn(42);

    $userEntity = $this->getMockBuilder(\stdClass::class)
      ->addMethods([
        'set',
        'save',
        'id',
        'getAccountName',
        'getEmail',
        'getRoles',
        'getPreferredLangcode',
      ])
      ->getMock();
    $userEntity->expects($this->once())
      ->method('set')
      ->with('preferred_langcode', 'de');
    $userEntity->expects($this->once())->method('save');
    $userEntity->method('id')->willReturn(42);
    $userEntity->method('getAccountName')->willReturn('alice');
    $userEntity->method('getEmail')->willReturn('alice@example.com');
    $userEntity->method('getRoles')->willReturn(['authenticated']);
    $userEntity->method('getPreferredLangcode')->willReturn('de');

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(42)->willReturn($userEntity);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('user')->willReturn($userStorage);

    // Replace the entity_type.manager service that ControllerBase reads
    // lazily, then rebuild the controller so it picks up the new container.
    $container = \Drupal::getContainer();
    $container->set('entity_type.manager', $entityTypeManager);

    $request = new Request([], [], [], [], [], [], '{"preferred_langcode":"de"}');
    $response = $this->controller->updatePreferences($request);

    $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['authenticated']);
    $this->assertEquals('de', $data['user']['preferred_langcode']);
    $this->assertEquals('alice@example.com', $data['user']['email']);
  }

}
