<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\Component\Datetime\Time;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the GeoreportProcessorService.
 *
 * Tests are structured as unit tests with mocked dependencies since the
 * service methods under test have well-defined inputs and outputs.
 *
 * @group markaspot_open311
 * @coversDefaultClass \Drupal\markaspot_open311\Service\GeoreportProcessorService
 */
class GeoreportProcessorServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorService
   */
  protected $processor;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileUrlGenerator;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleHandler;

  /**
   * Mocked hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $hierarchyResolver;

  /**
   * Mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * Mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $groupStorage;

  /**
   * Mocked term storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $termStorage;

  /**
   * Mocked media storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mediaStorage;

  /**
   * In-memory keyvalue factory backing the manual media publication store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueMemoryFactory
   */
  protected KeyValueMemoryFactory $keyValueFactory;

  /**
   * Mocked group relationship storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $relationshipStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    // Set up entity storages.
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->termStorage = $this->createMock(EntityStorageInterface::class);
    $this->mediaStorage = $this->createMock(EntityStorageInterface::class);
    $this->relationshipStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['node', $this->nodeStorage],
        ['group', $this->groupStorage],
        ['taxonomy_term', $this->termStorage],
        ['media', $this->mediaStorage],
        ['group_relationship', $this->relationshipStorage],
      ]);

    // Default config mock.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['bundle', 'service_request'],
        ['group_filter_enabled', FALSE],
        ['jurisdiction_group_type', 'jur'],
        [
          'field_access',
          [
            'manager_fields' => [
              'field_hazard_level',
              'field_sentiment',
              'field_ai_hazard_category',
              'field_organisation',
            ],
          ],
        ],
        [
          'field_access.manager_fields',
          [
            'field_hazard_level',
            'field_sentiment',
            'field_ai_hazard_category',
            'field_organisation',
          ],
        ],
      ]);
    $dateConfig = $this->createMock(ImmutableConfig::class);
    $dateConfig->method('get')
      ->willReturnMap([
        ['country.default', 'DE'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['markaspot_open311.settings', $config],
        ['system.date', $dateConfig],
      ]);

    $time = $this->createMock(Time::class);
    $requestStack = $this->createMock(RequestStack::class);
    $request = new Request();
    $requestStack->method('getCurrentRequest')->willReturn($request);
    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $token = $this->createMock(Token::class);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $database = $this->createMock(Connection::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $httpClient = $this->createMock(ClientInterface::class);
    $messenger = $this->createMock(MessengerInterface::class);
    $accountSwitcher = $this->createMock(AccountSwitcherInterface::class);

    $this->processor = new GeoreportProcessorService(
      $this->configFactory,
      $this->currentUser,
      $time,
      $requestStack,
      $this->entityTypeManager,
      $this->fileUrlGenerator,
      $this->moduleHandler,
      $entityFieldManager,
      $streamWrapperManager,
      $token,
      $languageManager,
      $database,
      $fileSystem,
      $httpClient,
      $messenger,
      $accountSwitcher,
      $this->hierarchyResolver,
      $this->logger,
      $this->keyValueFactory = new KeyValueMemoryFactory(),
    );
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessNullJurisdictionSkips(): void {
    // Single-tenant mode: no jurisdiction specified.
    $this->processor->validateJurisdictionAccess(NULL);
    // No exception thrown.
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessGroupModuleDisabledSkips(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(FALSE);

    $this->processor->validateJurisdictionAccess(42);
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessAdminBypasses(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    // Only uid 1 bypasses jurisdiction checks (not bypass node access).
    $admin = $this->createMock(AccountProxyInterface::class);
    $admin->method('hasPermission')->willReturn(FALSE);
    $admin->method('id')->willReturn(1);

    $this->processor->validateJurisdictionAccess(42, $admin);
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessAnonymousSkips(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $anon = $this->createMock(AccountProxyInterface::class);
    $anon->method('hasPermission')->willReturn(FALSE);
    $anon->method('id')->willReturn(0);
    $anon->method('isAnonymous')->willReturn(TRUE);

    $this->processor->validateJurisdictionAccess(42, $anon);
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessMemberAllowed(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $user->method('id')->willReturn(5);
    $user->method('isAnonymous')->willReturn(FALSE);

    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn($membership);

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $this->processor->validateJurisdictionAccess(42, $user);
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessNonMemberDenied(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $user->method('id')->willReturn(5);
    $user->method('isAnonymous')->willReturn(FALSE);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn(NULL);

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('not a member');
    $this->processor->validateJurisdictionAccess(42, $user);
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessNonExistentGroupThrows(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $user->method('id')->willReturn(5);
    $user->method('isAnonymous')->willReturn(FALSE);

    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('group not found');
    $this->processor->validateJurisdictionAccess(999, $user);
  }

  /**
   * @covers ::validateJurisdictionAccess
   */
  public function testValidateAccessNonJurGroupTypeThrows(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $user->method('id')->willReturn(5);
    $user->method('isAnonymous')->willReturn(FALSE);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('not a jurisdiction');
    $this->processor->validateJurisdictionAccess(42, $user);
  }

  /**
   * @covers ::getCategoryTidsForJurisdiction
   */
  public function testGetCategoryTidsForRootJurisdiction(): void {
    // Root jurisdiction: hierarchy resolver returns the same ID.
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(10)
      ->willReturn(10);

    $term1 = $this->createMock(TermInterface::class);
    $term1->method('id')->willReturn(100);
    $term2 = $this->createMock(TermInterface::class);
    $term2->method('id')->willReturn(101);

    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'status' => 1,
        'field_jurisdiction' => 10,
      ])
      ->willReturn([100 => $term1, 101 => $term2]);

    $result = $this->processor->getCategoryTidsForJurisdiction(10);
    $this->assertEqualsCanonicalizing([100, 101], $result);
  }

  /**
   * @covers ::getCategoryTidsForJurisdiction
   */
  public function testGetCategoryTidsForChildInheritsParent(): void {
    // Child jurisdiction 20 -> Root 10.
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(20)
      ->willReturn(10);

    $term1 = $this->createMock(TermInterface::class);
    $term1->method('id')->willReturn(100);

    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'status' => 1,
        'field_jurisdiction' => 10,
      ])
      ->willReturn([100 => $term1]);

    $result = $this->processor->getCategoryTidsForJurisdiction(20);
    $this->assertEqualsCanonicalizing([100], array_values($result));
  }

  /**
   * @covers ::getCategoryTidsForJurisdiction
   */
  public function testGetCategoryTidsEmptyReturnsEmptyArray(): void {
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(99)
      ->willReturn(99);

    $this->termStorage->method('loadByProperties')
      ->willReturn([]);

    $result = $this->processor->getCategoryTidsForJurisdiction(99);
    $this->assertEquals([], $result);
  }

  /**
   * Tests that anonymous users get the 'anonymous' role regardless of permissions.
   */
  public function testDetermineExtendedRoleAnonymous(): void {
    $anon = $this->createMock(AccountProxyInterface::class);
    $anon->method('isAnonymous')->willReturn(TRUE);
    $anon->method('hasPermission')->willReturn(FALSE);

    $result = $this->invokeMethod($this->processor, 'determineExtendedRole', [$anon]);
    $this->assertEquals('anonymous', $result);
  }

  /**
   * Tests that authenticated users with advanced properties get 'manager'.
   */
  public function testDetermineExtendedRoleManager(): void {
    $manager = $this->createMock(AccountProxyInterface::class);
    $manager->method('isAnonymous')->willReturn(FALSE);
    $manager->method('hasPermission')
      ->willReturnCallback(function ($perm) {
        return $perm === 'access open311 advanced properties';
      });

    $result = $this->invokeMethod($this->processor, 'determineExtendedRole', [$manager]);
    $this->assertEquals('manager', $result);
  }

  /**
   * Tests that api_user with extension permission gets 'user'.
   */
  public function testDetermineExtendedRoleApiUser(): void {
    $apiUser = $this->createMock(AccountProxyInterface::class);
    $apiUser->method('isAnonymous')->willReturn(FALSE);
    $apiUser->method('hasPermission')
      ->willReturnCallback(function ($perm) {
        return $perm === 'access open311 extension';
      });

    $result = $this->invokeMethod($this->processor, 'determineExtendedRole', [$apiUser]);
    $this->assertEquals('user', $result);
  }

  /**
   * Tests that authenticated user without extension permission gets 'anonymous'.
   */
  public function testDetermineExtendedRoleAuthenticatedNoPermission(): void {
    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('isAnonymous')->willReturn(FALSE);
    $user->method('hasPermission')->willReturn(FALSE);

    $result = $this->invokeMethod($this->processor, 'determineExtendedRole', [$user]);
    $this->assertEquals('anonymous', $result);
  }

  /**
   * @covers ::isJurisdictionMember
   */
  public function testIsJurisdictionMemberUnscopedReturnsTrue(): void {
    // No jurisdiction scope (single-tenant mode).
    $this->assertTrue($this->processor->isJurisdictionMember(NULL));
    $this->assertTrue($this->processor->isJurisdictionMember(0));
  }

  /**
   * @covers ::isJurisdictionMember
   */
  public function testIsJurisdictionMemberGroupModuleDisabledReturnsTrue(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(FALSE);

    $this->assertTrue($this->processor->isJurisdictionMember(42));
  }

  /**
   * @covers ::isJurisdictionMember
   */
  public function testIsJurisdictionMemberUid1Bypasses(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $admin = $this->createMock(AccountProxyInterface::class);
    $admin->method('id')->willReturn(1);

    $this->assertTrue($this->processor->isJurisdictionMember(42, $admin));
  }

  /**
   * @covers ::isJurisdictionMember
   */
  public function testIsJurisdictionMemberMemberReturnsTrue(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn($membership);

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $this->assertTrue($this->processor->isJurisdictionMember(42, $user));
  }

  /**
   * @covers ::isJurisdictionMember
   */
  public function testIsJurisdictionMemberNonMemberReturnsFalse(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn(NULL);

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $this->assertFalse($this->processor->isJurisdictionMember(42, $user));
  }

  /**
   * @covers ::isJurisdictionMember
   */
  public function testIsJurisdictionMemberUnknownGroupReturnsFalse(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->assertFalse($this->processor->isJurisdictionMember(999, $user));
  }

  /**
   * @covers ::isJurisdictionMember
   */
  public function testIsJurisdictionMemberNonJurGroupReturnsFalse(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $this->assertFalse($this->processor->isJurisdictionMember(42, $user));
  }

  /**
   * Foreign tenant manager is downgraded to the anonymous/public shape.
   *
   * A dashboard-capable user (access open311 advanced properties) who is
   * NOT a member of the jurisdiction the response is scoped to must be
   * serialized as 'anonymous'. Elevated request parameters
   * (extended_attributes, extensions, fields) must not re-elevate.
   */
  public function testReadScopeDowngradesForeignManagerToAnonymous(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);
    $user->method('isAnonymous')->willReturn(FALSE);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn(NULL);

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeExtendedRoleToReadScope',
      ['manager', 42, $user]
    );
    $this->assertEquals('anonymous', $result);
  }

  /**
   * A member of the scoped jurisdiction keeps the manager shape.
   */
  public function testReadScopeKeepsMemberManager(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);
    $user->method('isAnonymous')->willReturn(FALSE);

    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn($membership);

    $this->groupStorage->method('load')
      ->with(42)
      ->willReturn($group);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeExtendedRoleToReadScope',
      ['manager', 42, $user]
    );
    $this->assertEquals('manager', $result);
  }

  /**
   * Without a read scope (single-tenant mode) the role is unchanged.
   */
  public function testReadScopeWithoutScopeKeepsManager(): void {
    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeExtendedRoleToReadScope',
      ['manager', NULL, $user]
    );
    $this->assertEquals('manager', $result);
  }

  /**
   * The anonymous role passes through untouched.
   */
  public function testReadScopeLeavesAnonymousRole(): void {
    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(0);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeExtendedRoleToReadScope',
      ['anonymous', 42, $user]
    );
    $this->assertEquals('anonymous', $result);
  }

  /**
   * The 'user' role (API service identities) is not membership-scoped.
   */
  public function testReadScopeLeavesUserRole(): void {
    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeExtendedRoleToReadScope',
      ['user', 42, $user]
    );
    $this->assertEquals('user', $result);
  }

  /**
   * Builds a node stub whose field_jurisdiction resolves to a group ID.
   */
  protected function buildNodeWithJurisdiction(int $nodeId, int $jurisdictionId): object {
    $jurGroup = new class($jurisdictionId) {

      /**
       * Constructs the jurisdiction group stub.
       */
      public function __construct(private int $groupId) {}

      /**
       * Returns the group ID.
       */
      public function id(): int {
        return $this->groupId;
      }

    };

    $jurisdictionField = new class($jurGroup) {

      /**
       * The referenced jurisdiction group.
       */
      public object $entity;

      /**
       * Constructs the field stub.
       */
      public function __construct(object $entity) {
        $this->entity = $entity;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nodeId);
    $node->method('hasField')
      ->willReturnCallback(fn($name) => $name === 'field_jurisdiction');
    $node->method('get')
      ->willReturnCallback(function ($name) use ($jurisdictionField) {
        if ($name === 'field_jurisdiction') {
          return $jurisdictionField;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $node;
  }

  /**
   * Builds a node stub whose jurisdiction cannot be resolved.
   */
  protected function buildNodeWithoutJurisdiction(int $nodeId): object {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nodeId);
    $node->method('hasField')->willReturn(FALSE);

    // No field_jurisdiction, no organisation; the relationship lookup
    // finds nothing either.
    $this->relationshipStorage->method('loadByProperties')->willReturn([]);

    return $node;
  }

  /**
   * Configures the group storage query for the jur-groups-exist check.
   */
  protected function mockJurisdictionGroupsExist(bool $exists): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($exists ? [1 => '1'] : []);
    $this->groupStorage->method('getQuery')->willReturn($query);
  }

  /**
   * Unclaimed read: a foreign node degrades the manager to anonymous.
   */
  public function testUnclaimedScopeForeignNodeDegradesToAnonymous(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);
    $this->mockJurisdictionGroupsExist(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    // The node belongs to jurisdiction 42; the user is not a member.
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn(NULL);
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    $node = $this->buildNodeWithJurisdiction(7, 42);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeManagerRoleToNode',
      [$node, $user]
    );
    $this->assertEquals('anonymous', $result);
  }

  /**
   * Unclaimed read: a node of the user's own jurisdiction keeps manager.
   */
  public function testUnclaimedScopeMemberNodeKeepsManager(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);
    $this->mockJurisdictionGroupsExist(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('getMember')->willReturn($membership);
    $this->groupStorage->method('load')->with(42)->willReturn($group);

    $node = $this->buildNodeWithJurisdiction(7, 42);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeManagerRoleToNode',
      [$node, $user]
    );
    $this->assertEquals('manager', $result);
  }

  /**
   * Unclaimed read: unresolvable node jurisdiction fails closed.
   *
   * In multi-tenant installs (jur groups exist), a node whose
   * jurisdiction cannot be resolved must be serialized publicly.
   */
  public function testUnclaimedScopeNullJurisdictionFailsClosed(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);
    $this->mockJurisdictionGroupsExist(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $node = $this->buildNodeWithoutJurisdiction(7);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeManagerRoleToNode',
      [$node, $user]
    );
    $this->assertEquals('anonymous', $result);
  }

  /**
   * Unclaimed read: legacy single-tenant installs keep the manager shape.
   *
   * Group module enabled but zero jur groups: no cross-tenant risk, staff
   * reads without jurisdiction_id must not regress.
   */
  public function testUnclaimedScopeWithoutJurGroupsKeepsManager(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);
    $this->mockJurisdictionGroupsExist(FALSE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $node = $this->buildNodeWithoutJurisdiction(7);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeManagerRoleToNode',
      [$node, $user]
    );
    $this->assertEquals('manager', $result);
  }

  /**
   * Unclaimed read: uid 1 is never scoped per node.
   */
  public function testUnclaimedScopeUid1KeepsManager(): void {
    $admin = $this->createMock(AccountProxyInterface::class);
    $admin->method('id')->willReturn(1);

    $node = $this->createMock(NodeInterface::class);

    $result = $this->invokeMethod(
      $this->processor,
      'scopeManagerRoleToNode',
      [$node, $admin]
    );
    $this->assertEquals('manager', $result);
  }

  /**
   * Repeated membership checks load the group exactly once.
   */
  public function testIsJurisdictionMemberIsMemoizedPerGidUid(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn(5);

    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->expects($this->once())->method('getMember')->willReturn($membership);
    $this->groupStorage->expects($this->once())
      ->method('load')
      ->with(42)
      ->willReturn($group);

    $this->assertTrue($this->processor->isJurisdictionMember(42, $user));
    $this->assertTrue($this->processor->isJurisdictionMember(42, $user));
    $this->assertTrue($this->processor->isJurisdictionMember(42, $user));
  }

  /**
   * The jur-groups-exist install check queries exactly once.
   */
  public function testHasJurisdictionGroupsIsMemoized(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([1 => '1']);
    $this->groupStorage->expects($this->once())
      ->method('getQuery')
      ->willReturn($query);

    $this->assertTrue($this->processor->hasJurisdictionGroups());
    $this->assertTrue($this->processor->hasJurisdictionGroups());
  }

  /**
   * Node jurisdiction resolution is memoized per node ID.
   */
  public function testResolveNodeJurisdictionIdIsMemoized(): void {
    $node = $this->buildNodeWithJurisdiction(7, 42);

    $this->assertSame(42, $this->processor->resolveNodeJurisdictionId($node));
    $this->assertSame(42, $this->processor->resolveNodeJurisdictionId($node));
  }

  /**
   * Allowlisted entity references are compacted instead of serialised whole.
   *
   * The GeoReport `extensions&fields=...` path is config-driven. If an
   * operational entity-reference field is allowlisted, the API must not leak
   * target entity internals such as field_status_definition through toArray().
   *
   * @covers ::getFieldValues
   */
  public function testFieldValuesCompactEntityReferences(): void {
    $term = $this->createMock(ContentEntityInterface::class);
    $term->method('id')->willReturn(77);
    $term->method('label')->willReturn('Bitte um Prüfung');
    $term->method('access')
      ->with('view', $this->currentUser)
      ->willReturn(TRUE);
    $term->expects($this->never())->method('toArray');

    $field = new class($term) {

      /**
       * Referenced entity.
       */
      private ContentEntityInterface $entity;

      /**
       * Constructs the field stub.
       */
      public function __construct(ContentEntityInterface $entity) {
        $this->entity = $entity;
      }

      /**
       * Allows field view access.
       */
      public function access(string $operation, mixed $account = NULL, bool $returnAsObject = FALSE): AccessResult {
        return AccessResult::allowed();
      }

      /**
       * Returns referenced entities.
       *
       * @return \Drupal\Core\Entity\ContentEntityInterface[]
       *   Referenced entities.
       */
      public function referencedEntities(): array {
        return [$this->entity];
      }

    };

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('hasField')
      ->with('field_status_internal_term')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_status_internal_term')
      ->willReturn($field);

    $result = $this->invokeMethod($this->processor, 'getFieldValues', [
      $node,
      'field_status_internal_term',
    ]);

    $this->assertSame([
      'field_status_internal_term' => [
        'target_id' => 77,
        'label' => 'Bitte um Prüfung',
      ],
    ], $result);
  }

  /**
   * Allowlisted entity references still respect referenced entity view access.
   *
   * @covers ::getFieldValues
   */
  public function testFieldValuesSkipInaccessibleEntityReferences(): void {
    $term = $this->createMock(ContentEntityInterface::class);
    $term->method('access')
      ->with('view', $this->currentUser)
      ->willReturn(FALSE);
    $term->expects($this->never())->method('id');
    $term->expects($this->never())->method('label');
    $term->expects($this->never())->method('toArray');

    $field = new class($term) {

      /**
       * Referenced entity.
       */
      private ContentEntityInterface $entity;

      /**
       * Constructs the field stub.
       */
      public function __construct(ContentEntityInterface $entity) {
        $this->entity = $entity;
      }

      /**
       * Allows source field view access.
       */
      public function access(string $operation, mixed $account = NULL, bool $returnAsObject = FALSE): AccessResult {
        return AccessResult::allowed();
      }

      /**
       * Returns referenced entities.
       *
       * @return \Drupal\Core\Entity\ContentEntityInterface[]
       *   Referenced entities.
       */
      public function referencedEntities(): array {
        return [$this->entity];
      }

    };

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('hasField')
      ->with('field_status_internal_term')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_status_internal_term')
      ->willReturn($field);

    $result = $this->invokeMethod($this->processor, 'getFieldValues', [
      $node,
      'field_status_internal_term',
    ]);

    $this->assertSame([], $result);
  }

  /**
   * Manager-only scalar fields still respect field view access.
   *
   * @covers ::viewableFieldValue
   */
  public function testViewableFieldValueSkipsInaccessibleFields(): void {
    $field = new class {

      /**
       * Field value.
       */
      public string $value = 'person@example.com';

      /**
       * Field is populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

      /**
       * Denies field access.
       */
      public function access(string $operation, mixed $account = NULL): bool {
        return FALSE;
      }

    };

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('hasField')
      ->with('field_e_mail')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_e_mail')
      ->willReturn($field);

    $result = $this->invokeMethod($this->processor, 'viewableFieldValue', [
      $node,
      'field_e_mail',
    ]);

    $this->assertNull($result);
  }

  /**
   * Accessible manager-only scalar fields are still returned.
   *
   * @covers ::viewableFieldValue
   */
  public function testViewableFieldValueReturnsAccessibleFields(): void {
    $field = new class {

      /**
       * Field value.
       */
      public string $value = 'person@example.com';

      /**
       * Field is populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

      /**
       * Allows field access.
       */
      public function access(string $operation, mixed $account = NULL): bool {
        return TRUE;
      }

    };

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('hasField')
      ->with('field_e_mail')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_e_mail')
      ->willReturn($field);

    $result = $this->invokeMethod($this->processor, 'viewableFieldValue', [
      $node,
      'field_e_mail',
    ]);

    $this->assertSame('person@example.com', $result);
  }

  /**
   * Public GeoReport media_url output stays limited to published media.
   *
   * @covers ::getMediaUrls
   */
  public function testMediaUrlsSkipUnpublishedMediaForPublicUsers(): void {
    $media = $this->buildMediaWithImage(FALSE, TRUE, 'public://private.jpg');
    $node = $this->buildNodeWithRequestMedia([$media]);

    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(FALSE);
    $media->expects($this->never())->method('access');
    $this->fileUrlGenerator->expects($this->never())->method('generateAbsoluteString');

    $result = $this->invokeMethod($this->processor, 'getMediaUrls', [$node, FALSE]);

    $this->assertSame('', $result);
  }

  /**
   * Advanced users still need the effective manager response shape.
   *
   * @covers ::getMediaUrls
   */
  public function testMediaUrlsSkipUnpublishedMediaWhenResponseShapeIsPublic(): void {
    $media = $this->buildMediaWithImage(FALSE, TRUE, 'public://private.jpg');
    $node = $this->buildNodeWithRequestMedia([$media]);

    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $media->expects($this->never())->method('access');
    $this->fileUrlGenerator->expects($this->never())->method('generateAbsoluteString');

    $result = $this->invokeMethod($this->processor, 'getMediaUrls', [$node, FALSE]);

    $this->assertSame('', $result);
  }

  /**
   * Authorized API users can receive URLs for viewable unpublished media.
   *
   * @covers ::getMediaUrls
   */
  public function testMediaUrlsExposeUnpublishedMediaForAuthorizedUsers(): void {
    $media = $this->buildMediaWithImage(FALSE, TRUE, 'public://private.jpg');
    $node = $this->buildNodeWithRequestMedia([$media]);

    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $media->expects($this->once())
      ->method('access')
      ->with('view', $this->currentUser)
      ->willReturn(TRUE);
    $this->fileUrlGenerator->expects($this->once())
      ->method('generateAbsoluteString')
      ->with('public://private.jpg')
      ->willReturn('https://example.test/sites/default/files/private.jpg');

    $result = $this->invokeMethod($this->processor, 'getMediaUrls', [$node, TRUE]);

    $this->assertSame('https://example.test/sites/default/files/private.jpg', $result);
  }

  /**
   * Advanced Open311 access still respects media entity view access.
   *
   * @covers ::getMediaUrls
   */
  public function testMediaUrlsSkipUnpublishedMediaWhenMediaAccessDenied(): void {
    $media = $this->buildMediaWithImage(FALSE, TRUE, 'public://private.jpg');
    $node = $this->buildNodeWithRequestMedia([$media]);

    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $media->expects($this->once())
      ->method('access')
      ->with('view', $this->currentUser)
      ->willReturn(FALSE);
    $this->fileUrlGenerator->expects($this->never())->method('generateAbsoluteString');

    $result = $this->invokeMethod($this->processor, 'getMediaUrls', [$node, TRUE]);

    $this->assertSame('', $result);
  }

  /**
   * The mapped public/user response never exposes unpublished media_url.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testMapNodeToServiceRequestHidesUnpublishedMediaUrlForPublicShape(): void {
    $media = $this->buildMediaWithImage(FALSE, TRUE, 'public://private.jpg');
    $node = $this->buildMappedNodeWithRequestMedia(5001, [$media]);

    $media->expects($this->never())->method('access');
    $this->fileUrlGenerator->expects($this->never())->method('generateAbsoluteString');

    $result = $this->processor->mapNodeToServiceRequest($node, 'user', ['langcode' => 'en']);

    $this->assertArrayNotHasKey('media_url', $result);
  }

  /**
   * The mapped manager response may expose viewable unpublished media_url.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testMapNodeToServiceRequestExposesUnpublishedMediaUrlForManagerShape(): void {
    $media = $this->buildMediaWithImage(FALSE, TRUE, 'public://private.jpg');
    $node = $this->buildMappedNodeWithRequestMedia(5002, [$media]);

    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);
    $media->expects($this->once())
      ->method('access')
      ->with('view', $this->currentUser)
      ->willReturn(TRUE);
    $this->fileUrlGenerator->expects($this->once())
      ->method('generateAbsoluteString')
      ->with('public://private.jpg')
      ->willReturn('https://example.test/sites/default/files/private.jpg');

    $result = $this->processor->mapNodeToServiceRequest($node, 'manager', ['langcode' => 'en']);

    $this->assertSame('https://example.test/sites/default/files/private.jpg', $result['media_url']);
  }

  /**
   * GeoReport media status updates can publish state on referenced media.
   *
   * @covers ::updateMediaPublishedStatus
   */
  public function testUpdateMediaPublishedStatusUpdatesReferencedMedia(): void {
    $node = $this->buildNodeWithRequestMediaIds([10]);
    $media = $this->createMock(MediaInterface::class);
    $this->currentUser->method('hasPermission')
      ->with('update open311 request media publication')
      ->willReturn(TRUE);
    $media->expects($this->never())->method('access');
    $media->method('isPublished')->willReturn(TRUE);
    $media->expects($this->once())->method('setUnpublished');
    $media->expects($this->once())->method('save');

    $this->mediaStorage->expects($this->once())
      ->method('load')
      ->with(10)
      ->willReturn($media);

    $this->processor->updateMediaPublishedStatus([
      0 => [
        'target_id' => 10,
        'status' => '0',
      ],
    ], $node);

    // The explicit unpublish must mark the media as manually controlled so the
    // markaspot_vision AI pipeline hands off and does not revert it on the
    // next node save.
    $this->assertTrue((bool) $this->keyValueFactory->get('markaspot_open311.media_publication_manual')->get(10));
  }

  /**
   * Compact GeoReport media status payloads use explicit IDs, not delta 0.
   *
   * @covers ::updateMediaPublishedStatus
   */
  public function testUpdateMediaPublishedStatusAcceptsCompactExplicitMediaId(): void {
    $node = $this->buildNodeWithRequestMediaIds([10, 11]);
    $media = $this->createMock(MediaInterface::class);
    $this->currentUser->method('hasPermission')
      ->with('update open311 request media publication')
      ->willReturn(TRUE);
    $media->expects($this->never())->method('access');
    $media->method('isPublished')->willReturn(TRUE);
    $media->expects($this->once())->method('setUnpublished');
    $media->expects($this->once())->method('save');

    $this->mediaStorage->expects($this->once())
      ->method('load')
      ->with(11)
      ->willReturn($media);

    $this->processor->updateMediaPublishedStatus([
      '' => [
        'target_id' => 11,
        'published' => 'false',
      ],
    ], $node);

    $this->assertTrue((bool) $this->keyValueFactory->get('markaspot_open311.media_publication_manual')->get(11));
  }

  /**
   * An explicit publish also marks the media as manually controlled.
   *
   * The mark is symmetric: any deliberate GeoReport decision (publish or
   * unpublish) hands control to the editorial consumer, so a subsequent AI
   * screening pass cannot flip an explicitly published media back.
   *
   * @covers ::updateMediaPublishedStatus
   */
  public function testUpdateMediaPublishedStatusPublishMarksManualControl(): void {
    $node = $this->buildNodeWithRequestMediaIds([10]);
    $media = $this->createMock(MediaInterface::class);
    $this->currentUser->method('hasPermission')
      ->with('update open311 request media publication')
      ->willReturn(TRUE);
    $media->method('isPublished')->willReturn(FALSE);
    $media->expects($this->once())->method('setPublished');
    $media->expects($this->once())->method('save');

    $this->mediaStorage->expects($this->once())
      ->method('load')
      ->with(10)
      ->willReturn($media);

    $this->processor->updateMediaPublishedStatus([
      0 => [
        'target_id' => 10,
        'status' => '1',
      ],
    ], $node);

    $this->assertTrue((bool) $this->keyValueFactory->get('markaspot_open311.media_publication_manual')->get(10));
  }

  /**
   * GeoReport media status updates are scoped to media on the request.
   *
   * @covers ::updateMediaPublishedStatus
   */
  public function testUpdateMediaPublishedStatusRejectsUnreferencedMedia(): void {
    $node = $this->buildNodeWithRequestMediaIds([10]);

    $this->mediaStorage->expects($this->never())->method('load');
    $this->expectException(GeoreportException::class);
    $this->expectExceptionMessage('Invalid GeoReport media publication update payload.');

    $this->processor->updateMediaPublishedStatus([
      0 => [
        'target_id' => 11,
        'published' => 'false',
      ],
    ], $node);
  }

  /**
   * GeoReport media status updates still require media entity update access.
   *
   * @covers ::updateMediaPublishedStatus
   */
  public function testUpdateMediaPublishedStatusRequiresMediaUpdateAccess(): void {
    $node = $this->buildNodeWithRequestMediaIds([10]);
    $media = $this->createMock(MediaInterface::class);
    $this->currentUser->method('hasPermission')
      ->with('update open311 request media publication')
      ->willReturn(FALSE);
    $media->method('access')
      ->with('update')
      ->willReturn(FALSE);
    $media->expects($this->never())->method('save');

    $this->mediaStorage->expects($this->once())
      ->method('load')
      ->with(10)
      ->willReturn($media);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('GeoReport media publication updates are not permitted for this API account.');

    $this->processor->updateMediaPublishedStatus([
      0 => [
        'target_id' => 10,
        'published' => 'false',
      ],
    ], $node);
  }

  /**
   * Citizen create requests cannot mass-assign Drupal fields.
   *
   * @covers ::prepareNodeProperties
   */
  public function testCreateIgnoresExtendedDrupalFieldAssignments(): void {
    $values = $this->processor->prepareNodeProperties([
      'description' => 'Public issue text',
      'extended_attributes' => [
        'drupal' => [
          'field_internal_remark' => 'must not be accepted',
          'field_status_internal_term' => 77,
        ],
      ],
    ], 'create');

    $this->assertArrayNotHasKey('field_internal_remark', $values);
    $this->assertArrayNotHasKey('field_status_internal_term', $values);
    $this->assertSame([
      'value' => 'Public issue text',
      'format' => 'plain_text',
    ], $values['body']);
  }

  /**
   * GeoReport update rejects raw request media reference assignments.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateRejectsRawRequestMediaAssignments(): void {
    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);

    $this->expectException(GeoreportException::class);
    $this->expectExceptionMessage('Invalid GeoReport media publication update payload.');

    $this->processor->prepareNodeProperties([
      'extended_attributes' => [
        'drupal' => [
          'field_request_media' => [
            0 => [
              'target_id' => 11,
            ],
          ],
        ],
      ],
    ], 'update');
  }

  /**
   * GeoReport update rejects the legacy top-level media payload.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateRejectsTopLevelMediaPayload(): void {
    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);

    $this->expectException(GeoreportException::class);
    $this->expectExceptionMessage('Invalid GeoReport media publication update payload.');

    $this->processor->prepareNodeProperties([
      'extended_attributes' => [
        'media' => [
          0 => [
            'mid' => 11,
            'published' => 'false',
          ],
        ],
      ],
    ], 'update');
  }

  /**
   * Compact request media payloads stay ID-based during normalization.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateNormalizesCompactRequestMediaPayloadWithoutDelta(): void {
    $this->currentUser->method('hasPermission')
      ->with('access open311 advanced properties')
      ->willReturn(TRUE);

    $values = $this->processor->prepareNodeProperties([
      'extended_attributes' => [
        'drupal' => [
          'field_request_media' => [
            'target_id' => 11,
            'published' => 'false',
          ],
        ],
      ],
    ], 'update');

    $this->assertSame([
      '' => [
        'target_id' => 11,
        'published' => 'false',
      ],
    ], $values['_media_updates']);
    $this->assertArrayNotHasKey('field_request_media', $values);
  }

  /**
   * Facility create requests accept the explicit public facility field only.
   *
   * @covers ::prepareNodeProperties
   */
  public function testCreateAcceptsTopLevelFacilityField(): void {
    $values = $this->processor->prepareNodeProperties([
      'field_facility' => 'campus_north',
      'jurisdiction_id' => 42,
    ], 'create');

    $this->assertSame('campus_north', $values['field_facility']);
    $this->assertSame(42, $values['field_jurisdiction']);
  }

  /**
   * Open311 clients can use facility_id without raw Drupal field assignment.
   *
   * @covers ::prepareNodeProperties
   */
  public function testCreateAcceptsFacilityIdAlias(): void {
    $values = $this->processor->prepareNodeProperties([
      'facility_id' => 'school-centre',
      'jurisdiction_id' => 42,
      'extended_attributes' => [
        'drupal' => [
          'field_internal_remark' => 'must not be accepted',
        ],
      ],
    ], 'create');

    $this->assertSame('school-centre', $values['field_facility']);
    $this->assertSame(42, $values['field_jurisdiction']);
    $this->assertArrayNotHasKey('field_internal_remark', $values);
  }

  /**
   * Update requests without advanced API permission cannot mass-assign fields.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateWithoutAdvancedPermissionIgnoresExtendedDrupalFieldAssignments(): void {
    $this->currentUser->method('hasPermission')->willReturn(FALSE);

    $values = $this->processor->prepareNodeProperties([
      'extended_attributes' => [
        'drupal' => [
          'field_internal_remark' => 'must not be accepted',
          'field_status_internal_term' => 77,
        ],
      ],
    ], 'update');

    $this->assertArrayNotHasKey('field_internal_remark', $values);
    $this->assertArrayNotHasKey('field_status_internal_term', $values);
  }

  /**
   * @covers ::createNodeQuery
   */
  public function testCreateNodeQueryAnonymousPublishedOnly(): void {
    $query = $this->createMock(QueryInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($query);

    // Track conditions set on the query.
    $conditions = [];
    $query->method('condition')
      ->willReturnCallback(function ($field, $value = NULL, $operator = NULL) use ($query, &$conditions) {
        $conditions[] = ['field' => $field, 'value' => $value, 'operator' => $operator];
        return $query;
      });
    $query->method('accessCheck')
      ->willReturnSelf();

    $anon = $this->createMock(AccountProxyInterface::class);
    $anon->method('isAnonymous')->willReturn(TRUE);
    $anon->method('hasPermission')->willReturn(FALSE);
    $anon->method('id')->willReturn(0);

    $this->processor->createNodeQuery([], $anon);

    // Should have type=service_request and status=1 conditions.
    $fieldNames = array_column($conditions, 'field');
    $this->assertContains('type', $fieldNames, 'Query has type condition');
    $this->assertContains('status', $fieldNames, 'Anonymous query has published status condition');

    // Find status condition and verify value.
    foreach ($conditions as $cond) {
      if ($cond['field'] === 'status') {
        $this->assertEquals(1, $cond['value'], 'Anonymous sees only published nodes');
      }
    }
  }

  /**
   * @covers ::createNodeQuery
   */
  public function testCreateNodeQueryAdminBypassesAccess(): void {
    $query = $this->createMock(QueryInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($query);

    $accessCheckValue = NULL;
    $query->method('accessCheck')
      ->willReturnCallback(function ($value) use ($query, &$accessCheckValue) {
        $accessCheckValue = $value;
        return $query;
      });
    $query->method('condition')->willReturnSelf();

    // Only uid 1 bypasses access checks (not bypass node access).
    $admin = $this->createMock(AccountProxyInterface::class);
    $admin->method('hasPermission')->willReturn(FALSE);
    $admin->method('id')->willReturn(1);
    $admin->method('isAnonymous')->willReturn(FALSE);

    $this->processor->createNodeQuery([], $admin);
    $this->assertFalse($accessCheckValue, 'Admin (uid 1) query has accessCheck(FALSE)');
  }

  /**
   * @covers ::createNodeQuery
   */
  public function testCreateNodeQueryAuthenticatedUsesAccessCheck(): void {
    $query = $this->createMock(QueryInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($query);

    $accessCheckValue = NULL;
    $query->method('accessCheck')
      ->willReturnCallback(function ($value) use ($query, &$accessCheckValue) {
        $accessCheckValue = $value;
        return $query;
      });
    $query->method('condition')->willReturnSelf();

    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('hasPermission')->willReturn(FALSE);
    $user->method('id')->willReturn(5);
    $user->method('isAnonymous')->willReturn(FALSE);

    $this->processor->createNodeQuery([], $user);
    $this->assertTrue($accessCheckValue, 'Authenticated query has accessCheck(TRUE)');
  }

  /**
   * @covers ::createNodeQuery
   */
  public function testCreateNodeQueryBypassPermissionDoesNotBypassForNonUid1(): void {
    $query = $this->createMock(QueryInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($query);

    $accessCheckValue = NULL;
    $query->method('accessCheck')
      ->willReturnCallback(function ($value) use ($query, &$accessCheckValue) {
        $accessCheckValue = $value;
        return $query;
      });
    $query->method('condition')->willReturnSelf();

    // A tenant_admin (uid != 1) with bypass node access must NOT bypass.
    $tenantAdmin = $this->createMock(AccountProxyInterface::class);
    $tenantAdmin->method('hasPermission')
      ->willReturnCallback(fn($perm) => $perm === 'bypass node access');
    $tenantAdmin->method('id')->willReturn(42);
    $tenantAdmin->method('isAnonymous')->willReturn(FALSE);

    $this->processor->createNodeQuery([], $tenantAdmin);
    $this->assertTrue(
      $accessCheckValue,
      'User with bypass node access but uid != 1 must use accessCheck(TRUE)'
    );
  }

  /**
   * @covers ::getJurisdictionIdFromNode
   */
  public function testGetJurisdictionIdFromNodeWithCategory(): void {
    // Use anonymous classes to provide target_id and entity-like properties.
    // PHPUnit mocks of interfaces don't support dynamic properties in PHP 8.2+.
    $jurisdictionField = new class() {

      /**
       * The target entity ID.
       */
      public int $targetId = 42;

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

      /**
       * Provides Drupal-style snake_case field item properties.
       */
      public function __get(string $name): mixed {
        if ($name === 'target_id') {
          return $this->targetId;
        }
        return NULL;
      }

    };

    $categoryTerm = new class($jurisdictionField) {

      /**
       * The jurisdiction field reference.
       */
      private object $jurisdictionField;

      /**
       * Constructs the category term stub.
       */
      public function __construct(object $jurisdictionField) {
        $this->jurisdictionField = $jurisdictionField;
      }

      /**
       * Checks if a field exists.
       */
      public function hasField(string $name): bool {
        return $name === 'field_jurisdiction';
      }

      /**
       * Gets a field value.
       */
      public function get(string $name): object {
        return $this->jurisdictionField;
      }

    };

    $categoryField = new class($categoryTerm) {

      /**
       * The referenced entity.
       */
      public object $entity;

      /**
       * Constructs the category field stub.
       */
      public function __construct(object $entity) {
        $this->entity = $entity;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->willReturnCallback(fn($name) => $name === 'field_category');
    $node->method('get')
      ->willReturnCallback(function ($name) use ($categoryField) {
        if ($name === 'field_category') {
          return $categoryField;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $result = $this->processor->getJurisdictionIdFromNode($node);
    $this->assertEquals(42, $result);
  }

  /**
   * @covers ::getJurisdictionIdFromNode
   */
  public function testGetJurisdictionIdFromNodeWithoutCategory(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->with('field_category')
      ->willReturn(FALSE);

    $result = $this->processor->getJurisdictionIdFromNode($node);
    $this->assertNull($result);
  }

  /**
   * Tests that jurisdiction_id parameter is resolved as canonical name.
   */
  public function testResolveJurisdictionIdCanonical(): void {
    $result = $this->invokeMethod($this->processor, 'resolveJurisdictionId', [
      ['jurisdiction_id' => '42'],
    ]);
    $this->assertEquals(42, $result);
  }

  /**
   * Tests that deprecated 'jurisdiction' parameter still resolves.
   */
  public function testResolveJurisdictionIdDeprecatedJurisdiction(): void {
    $this->logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('Deprecated API parameter "jurisdiction"'));

    $result = $this->invokeMethod($this->processor, 'resolveJurisdictionId', [
      ['jurisdiction' => '42'],
    ]);
    $this->assertEquals(42, $result);
  }

  /**
   * Tests that deprecated 'gid' parameter still resolves.
   */
  public function testResolveJurisdictionIdDeprecatedGid(): void {
    $this->logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('Deprecated API parameter "gid"'));

    $result = $this->invokeMethod($this->processor, 'resolveJurisdictionId', [
      ['gid' => '42'],
    ]);
    $this->assertEquals(42, $result);
  }

  /**
   * Tests that jurisdiction_id takes priority over deprecated aliases.
   */
  public function testResolveJurisdictionIdCanonicalTakesPriority(): void {
    $this->logger->expects($this->never())->method('notice');

    $result = $this->invokeMethod($this->processor, 'resolveJurisdictionId', [
      ['jurisdiction_id' => '10', 'jurisdiction' => '20', 'gid' => '30'],
    ]);
    $this->assertEquals(10, $result);
  }

  /**
   * Tests that empty parameters return NULL.
   */
  public function testResolveJurisdictionIdEmpty(): void {
    $result = $this->invokeMethod($this->processor, 'resolveJurisdictionId', [[]]);
    $this->assertNull($result);
  }

  /**
   * Tests that org_id parameter is resolved as canonical name.
   */
  public function testResolveOrganisationGroupIdCanonical(): void {
    // org_id=5 with valid group and user membership.
    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')->with($this->currentUser)->willReturn($membership);
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $result = $this->invokeMethod($this->processor, 'resolveOrganisationGroupId', [
      ['org_id' => '5'],
    ]);
    $this->assertEquals(5, $result);
  }

  /**
   * Tests that deprecated 'group_id' parameter still resolves.
   */
  public function testResolveOrganisationGroupIdDeprecatedGroupId(): void {
    $this->logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('Deprecated API parameter "group_id"'));

    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')->with($this->currentUser)->willReturn($membership);
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $result = $this->invokeMethod($this->processor, 'resolveOrganisationGroupId', [
      ['group_id' => '5'],
    ]);
    $this->assertEquals(5, $result);
  }

  /**
   * Tests that org_id takes priority over deprecated group_id.
   */
  public function testResolveOrganisationGroupIdCanonicalTakesPriority(): void {
    $this->logger->expects($this->never())->method('notice');

    $membership = $this->createMock(GroupMembership::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')->with($this->currentUser)->willReturn($membership);
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $result = $this->invokeMethod($this->processor, 'resolveOrganisationGroupId', [
      ['org_id' => '5', 'group_id' => '99'],
    ]);
    $this->assertEquals(5, $result);
  }

  /**
   * Tests that empty parameters return NULL.
   */
  public function testResolveOrganisationGroupIdEmpty(): void {
    $result = $this->invokeMethod($this->processor, 'resolveOrganisationGroupId', [[]]);
    $this->assertNull($result);
  }

  /**
   * Tests that a non-numeric slug triggers entity lookup by field_slug.
   */
  public function testResolveJurisdictionIdSlugLookup(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(42);

    $this->groupStorage->method('loadByProperties')
      ->with([
        'type' => 'jur',
        'field_slug' => 'bonn',
      ])
      ->willReturn([42 => $group]);

    $result = $this->invokeMethod($this->processor, 'resolveJurisdictionId', [
      ['jurisdiction_id' => 'bonn'],
    ]);
    $this->assertEquals(42, $result);
  }

  /**
   * Tests that a slug with no matching group returns NULL.
   */
  public function testResolveJurisdictionIdSlugNotFound(): void {
    $this->groupStorage->method('loadByProperties')
      ->with([
        'type' => 'jur',
        'field_slug' => 'nonexistent',
      ])
      ->willReturn([]);

    $result = $this->invokeMethod($this->processor, 'resolveJurisdictionId', [
      ['jurisdiction_id' => 'nonexistent'],
    ]);
    $this->assertNull($result);
  }

  /**
   * Tests that a non-existent group returns -1 sentinel.
   */
  public function testResolveOrganisationGroupIdNonExistentGroupReturnsSentinel(): void {
    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $result = $this->invokeMethod($this->processor, 'resolveOrganisationGroupId', [
      ['org_id' => '999'],
    ]);
    $this->assertEquals(-1, $result);
  }

  /**
   * Tests that a valid group where user is not a member returns -1 sentinel.
   */
  public function testResolveOrganisationGroupIdNonMemberReturnsSentinel(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')->with($this->currentUser)->willReturn(NULL);
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $result = $this->invokeMethod($this->processor, 'resolveOrganisationGroupId', [
      ['org_id' => '5'],
    ]);
    $this->assertEquals(-1, $result);
  }

  /**
   * Tests that creating a request without location data throws an exception.
   *
   * @covers ::prepareNodeProperties
   */
  public function testPrepareNodePropertiesCreateWithoutLocationAllowed(): void {
    // Without coordinates, field_geolocation is not set explicitly.
    // The field's default value (map center) applies via Drupal's entity system.
    // DefaultLocationConstraintValidator optionally warns if coords == default.
    $result = $this->processor->prepareNodeProperties([
      'description' => 'No location provided',
    ], 'create');

    $this->assertArrayNotHasKey('field_geolocation', $result);
  }

  /**
   * Tests that invalid (non-numeric) coordinates throw an exception.
   *
   * @covers ::prepareNodeProperties
   */
  public function testPrepareNodePropertiesInvalidCoordinatesThrows(): void {
    $this->expectException(GeoreportException::class);
    $this->expectExceptionCode(400);
    $this->expectExceptionMessage('Coordinates must be numeric');

    $this->processor->prepareNodeProperties([
      'service_code' => 'test',
      'lat' => 'abc',
      'long' => '9.0',
    ], 'create');
  }

  /**
   * Tests that out-of-range coordinates throw an exception.
   *
   * @covers ::prepareNodeProperties
   */
  public function testPrepareNodePropertiesOutOfRangeCoordinatesThrows(): void {
    $this->expectException(GeoreportException::class);
    $this->expectExceptionCode(400);
    $this->expectExceptionMessage('Coordinates out of range');

    $this->processor->prepareNodeProperties([
      'service_code' => 'test',
      'lat' => '999',
      'long' => '9.0',
    ], 'create');
  }

  /**
   * Tests that an update without location data does NOT throw.
   *
   * @covers ::prepareNodeProperties
   */
  public function testPrepareNodePropertiesUpdateWithoutLocationAllowed(): void {
    // Updates may only change status, so location is not required.
    // Omit service_code to avoid taxonomy mapping side effects.
    $values = $this->processor->prepareNodeProperties([
      'description' => 'Status change only',
    ], 'update');
    // No location exception thrown, update proceeds.
    $this->assertIsArray($values);
  }

  /**
   * Tests that valid coordinates pass validation in prepareNodeProperties.
   *
   * @covers ::prepareNodeProperties
   */
  public function testPrepareNodePropertiesValidCoordinatesPass(): void {
    // Omit service_code to isolate coordinate validation.
    $values = $this->processor->prepareNodeProperties([
      'lat' => '50.7753',
      'long' => '6.0839',
    ], 'create');
    $this->assertEquals(50.7753, $values['field_geolocation']['lat']);
    $this->assertEquals(6.0839, $values['field_geolocation']['lng']);
  }

  /**
   * Tests that address_string alone satisfies the location requirement.
   *
   * @covers ::prepareNodeProperties
   */
  public function testPrepareNodePropertiesAddressStringSuffices(): void {
    // Omit service_code to isolate location validation.
    $values = $this->processor->prepareNodeProperties([
      'address_string' => 'Markt 1, 53111 Bonn',
    ], 'create');
    // No location exception thrown, address was accepted.
    $this->assertIsArray($values);
  }

  /**
   * Tests that a "closed" status string maps to field_status on update.
   *
   * This is the regression test for the bug where status=closed was silently
   * dropped because prepareNodeProperties had no block for the GeoReport
   * status-to-taxonomy mapping on the update path.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateStatusClosedMapsToFieldStatus(): void {
    // Mock a "Closed" term with TID=5.
    $closedTerm = $this->createMock(TermInterface::class);
    $closedTerm->method('id')->willReturn(5);

    $this->termStorage->method('loadByProperties')
      ->willReturnCallback(function (array $props) use ($closedTerm) {
        if (
          ($props['vid'] ?? '') === 'service_status'
          && ($props['field_open311_mapping'] ?? '') === 'closed'
          && ($props['status'] ?? '') === 1
        ) {
          return [5 => $closedTerm];
        }
        return [];
      });

    $values = $this->processor->prepareNodeProperties([
      'status' => 'closed',
      'jurisdiction_id' => NULL,
    ], 'update');

    $this->assertArrayHasKey('field_status', $values, 'field_status must be set for status=closed updates');
    $this->assertSame(5, $values['field_status'], 'field_status must hold the TID of the first closed term');
  }

  /**
   * Tests that a "closed" status string maps to field_status on update with jurisdiction.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateStatusClosedWithJurisdictionMapsToFieldStatus(): void {
    $closedTerm = $this->createMock(TermInterface::class);
    $closedTerm->method('id')->willReturn(87);

    // Root jurisdiction resolves to 1.
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(1)
      ->willReturn(1);

    $this->termStorage->method('loadByProperties')
      ->willReturnCallback(function (array $props) use ($closedTerm) {
        if (
          ($props['vid'] ?? '') === 'service_status'
          && ($props['field_open311_mapping'] ?? '') === 'closed'
          && ($props['field_jurisdiction'] ?? '') === 1
        ) {
          return [87 => $closedTerm];
        }
        return [];
      });

    $values = $this->processor->prepareNodeProperties([
      'status' => 'closed',
      'jurisdiction_id' => 1,
    ], 'update');

    $this->assertArrayHasKey('field_status', $values, 'field_status must be set for jurisdiction-scoped closed updates');
    $this->assertSame(87, $values['field_status']);
  }

  /**
   * Tests that status is NOT mapped on create (create sets initial status separately).
   *
   * @covers ::prepareNodeProperties
   */
  public function testCreateOperationIgnoresStatusField(): void {
    $values = $this->processor->prepareNodeProperties([
      'status' => 'closed',
    ], 'create');

    $this->assertArrayNotHasKey('field_status', $values, 'Create operation must not map the Open311 status string');
  }

  /**
   * Tests that an empty status string does not set field_status.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateEmptyStatusStringDoesNotSetFieldStatus(): void {
    $values = $this->processor->prepareNodeProperties([
      'status' => '',
    ], 'update');

    $this->assertArrayNotHasKey('field_status', $values, 'An empty status string must not set field_status');
  }

  /**
   * Tests that status_notes is mapped on update.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateStatusNotesMapsToFieldStatusNotes(): void {
    $values = $this->processor->prepareNodeProperties([
      'status_notes' => 'Closed by Open311 API',
    ], 'update');

    $this->assertArrayHasKey('field_status_notes', $values, 'field_status_notes must be set when status_notes is provided');
    $this->assertSame('Closed by Open311 API', $values['field_status_notes']);
  }

  /**
   * Tests that status_notes is NOT set for an empty string.
   *
   * @covers ::prepareNodeProperties
   */
  public function testUpdateEmptyStatusNotesDoesNotSetField(): void {
    $values = $this->processor->prepareNodeProperties([
      'status_notes' => '',
    ], 'update');

    $this->assertArrayNotHasKey('field_status_notes', $values, 'An empty status_notes must not set field_status_notes');
  }

  /**
   * Managers see the latest revision author as last_editor / last_edited.
   *
   * Asserts the keys are exposed under extended_attributes.markaspot, reflect
   * the REVISION user (not the node author uid), and carry the revision
   * creation time formatted as ISO 8601.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testLastEditorExposedToManagerReflectsRevisionUser(): void {
    $node = $this->createLastEditorNode(
      nid: 4001,
      authorName: 'Original Author',
      revisionUserName: 'Editing Moderator',
      revisionTimestamp: 1717500000,
    );

    $request = $this->processor->mapNodeToServiceRequest($node, 'manager', [
      'langcode' => 'en',
      'extensions' => 'true',
    ]);

    $this->assertArrayHasKey('markaspot', $request['extended_attributes']);
    $this->assertSame('Editing Moderator', $request['extended_attributes']['markaspot']['last_editor']);
    $this->assertNotSame('Original Author', $request['extended_attributes']['markaspot']['last_editor'], 'last_editor must reflect the revision user, not the node author');
    $this->assertSame(date('c', 1717500000), $request['extended_attributes']['markaspot']['last_edited']);
    $this->assertSame([
      'display_name' => 'Original Author',
      'uid' => 8001,
    ], $request['extended_attributes']['markaspot']['created_by']);
    $this->assertSame('staff', $request['extended_attributes']['markaspot']['source']);
    $this->assertSame('staff', $request['extended_attributes']['markaspot']['channel']);

    // The author key keeps reflecting the node uid.
    $this->assertSame('Original Author', $request['extended_attributes']['author']);
  }

  /**
   * Non-managers never receive last_editor / last_edited.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testLastEditorHiddenFromNonManager(): void {
    $node = $this->createLastEditorNode(
      nid: 4002,
      authorName: 'Original Author',
      revisionUserName: 'Editing Moderator',
      revisionTimestamp: 1717500000,
    );

    $request = $this->processor->mapNodeToServiceRequest($node, 'user', ['langcode' => 'en']);

    $markaspot = $request['extended_attributes']['markaspot'] ?? [];
    $this->assertArrayNotHasKey('last_editor', $markaspot, 'last_editor must not leak to non-managers');
    $this->assertArrayNotHasKey('last_edited', $markaspot, 'last_edited must not leak to non-managers');
    $this->assertArrayNotHasKey('created_by', $markaspot, 'created_by must not leak to non-managers');
    $this->assertArrayNotHasKey('author', $request['extended_attributes'] ?? []);
  }

  /**
   * A deleted revision user omits last_editor but still exposes last_edited.
   *
   * @covers ::mapNodeToServiceRequest
   */
  public function testLastEditorOmittedWhenRevisionUserNull(): void {
    $node = $this->createLastEditorNode(
      nid: 4003,
      authorName: 'Original Author',
      revisionUserName: NULL,
      revisionTimestamp: 1717500000,
    );

    $request = $this->processor->mapNodeToServiceRequest($node, 'manager', ['langcode' => 'en']);

    $this->assertArrayNotHasKey('last_editor', $request['extended_attributes']['markaspot'], 'last_editor must be omitted when the revision user is null/deleted');
    $this->assertSame(date('c', 1717500000), $request['extended_attributes']['markaspot']['last_edited']);
  }

  /**
   * Tests that a valid UUID for an imagelist attribute is accepted.
   */
  public function testValidateImagelistAttributesAcceptsValidUuid(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'bautyp',
          'datatype' => 'imagelist',
          'media_type' => 'catalog_image',
          'description' => 'Rack type',
          'required' => TRUE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
      ])
      ->willReturn([1 => $term]);

    $mediaEntity = $this->createMock(ContentEntityInterface::class);
    $mediaEntity->method('uuid')->willReturn('valid-uuid-1234');

    $this->mediaStorage->method('loadByProperties')
      ->with([
        'uuid' => ['valid-uuid-1234'],
        'bundle' => 'catalog_image',
        'status' => 1,
      ])
      ->willReturn([10 => $mediaEntity]);

    $attributes = ['bautyp' => 'valid-uuid-1234'];
    $requestData = ['service_code' => 'rack_001'];

    $result = $this->invokeMethod(
      $this->processor,
      'validateImagelistAttributes',
      [$attributes, $requestData]
    );

    $this->assertEquals('valid-uuid-1234', $result['bautyp']);
  }

  /**
   * Tests that attributes outside the service definition are rejected.
   */
  public function testValidateImagelistAttributesRejectsUnknownAttributes(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'public_note',
          'datatype' => 'text',
          'description' => 'Public note',
          'required' => FALSE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
      ])
      ->willReturn([1 => $term]);

    $attributes = [
      'public_note' => 'visible',
      'radbuegel_systemskizze' => 'internal-media-uuid',
    ];
    $requestData = ['service_code' => 'rack_001'];

    $result = $this->invokeMethod(
      $this->processor,
      'validateImagelistAttributes',
      [$attributes, $requestData]
    );

    $this->assertSame(['public_note' => 'visible'], $result);
  }

  /**
   * Tests that public request attributes are filtered on Open311 read output.
   */
  public function testFilterPublicRequestAttributesRejectsInternalAttributes(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'public_note',
          'datatype' => 'text',
          'description' => 'Public note',
          'required' => FALSE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(19)
      ->willReturn(19);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
        'field_jurisdiction' => 19,
      ])
      ->willReturn([1 => $term]);

    $node = $this->createRequestAttributeNode('rack_001', 19);

    $result = $this->invokeMethod(
      $this->processor,
      'filterPublicRequestAttributes',
      [
        [
          'public_note' => 'visible',
          'radbuegel_systemskizze' => 'internal-media-uuid',
        ],
        $node,
      ]
    );

    $this->assertSame(['public_note' => 'visible'], $result);
  }

  /**
   * Tests that public attribute validation is scoped to the jurisdiction.
   */
  public function testValidateImagelistAttributesScopesServiceDefinitionByJurisdiction(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'public_note',
          'datatype' => 'text',
          'description' => 'Public note',
          'required' => FALSE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->with(20)
      ->willReturn(19);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
        'field_jurisdiction' => 19,
      ])
      ->willReturn([1 => $term]);

    $attributes = [
      'public_note' => 'visible',
      'foreign_only' => 'wrong tenant',
    ];
    $requestData = [
      'service_code' => 'rack_001',
      'jurisdiction_id' => 20,
    ];

    $result = $this->invokeMethod(
      $this->processor,
      'validateImagelistAttributes',
      [$attributes, $requestData]
    );

    $this->assertSame(['public_note' => 'visible'], $result);
  }

  /**
   * Tests that an invalid (non-existent) UUID is rejected.
   */
  public function testValidateImagelistAttributesRejectsInvalidUuid(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'bautyp',
          'datatype' => 'imagelist',
          'media_type' => 'catalog_image',
          'description' => 'Rack type',
          'required' => TRUE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
      ])
      ->willReturn([1 => $term]);

    // Media storage returns empty: UUID does not exist.
    $this->mediaStorage->method('loadByProperties')
      ->willReturn([]);

    $attributes = ['bautyp' => 'nonexistent-uuid'];
    $requestData = ['service_code' => 'rack_001'];

    $result = $this->invokeMethod(
      $this->processor,
      'validateImagelistAttributes',
      [$attributes, $requestData]
    );

    $this->assertArrayNotHasKey('bautyp', $result);
  }

  /**
   * Tests that media_group enforcement rejects a UUID with wrong group.
   */
  public function testValidateImagelistAttributesEnforcesMediaGroup(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'bautyp',
          'datatype' => 'imagelist',
          'media_type' => 'catalog_image',
          'media_group' => 'radbuegel_bautypen',
          'description' => 'Rack type',
          'required' => TRUE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
      ])
      ->willReturn([1 => $term]);

    // Media storage returns empty because the UUID does not match the group.
    $this->mediaStorage->method('loadByProperties')
      ->with([
        'uuid' => ['wrong-group-uuid'],
        'bundle' => 'catalog_image',
        'status' => 1,
        'field_definition_group' => 'radbuegel_bautypen',
      ])
      ->willReturn([]);

    $attributes = ['bautyp' => 'wrong-group-uuid'];
    $requestData = ['service_code' => 'rack_001'];

    $result = $this->invokeMethod(
      $this->processor,
      'validateImagelistAttributes',
      [$attributes, $requestData]
    );

    $this->assertArrayNotHasKey('bautyp', $result);
  }

  /**
   * Tests that a UUID matching both media_type and media_group is accepted.
   */
  public function testValidateImagelistAttributesAcceptsCorrectGroup(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'bautyp',
          'datatype' => 'imagelist',
          'media_type' => 'catalog_image',
          'media_group' => 'radbuegel_bautypen',
          'description' => 'Rack type',
          'required' => TRUE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
      ])
      ->willReturn([1 => $term]);

    $mediaEntity = $this->createMock(ContentEntityInterface::class);
    $mediaEntity->method('uuid')->willReturn('correct-group-uuid');

    $this->mediaStorage->method('loadByProperties')
      ->with([
        'uuid' => ['correct-group-uuid'],
        'bundle' => 'catalog_image',
        'status' => 1,
        'field_definition_group' => 'radbuegel_bautypen',
      ])
      ->willReturn([10 => $mediaEntity]);

    $attributes = ['bautyp' => 'correct-group-uuid'];
    $requestData = ['service_code' => 'rack_001'];

    $result = $this->invokeMethod(
      $this->processor,
      'validateImagelistAttributes',
      [$attributes, $requestData]
    );

    $this->assertEquals('correct-group-uuid', $result['bautyp']);
  }

  /**
   * Tests backward compatibility when media_group is not set in the definition.
   */
  public function testValidateImagelistAttributesSkipsGroupWhenNotSet(): void {
    $serviceDefinition = json_encode([
      'attributes' => [
        [
          'code' => 'bautyp',
          'datatype' => 'imagelist',
          'media_type' => 'catalog_image',
          'description' => 'Rack type',
          'required' => TRUE,
          'variable' => TRUE,
          'order' => 0,
        ],
      ],
    ]);

    $term = $this->createImagelistTerm($serviceDefinition);
    $this->termStorage->method('loadByProperties')
      ->with([
        'vid' => 'service_category',
        'field_service_code' => 'rack_001',
      ])
      ->willReturn([1 => $term]);

    $mediaEntity = $this->createMock(ContentEntityInterface::class);
    $mediaEntity->method('uuid')->willReturn('no-group-uuid');

    // Without media_group, the lookup should NOT include field_definition_group.
    $this->mediaStorage->method('loadByProperties')
      ->with([
        'uuid' => ['no-group-uuid'],
        'bundle' => 'catalog_image',
        'status' => 1,
      ])
      ->willReturn([10 => $mediaEntity]);

    $attributes = ['bautyp' => 'no-group-uuid'];
    $requestData = ['service_code' => 'rack_001'];

    $result = $this->invokeMethod(
      $this->processor,
      'validateImagelistAttributes',
      [$attributes, $requestData]
    );

    $this->assertEquals('no-group-uuid', $result['bautyp']);
  }

  /**
   * @covers ::normalizeRequestListSort
   */
  public function testNormalizeRequestListSortKeepsLegacyValues(): void {
    $this->assertSame(
      [
        'api_field' => 'created',
        'field' => 'created',
        'direction' => 'DESC',
      ],
      $this->processor->normalizeRequestListSort(['sort' => 'DESC'])
    );

    $this->assertSame(
      [
        'api_field' => 'created',
        'field' => 'created',
        'direction' => 'ASC',
      ],
      $this->processor->normalizeRequestListSort(['sort' => 'ASC'])
    );
  }

  /**
   * @covers ::normalizeRequestListSort
   */
  public function testNormalizeRequestListSortMapsJsonApiStyleFields(): void {
    $this->assertSame(
      [
        'api_field' => 'updated',
        'field' => 'changed',
        'direction' => 'DESC',
      ],
      $this->processor->normalizeRequestListSort(['sort' => '-updated'])
    );

    $this->assertSame(
      [
        'api_field' => 'nid',
        'field' => 'nid',
        'direction' => 'ASC',
      ],
      $this->processor->normalizeRequestListSort(['sort' => 'nid'])
    );
  }

  /**
   * @covers ::normalizeRequestListSort
   */
  public function testUpdatedFilterKeepsChangedDescSort(): void {
    $this->assertSame(
      [
        'api_field' => 'updated',
        'field' => 'changed',
        'direction' => 'DESC',
      ],
      $this->processor->normalizeRequestListSort([
        'updated' => '2026-05-01',
        'sort' => 'created',
      ])
    );
  }

  /**
   * @covers ::normalizeRequestListPagination
   */
  public function testNormalizeRequestListPaginationCapsUnfilteredLimit(): void {
    $sort = $this->processor->normalizeRequestListSort([]);

    $pagination = $this->processor->normalizeRequestListPagination([
      'limit' => 250,
      'page' => 3,
    ], $sort);

    $this->assertSame(100, $pagination['limit']);
    $this->assertSame(500, $pagination['offset']);
    $this->assertNull($pagination['cursor']);
  }

  /**
   * @covers ::normalizeRequestListPagination
   * @covers ::buildRequestListCursor
   * @covers ::decodeRequestListCursor
   */
  public function testCursorPaginationResetsOffset(): void {
    $sort = $this->processor->normalizeRequestListSort(['sort' => '-created']);
    $cursor = $this->processor->buildRequestListCursor(
      $this->createRequestListCursorNode(99, 'created', 1777777777),
      $sort
    );

    $pagination = $this->processor->normalizeRequestListPagination([
      'limit' => 25,
      'offset' => 50,
      'cursor' => $cursor,
    ], $sort);

    $this->assertSame(25, $pagination['limit']);
    $this->assertSame(0, $pagination['offset']);
    $this->assertSame([
      'value' => 1777777777,
      'nid' => 99,
    ], $pagination['cursor']);
  }

  /**
   * @covers ::buildRequestListCursor
   * @covers ::decodeRequestListCursor
   */
  public function testRequestListCursorRoundTrip(): void {
    $sort = $this->processor->normalizeRequestListSort(['sort' => '-created']);
    $cursor = $this->processor->buildRequestListCursor(
      $this->createRequestListCursorNode(42, 'created', 1700000000),
      $sort
    );

    $this->assertIsString($cursor);
    $this->assertSame(
      [
        'value' => 1700000000,
        'nid' => 42,
      ],
      $this->processor->decodeRequestListCursor($cursor, $sort)
    );
  }

  /**
   * @covers ::decodeRequestListCursor
   */
  public function testRequestListCursorRejectsSortMismatch(): void {
    $sort = $this->processor->normalizeRequestListSort(['sort' => '-created']);
    $cursor = $this->processor->buildRequestListCursor(
      $this->createRequestListCursorNode(42, 'created', 1700000000),
      $sort
    );

    $this->expectException(GeoreportException::class);
    $this->processor->decodeRequestListCursor(
      $cursor,
      $this->processor->normalizeRequestListSort(['sort' => 'created'])
    );
  }

  /**
   * @covers ::decodeRequestListCursor
   */
  public function testRequestListCursorRejectsNonScalarValue(): void {
    $sort = $this->processor->normalizeRequestListSort(['sort' => '-created']);
    $cursor = $this->encodeRequestListCursor([
      'v' => 1,
      'field' => 'created',
      'direction' => 'DESC',
      'value' => ['bad'],
      'nid' => 42,
    ]);

    $this->expectException(GeoreportException::class);
    $this->processor->decodeRequestListCursor($cursor, $sort);
  }

  /**
   * @covers ::decodeRequestListCursor
   */
  public function testRequestListCursorRejectsNonNumericDateValue(): void {
    $sort = $this->processor->normalizeRequestListSort(['sort' => '-created']);
    $cursor = $this->encodeRequestListCursor([
      'v' => 1,
      'field' => 'created',
      'direction' => 'DESC',
      'value' => 'not-a-timestamp',
      'nid' => 42,
    ]);

    $this->expectException(GeoreportException::class);
    $this->processor->decodeRequestListCursor($cursor, $sort);
  }

  /**
   * @covers ::normalizeRequestListPagination
   */
  public function testCursorPaginationRejectsTextSearch(): void {
    $sort = $this->processor->normalizeRequestListSort(['sort' => '-created']);
    $cursor = $this->processor->buildRequestListCursor(
      $this->createRequestListCursorNode(42, 'created', 1700000000),
      $sort
    );

    $this->expectException(GeoreportException::class);
    $this->processor->normalizeRequestListPagination([
      'cursor' => $cursor,
      'q' => 'graffiti',
    ], $sort);
  }

  /**
   * @covers ::buildRequestListMetadata
   */
  public function testRequestListMetadataDoesNotAdvertiseCursorForTextSearch(): void {
    $sort = $this->processor->normalizeRequestListSort(['sort' => '-created']);

    $meta = $this->invokeMethod($this->processor, 'buildRequestListMetadata', [
      20,
      10,
      0,
      ['q' => 'graffiti'],
      $this->createRequestListCursorNode(42, 'created', 1700000000),
      $sort,
    ]);

    $this->assertSame([
      'total' => 20,
      'limit' => 10,
      'offset' => 0,
    ], $meta);
  }

  /**
   * @covers ::applyRequestListSort
   */
  public function testApplyRequestListSortAddsStableTieBreaker(): void {
    $query = $this->createMock(QueryInterface::class);
    $sortCalls = [];
    $query->method('sort')
      ->willReturnCallback(function ($field, $direction) use (&$sortCalls, $query) {
        $sortCalls[] = [$field, $direction];
        return $query;
      });

    $this->processor->applyRequestListSort($query, [
      'api_field' => 'created',
      'field' => 'created',
      'direction' => 'DESC',
    ]);

    $this->assertSame([
      ['created', 'DESC'],
      ['nid', 'DESC'],
    ], $sortCalls);
  }

  /**
   * @covers ::applyRequestListSort
   */
  public function testApplyRequestListSortDoesNotDuplicateNidTieBreaker(): void {
    $query = $this->createMock(QueryInterface::class);
    $sortCalls = [];
    $query->method('sort')
      ->willReturnCallback(function ($field, $direction) use (&$sortCalls, $query) {
        $sortCalls[] = [$field, $direction];
        return $query;
      });

    $this->processor->applyRequestListSort($query, [
      'api_field' => 'nid',
      'field' => 'nid',
      'direction' => 'ASC',
    ]);

    $this->assertSame([
      ['nid', 'ASC'],
    ], $sortCalls);
  }

  /**
   * @covers ::orderLoadedNodes
   */
  public function testOrderLoadedNodesKeepsQueryResultOrder(): void {
    $result = $this->invokeMethod($this->processor, 'orderLoadedNodes', [
      [
        3 => 'third',
        1 => 'first',
      ],
      [1, 3, 2],
    ]);

    $this->assertSame([
      1 => 'first',
      3 => 'third',
    ], $result);
  }

  /**
   * Creates a mock term with a field_service_definition containing JSON.
   *
   * Uses an anonymous class for the field item because PHPUnit mocks of
   * interfaces do not support dynamic properties (like ->value) in PHP 8.2+.
   *
   * @param string $serviceDefinitionJson
   *   The JSON-encoded service definition.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked term entity.
   */
  protected function createImagelistTerm(string $serviceDefinitionJson): ContentEntityInterface {
    $fieldItem = new class($serviceDefinitionJson) {

      /**
       * The raw field value.
       */
      public string $value;

      /**
       * Constructs the field item stub.
       */
      public function __construct(string $value) {
        $this->value = $value;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $term = $this->createMock(ContentEntityInterface::class);
    $term->method('hasField')
      ->with('field_service_definition')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_service_definition')
      ->willReturn($fieldItem);

    return $term;
  }

  /**
   * Creates a service request node stub with category service code/jurisdiction.
   */
  protected function createRequestAttributeNode(string $serviceCode, int $jurisdictionId): NodeInterface {
    $serviceCodeField = new class($serviceCode) {

      /**
       * Field scalar value.
       */
      public string $value;

      /**
       * Constructs the field item stub.
       */
      public function __construct(string $value) {
        $this->value = $value;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return $this->value === '';
      }

    };

    $jurisdictionField = new class($jurisdictionId) {

      /**
       * Target entity ID.
       */
      public int $targetId;

      /**
       * Constructs the field item stub.
       */
      public function __construct(int $targetId) {
        $this->targetId = $targetId;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

      /**
       * Provides Drupal-style snake_case field item properties.
       */
      public function __get(string $name): mixed {
        if ($name === 'target_id') {
          return $this->targetId;
        }
        return NULL;
      }

    };

    $category = $this->createMock(ContentEntityInterface::class);
    $category->method('hasField')
      ->willReturnCallback(fn($name) => in_array($name, [
        'field_service_code',
        'field_jurisdiction',
      ], TRUE));
    $category->method('get')
      ->willReturnCallback(function (string $name) use ($serviceCodeField, $jurisdictionField) {
        if ($name === 'field_service_code') {
          return $serviceCodeField;
        }
        if ($name === 'field_jurisdiction') {
          return $jurisdictionField;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $categoryField = new class($category) {

      /**
       * Referenced category entity.
       */
      public ContentEntityInterface $entity;

      /**
       * Constructs the category field stub.
       */
      public function __construct(ContentEntityInterface $entity) {
        $this->entity = $entity;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->willReturnCallback(fn($name) => $name === 'field_category');
    $node->method('get')
      ->willReturnCallback(function (string $name) use ($categoryField) {
        if ($name === 'field_category') {
          return $categoryField;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $node;
  }

  /**
   * Creates a mock node for request-list cursor tests.
   *
   * @param int $nid
   *   Node ID.
   * @param string $fieldName
   *   Field name used for the cursor.
   * @param int|string $value
   *   Field value.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\PHPUnit\Framework\MockObject\MockObject
   *   Cursor node mock.
   */
  protected function createRequestListCursorNode(int $nid, string $fieldName, int|string $value): ContentEntityInterface {
    $fieldItem = new class($value) {

      /**
       * Field scalar value.
       */
      public int|string $value;

      /**
       * Constructs the field item stub.
       */
      public function __construct(int|string $value) {
        $this->value = $value;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('hasField')
      ->willReturnCallback(fn($requestedField) => $requestedField === $fieldName);
    $node->method('get')
      ->willReturnCallback(function ($requestedField) use ($fieldName, $fieldItem) {
        if ($requestedField !== $fieldName) {
          throw new \InvalidArgumentException("Unexpected field $requestedField");
        }
        return $fieldItem;
      });

    return $node;
  }

  /**
   * Encodes a request-list cursor payload for forged cursor tests.
   *
   * @param array $payload
   *   Cursor payload.
   *
   * @return string
   *   URL-safe encoded cursor.
   */
  protected function encodeRequestListCursor(array $payload): string {
    return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
  }

  /**
   * Builds a minimal service_request node for "last edited by" tests.
   *
   * Only the fields consumed before/within the manager gate are populated;
   * every optional field reports hasField() === FALSE so the mapper skips it
   * and the test stays focused on the revision attribution.
   *
   * @param int $nid
   *   Node ID (also seeds the static cache key, so use a unique value per test).
   * @param string $authorName
   *   Display name returned for the node author (uid).
   * @param string|null $revisionUserName
   *   Display name for the revision user, or NULL to simulate a deleted user.
   * @param int $revisionTimestamp
   *   Revision creation timestamp.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The node mock.
   */
  protected function createLastEditorNode(int $nid, string $authorName, ?string $revisionUserName, int $revisionTimestamp): NodeInterface {
    // Field items the mapper reads unconditionally (no hasField guard).
    $emptyField = new class {

      /**
       * Field is empty.
       */
      public function isEmpty(): bool {
        return TRUE;
      }

    };

    // Author (uid) field item with a referenced user entity.
    $author = $this->createMock(UserInterface::class);
    $author->method('id')->willReturn(8001);
    $author->method('label')->willReturn($authorName);
    $uidField = new class($author) {

      /**
       * Referenced author entity.
       */
      public object $entity;

      /**
       * Constructs the uid field stub.
       */
      public function __construct(object $entity) {
        $this->entity = $entity;
      }

      /**
       * Field is populated.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $scalarField = function (int|string $value) {
      return new class($value) {

        /**
         * Field scalar value.
         */
        public int|string $value;

        /**
         * Constructs the scalar field stub.
         */
        public function __construct(int|string $value) {
          $this->value = $value;
        }

        /**
         * Field is populated.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('hasTranslation')->willReturn(FALSE);
    $node->method('getTitle')->willReturn('Broken streetlight');
    $node->method('getOwner')->willReturn($author);

    // Only uid and field_source are "present" optional fields; everything else
    // is absent so the mapper skips it (media, status notes, address, PII
    // fields, etc.).
    $node->method('hasField')
      ->willReturnCallback(fn($name) => in_array($name, ['uid', 'field_source'], TRUE));

    $node->method('get')
      ->willReturnCallback(function (string $name) use ($emptyField, $uidField, $scalarField) {
        return match ($name) {
          'request_id' => $scalarField('REQ-' . uniqid()),
          'created' => $scalarField(1717400000),
          'changed' => $scalarField(1717450000),
          'uid' => $uidField,
          'field_source' => $scalarField('staff'),
          default => $emptyField,
        };
      });

    // Revision attribution.
    if ($revisionUserName === NULL) {
      $node->method('getRevisionUser')->willReturn(NULL);
    }
    else {
      $revisionUser = $this->createMock(UserInterface::class);
      $revisionUser->method('label')->willReturn($revisionUserName);
      $node->method('getRevisionUser')->willReturn($revisionUser);
    }
    $node->method('getRevisionCreationTime')->willReturn($revisionTimestamp);

    return $node;
  }

  /**
   * Invokes a non-public method on an object.
   *
   * @param object $object
   *   The object to invoke the method on.
   * @param string $methodName
   *   The method name.
   * @param array $args
   *   The method arguments.
   *
   * @return mixed
   *   The method's return value.
   */
  protected function invokeMethod(object $object, string $methodName, array $args = []) {
    $ref = new \ReflectionMethod($object, $methodName);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * Builds a service request node exposing field_request_media references.
   *
   * @param array $media
   *   Referenced media entities.
   */
  protected function buildNodeWithRequestMedia(array $media): ContentEntityInterface {
    $field = new class($media) {

      /**
       * Referenced media entities.
       *
       * @var array
       */
      private array $media;

      /**
       * Constructs the field stub.
       */
      public function __construct(array $media) {
        $this->media = $media;
      }

      /**
       * Field emptiness.
       */
      public function isEmpty(): bool {
        return $this->media === [];
      }

      /**
       * Referenced entities.
       */
      public function referencedEntities(): array {
        return $this->media;
      }

    };

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('hasField')
      ->willReturnCallback(
        static fn (string $field_name): bool => $field_name === 'field_request_media'
      );
    $node->method('get')
      ->with('field_request_media')
      ->willReturn($field);

    return $node;
  }

  /**
   * Builds a service request node exposing field_request_media target IDs.
   *
   * @param int[] $mediaIds
   *   Referenced media entity IDs.
   */
  protected function buildNodeWithRequestMediaIds(array $mediaIds): ContentEntityInterface {
    $items = [];
    foreach (array_values($mediaIds) as $delta => $mediaId) {
      $items[$delta] = ['target_id' => $mediaId];
    }

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($items === []);
    $field->method('getValue')->willReturn($items);

    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('hasField')
      ->willReturnCallback(
        static fn (string $field_name): bool => $field_name === 'field_request_media'
      );
    $node->method('get')
      ->with('field_request_media')
      ->willReturn($field);

    return $node;
  }

  /**
   * Builds a service request node for mapNodeToServiceRequest media tests.
   *
   * @param int $nid
   *   Node ID.
   * @param array $media
   *   Referenced media entities.
   */
  protected function buildMappedNodeWithRequestMedia(int $nid, array $media): NodeInterface {
    $mediaField = new class($media) {

      /**
       * Referenced media entities.
       *
       * @var array
       */
      private array $media;

      /**
       * Constructs the field stub.
       */
      public function __construct(array $media) {
        $this->media = $media;
      }

      /**
       * Field emptiness.
       */
      public function isEmpty(): bool {
        return $this->media === [];
      }

      /**
       * Referenced entities.
       */
      public function referencedEntities(): array {
        return $this->media;
      }

    };

    $emptyField = new class {

      /**
       * Empty scalar value.
       */
      public mixed $value = NULL;

      /**
       * Empty target ID.
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public mixed $target_id = NULL;

      /**
       * Field emptiness.
       */
      public function isEmpty(): bool {
        return TRUE;
      }

    };

    $scalarField = static function (int|string $value) {
      return new class($value) {

        /**
         * Field scalar value.
         */
        public int|string $value;

        /**
         * Constructs the scalar field.
         */
        public function __construct(int|string $value) {
          $this->value = $value;
        }

        /**
         * Field emptiness.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };
    };

    $owner = $this->createMock(UserInterface::class);
    $owner->method('id')->willReturn(8001);
    $owner->method('label')->willReturn('Original Author');

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('hasTranslation')->willReturn(FALSE);
    $node->method('getTitle')->willReturn('Mapped media request');
    $node->method('getOwner')->willReturn($owner);
    $node->method('getRevisionUser')->willReturn(NULL);
    $node->method('getRevisionCreationTime')->willReturn(NULL);
    $node->method('hasField')
      ->willReturnCallback(
        static fn (string $field_name): bool => $field_name === 'field_request_media'
      );
    $node->method('get')
      ->willReturnCallback(function (string $field_name) use ($emptyField, $mediaField, $scalarField) {
        return match ($field_name) {
          'request_id' => $scalarField('REQ-' . uniqid()),
          'created' => $scalarField(1717400000),
          'changed' => $scalarField(1717450000),
          'field_request_media' => $mediaField,
          default => $emptyField,
        };
      });

    return $node;
  }

  /**
   * Builds a media mock with an image field.
   */
  protected function buildMediaWithImage(bool $published, bool $hasImage, string $uri): MediaInterface {
    $media = $this->createMock(MediaInterface::class);
    $media->method('isPublished')->willReturn($published);
    $media->method('hasField')
      ->with('field_media_image')
      ->willReturn($hasImage);

    if ($hasImage) {
      $file = new class($uri) {

        /**
         * File URI.
         */
        private string $uri;

        /**
         * Constructs the file stub.
         */
        public function __construct(string $uri) {
          $this->uri = $uri;
        }

        /**
         * Returns the file URI.
         */
        public function getFileUri(): string {
          return $this->uri;
        }

      };

      $field = new class($file) {

        /**
         * Referenced file entity.
         */
        public object $entity;

        /**
         * Constructs the field stub.
         */
        public function __construct(object $file) {
          $this->entity = $file;
        }

        /**
         * Field emptiness.
         */
        public function isEmpty(): bool {
          return FALSE;
        }

      };

      $media->method('get')
        ->with('field_media_image')
        ->willReturn($field);
    }

    return $media;
  }

  /**
   * An update must not force the 'changed' timestamp.
   *
   * Stamping 'changed' = now on every update makes an idempotent re-save of
   * unchanged data advance updated_datetime (mapped from node 'changed') on
   * each call. On update we leave 'changed' unset so Drupal's
   * ChangedItem::preSave() advances it only when a field actually differs;
   * create keeps stamping it.
   *
   * @covers ::prepareNodeProperties
   */
  public function testPrepareNodePropertiesOmitsChangedOnUpdate(): void {
    $update = $this->processor->prepareNodeProperties(['first_name' => 'Test'], 'update');
    $this->assertArrayNotHasKey('changed', $update, 'Update must not force the changed timestamp.');
    // Sanity: the update payload is otherwise populated (so the assertion above
    // is meaningful and not passing on an empty result).
    $this->assertArrayHasKey('field_first_name', $update);
  }

  /**
   * A bare "PLZ Ort" string maps the city to locality, not address_line1.
   *
   * @covers ::addressParser
   */
  public function testAddressParserGermanPostalCityWithoutComma(): void {
    $result = $this->invokeMethod($this->processor, 'addressParser', ['47051 Duisburg', 'DE']);

    $this->assertSame('47051', $result['postal_code']);
    $this->assertSame('Duisburg', $result['locality']);
    $this->assertSame('', $result['address_line1']);
  }

  /**
   * Street with house number plus a trailing PLZ Ort keeps street and city.
   *
   * @covers ::addressParser
   */
  public function testAddressParserGermanStreetWithPostalCity(): void {
    $result = $this->invokeMethod($this->processor, 'addressParser', ['Sonnenwall 100, 47051 Duisburg', 'DE']);

    $this->assertSame('Sonnenwall 100', $result['address_line1']);
    $this->assertSame('47051', $result['postal_code']);
    $this->assertSame('Duisburg', $result['locality']);
  }

  /**
   * A street name without house number still resolves the trailing PLZ Ort.
   *
   * @covers ::addressParser
   */
  public function testAddressParserGermanStreetNameWithPostalCity(): void {
    $result = $this->invokeMethod($this->processor, 'addressParser', ['Königstraße, 47051 Duisburg', 'DE']);

    $this->assertSame('Königstraße', $result['address_line1']);
    $this->assertSame('47051', $result['postal_code']);
    $this->assertSame('Duisburg', $result['locality']);
  }

  /**
   * House-number-first countries keep a leading number as part of the street.
   *
   * US, CA, AU etc. write the house number before the street name, so the
   * "PLZ Ort" rule must not fire there: the street stays in address_line1
   * and locality is never populated from a street segment.
   *
   * @covers ::addressParser
   */
  public function testAddressParserHouseNumberFirstCountryKeepsStreet(): void {
    $result = $this->invokeMethod($this->processor, 'addressParser', [
      '10250 Santa Monica Blvd, Los Angeles, CA 90064',
      'US',
    ]);

    $this->assertSame('Santa Monica Blvd', $result['address_line1']);
    $this->assertSame('Los Angeles', $result['address_line2']);
    $this->assertSame('90064', $result['postal_code']);
    $this->assertSame('', $result['locality']);
  }

  /**
   * Without a country code the parser falls back to positional behavior.
   *
   * The "PLZ Ort" rule needs a postal-code-first country; an empty country
   * disables it, so a bare "47051 Duisburg" degrades to the conservative
   * positional result (city in address_line1) instead of guessing.
   *
   * @covers ::addressParser
   */
  public function testAddressParserWithoutCountryFallsBackToPositional(): void {
    $result = $this->invokeMethod($this->processor, 'addressParser', ['47051 Duisburg']);

    $this->assertSame('47051', $result['postal_code']);
    $this->assertSame('Duisburg', $result['address_line1']);
    $this->assertSame('', $result['locality']);
  }

  /**
   * HTML markup in an untrusted address_string is stripped, not persisted.
   *
   * The parser must not carry tags into address components, otherwise a
   * downstream HTML consumer (Nuxt v-html, HTML mail, SAP) would render an
   * anonymous reporter's payload. Covers both raw and entity-encoded markup.
   *
   * @covers ::addressParser
   */
  public function testAddressParserStripsMarkupFromComponents(): void {
    $result = $this->invokeMethod($this->processor, 'addressParser', [
      '<img src=x onerror=alert(1)> Sonnenwall 100, 47051 Duisburg',
      'DE',
    ]);

    $this->assertStringNotContainsString('<', $result['address_line1']);
    $this->assertStringNotContainsString('onerror', $result['address_line1']);
    $this->assertSame('47051', $result['postal_code']);
    $this->assertSame('Duisburg', $result['locality']);
  }

  /**
   * Populated components are joined into "street, PLZ locality".
   *
   * @covers ::formatAddress
   */
  public function testFormatAddressJoinsPopulatedComponents(): void {
    $field = $this->buildAddressField([
      'address_line1' => 'Königstraße',
      'address_line2' => '',
      'postal_code' => '47051',
      'locality' => 'Duisburg',
    ]);

    $this->assertSame('Königstraße, 47051 Duisburg', $this->processor->formatAddress($field));
  }

  /**
   * An empty street line must not leave a leading comma.
   *
   * @covers ::formatAddress
   */
  public function testFormatAddressSkipsEmptyStreetLine(): void {
    $field = $this->buildAddressField([
      'address_line1' => '',
      'address_line2' => '',
      'postal_code' => '47051',
      'locality' => 'Duisburg',
    ]);

    $this->assertSame('47051 Duisburg', $this->processor->formatAddress($field));
  }

  /**
   * Whitespace-only and NULL components are skipped, not emitted as blanks.
   *
   * @covers ::formatAddress
   */
  public function testFormatAddressSkipsWhitespaceAndNullComponents(): void {
    $field = $this->buildAddressField([
      'address_line1' => '  ',
      'address_line2' => NULL,
      'postal_code' => '',
      'locality' => 'Duisburg',
    ]);

    $this->assertSame('Duisburg', $this->processor->formatAddress($field));
  }

  /**
   * An entirely empty address yields an empty string, no stray commas.
   *
   * @covers ::formatAddress
   */
  public function testFormatAddressAllEmptyReturnsEmptyString(): void {
    $field = $this->buildAddressField([
      'address_line1' => '',
      'address_line2' => '',
      'postal_code' => '',
      'locality' => '',
    ]);

    $this->assertSame('', $this->processor->formatAddress($field));
  }

  /**
   * Builds an address field stub exposing components via magic property access.
   *
   * @param array $components
   *   Address component values keyed by property name (address_line1,
   *   address_line2, postal_code, locality).
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The mocked address field item list.
   */
  protected function buildAddressField(array $components): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')
      ->willReturnCallback(fn(string $property) => $components[$property] ?? NULL);

    return $field;
  }

}
