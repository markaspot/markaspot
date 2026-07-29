<?php

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\markaspot_nuxt\Service\FrontendUrlService;
use Drupal\markaspot_passwordless\Service\BreakGlassOtpServiceInterface;
use Drupal\markaspot_passwordless\Controller\PasswordlessAuthController;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__, 4) . '/markaspot_nuxt/src/Service/FeatureScopeResolver.php';
require_once dirname(__DIR__, 3) . '/src/Service/BreakGlassOtpServiceInterface.php';
require_once dirname(__DIR__, 3) . '/src/Controller/PasswordlessAuthController.php';

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
   * @var \Drupal\markaspot_nuxt\Service\FeatureScopeResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $featureScopeResolver;

  /**
   * Mocked Core state service.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

  /**
   * Mocked recovery-only OTP service.
   *
   * @var \Drupal\markaspot_passwordless\Service\BreakGlassOtpServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $breakGlassOtp;

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
    $this->featureScopeResolver = $this->createMock(FeatureScopeResolver::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->breakGlassOtp = $this->createMock(BreakGlassOtpServiceInterface::class);
    // Default: passwordless feature enabled so tests hit the logic under test.
    $this->featureScopeResolver->method('isPlatformFeatureEnabled')->willReturn(TRUE);
    $this->state->method('get')
      ->with('system.maintenance_mode', FALSE)
      ->willReturn(FALSE);

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
    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('get')
      ->with('platform_features.passwordless')
      ->willReturn(TRUE);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['markaspot_passwordless.settings', $passwordlessConfig],
        ['markaspot_open311.settings', $open311Config],
        ['markaspot_nuxt.settings', $nuxtConfig],
      ]);

    // Default: all flood checks pass.
    $this->flood->method('isAllowed')->willReturn(TRUE);

    // Set up container for module_handler dependency.
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

    $this->controller = $this->buildController();
  }

  /**
   * Builds the controller, optionally stubbing group membership loading.
   */
  protected function buildController(
    ?ConfigFactoryInterface $configFactory = NULL,
    ?EntityRepositoryInterface $entityRepository = NULL,
    ?array $memberships = NULL,
    ?FrontendUrlService $frontendUrlService = NULL,
  ): PasswordlessAuthController {
    $configFactory ??= $this->configFactory;

    if ($memberships !== NULL) {
      return new class(
        $this->otpService,
        $this->currentUser,
        $this->flood,
        $configFactory,
        $this->sessionConfiguration,
        $this->keyValueExpirable,
        $this->featureScopeResolver,
        $this->state,
        $this->breakGlassOtp,
        $entityRepository,
        $frontendUrlService,
        $memberships,
      ) extends PasswordlessAuthController {

        public function __construct(
          OtpService $otp_service,
          AccountInterface $current_user,
          FloodInterface $flood,
          ConfigFactoryInterface $config_factory,
          SessionConfigurationInterface $session_configuration,
          KeyValueExpirableFactoryInterface $key_value_expirable,
          FeatureScopeResolver $feature_scope_resolver,
          StateInterface $state,
          BreakGlassOtpServiceInterface $break_glass_otp,
          ?EntityRepositoryInterface $entityRepository,
          ?FrontendUrlService $frontendUrlService,
          private readonly array $testMemberships,
        ) {
          parent::__construct(
            $otp_service,
            $current_user,
            $flood,
            $config_factory,
            $session_configuration,
            $key_value_expirable,
            $feature_scope_resolver,
            $state,
            $break_glass_otp,
            $entityRepository,
            $frontendUrlService,
          );
        }

        /**
         * {@inheritdoc}
         */
        protected function loadUserGroupMemberships(AccountInterface $user): array {
          return $this->testMemberships;
        }

      };
    }

    return new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureScopeResolver,
      $this->state,
      $this->breakGlassOtp,
      $entityRepository,
      $frontendUrlService,
    );
  }

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
    $this->breakGlassOtp->expects($this->never())->method('requestCode');

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","jurisdiction_id":42}');
    $response = $this->controller->requestCode($request);

    $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Core maintenance switches the route to recovery OTP.
   *
   * This runs before the normal passwordless feature flag and never reaches
   * normal auto-registration.
   *
   * @covers ::requestCode
   * @covers ::requestBreakGlassCode
   */
  public function testMaintenanceUsesBreakGlassOtpWhenNormalPasswordlessIsDisabled(): void {
    $this->setPasswordlessFlag(FALSE);
    $this->setMaintenanceMode(TRUE);
    $this->otpService->expects($this->never())->method('requestCode');
    $this->breakGlassOtp->expects($this->once())
      ->method('requestCode')
      ->with('operator@example.com', '')
      ->willReturn([
        'success' => TRUE,
        'message' => 'If the account is eligible, a verification code has been sent.',
        'expiresIn' => 600,
      ]);

    $response = $this->controller->requestCode(
      new Request([], [], [], [], [], [], '{"email":"operator@example.com","jurisdiction_id":"not-used"}'),
    );

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'success' => TRUE,
      'message' => 'If the account is eligible, a verification code has been sent.',
      'expiresIn' => 600,
    ], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Maintenance request Flood buckets canonicalize case and surrounding space.
   *
   * @covers ::requestCode
   * @covers ::requestBreakGlassCode
   * @covers ::canonicalizeBreakGlassFloodEmail
   */
  public function testMaintenanceRequestCanonicalizesEmailFloodIdentifier(): void {
    $this->setMaintenanceMode(TRUE);
    $this->breakGlassOtp->expects($this->once())
      ->method('requestCode')
      ->with('Operator@Example.COM', '')
      ->willReturn([
        'success' => TRUE,
        'message' => 'If the account is eligible, a verification code has been sent.',
        'expiresIn' => 600,
      ]);

    $this->flood = $this->createMock(FloodInterface::class);
    $allowed_calls = [
      ['passwordless.break_glass.request', 3, 3600, 'break_glass:operator@example.com'],
      ['passwordless.break_glass.request.ip', 10, 3600, '203.0.113.10'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $threshold, int $window, string $identifier) use (&$allowed_calls): bool {
        self::assertSame(array_shift($allowed_calls), [$event, $threshold, $window, $identifier]);
        return TRUE;
      });
    $register_calls = [
      ['passwordless.break_glass.request', 3600, 'break_glass:operator@example.com'],
      ['passwordless.break_glass.request.ip', 3600, '203.0.113.10'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(static function (string $event, int $window, string $identifier) use (&$register_calls): void {
        self::assertSame(array_shift($register_calls), [$event, $window, $identifier]);
      });
    $this->recreateController();

    $response = $this->controller->requestCode(Request::create(
      '/api/auth/request-code',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.10'],
      '{"email":" Operator@Example.COM "}',
    ));

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Noneligible recovery attempts receive the generic invalid-code result.
   *
   * They cannot fall through to the normal OTP verifier.
   *
   * @covers ::verifyCode
   * @covers ::verifyBreakGlassCode
   */
  public function testMaintenanceNoneligibleVerificationStaysGeneric(): void {
    $this->setMaintenanceMode(TRUE);
    $this->otpService->expects($this->never())->method('verifyCode');
    $this->breakGlassOtp->expects($this->once())
      ->method('verifyCode')
      ->with('noneligible@example.com', '123456')
      ->willReturn(NULL);

    $response = $this->controller->verifyCode(
      new Request([], [], [], [], [], [], '{"email":"noneligible@example.com","code":"123456"}'),
    );

    self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    self::assertSame(['error' => 'Invalid verification code'], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Maintenance verification Flood buckets use the same canonical email key.
   *
   * @covers ::verifyCode
   * @covers ::verifyBreakGlassCode
   * @covers ::canonicalizeBreakGlassFloodEmail
   */
  public function testMaintenanceVerificationCanonicalizesEmailFloodIdentifier(): void {
    $this->setMaintenanceMode(TRUE);
    $this->breakGlassOtp->expects($this->once())
      ->method('verifyCode')
      ->with('Operator@Example.COM', '123456')
      ->willReturn(NULL);

    $this->flood = $this->createMock(FloodInterface::class);
    $allowed_calls = [
      ['passwordless.break_glass.verify.lockout', 5, 900, 'break_glass:operator@example.com:203.0.113.10'],
      ['passwordless.break_glass.verify.backoff', 3, 60, 'break_glass:operator@example.com:203.0.113.10'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $threshold, int $window, string $identifier) use (&$allowed_calls): bool {
        self::assertSame(array_shift($allowed_calls), [$event, $threshold, $window, $identifier]);
        return TRUE;
      });
    $register_calls = [
      ['passwordless.break_glass.verify.lockout', 900, 'break_glass:operator@example.com:203.0.113.10'],
      ['passwordless.break_glass.verify.backoff', 60, 'break_glass:operator@example.com:203.0.113.10'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(static function (string $event, int $window, string $identifier) use (&$register_calls): void {
        self::assertSame(array_shift($register_calls), [$event, $window, $identifier]);
      });
    $this->recreateController();

    $response = $this->controller->verifyCode(Request::create(
      '/api/auth/verify-code',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.10'],
      '{"email":" Operator@Example.COM ","code":"123456"}',
    ));

    self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
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
    $container->set('language_manager', $languageManager);

    $controller = $this->buildController(NULL, $entityRepository, [$membership]);

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

    $controller = $this->buildController($configFactory, NULL, [$membership]);

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

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);

    $controller = $this->buildController(NULL, NULL, [$membership]);

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

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);

    $controller = $this->buildController(NULL, NULL, [$membership]);

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

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);

    $container = \Drupal::getContainer();
    $container->set('module_handler', $moduleHandler);

    $controller = $this->buildController(NULL, NULL, [$membership]);

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

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","code":"123456","jurisdiction_id":42}');
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
   * Tests requestCode scopes the email flood key by jurisdiction.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeScopesEmailFloodByJurisdiction(): void {
    $this->setResolvableJurisdiction(5);

    $this->otpService->expects($this->once())
      ->method('requestCode')
      ->with('user@example.com', 5, '')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => 600,
      ]);

    $this->flood = $this->createMock(FloodInterface::class);
    $allowedCalls = [
      ['passwordless.request_code', 3, 3600, 'user@example.com:5'],
      ['passwordless.request_code.ip', 10, 3600, '203.0.113.10'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $threshold, int $window, string $identifier) use (&$allowedCalls): bool {
        self::assertSame(array_shift($allowedCalls), [$event, $threshold, $window, $identifier]);
        return TRUE;
      });
    $registerCalls = [
      ['passwordless.request_code', 3600, 'user@example.com:5'],
      ['passwordless.request_code.ip', 3600, '203.0.113.10'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(static function (string $event, int $window, string $identifier) use (&$registerCalls): void {
        self::assertSame(array_shift($registerCalls), [$event, $window, $identifier]);
      });
    $this->recreateController();

    $request = Request::create(
      '/api/auth/request-code',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.10'],
      '{"email":"user@example.com","jurisdiction_id":5}'
    );
    $response = $this->controller->requestCode($request);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Tests requestCode accepts explicit zero as unscoped jurisdiction.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeAcceptsExplicitZeroJurisdiction(): void {
    $this->otpService->expects($this->once())
      ->method('requestCode')
      ->with('user@example.com', 0, '')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => 600,
      ]);

    $request = new Request([], [], [], [], [], [], '{"email":"user@example.com","jurisdiction_id":0}');
    $response = $this->controller->requestCode($request);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Tests malformed scalar jurisdiction payloads are rejected.
   *
   * @param mixed $jurisdictionId
   *   The malformed jurisdiction ID payload.
   *
   * @covers ::requestCode
   *
   * @dataProvider malformedJurisdictionPayloadProvider
   */
  public function testRequestCodeRejectsMalformedJurisdictionPayload(mixed $jurisdictionId): void {
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->expects($this->never())
      ->method('isAllowed');
    $this->recreateController();

    $request = new Request([], [], [], [], [], [], json_encode([
      'email' => 'user@example.com',
      'jurisdiction_id' => $jurisdictionId,
    ]));
    $response = $this->controller->requestCode($request);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
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
   * Tests failed verifyCode registers scoped jurisdiction flood keys.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeFailureScopesFloodByJurisdiction(): void {
    $this->setResolvableJurisdiction(5);

    $this->otpService->expects($this->once())
      ->method('verifyCode')
      ->with('user@example.com', '123456', 5)
      ->willReturn([
        'success' => FALSE,
        'error' => 'Invalid verification code',
      ]);

    $this->flood = $this->createMock(FloodInterface::class);
    $allowedCalls = [
      ['passwordless.verify.lockout', 5, 900, 'user@example.com:203.0.113.10:5'],
      ['passwordless.verify.backoff', 3, 60, 'user@example.com:203.0.113.10:5'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $threshold, int $window, string $identifier) use (&$allowedCalls): bool {
        self::assertSame(array_shift($allowedCalls), [$event, $threshold, $window, $identifier]);
        return TRUE;
      });
    $registerCalls = [
      ['passwordless.verify.lockout', 900, 'user@example.com:203.0.113.10:5'],
      ['passwordless.verify.backoff', 60, 'user@example.com:203.0.113.10:5'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(static function (string $event, int $window, string $identifier) use (&$registerCalls): void {
        self::assertSame(array_shift($registerCalls), [$event, $window, $identifier]);
      });
    $this->recreateController();

    $request = Request::create(
      '/api/auth/verify-code',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.10'],
      '{"email":"user@example.com","code":"123456","jurisdiction_id":5}'
    );
    $response = $this->controller->verifyCode($request);

    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
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
          'uuid' => 'user-uuid-5',
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
    $this->assertSame('user-uuid-5', $data['user']['uuid']);
    $this->assertTrue($data['user']['tos_accepted']);
    $this->assertSame(1714567890, $data['user']['tos_accepted_at']);
  }

  /**
   * Tests successful verifyCode clears scoped jurisdiction flood keys.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeSuccessScopesFloodByJurisdiction(): void {
    $this->setResolvableJurisdiction(5);

    $this->otpService->expects($this->once())
      ->method('verifyCode')
      ->with('user@example.com', '123456', 5)
      ->willReturn([
        'success' => TRUE,
        'message' => 'Authentication successful',
        'user' => [
          'uid' => 5,
          'uuid' => 'user-uuid-5',
          'name' => 'testuser',
          'email' => 'user@example.com',
          'roles' => ['authenticated'],
          'groups' => [],
        ],
      ]);

    $this->flood = $this->createMock(FloodInterface::class);
    $allowedCalls = [
      ['passwordless.verify.lockout', 5, 900, 'user@example.com:203.0.113.10:5'],
      ['passwordless.verify.backoff', 3, 60, 'user@example.com:203.0.113.10:5'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $threshold, int $window, string $identifier) use (&$allowedCalls): bool {
        self::assertSame(array_shift($allowedCalls), [$event, $threshold, $window, $identifier]);
        return TRUE;
      });
    $clearCalls = [
      ['passwordless.verify.lockout', 'user@example.com:203.0.113.10:5'],
      ['passwordless.verify.backoff', 'user@example.com:203.0.113.10:5'],
    ];
    $this->flood->expects($this->exactly(2))
      ->method('clear')
      ->willReturnCallback(static function (string $event, string $identifier) use (&$clearCalls): void {
        self::assertSame(array_shift($clearCalls), [$event, $identifier]);
      });
    $this->recreateController();

    $request = Request::create(
      '/api/auth/verify-code',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.10'],
      '{"email":"user@example.com","code":"123456","jurisdiction_id":5}'
    );
    $response = $this->controller->verifyCode($request);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

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
    $this->assertFalse($data['maintenance_access']);
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
    $this->currentUser->method('hasPermission')
      ->willReturnMap([
        ['administer site configuration', FALSE],
        ['triage inbound mail', TRUE],
        ['delete any service_request content', FALSE],
        ['switch users', TRUE],
        ['access site in maintenance mode', TRUE],
      ]);

    $tosField = $this->createMock(FieldItemListInterface::class);
    $tosField->method('getString')->willReturn('1714567890');

    $userEntity = $this->createMock(UserInterface::class);
    $userEntity->method('uuid')->willReturn('alice-user-uuid');
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
    $this->assertTrue($data['maintenance_access']);
    $this->assertSame('alice-user-uuid', $data['user']['uuid']);
    $this->assertSame(['triage inbound mail', 'switch users'], $data['user']['permissions']);
    $this->assertTrue($data['user']['tos_accepted']);
    $this->assertSame(1714567890, $data['user']['tos_accepted_at']);
  }

  /**
   * Tests absent Drupal permissions stay absent from the frontend payload.
   *
   * @covers ::getFrontendPermissions
   */
  public function testFrontendPermissionsOmitSwitchUsersWithoutPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $method = new \ReflectionMethod($this->controller, 'getFrontendPermissions');

    $this->assertSame([], $method->invoke($this->controller, $account));
  }

  /**
   * Tests anonymous session handoff starts at Drupal login.
   *
   * @covers ::startSessionHandoff
   */
  public function testStartSessionHandoffAnonymousRedirectsToDrupalLogin(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);

    $request = Request::create('https://dev.ddev.site/api/auth/session-handoff/start?redirect=/amsterdam/dashboard');
    $response = $this->controller->startSessionHandoff($request);

    $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    $location = $response->headers->get('location');
    $this->assertIsString($location);
    $this->assertStringStartsWith('/user/login?destination=', $location);
    $this->assertStringContainsString(rawurlencode($request->getRequestUri()), $location);
  }

  /**
   * Tests authenticated session handoff creates a short-lived Nuxt claim URL.
   *
   * @covers ::startSessionHandoff
   * @covers ::buildSessionHandoffClaimPath
   * @covers ::normalizeInternalRedirect
   * @covers ::resolveFrontendBaseUrl
   */
  public function testStartSessionHandoffCreatesTokenAndRedirectsToNuxtClaim(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->currentUser->method('id')->willReturn(42);

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->once())
      ->method('setWithExpire')
      ->with(
        $this->matchesRegularExpression('/^[0-9a-f]{64}$/'),
        $this->callback(static function (array $payload): bool {
          return $payload['uid'] === 42
            && $payload['redirect'] === '/amsterdam/dashboard';
        }),
        60
      );
    $this->keyValueExpirable->expects($this->once())
      ->method('get')
      ->with('markaspot_session_handoff_tokens')
      ->willReturn($store);

    $request = Request::create('https://dev.ddev.site/api/auth/session-handoff/start?redirect=/amsterdam/dashboard');
    $response = $this->controller->startSessionHandoff($request);

    $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    $this->assertMatchesRegularExpression(
      '~^https://dev\.ddev\.site:3001/amsterdam/auth/claim\?type=drupal-session#token=[0-9a-f]{64}$~',
      (string) $response->headers->get('location')
    );
  }

  /**
   * Tests configured frontend base URL wins over the local DDEV fallback.
   *
   * @covers ::startSessionHandoff
   * @covers ::resolveFrontendBaseUrl
   */
  public function testStartSessionHandoffUsesConfiguredFrontendBaseUrl(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->currentUser->method('id')->willReturn(42);

    $frontendUrl = $this->createMock(FrontendUrlService::class);
    $frontendUrl->method('getFrontendBaseUrl')
      ->willReturn('https://frontend.example.test/');

    $this->controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureScopeResolver,
      $this->state,
      $this->breakGlassOtp,
      NULL,
      $frontendUrl,
    );

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->once())
      ->method('setWithExpire');
    $this->keyValueExpirable->method('get')
      ->with('markaspot_session_handoff_tokens')
      ->willReturn($store);

    $request = Request::create('https://drupal.example.test/api/auth/session-handoff/start?redirect=/dashboard');
    $response = $this->controller->startSessionHandoff($request);

    $this->assertMatchesRegularExpression(
      '~^https://frontend\.example\.test/auth/claim\?type=drupal-session#token=[0-9a-f]{64}$~',
      (string) $response->headers->get('location')
    );
  }

  /**
   * Tests invalid configured frontend base URLs are rejected.
   *
   * @covers ::startSessionHandoff
   * @covers ::normalizeFrontendBaseUrl
   */
  public function testStartSessionHandoffRejectsUnsafeFrontendBaseUrl(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);

    $frontendUrl = $this->createMock(FrontendUrlService::class);
    $frontendUrl->method('getFrontendBaseUrl')
      ->willReturn('javascript:alert(1)');

    $this->controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureScopeResolver,
      $this->state,
      $this->breakGlassOtp,
      NULL,
      $frontendUrl,
    );

    $request = Request::create('https://drupal.example.test/api/auth/session-handoff/start?redirect=/dashboard');
    $response = $this->controller->startSessionHandoff($request);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
  }

  /**
   * Tests configured frontend base URLs with query strings are rejected.
   *
   * @covers ::startSessionHandoff
   * @covers ::normalizeFrontendBaseUrl
   */
  public function testStartSessionHandoffRejectsFrontendBaseUrlWithQuery(): void {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);

    $frontendUrl = $this->createMock(FrontendUrlService::class);
    $frontendUrl->method('getFrontendBaseUrl')
      ->willReturn('https://frontend.example.test/?x=1');

    $this->controller = new PasswordlessAuthController(
      $this->otpService,
      $this->currentUser,
      $this->flood,
      $this->configFactory,
      $this->sessionConfiguration,
      $this->keyValueExpirable,
      $this->featureScopeResolver,
      $this->state,
      $this->breakGlassOtp,
      NULL,
      $frontendUrl,
    );

    $request = Request::create('https://drupal.example.test/api/auth/session-handoff/start?redirect=/dashboard');
    $response = $this->controller->startSessionHandoff($request);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
  }

  /**
   * Tests session handoff redirect normalization rejects unsafe paths.
   *
   * @covers ::normalizeInternalRedirect
   *
   * @dataProvider unsafeSessionHandoffRedirectProvider
   */
  public function testNormalizeInternalRedirectRejectsUnsafeValues(mixed $input): void {
    $method = new \ReflectionMethod($this->controller, 'normalizeInternalRedirect');
    $method->setAccessible(TRUE);

    $this->assertSame('/dashboard', $method->invoke($this->controller, $input));
  }

  /**
   * Provides unsafe redirect values.
   */
  public static function unsafeSessionHandoffRedirectProvider(): array {
    return [
      'null' => [NULL],
      'empty' => [''],
      'external-url' => ['https://evil.example/dashboard'],
      'protocol-relative' => ['//evil.example/dashboard'],
      'backslash-host' => ['/\\evil.example/dashboard'],
      'auth-segment' => ['/amsterdam/auth/login'],
      'oversize' => ['/' . str_repeat('a', 1025)],
    ];
  }

  /**
   * Tests session handoff redirect normalization preserves safe paths.
   *
   * @covers ::normalizeInternalRedirect
   */
  public function testNormalizeInternalRedirectPreservesSafeInternalPath(): void {
    $method = new \ReflectionMethod($this->controller, 'normalizeInternalRedirect');
    $method->setAccessible(TRUE);

    $this->assertSame('/amsterdam/dashboard?tab=requests', $method->invoke($this->controller, '/amsterdam/dashboard?tab=requests'));
  }

  /**
   * Tests session handoff claim with missing token returns 400.
   *
   * @covers ::claimSessionHandoff
   */
  public function testClaimSessionHandoffMissingToken(): void {
    $request = new Request([], [], [], [], [], [], '{}');
    $response = $this->controller->claimSessionHandoff($request);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Tests session handoff claim is rate-limited by client IP.
   *
   * @covers ::claimSessionHandoff
   * @covers ::assertSessionHandoffClaimAllowed
   */
  public function testClaimSessionHandoffRateLimited(): void {
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->expects($this->once())
      ->method('isAllowed')
      ->with('passwordless.session_handoff_claim', 30, 300, '203.0.113.10')
      ->willReturn(FALSE);
    $this->flood->expects($this->never())
      ->method('register');
    $this->recreateController();

    $request = Request::create(
      'https://dev.ddev.site/api/auth/session-handoff/claim',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.10'],
      json_encode(['token' => str_repeat('a', 64)])
    );
    $response = $this->controller->claimSessionHandoff($request);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
  }

  /**
   * Tests session handoff claim with invalid token format returns 400.
   *
   * @covers ::claimSessionHandoff
   */
  public function testClaimSessionHandoffInvalidFormat(): void {
    $request = new Request([], [], [], [], [], [], '{"token":"short"}');
    $response = $this->controller->claimSessionHandoff($request);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Tests session handoff claim with expired token returns 401.
   *
   * @covers ::claimSessionHandoff
   */
  public function testClaimSessionHandoffExpiredToken(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);
    $this->keyValueExpirable->method('get')
      ->with('markaspot_session_handoff_tokens')
      ->willReturn($store);

    $request = new Request([], [], [], [], [], [], json_encode(['token' => str_repeat('a', 64)]));
    $response = $this->controller->claimSessionHandoff($request);

    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
  }

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

  /**
   * Tests service accounts are excluded before the result cap is applied.
   *
   * @covers ::listSwitchUsers
   */
  public function testListSwitchUsersFiltersApiRoleBeforeRange(): void {
    $this->enableDevelModule();

    $queryCalls = [];
    $roleQuery = $this->createMock(QueryInterface::class);
    $roleQuery->method('condition')->willReturnCallback(
      function (string $field, mixed $value, ?string $operator = NULL) use (&$queryCalls, $roleQuery): QueryInterface {
        $queryCalls[] = ['role condition', $field, $value, $operator];
        return $roleQuery;
      },
    );
    $roleQuery->method('accessCheck')->willReturnSelf();
    $roleQuery->method('execute')->willReturnCallback(
      function () use (&$queryCalls): array {
        $queryCalls[] = ['role execute'];
        return [2 => 2, 4 => 4];
      },
    );

    $userQuery = $this->createMock(QueryInterface::class);
    $userQuery->method('condition')->willReturnCallback(
      function (string $field, mixed $value, ?string $operator = NULL) use (&$queryCalls, $userQuery): QueryInterface {
        $queryCalls[] = ['user condition', $field, $value, $operator];
        return $userQuery;
      },
    );
    $userQuery->method('sort')->willReturnCallback(
      function (string $field) use (&$queryCalls, $userQuery): QueryInterface {
        $queryCalls[] = ['sort', $field];
        return $userQuery;
      },
    );
    $userQuery->method('range')->willReturnCallback(
      function (int $start, int $length) use (&$queryCalls, $userQuery): QueryInterface {
        $queryCalls[] = ['range', $start, $length];
        return $userQuery;
      },
    );
    $userQuery->method('accessCheck')->willReturnSelf();
    $userQuery->method('execute')->willReturn([3 => 3, 5 => 5]);

    $staffAccount = $this->createMock(UserInterface::class);
    $staffAccount->method('id')->willReturn(3);
    $staffAccount->method('getAccountName')->willReturn('staff');
    $staffAccount->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $rolelessAccount = $this->createMock(UserInterface::class);
    $rolelessAccount->method('id')->willReturn(5);
    $rolelessAccount->method('getAccountName')->willReturn('roleless');
    $rolelessAccount->method('getRoles')->willReturn(['authenticated']);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('getQuery')->willReturnOnConsecutiveCalls($roleQuery, $userQuery);
    $userStorage->method('loadMultiple')
      ->with([3 => 3, 5 => 5])
      ->willReturn([3 => $staffAccount, 5 => $rolelessAccount]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('user')
      ->willReturn($userStorage);
    \Drupal::getContainer()->set('entity_type.manager', $entityTypeManager);

    $response = $this->controller->listSwitchUsers();

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([
      ['role condition', 'roles', 'api_user', NULL],
      ['role execute'],
      ['user condition', 'uid', 1, '>'],
      ['user condition', 'status', 1, NULL],
      ['sort', 'uid'],
      ['user condition', 'uid', [2, 4], 'NOT IN'],
      ['range', 0, 20],
    ], $queryCalls);
    $this->assertSame([
      [
        'uid' => 3,
        'name' => 'staff',
        'roles' => ['tenant_admin'],
      ],
      [
        'uid' => 5,
        'name' => 'roleless',
        'roles' => [],
      ],
    ], json_decode((string) $response->getContent(), TRUE));
  }

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
      $this->featureScopeResolver,
      $this->state,
      $this->breakGlassOtp,
    );
  }

  /**
   * Registers a resolvable jurisdiction group in the test container.
   *
   * @param int $id
   *   The jurisdiction group ID.
   * @param string $bundle
   *   The jurisdiction group bundle.
   */
  protected function setResolvableJurisdiction(int $id, string $bundle = 'jur'): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('isPublished')->willReturn(TRUE);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->with($id)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    \Drupal::getContainer()->set('entity_type.manager', $entityTypeManager);
  }

  /**
   * Provides malformed scalar jurisdiction payload values.
   */
  public static function malformedJurisdictionPayloadProvider(): array {
    return [
      'boolean' => [TRUE],
      'float' => [1.9],
      'decimal-string' => ['42.9'],
      'scientific-string' => ['1e2'],
    ];
  }

  /**
   * Rebuilds the controller with the passwordless feature flag toggled.
   *
   * Production default is fail-closed (flag absent = disabled), so negative
   * cases must swap the default-TRUE mock for a fresh FALSE stub.
   */
  protected function setPasswordlessFlag(bool $enabled): void {
    $this->featureScopeResolver = $this->createMock(FeatureScopeResolver::class);
    $this->featureScopeResolver->method('isPlatformFeatureEnabled')->willReturn($enabled);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('42');
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->with(42)->willReturn($group);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('group')->willReturn($groupStorage);
    \Drupal::getContainer()->set('entity_type.manager', $entityTypeManager);
    $this->recreateController();
  }

  /**
   * Rebuilds the controller with a fixed Core maintenance-mode state.
   */
  protected function setMaintenanceMode(bool $enabled): void {
    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('get')
      ->with('system.maintenance_mode', FALSE)
      ->willReturn($enabled);
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

    $userEntity = $this->createMock(UserInterface::class);
    $userEntity->expects($this->once())
      ->method('set')
      ->with('preferred_langcode', 'de');
    $userEntity->expects($this->once())->method('save');
    $userEntity->method('id')->willReturn(42);
    $userEntity->method('uuid')->willReturn('alice-user-uuid');
    $userEntity->method('getAccountName')->willReturn('alice');
    $userEntity->method('getEmail')->willReturn('alice@example.com');
    $userEntity->method('getRoles')->willReturn(['authenticated']);
    $userEntity->method('getPreferredLangcode')->willReturn('de');
    $userEntity->method('hasPermission')->willReturn(FALSE);
    $userEntity->method('hasField')
      ->with('field_tos_accepted_at')
      ->willReturn(FALSE);

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
