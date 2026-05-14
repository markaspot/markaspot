<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Tests OTP jurisdiction binding.
 *
 * Guards against cross-tenant OTP reuse: a code issued by tenant A must
 * not be consumable on tenant B even when both tenants enable passwordless
 * auth and share an account by email.
 *
 * @group markaspot_passwordless
 * @coversDefaultClass \Drupal\markaspot_passwordless\Service\OtpService
 */
class OtpServiceTest extends UnitTestCase {

  /**
   * Mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * Builds an OtpService with a database mock and sensible stubs.
   */
  protected function buildService(
    Connection $database,
    ?ModuleHandlerInterface $moduleHandler = NULL,
    ?EntityRepositoryInterface $entityRepository = NULL,
    ?LanguageManagerInterface $languageManager = NULL,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    string $jurisdictionGroupType = 'jur',
  ): OtpService {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturn(['result' => TRUE]);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['code_lifetime', 600],
      ['max_attempts', 3],
      ['auto_register', FALSE],
    ]);

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->willReturnMap([
      ['name', 'Mark-a-Spot'],
    ]);

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')->willReturnMap([
      ['jurisdiction_group_type', $jurisdictionGroupType],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['markaspot_passwordless.settings', $settings],
      ['system.site', $siteConfig],
      ['markaspot_open311.settings', $open311Config],
    ]);

    if ($entityTypeManager === NULL) {
      // Group storage: load() returns NULL so sendCode falls back to the
      // site name for the email platform string — the jurisdiction label
      // is not what these tests assert on.
      $groupStorage = $this->createMock(EntityStorageInterface::class);
      $groupStorage->method('load')->willReturn(NULL);
      $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
      $entityTypeManager->method('getStorage')->willReturn($groupStorage);
    }

    if ($languageManager === NULL) {
      $language = $this->createMock(LanguageInterface::class);
      $language->method('getId')->willReturn('en');
      $languageManager = $this->createMock(LanguageManagerInterface::class);
      $languageManager->method('getCurrentLanguage')->willReturn($language);
    }

    $moduleHandler ??= $this->createMock(ModuleHandlerInterface::class);

    return new OtpService(
      $database,
      $mail,
      $currentUser,
      $logger,
      $configFactory,
      $entityTypeManager,
      $languageManager,
      $moduleHandler,
      $entityRepository,
    );
  }

  /**
   * Builds a Transaction stand-in without entering the DB lifecycle.
   */
  protected function fakeTransaction(): Transaction {
    return new class() extends Transaction {

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __construct() {
        // Skip parent constructor to avoid readonly property init and
        // Database::commitAllOnShutdown() registration.
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __destruct() {
        // No-op — parent destructor dereferences the uninitialized
        // connection property.
      }

    };
  }

  /**
   * Tests group payload labels use the user's preferred language.
   *
   * @covers ::getUserGroups
   * @covers ::getEntityLabelForUserLanguage
   */
  public function testGetUserGroupsTranslatesLabelsForPreferredLangcode(): void {
    $user = $this->createMock(User::class);
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

    $container = new ContainerBuilder();
    $container->set('group.membership_loader', $membershipLoader);
    \Drupal::setContainer($container);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);
    $service = $this->buildService($this->createMock(Connection::class), $moduleHandler, $entityRepository, $languageManager);

    $method = new \ReflectionMethod($service, 'getUserGroups');
    $method->setAccessible(TRUE);

    $this->assertSame([
      [
        'id' => 5,
        'uuid' => 'group-uuid',
        'label' => 'Strassen',
        'type' => 'jur',
        'roles' => [
          [
            'id' => 'jur-member',
            'label' => 'Mitglied',
          ],
        ],
      ],
    ], $method->invoke($service, $user));
  }

  /**
   * Tests OTP login payload keeps the canonical jurisdiction auth contract.
   *
   * @covers ::getUserGroups
   */
  public function testGetUserGroupsCanonicalizesConfiguredJurisdictionType(): void {
    $user = $this->createMock(User::class);
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

    $container = new ContainerBuilder();
    $container->set('group.membership_loader', $membershipLoader);
    \Drupal::setContainer($container);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('group')->willReturn(TRUE);
    $service = $this->buildService(
      $this->createMock(Connection::class),
      $moduleHandler,
      NULL,
      NULL,
      NULL,
      'jurisdiction',
    );

    $method = new \ReflectionMethod($service, 'getUserGroups');
    $method->setAccessible(TRUE);

    $this->assertSame([
      [
        'id' => 5,
        'uuid' => 'group-uuid',
        'label' => 'Roads',
        'type' => 'jur',
        'roles' => [
          [
            'id' => 'jur-tenant_admin',
            'label' => 'Tenant admin',
          ],
        ],
      ],
    ], $method->invoke($service, $user));
  }

  /**
   * Tests ToS acceptance payload reads the optional FastMap user field.
   *
   * @covers ::getTosAcceptancePayload
   */
  public function testGetTosAcceptancePayloadReadsTimestamp(): void {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getString')->willReturn('1714567890');

    $user = $this->createMock(User::class);
    $user->method('hasField')
      ->with('field_tos_accepted_at')
      ->willReturn(TRUE);
    $user->method('get')
      ->with('field_tos_accepted_at')
      ->willReturn($field);

    $service = $this->buildService($this->createMock(Connection::class));
    $method = new \ReflectionMethod($service, 'getTosAcceptancePayload');
    $method->setAccessible(TRUE);

    $this->assertSame([
      'tos_accepted' => TRUE,
      'tos_accepted_at' => 1714567890,
    ], $method->invoke($service, $user));
  }

  /**
   * Tests ToS payload fails closed when the optional field is absent.
   *
   * @covers ::getTosAcceptancePayload
   */
  public function testGetTosAcceptancePayloadHandlesMissingField(): void {
    $user = $this->createMock(User::class);
    $user->method('hasField')
      ->with('field_tos_accepted_at')
      ->willReturn(FALSE);

    $service = $this->buildService($this->createMock(Connection::class));
    $method = new \ReflectionMethod($service, 'getTosAcceptancePayload');
    $method->setAccessible(TRUE);

    $this->assertSame([
      'tos_accepted' => FALSE,
      'tos_accepted_at' => NULL,
    ], $method->invoke($service, $user));
  }

  /**
   * Blocked accounts cannot authenticate through passwordless OTP.
   *
   * @covers ::authenticateUser
   */
  public function testAuthenticateUserRejectsBlockedExistingUser(): void {
    $user = $this->createMock(User::class);
    $user->expects($this->once())
      ->method('isBlocked')
      ->willReturn(TRUE);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['mail' => 'blocked@example.com'])
      ->willReturn([$user]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($userStorage);

    $service = $this->buildService(
      $this->createMock(Connection::class),
      NULL,
      NULL,
      NULL,
      $entityTypeManager,
    );

    $method = new \ReflectionMethod($service, 'authenticateUser');
    $method->setAccessible(TRUE);

    $this->assertNull($method->invoke($service, 'blocked@example.com'));
  }

  /**
   * Creates an Update mock that records and chains condition() calls.
   *
   * @param array $recordedConditions
   *   Output bag, populated with [field => value] pairs as the code
   *   calls ->condition() on the returned mock.
   */
  protected function recordingUpdate(array &$recordedConditions): Update {
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnCallback(
      function ($field, $value = NULL) use ($update, &$recordedConditions) {
        $recordedConditions[$field] = $value;
        return $update;
      }
    );
    $update->method('execute')->willReturn(0);
    return $update;
  }

  /**
   * Creates a Select mock that records condition() calls and returns rows.
   */
  protected function recordingSelect(array &$recordedConditions, array $rows = []): Select {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($rows);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('condition')->willReturnCallback(
      function ($field, $value = NULL) use ($select, &$recordedConditions) {
        $recordedConditions[$field] = $value;
        return $select;
      }
    );
    $select->method('execute')->willReturn($statement);
    return $select;
  }

  /**
   * RequestCode binds the inserted row to the issuing jurisdiction.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeInsertsJurisdictionId(): void {
    $insertedFields = NULL;

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(
      function (array $fields) use ($insert, &$insertedFields) {
        $insertedFields = $fields;
        return $insert;
      }
    );
    $insert->method('execute')->willReturn(1);

    $updateConditions = [];
    $update = $this->recordingUpdate($updateConditions);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $database->method('delete')->willReturn($delete);
    $database->method('update')->willReturn($update);
    $database->method('insert')->willReturn($insert);

    $service = $this->buildService($database);
    $service->requestCode('user@example.com', 42);

    $this->assertIsArray($insertedFields);
    $this->assertArrayHasKey('jurisdiction_id', $insertedFields);
    $this->assertSame(42, $insertedFields['jurisdiction_id']);
    $this->assertSame('user@example.com', $insertedFields['email']);

    $this->assertSame(42, $updateConditions['jurisdiction_id']);
    $this->assertSame('user@example.com', $updateConditions['email']);
  }

  /**
   * Blocked accounts do not receive fresh passwordless OTP codes.
   *
   * @covers ::requestCode
   * @covers ::loadUserByEmail
   */
  public function testRequestCodeDoesNotSendCodeForBlockedExistingUser(): void {
    $user = $this->createMock(User::class);
    $user->expects($this->once())
      ->method('isBlocked')
      ->willReturn(TRUE);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['mail' => 'blocked@example.com'])
      ->willReturn([$user]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($userStorage);

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);

    $database = $this->createMock(Connection::class);
    $database->method('delete')->willReturn($delete);
    $database->method('update')->willReturn($update);
    $database->expects($this->never())
      ->method('insert');

    $service = $this->buildService(
      $database,
      NULL,
      NULL,
      NULL,
      $entityTypeManager,
    );

    $result = $service->requestCode('blocked@example.com', 42);

    $this->assertTrue($result['success']);
    $this->assertSame('Verification code sent to your email', $result['message']);
    $this->assertSame(600, $result['expiresIn']);
  }

  /**
   * VerifyCode filters the lookup by jurisdiction_id.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeFiltersByJurisdictionId(): void {
    $selectConditions = [];
    $select = $this->recordingSelect($selectConditions, []);

    // Transaction has readonly typed $connection via constructor promotion
    // and a __destruct() that dereferences it. Instead of mocking the full
    // lifecycle we return a subclass that short-circuits construction and
    // destruction — the service only stores the reference, it never reads it.
    $transaction = $this->fakeTransaction();
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    $database->method('select')->willReturn($select);

    $service = $this->buildService($database);
    $service->verifyCode('user@example.com', '123456', 42);

    $this->assertArrayHasKey('jurisdiction_id', $selectConditions);
    $this->assertSame(42, $selectConditions['jurisdiction_id']);
    $this->assertSame('user@example.com', $selectConditions['email']);
    $this->assertSame(0, $selectConditions['verified']);
  }

  /**
   * Cross-tenant verify finds nothing and returns an invalid-code error.
   *
   * Simulates the issue's scenario: OTP issued for jurisdiction 42,
   * verification attempted against jurisdiction 5. The SQL filter yields
   * zero rows (the record exists but is bound to 42), so verification
   * fails with the same generic error as any other invalid code.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeCrossTenantRejected(): void {
    $selectConditions = [];
    // Empty row set models the DB filter rejecting the mismatched tenant.
    $select = $this->recordingSelect($selectConditions, []);

    // Transaction has readonly typed $connection via constructor promotion
    // and a __destruct() that dereferences it. Instead of mocking the full
    // lifecycle we return a subclass that short-circuits construction and
    // destruction — the service only stores the reference, it never reads it.
    $transaction = $this->fakeTransaction();
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    $database->method('select')->willReturn($select);

    $service = $this->buildService($database);
    $result = $service->verifyCode('user@example.com', '123456', 5);

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid verification code', $result['error']);
    $this->assertSame(5, $selectConditions['jurisdiction_id']);
  }

}
