<?php

namespace Drupal\Tests\markaspot_open311\Unit;

use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
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

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['node', $this->nodeStorage],
        ['group', $this->groupStorage],
        ['taxonomy_term', $this->termStorage],
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
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $token = $this->createMock(Token::class);
    $languageManager = $this->createMock(LanguageManagerInterface::class);

    $this->processor = new GeoreportProcessorService(
      $this->configFactory,
      $this->currentUser,
      $time,
      $requestStack,
      $this->entityTypeManager,
      $fileUrlGenerator,
      $this->moduleHandler,
      $entityFieldManager,
      $streamWrapperManager,
      $token,
      $languageManager,
      $this->hierarchyResolver,
      $this->logger,
    );
  }

  // =========================================================================
  // validateJurisdictionAccess() tests
  // =========================================================================

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

    $admin = $this->createMock(AccountProxyInterface::class);
    $admin->method('hasPermission')
      ->willReturnCallback(fn($perm) => $perm === 'bypass node access');
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
    $user->method('hasPermission')->willReturn(FALSE);
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
    $user->method('hasPermission')->willReturn(FALSE);
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
    $user->method('hasPermission')->willReturn(FALSE);
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
    $user->method('hasPermission')->willReturn(FALSE);
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

  // =========================================================================
  // getCategoryTidsForJurisdiction() tests
  // =========================================================================

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

  // =========================================================================
  // determineExtendedRole() tests (via reflection since private)
  // =========================================================================

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

  // =========================================================================
  // createNodeQuery() tests
  // =========================================================================

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

    $admin = $this->createMock(AccountProxyInterface::class);
    $admin->method('hasPermission')
      ->willReturnCallback(fn($perm) => $perm === 'bypass node access');
    $admin->method('id')->willReturn(1);
    $admin->method('isAnonymous')->willReturn(FALSE);

    $this->processor->createNodeQuery([], $admin);
    $this->assertFalse($accessCheckValue, 'Admin query has accessCheck(FALSE)');
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

  // =========================================================================
  // getJurisdictionIdFromNode() tests
  // =========================================================================

  /**
   * @covers ::getJurisdictionIdFromNode
   */
  public function testGetJurisdictionIdFromNodeWithCategory(): void {
    // Use anonymous classes to provide target_id and entity as real properties.
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

    };
    // Map target_id property for compatibility.
    $jurisdictionField->target_id = 42;

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

  // =========================================================================
  // resolveJurisdictionId() tests (API parameter cleanup)
  // =========================================================================

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

  // =========================================================================
  // resolveOrganisationGroupId() tests (API parameter cleanup)
  // =========================================================================

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

  // =========================================================================
  // resolveJurisdictionId() slug resolution tests
  // =========================================================================

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

  // =========================================================================
  // resolveOrganisationGroupId() sentinel value tests
  // =========================================================================

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

  // =========================================================================
  // prepareNodeProperties() location validation tests
  // =========================================================================

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

  // =========================================================================
  // Helper: invoke private/protected methods via reflection.
  // =========================================================================

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

}
