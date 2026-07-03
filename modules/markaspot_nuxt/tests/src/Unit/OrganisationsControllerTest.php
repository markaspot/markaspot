<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController;
use Drupal\markaspot_nuxt\Service\EnterpriseFeatureGate;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests role-based filtering in the getOrganisations() endpoint.
 *
 * Verifies that privileged users (moderator, administrator, editorial_board)
 * receive all organisations, while non-privileged users only see orgs they
 * are members of via group_relationship entities.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController
 */
class OrganisationsControllerTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The mocked hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The mocked group_relationship storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $relationshipStorage;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected AccountInterface $currentUser;

  /**
   * The Drupal container.
   *
   * @var \Drupal\Core\DependencyInjection\ContainerBuilder
   */
  protected ContainerBuilder $container;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Build nuxt config (required by constructor, not used by getOrganisations).
    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('isNew')->willReturn(FALSE);
    $nuxtConfig->method('get')->willReturn(NULL);

    // Build open311 config with org/jur type settings.
    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'jurisdiction_group_type' => 'jur',
        'organisation_group_type' => 'org',
        default => NULL,
      });

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_nuxt.settings' => $nuxtConfig,
        'markaspot_open311.settings' => $open311Config,
        default => $this->createMock(ImmutableConfig::class),
      });

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->relationshipStorage = $this->createMock(EntityStorageInterface::class);

    $groupTypeStorage = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage->method('load')->willReturn(NULL);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        'group_relationship' => $this->relationshipStorage,
        'group_type' => $groupTypeStorage,
        'taxonomy_term' => $this->createMock(EntityStorageInterface::class),
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->willReturnCallback(fn(int $id) => $id);

    // Module handler: reports no modules installed.
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);
    $moduleHandler->method('alter');

    // Language manager.
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    // Cache contexts manager for CacheableJsonResponse.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    // Default current user (non-privileged, uid 5).
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('id')->willReturn(5);
    $this->currentUser->method('getRoles')->willReturn(['authenticated']);

    // Set up the Drupal container.
    $this->container = new ContainerBuilder();
    $this->container->set('config.factory', $this->configFactory);
    $this->container->set('entity_type.manager', $this->entityTypeManager);
    $this->container->set('stream_wrapper_manager', $this->streamWrapperManager);
    $this->container->set('markaspot_group.hierarchy_resolver', $this->hierarchyResolver);
    $this->container->set('module_handler', $moduleHandler);
    $this->container->set('language_manager', $languageManager);
    $this->container->set('cache_contexts_manager', $cacheContextsManager);
    $this->container->set('current_user', $this->currentUser);
    \Drupal::setContainer($this->container);
  }

  /**
   * Creates a controller instance with the given current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to use as the current user.
   *
   * @return \Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController
   *   The controller under test.
   */
  protected function createController(AccountInterface $account): MarkASpotSettingsController {
    $this->container->set('current_user', $account);
    \Drupal::setContainer($this->container);

    return new MarkASpotSettingsController(
      $this->entityTypeManager,
      $this->configFactory,
      $this->streamWrapperManager,
      $this->hierarchyResolver,
      new EnterpriseFeatureGate(),
    );
  }

  /**
   * Creates a mock organisation group entity.
   *
   * @param int $id
   *   The numeric group ID.
   * @param string $label
   *   The group label.
   * @param string $uuid
   *   The group UUID.
   * @param int|null $jurisdictionId
   *   The optional jurisdiction group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockOrgGroup(int $id, string $label, string $uuid, ?int $jurisdictionId = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn('org');
    $group->method('label')->willReturn($label);
    $group->method('uuid')->willReturn($uuid);
    $group->method('isPublished')->willReturn(TRUE);
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $group->method('getCacheContexts')->willReturn([]);
    $jurisdiction = NULL;
    if ($jurisdictionId !== NULL) {
      $jurisdiction = $this->createMock(GroupInterface::class);
      $jurisdiction->method('id')->willReturn((string) $jurisdictionId);
      $jurisdiction->method('bundle')->willReturn('jur');
    }
    $group->method('hasField')
      ->willReturnCallback(fn(string $field): bool => $field === 'field_jurisdiction');
    $group->method('get')
      ->willReturnCallback(static function (string $field) use ($jurisdictionId, $jurisdiction) {
        if ($field !== 'field_jurisdiction') {
          return NULL;
        }
        return new class($jurisdictionId, $jurisdiction) {

          /**
           * Constructs a jurisdiction reference field stub.
           */
          public function __construct(
            public ?int $targetId,
            public ?object $entity,
          ) {}

          /**
           * Checks whether the field is empty.
           */
          public function isEmpty(): bool {
            return $this->targetId === NULL;
          }

        };
      });
    return $group;
  }

  /**
   * Creates a mock group_relationship (membership) entity.
   *
   * @param int $group_id
   *   The group ID this membership belongs to.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group relationship entity.
   */
  protected function createMockMembership(int $group_id): GroupRelationshipInterface {
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('getGroupId')->willReturn($group_id);
    return $membership;
  }

  /**
   * Creates a mock entity query that returns given IDs.
   *
   * @param array $ids
   *   The entity IDs to return from execute().
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked query.
   */
  protected function createMockQuery(array $ids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Creates a mock current user with the given roles.
   *
   * @param array $roles
   *   The role IDs.
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked account.
   */
  protected function createMockUser(array $roles, int $uid = 5): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

  /**
   * Tests that a privileged user (moderator) receives all organisations.
   *
   * @covers ::getOrganisations
   */
  public function testPrivilegedUserReceivesAllOrganisations(): void {
    $account = $this->createMockUser(['authenticated', 'moderator'], 3);
    $controller = $this->createController($account);

    $org1 = $this->createMockOrgGroup(100, 'Org Alpha', 'uuid-alpha');
    $org2 = $this->createMockOrgGroup(101, 'Org Beta', 'uuid-beta');
    $org3 = $this->createMockOrgGroup(102, 'Org Gamma', 'uuid-gamma');

    // Group query returns all three org IDs.
    $groupQuery = $this->createMockQuery([100, 101, 102]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100, 101, 102])
      ->willReturn([100 => $org1, 101 => $org2, 102 => $org3]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertCount(3, $data['organisations']);
    $this->assertEquals(3, $data['count']);
    $this->assertEquals('uuid-alpha', $data['organisations'][0]['id']);
    $this->assertEquals('Org Alpha', $data['organisations'][0]['label']);
    $this->assertEquals(100, $data['organisations'][0]['numericId']);
  }

  /**
   * Tests that an administrator also receives all organisations.
   *
   * @covers ::getOrganisations
   */
  public function testAdministratorReceivesAllOrganisations(): void {
    $account = $this->createMockUser(['authenticated', 'administrator'], 1);
    $controller = $this->createController($account);

    $org1 = $this->createMockOrgGroup(100, 'Org Alpha', 'uuid-alpha');

    $groupQuery = $this->createMockQuery([100]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100])
      ->willReturn([100 => $org1]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $data['organisations']);
  }

  /**
   * Tests that organisation responses expose jurisdiction metadata.
   *
   * @covers ::getOrganisations
   */
  public function testOrganisationResponseIncludesJurisdictionMetadata(): void {
    $account = $this->createMockUser(['authenticated', 'administrator'], 1);
    $controller = $this->createController($account);

    $org1 = $this->createMockOrgGroup(100, 'Org Alpha', 'uuid-alpha', 14);
    $jurGroup = $this->createMock(GroupInterface::class);
    $jurGroup->method('id')->willReturn('14');
    $jurGroup->method('bundle')->willReturn('jur');
    $jurGroup->method('isPublished')->willReturn(TRUE);
    $jurGroup->method('getCacheTags')->willReturn(['group:14']);
    $jurGroup->method('getCacheMaxAge')->willReturn(-1);
    $jurGroup->method('getCacheContexts')->willReturn([]);

    $this->groupStorage->method('load')
      ->with(14)
      ->willReturn($jurGroup);

    $groupQuery = $this->createMockQuery([100]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100])
      ->willReturn([100 => $org1]);

    $request = Request::create('/api/organisations?jurisdiction=14', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(14, $data['organisations'][0]['jurisdictionId']);
    $this->assertFalse($data['organisations'][0]['orphan']);
  }

  /**
   * Tests organisation jurisdiction validation uses configured group type.
   *
   * @covers ::getOrganisationJurisdictionId
   */
  public function testOrganisationJurisdictionValidationUsesConfiguredGroupType(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $source = file_get_contents($moduleRoot . '/src/Controller/MarkASpotSettingsController.php');

    $this->assertStringContainsString('$this->isJurisdictionGroup($jurisdiction)', $source);
  }

  /**
   * Tests unfiltered responses do not expose jurisdiction topology.
   *
   * @covers ::getOrganisations
   */
  public function testUnfilteredOrganisationResponseOmitsJurisdictionMetadata(): void {
    $account = $this->createMockUser(['authenticated', 'administrator'], 1);
    $controller = $this->createController($account);

    $org1 = $this->createMockOrgGroup(100, 'Org Alpha', 'uuid-alpha', 14);

    $groupQuery = $this->createMockQuery([100]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100])
      ->willReturn([100 => $org1]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertArrayNotHasKey('jurisdictionId', $data['organisations'][0]);
    $this->assertArrayNotHasKey('orphan', $data['organisations'][0]);
  }

  /**
   * Tests that editorial_board role is treated as privileged.
   *
   * @covers ::getOrganisations
   */
  public function testEditorialBoardReceivesAllOrganisations(): void {
    $account = $this->createMockUser(['authenticated', 'editorial_board'], 2);
    $controller = $this->createController($account);

    $org1 = $this->createMockOrgGroup(100, 'Org Alpha', 'uuid-alpha');
    $org2 = $this->createMockOrgGroup(101, 'Org Beta', 'uuid-beta');

    $groupQuery = $this->createMockQuery([100, 101]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100, 101])
      ->willReturn([100 => $org1, 101 => $org2]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(2, $data['organisations']);
    $this->assertEquals(2, $data['count']);
  }

  /**
   * Tests that a non-privileged user only receives their org memberships.
   *
   * @covers ::getOrganisations
   */
  public function testNonPrivilegedUserReceivesOnlyMemberOrgs(): void {
    $account = $this->createMockUser(['authenticated'], 10);
    $controller = $this->createController($account);

    // User is member of org 100 and 102, but not 101.
    $membership1 = $this->createMockMembership(100);
    $membership2 = $this->createMockMembership(102);

    // group_relationship query returns membership IDs.
    $membershipQuery = $this->createMockQuery([50, 51]);
    $this->relationshipStorage->method('getQuery')->willReturn($membershipQuery);
    $this->relationshipStorage->method('loadMultiple')
      ->with([50, 51])
      ->willReturn([50 => $membership1, 51 => $membership2]);

    // Group query returns the filtered org IDs (only 100, 102).
    $org1 = $this->createMockOrgGroup(100, 'Org Alpha', 'uuid-alpha');
    $org2 = $this->createMockOrgGroup(102, 'Org Gamma', 'uuid-gamma');

    $groupQuery = $this->createMockQuery([100, 102]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100, 102])
      ->willReturn([100 => $org1, 102 => $org2]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(2, $data['organisations']);
    $this->assertEquals(2, $data['count']);
    $this->assertEquals('uuid-alpha', $data['organisations'][0]['id']);
    $this->assertEquals('uuid-gamma', $data['organisations'][1]['id']);
  }

  /**
   * Tests that a non-privileged user with no memberships gets empty response.
   *
   * @covers ::getOrganisations
   */
  public function testNonPrivilegedUserWithNoMembershipsGetsEmptyList(): void {
    $account = $this->createMockUser(['authenticated'], 10);
    $controller = $this->createController($account);

    // group_relationship query returns no membership IDs.
    $membershipQuery = $this->createMockQuery([]);
    $this->relationshipStorage->method('getQuery')->willReturn($membershipQuery);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertEmpty($data['organisations']);
    $this->assertEquals(0, $data['count']);
  }

  /**
   * Tests that cache metadata includes the 'user' context.
   *
   * @covers ::getOrganisations
   */
  public function testCacheMetadataIncludesUserContext(): void {
    $account = $this->createMockUser(['authenticated', 'moderator'], 3);
    $controller = $this->createController($account);

    $groupQuery = $this->createMockQuery([]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')->willReturn([]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $cacheMetadata = $response->getCacheableMetadata();
    $this->assertContains('user', $cacheMetadata->getCacheContexts());
    $this->assertContains('url.query_args:jurisdiction', $cacheMetadata->getCacheContexts());
  }

  /**
   * Tests cache metadata for non-privileged empty response includes user.
   *
   * @covers ::getOrganisations
   */
  public function testEmptyResponseCacheMetadataIncludesUserContext(): void {
    $account = $this->createMockUser(['authenticated'], 10);
    $controller = $this->createController($account);

    $membershipQuery = $this->createMockQuery([]);
    $this->relationshipStorage->method('getQuery')->willReturn($membershipQuery);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $cacheMetadata = $response->getCacheableMetadata();
    $this->assertContains('user', $cacheMetadata->getCacheContexts());
  }

  /**
   * Tests jurisdiction filter works for privileged users.
   *
   * When a jurisdiction parameter is provided, only orgs belonging to that
   * jurisdiction should be returned. The jurisdiction group must be validated.
   *
   * @covers ::getOrganisations
   */
  public function testJurisdictionFilterForPrivilegedUser(): void {
    $account = $this->createMockUser(['authenticated', 'moderator'], 3);
    $controller = $this->createController($account);

    // Create a valid jurisdiction group for validation.
    $jurGroup = $this->createMock(GroupInterface::class);
    $jurGroup->method('id')->willReturn('14');
    $jurGroup->method('bundle')->willReturn('jur');
    $jurGroup->method('isPublished')->willReturn(TRUE);
    $jurGroup->method('getCacheTags')->willReturn(['group:14']);
    $jurGroup->method('getCacheMaxAge')->willReturn(-1);
    $jurGroup->method('getCacheContexts')->willReturn([]);

    // Group storage load() is called for jurisdiction validation,
    // then getQuery() for the org query.
    $this->groupStorage->method('load')
      ->with(14)
      ->willReturn($jurGroup);

    // Only one org belongs to jurisdiction 14.
    $org1 = $this->createMockOrgGroup(100, 'Org In Jur', 'uuid-in-jur');

    $groupQuery = $this->createMockQuery([100]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100])
      ->willReturn([100 => $org1]);

    $request = Request::create('/api/organisations?jurisdiction=14', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $data['organisations']);
    $this->assertEquals('uuid-in-jur', $data['organisations'][0]['id']);
  }

  /**
   * Tests invalid jurisdiction returns empty response.
   *
   * @covers ::getOrganisations
   */
  public function testInvalidJurisdictionReturnsEmptyResponse(): void {
    $account = $this->createMockUser(['authenticated', 'moderator'], 3);
    $controller = $this->createController($account);

    // Jurisdiction group does not exist.
    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $request = Request::create('/api/organisations?jurisdiction=999', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEmpty($data['organisations']);
    $this->assertEquals(0, $data['count']);
  }

  /**
   * Tests invalid jurisdiction slugs return an empty response.
   *
   * @covers ::getOrganisations
   */
  public function testInvalidJurisdictionSlugReturnsEmptyResponse(): void {
    $account = $this->createMockUser(['authenticated', 'moderator'], 3);
    $controller = $this->createController($account);

    $this->groupStorage->method('loadByProperties')->willReturn([]);
    $this->groupStorage->expects($this->never())->method('getQuery');

    $request = Request::create('/api/organisations?jurisdiction=unknown-slug', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEmpty($data['organisations']);
    $this->assertEquals(0, $data['count']);
  }

  /**
   * Tests that cache tags include group_list and config dependency.
   *
   * @covers ::getOrganisations
   */
  public function testCacheTagsIncludeGroupListAndConfig(): void {
    $account = $this->createMockUser(['authenticated', 'moderator'], 3);
    $controller = $this->createController($account);

    $groupQuery = $this->createMockQuery([]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')->willReturn([]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $cacheMetadata = $response->getCacheableMetadata();
    $this->assertContains('group_list', $cacheMetadata->getCacheTags());
    $this->assertContains('config:markaspot_open311.settings', $cacheMetadata->getCacheTags());
  }

  /**
   * Tests that privileged user does not trigger group_relationship queries.
   *
   * The group_relationship storage should never be queried for privileged
   * users, as they see all organisations regardless of membership.
   *
   * @covers ::getOrganisations
   */
  public function testPrivilegedUserDoesNotQueryMemberships(): void {
    $account = $this->createMockUser(['authenticated', 'moderator'], 3);
    $controller = $this->createController($account);

    $groupQuery = $this->createMockQuery([]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')->willReturn([]);

    // The relationship storage should never be asked for a query.
    $this->relationshipStorage->expects($this->never())->method('getQuery');

    $request = Request::create('/api/organisations', 'GET');
    $controller->getOrganisations($request);
  }

  /**
   * Tests that duplicate org memberships are deduplicated.
   *
   * A user might have multiple memberships pointing to the same org
   * (e.g. different relationship types). The org should appear only once.
   *
   * @covers ::getOrganisations
   */
  public function testDuplicateMembershipsAreDeduplicated(): void {
    $account = $this->createMockUser(['authenticated'], 10);
    $controller = $this->createController($account);

    // Two memberships both pointing to org 100.
    $membership1 = $this->createMockMembership(100);
    $membership2 = $this->createMockMembership(100);

    $membershipQuery = $this->createMockQuery([50, 51]);
    $this->relationshipStorage->method('getQuery')->willReturn($membershipQuery);
    $this->relationshipStorage->method('loadMultiple')
      ->with([50, 51])
      ->willReturn([50 => $membership1, 51 => $membership2]);

    $org1 = $this->createMockOrgGroup(100, 'Org Alpha', 'uuid-alpha');

    $groupQuery = $this->createMockQuery([100]);
    $this->groupStorage->method('getQuery')->willReturn($groupQuery);
    $this->groupStorage->method('loadMultiple')
      ->with([100])
      ->willReturn([100 => $org1]);

    $request = Request::create('/api/organisations', 'GET');
    $response = $controller->getOrganisations($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $data['organisations']);
  }

}
