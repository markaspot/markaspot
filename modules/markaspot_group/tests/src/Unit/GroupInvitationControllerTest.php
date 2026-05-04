<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Controller\GroupInvitationController;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the GroupInvitationController access check and role validation.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Controller\GroupInvitationController
 */
class GroupInvitationControllerTest extends UnitTestCase {

  /**
   * Mocked membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoaderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $membershipLoader;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up container for Cache::mergeContexts() used by AccessResult.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);
    $container->set('language_manager', $languageManager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a controller instance with mocked dependencies.
   *
   * Uses reflection to access the protected getPermittedRoles() method
   * and the public accessCheck() method without the full dependency set.
   *
   * @return \Drupal\markaspot_group\Controller\GroupInvitationController
   *   The controller with minimal dependencies set.
   */
  protected function createControllerForAccessCheck(): GroupInvitationController {
    $this->membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);

    // Create a partial mock that only mocks what we do not test.
    $controller = $this->getMockBuilder(GroupInvitationController::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    // Inject the membershipLoader via reflection.
    $ref = new \ReflectionClass($controller);
    $prop = $ref->getProperty('membershipLoader');
    $prop->setAccessible(TRUE);
    $prop->setValue($controller, $this->membershipLoader);

    return $controller;
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForSuperAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForAdministratorRole(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated', 'administrator']);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckGrantsForTenantAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(10);
    $account->method('getRoles')->willReturn(['authenticated']);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $membership = $this->getMockBuilder(GroupMembership::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getGroup'])
      ->getMock();
    $membership->method('getGroup')->willReturn($group);

    // Has jur-tenant_admin membership.
    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([$membership]);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::accessCheck
   */
  public function testAccessCheckDeniesRegularUser(): void {
    $controller = $this->createControllerForAccessCheck();

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(20);
    $account->method('getRoles')->willReturn(['authenticated']);

    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([]);

    $result = $controller->accessCheck($account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::claimInvitation
   */
  public function testClaimInvitationReturnsTooManyRequestsWhenFlooded(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('update');

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('markaspot_group.invitation_claim', 5, 3600, '203.0.113.9')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $controller = new GroupInvitationController(
      $database,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $flood,
      $this->createMock(LockBackendInterface::class),
    );

    $request = Request::create(
      '/api/group-members/claim/' . str_repeat('a', 64),
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.9'],
    );

    $response = $controller->claimInvitation(str_repeat('a', 64), $request);

    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'Too many attempts. Try again later.'],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * @covers ::claimInvitation
   */
  public function testClaimInvitationRegistersAllowedAttemptBeforeDatabaseLookup(): void {
    $registered = FALSE;

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('update')
      ->with('markaspot_group_invitations')
      ->willReturnCallback(static function () use (&$registered): void {
        self::assertTrue($registered);
        throw new \RuntimeException('Stop before database query chain.');
      });

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('markaspot_group.invitation_claim', 5, 3600, '203.0.113.10')
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('markaspot_group.invitation_claim', 3600, '203.0.113.10')
      ->willReturnCallback(static function () use (&$registered): void {
        $registered = TRUE;
      });

    $controller = new GroupInvitationController(
      $database,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $flood,
      $this->createMock(LockBackendInterface::class),
    );

    $request = Request::create(
      '/api/group-members/claim/' . str_repeat('b', 64),
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.10'],
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Stop before database query chain.');

    $controller->claimInvitation(str_repeat('b', 64), $request);
  }

  /**
   * @covers ::invite
   */
  public function testInviteRejectsMalformedRoleIds(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('load')
      ->with(5)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(1);
    $currentAccount->method('getRoles')->willReturn([]);

    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');
    $database->expects($this->never())->method('insert');

    $controller = new GroupInvitationController(
      $database,
      $entityTypeManager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
    );

    $request = Request::create(
      '/api/group-members/invite',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'email' => 'admin@example.com',
        'group_id' => 5,
        'roles' => ['jur-member', 123],
      ])
    );

    $response = $controller->invite($request);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(['error' => 'Invalid role ID.'], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * @covers ::invite
   * @covers ::isGroupInAdminScope
   * @covers ::getAdminJurisdictionIds
   */
  public function testInviteRejectsForeignJurisdictionBeforePersistence(): void {
    $foreignGroup = $this->createMock(GroupInterface::class);
    $foreignGroup->method('id')->willReturn(5);
    $foreignGroup->method('bundle')->willReturn('jur');

    $adminJurisdiction = $this->createMock(GroupInterface::class);
    $adminJurisdiction->method('id')->willReturn(1);
    $adminJurisdiction->method('bundle')->willReturn('jur');

    $membership = $this->getMockBuilder(GroupMembership::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getGroup'])
      ->getMock();
    $membership->method('getGroup')->willReturn($adminJurisdiction);

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(10);
    $currentAccount->method('getRoles')->willReturn(['authenticated']);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->with(5)
      ->willReturn($foreignGroup);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membershipLoader->expects($this->once())
      ->method('loadByUser')
      ->with($currentAccount, ['jur-tenant_admin'])
      ->willReturn([$membership]);

    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchyResolver->expects($this->once())
      ->method('getDescendantIds')
      ->with(1)
      ->willReturn([1, 2]);

    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');
    $database->expects($this->never())->method('insert');

    $controller = new GroupInvitationController(
      $database,
      $entityTypeManager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $membershipLoader,
      $hierarchyResolver,
      $currentAccount,
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
    );

    $request = Request::create(
      '/api/group-members/invite',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'email' => 'admin@example.com',
        'group_id' => 5,
        'roles' => ['jur-member'],
      ])
    );

    $response = $controller->invite($request);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'Access denied to this group.'],
      json_decode((string) $response->getContent(), TRUE)
    );
  }

  /**
   * @covers ::listInvitations
   */
  public function testListInvitationsRejectsForeignGroupBeforeQueryingRows(): void {
    $group = $this->createMock(GroupInterface::class);

    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');

    $controller = $this->createForeignScopeInvitationController($group, $database);
    $request = Request::create('/api/group-members/invitations?group_id=5', 'GET');

    $response = $controller->listInvitations($request);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'Access denied to this group.'],
      json_decode((string) $response->getContent(), TRUE)
    );
  }

  /**
   * @covers ::revokeInvitation
   */
  public function testRevokeInvitationRejectsForeignGroupBeforeDeleting(): void {
    $group = $this->createMock(GroupInterface::class);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'id' => 99,
      'group_id' => 5,
      'email' => 'invitee@example.com',
    ]);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('isNull')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('select')
      ->with('markaspot_group_invitations', 'i')
      ->willReturn($select);
    $database->expects($this->never())->method('delete');

    $controller = $this->createForeignScopeInvitationController($group, $database);

    $response = $controller->revokeInvitation(99);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'Access denied to this group.'],
      json_decode((string) $response->getContent(), TRUE)
    );
  }

  /**
   * @covers ::resolveInvitationLangcode
   */
  public function testResolveInvitationLangcodeUsesExistingUserPreference(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getPreferredLangcode')->with(FALSE)->willReturn('de');

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['mail' => 'invitee@example.com'])
      ->willReturn([$user]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($userStorage);

    $controller = new GroupInvitationController(
      $this->createMock(Connection::class),
      $entityTypeManager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
    );

    $method = new \ReflectionMethod($controller, 'resolveInvitationLangcode');
    $method->setAccessible(TRUE);

    $this->assertSame('de', $method->invoke($controller, 'invitee@example.com'));
  }

  /**
   * @covers ::sendInvitationEmail
   * @covers ::getEntityLabelForLangcode
   */
  public function testSendInvitationEmailUsesTranslatedGroupName(): void {
    $frontendConfig = $this->createMock(ImmutableConfig::class);
    $frontendConfig->method('get')->with('frontend_base_url')->willReturn('https://frontend.example');
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->with('name')->willReturn('CivicSpot');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_nuxt.settings' => $frontendConfig,
        'system.site' => $siteConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('label')->willReturn('Roads');
    $translatedGroup = $this->createMock(GroupInterface::class);
    $translatedGroup->method('label')->willReturn('Strassen');

    $entityRepository = $this->createMock(EntityRepositoryInterface::class);
    $entityRepository->expects($this->once())
      ->method('getTranslationFromContext')
      ->with($group, 'de')
      ->willReturn($translatedGroup);

    $container = \Drupal::getContainer();
    $container->set('config.factory', $configFactory);

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_group',
        'member_invitation',
        'invitee@example.com',
        'de',
        $this->callback(static fn(array $params): bool => $params['group_name'] === 'Strassen'
          && $params['site_name'] === 'CivicSpot'
          && $params['claim_url'] === 'https://frontend.example/auth/invite?token=abc123'
          && $params['langcode'] === 'de'),
        NULL,
        TRUE,
      )
      ->willReturn(['result' => TRUE]);

    $controller = new GroupInvitationController(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $mailManager,
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
      NULL,
      $entityRepository,
    );

    $method = new \ReflectionMethod($controller, 'sendInvitationEmail');
    $method->setAccessible(TRUE);
    $method->invoke(
      $controller,
      'invitee@example.com',
      'abc123',
      $group,
      'de',
      Request::create('https://drupal.example/api/group-members/invite'),
    );
  }

  /**
   * Creates an invitation controller whose endpoint scope check denies access.
   */
  protected function createForeignScopeInvitationController(
    GroupInterface $group,
    Connection $database,
  ): GroupInvitationController {
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->with(5)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('id')->willReturn(10);
    $currentAccount->method('getRoles')->willReturn(['authenticated']);

    return new class(
      $database,
      $entityTypeManager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
    ) extends GroupInvitationController {

      /**
       * {@inheritdoc}
       */
      protected function isGroupInAdminScope(GroupInterface $group, AccountInterface $account): bool {
        return FALSE;
      }

    };
  }

  /**
   * @covers ::claimInvitation
   */
  public function testClaimInvitationNormalizesLegacyTenantAdminRole(): void {
    $token = str_repeat('c', 64);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('isNull')->willReturnSelf();
    $update->method('execute')->willReturn(1);

    $invitation = [
      'id' => 99,
      'email' => 'admin@example.com',
      'group_id' => 5,
      'roles' => json_encode(['jur-tenant_admin']),
      'invited_by' => 1,
      'created' => time() - 60,
      'expires' => time() + 3600,
      'claimed' => time(),
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($invitation);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('update')
      ->with('markaspot_group_invitations')
      ->willReturn($update);
    $database->expects($this->once())
      ->method('select')
      ->with('markaspot_group_invitations', 'i')
      ->willReturn($select);

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('loadByProperties')
      ->with(['mail' => 'admin@example.com'])
      ->willReturn([$user]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('bundle')->willReturn('jur');
    $group->method('label')->willReturn('Amsterdam');
    $group->method('getMember')->willReturn(NULL);
    $group->expects($this->once())
      ->method('addMember')
      ->with($user, ['group_roles' => ['jur-member', 'jur-tenant_admin']]);
    $group->method('hasField')
      ->with('field_slug')
      ->willReturn(FALSE);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->with(5)->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $groupStorage,
        'user' => $userStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('isAnonymous')->willReturn(TRUE);

    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $flood->method('register');

    $lock = $this->createMock(LockBackendInterface::class);
    $expectedAcquires = [
      ['markaspot_group:membership_group:5', 120.0],
      ['markaspot_group:user_email:' . hash('sha256', 'admin@example.com'), 120.0],
      ['markaspot_group:membership_update:10', 120.0],
    ];
    $lock->expects($this->exactly(3))
      ->method('acquire')
      ->willReturnCallback(function (string $name, float $ttl) use (&$expectedAcquires): bool {
        $expected = array_shift($expectedAcquires);
        $this->assertSame($expected, [$name, $ttl]);
        return TRUE;
      });
    $expectedReleases = [
      'markaspot_group:membership_update:10',
      'markaspot_group:user_email:' . hash('sha256', 'admin@example.com'),
      'markaspot_group:membership_group:5',
    ];
    $lock->expects($this->exactly(3))
      ->method('release')
      ->willReturnCallback(function (string $name) use (&$expectedReleases): void {
        $this->assertSame(array_shift($expectedReleases), $name);
      });

    $controller = new GroupInvitationController(
      $database,
      $entityTypeManager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $flood,
      $lock,
    );

    $request = Request::create(
      '/api/group-members/claim/' . $token,
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.11'],
    );

    $response = $controller->claimInvitation($token, $request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('claimed', json_decode((string) $response->getContent(), TRUE)['status']);
  }

  /**
   * @covers ::claimInvitation
   * @covers ::buildMembershipGroupLockName
   */
  public function testClaimInvitationLocksResolvedJurisdictionForOrgGroupLimits(): void {
    $token = str_repeat('d', 64);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('isNull')->willReturnSelf();
    $update->method('execute')->willReturn(1);

    $invitation = [
      'id' => 100,
      'email' => 'org-member@example.com',
      'group_id' => 7,
      'roles' => json_encode(['org-member']),
      'invited_by' => 1,
      'created' => time() - 60,
      'expires' => time() + 3600,
      'claimed' => time(),
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($invitation);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $pendingStatement = $this->createMock(StatementInterface::class);
    $pendingStatement->method('fetchField')->willReturn(0);
    $pendingSelect = $this->createMock(SelectInterface::class);
    $pendingSelect->method('condition')->willReturnSelf();
    $pendingSelect->method('isNull')->willReturnSelf();
    $pendingSelect->method('countQuery')->willReturnSelf();
    $pendingSelect->method('execute')->willReturn($pendingStatement);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('update')
      ->with('markaspot_group_invitations')
      ->willReturn($update);
    $database->expects($this->exactly(2))
      ->method('select')
      ->with('markaspot_group_invitations', 'i')
      ->willReturnOnConsecutiveCalls($select, $pendingSelect);

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('loadByProperties')
      ->with(['mail' => 'org-member@example.com'])
      ->willReturn([$user]);

    $jurGroup = $this->createGroup('jur', 'amsterdam', NULL, 'free');
    $jurGroup->method('id')->willReturn(5);
    $orgGroup = $this->createGroup('org', NULL, 5);
    $orgGroup->method('id')->willReturn(7);
    $orgGroup->method('bundle')->willReturn('org');
    $orgGroup->method('label')->willReturn('Roads');
    $orgGroup->method('getMember')->willReturn(NULL);
    $orgGroup->expects($this->once())
      ->method('addMember')
      ->with($user, ['group_roles' => ['org-member']]);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->willReturnMap([
        [7, $orgGroup],
        [5, $jurGroup],
      ]);
    $groupStorage->method('loadByProperties')
      ->with([
        'type' => 'org',
        'field_jurisdiction' => 5,
      ])
      ->willReturn([$orgGroup]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $groupStorage,
        'user' => $userStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $currentAccount = $this->createMock(AccountInterface::class);
    $currentAccount->method('isAnonymous')->willReturn(TRUE);

    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $flood->method('register');

    $tierConfig = new class() {

      /**
       * Counted group IDs.
       *
       * @var int[]
       */
      public array $countedGroupIds = [];

      /**
       * Gets a member limit for the test tier.
       */
      public function getMemberLimit(string $tier): int {
        return 10;
      }

      /**
       * Counts members for the given group ID.
       */
      public function countMembers(int $groupId): int {
        $this->countedGroupIds[] = $groupId;
        return 0;
      }

    };

    $lock = $this->createMock(LockBackendInterface::class);
    $expectedAcquires = [
      ['markaspot_group:membership_group:5', 120.0],
      ['markaspot_group:user_email:' . hash('sha256', 'org-member@example.com'), 120.0],
      ['markaspot_group:membership_update:10', 120.0],
    ];
    $lock->expects($this->exactly(3))
      ->method('acquire')
      ->willReturnCallback(function (string $name, float $ttl) use (&$expectedAcquires): bool {
        $this->assertSame(array_shift($expectedAcquires), [$name, $ttl]);
        return TRUE;
      });
    $expectedReleases = [
      'markaspot_group:membership_update:10',
      'markaspot_group:user_email:' . hash('sha256', 'org-member@example.com'),
      'markaspot_group:membership_group:5',
    ];
    $lock->expects($this->exactly(3))
      ->method('release')
      ->willReturnCallback(function (string $name) use (&$expectedReleases): void {
        $this->assertSame(array_shift($expectedReleases), $name);
      });

    $controller = new GroupInvitationController(
      $database,
      $entityTypeManager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $currentAccount,
      $flood,
      $lock,
      $tierConfig,
    );

    $request = Request::create(
      '/api/group-members/claim/' . $token,
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '203.0.113.12'],
    );

    $response = $controller->claimInvitation($token, $request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('claimed', json_decode((string) $response->getContent(), TRUE)['status']);
    $this->assertSame([5, 7], $tierConfig->countedGroupIds);
  }

  /**
   * @covers ::getJurisdictionSlug
   */
  public function testGetJurisdictionSlugResolvesOrgParentJurisdiction(): void {
    $jurGroup = $this->createGroup('jur', 'rotterdam');
    $orgGroup = $this->createGroup('org', NULL, 5);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('load')
      ->with(5)
      ->willReturn($jurGroup);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    $controller = new GroupInvitationController(
      $this->createMock(Connection::class),
      $entityTypeManager,
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
    );

    $method = new \ReflectionMethod($controller, 'getJurisdictionSlug');
    $method->setAccessible(TRUE);

    $this->assertSame('rotterdam', $method->invoke($controller, $orgGroup));
  }

  /**
   * @covers ::getJurisdictionSlug
   */
  public function testGetJurisdictionSlugRejectsUnsafeSlug(): void {
    $jurGroup = $this->createGroup('jur', '//evil.example');

    $controller = new GroupInvitationController(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
    );

    $method = new \ReflectionMethod($controller, 'getJurisdictionSlug');
    $method->setAccessible(TRUE);

    $this->assertNull($method->invoke($controller, $jurGroup));
  }

  /**
   * Tests getPermittedRoles for non-admin users.
   *
   * @covers ::getPermittedRoles
   */
  public function testGetPermittedRolesForNonAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, FALSE);

    $this->assertContains('jur-member', $roles);
    $this->assertContains('jur-moderator', $roles);
    $this->assertContains('org-member', $roles);
    $this->assertContains('org-moderator', $roles);
    $this->assertNotContains('jur-tenant_admin', $roles);
    $this->assertNotContains('org-tenant_admin', $roles);
  }

  /**
   * Tests free-tier invitations cannot assign moderator roles.
   *
   * @covers ::getPermittedRoles
   */
  public function testFreeTierBlocksModeratorRole(): void {
    $controller = $this->createControllerWithTierRoles([
      'free' => ['jur-member', 'org-member'],
    ]);
    $group = $this->createGroup('jur', 'amsterdam', NULL, 'free');

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, FALSE, $group);

    $this->assertContains('jur-member', $roles);
    $this->assertContains('org-member', $roles);
    $this->assertNotContains('jur-moderator', $roles);
    $this->assertNotContains('org-moderator', $roles);
  }

  /**
   * Tests paid-tier invitations keep moderator roles.
   *
   * @covers ::getPermittedRoles
   */
  public function testPaidTierKeepsModeratorRole(): void {
    $controller = $this->createControllerWithTierRoles([
      'starter' => ['jur-member', 'jur-moderator', 'org-member', 'org-moderator'],
    ]);
    $group = $this->createGroup('jur', 'amsterdam', NULL, 'starter');

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, FALSE, $group);

    $this->assertContains('jur-moderator', $roles);
    $this->assertContains('org-moderator', $roles);
  }

  /**
   * Tests unknown tiers fall back to member-only roles.
   *
   * @covers ::getPermittedRoles
   */
  public function testUnknownTierBlocksModeratorRole(): void {
    $controller = $this->createControllerWithTierRoles([
      'nonexistent' => ['jur-member', 'org-member'],
    ]);
    $group = $this->createGroup('jur', 'amsterdam', NULL, 'nonexistent');

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, FALSE, $group);

    $this->assertSame(['jur-member', 'org-member'], $roles);
  }

  /**
   * Tests empty tiers fall back to member-only roles.
   *
   * @covers ::getPermittedRoles
   */
  public function testEmptyTierBlocksModeratorRole(): void {
    $controller = $this->createControllerWithTierRoles([
      'free' => ['jur-member', 'org-member'],
    ]);
    $group = $this->createGroup('jur', 'amsterdam', NULL, '');

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, FALSE, $group);

    $this->assertSame(['jur-member', 'org-member'], $roles);
  }

  /**
   * Creates a mocked group entity for invitation tests.
   */
  protected function createGroup(string $bundle, ?string $slug = NULL, ?int $jurisdictionId = NULL, ?string $tier = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('hasField')->willReturnCallback(static fn(string $field): bool => in_array($field, [
      'field_slug',
      'field_jurisdiction',
      'field_tier',
    ], TRUE));
    $group->method('get')->willReturnCallback(function (string $field) use ($slug, $jurisdictionId, $tier): FieldItemListInterface {
      $fieldItem = $this->createMock(FieldItemListInterface::class);
      if ($field === 'field_slug') {
        $fieldItem->method('isEmpty')->willReturn($slug === NULL || $slug === '');
        $fieldItem->method('__get')->with('value')->willReturn($slug);
        return $fieldItem;
      }
      if ($field === 'field_jurisdiction') {
        $fieldItem->method('isEmpty')->willReturn($jurisdictionId === NULL);
        $fieldItem->method('__get')->with('target_id')->willReturn($jurisdictionId);
        return $fieldItem;
      }
      if ($field === 'field_tier') {
        $fieldItem->method('isEmpty')->willReturn($tier === NULL || $tier === '');
        $fieldItem->method('__get')->with('value')->willReturn($tier);
        return $fieldItem;
      }

      $fieldItem->method('isEmpty')->willReturn(TRUE);
      return $fieldItem;
    });

    return $group;
  }

  /**
   * Creates a controller with a tier role config service.
   *
   * @param array<string, string[]> $roleLimits
   *   Role IDs keyed by tier.
   */
  protected function createControllerWithTierRoles(array $roleLimits): GroupInvitationController {
    $tierConfig = new class($roleLimits) {

      /**
       * Constructs the test tier config.
       *
       * @param array<string, string[]> $roleLimits
       *   Role IDs keyed by tier.
       */
      public function __construct(private readonly array $roleLimits) {}

      /**
       * Gets assignable role IDs for a tier.
       *
       * @return string[]
       *   Role IDs.
       */
      public function getAssignableRoleIds(string $tier): array {
        return $this->roleLimits[$tier] ?? ['jur-member', 'org-member'];
      }

    };

    return new GroupInvitationController(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(GroupMembershipLoaderInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(FloodInterface::class),
      $this->createMock(LockBackendInterface::class),
      $tierConfig,
    );
  }

  /**
   * Tests getPermittedRoles for admin users.
   *
   * @covers ::getPermittedRoles
   */
  public function testGetPermittedRolesForAdmin(): void {
    $controller = $this->createControllerForAccessCheck();

    $ref = new \ReflectionMethod($controller, 'getPermittedRoles');
    $ref->setAccessible(TRUE);

    $roles = $ref->invoke($controller, TRUE);

    $this->assertContains('jur-tenant_admin', $roles);
    $this->assertContains('org-tenant_admin', $roles);
    $this->assertContains('jur-member', $roles);
  }

}
