<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Psr\Log\LoggerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Controller\TenantSettingsController;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the TenantSettingsController.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Controller\TenantSettingsController
 */
class TenantSettingsControllerTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * Mocked group_relationship storage for GroupMembership::loadByUser().
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupRelationshipStorage;

  /**
   * The mocked chained membership cache backing GroupMembership::loadByUser().
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected CacheBackendInterface $membershipCache;

  /**
   * The mocked stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The mocked file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The mocked file repository.
   *
   * @var \Drupal\file\FileRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * The mocked hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected AccountInterface $currentUser;

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_nuxt\Controller\TenantSettingsController
   */
  protected TenantSettingsController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupRelationshipStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        'group_relationship' => $this->groupRelationshipStorage,
        'file' => $this->createMock(EntityStorageInterface::class),
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->membershipCache = $this->createMock(CacheBackendInterface::class);

    $this->streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->fileRepository = $this->createMock(FileRepositoryInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('getDisplayName')->willReturn('testuser');
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $emailValidator = new EmailValidator();

    $config = $this->createMock(ImmutableConfig::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    // Set up container for Cache::mergeContexts() used by AccessResult.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    // Set up the Drupal container.
    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('stream_wrapper_manager', $this->streamWrapperManager);
    $container->set('file_system', $this->fileSystem);
    $container->set('file.repository', $this->fileRepository);
    $container->set('current_user', $this->currentUser);
    $container->set('markaspot_group.hierarchy_resolver', $this->hierarchyResolver);
    $container->set('email.validator', $emailValidator);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('cache.group_memberships_chained', $this->membershipCache);

    // Logger factory for $this->getLogger() calls in the controller.
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $container->set('logger.factory', $loggerFactory);

    \Drupal::setContainer($container);

    $this->controller = TenantSettingsController::create($container);
  }

  /**
   * Stubs the static GroupMembership::loadByUser() to yield given memberships.
   *
   * AccessCheck() resolves tenant_admin memberships through the group module's
   * static GroupMembership::loadByUser(), which reads the
   * cache.group_memberships_chained backend and the group_relationship storage.
   * Priming a cache hit lets that static API return the supplied test doubles
   * without bootstrapping the full group module.
   *
   * @param array $memberships
   *   The group membership doubles loadByUser() should return.
   */
  private function stubMemberships(array $memberships): void {
    $this->membershipCache->method('get')
      ->willReturn((object) ['data' => $memberships ? [1] : []]);
    $this->groupRelationshipStorage->method('loadMultiple')
      ->willReturn($memberships);
  }

  /**
   * Creates a mock group entity with configurable fields.
   *
   * @param array $fields
   *   Field values keyed by field name.
   * @param int $id
   *   The group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(array $fields = [], int $id = 14): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn('jur');

    $group->method('isDefaultTranslation')->willReturn(TRUE);

    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => array_key_exists($name, $fields));

    $group->method('get')
      ->willReturnCallback(function (string $name) use ($fields) {
        $value = $fields[$name] ?? NULL;
        // @phpcs:disable Drupal.Commenting.DocComment
        return new class ($value) {

          /**
           * The field value.
           *
           * @var mixed
           */
          public $value;

          /**
           * Whether the field is empty.
           *
           * @var bool
           */
          private bool $empty;

          /**
           * Constructs a field item stub.
           */
          public function __construct($value) {
            $this->value = $value;
            $this->empty = ($value === NULL);
          }

          /**
           * Returns whether the field is empty.
           */
          public function isEmpty(): bool {
            return $this->empty;
          }

        };
        // @phpcs:enable
      });

    return $group;
  }

  /**
   * Tests accessCheck() returns forbidden for nonexistent jurisdiction.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckForbiddenForNonexistentJurisdiction(): void {
    $account = $this->createMock(AccountInterface::class);
    // Slug resolution: no group found.
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $result = $this->controller->accessCheck($account, 'nonexistent-slug');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests accessCheck() allows superadmin (uid 1).
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckAllowsSuperAdmin(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('1');
    $account->method('getRoles')->willReturn(['authenticated']);

    $result = $this->controller->accessCheck($account, '14');

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests accessCheck() allows Drupal administrator role.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckAllowsAdministratorRole(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('2');
    $account->method('getRoles')->willReturn(['authenticated', 'administrator']);

    $result = $this->controller->accessCheck($account, '14');

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests accessCheck() denies users without any admin role.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckDeniesRegularUser(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('5');
    $account->method('getRoles')->willReturn(['authenticated']);

    $result = $this->controller->accessCheck($account, '14');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests accessCheck() allows tenant_admin with matching jurisdiction.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckAllowsTenantAdminForOwnJurisdiction(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('3');
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    // The tenant_admin manages group 14.
    $memberGroup = $this->createMock(GroupInterface::class);
    $memberGroup->method('id')->willReturn('14');
    $memberGroup->method('bundle')->willReturn('jur');

    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroup')->willReturn($memberGroup);

    $this->stubMemberships([$membership]);

    // Group 14 descendants include itself.
    $this->hierarchyResolver->method('getDescendantIds')
      ->with(14)
      ->willReturn([14]);

    $result = $this->controller->accessCheck($account, '14');

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests accessCheck() allows tenant_admin for descendant jurisdiction.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckAllowsTenantAdminForDescendant(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('3');
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    // The tenant_admin manages group 10 (parent of 14).
    $parentGroup = $this->createMock(GroupInterface::class);
    $parentGroup->method('id')->willReturn('10');
    $parentGroup->method('bundle')->willReturn('jur');

    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroup')->willReturn($parentGroup);

    $this->stubMemberships([$membership]);

    // Group 10 descendants include 14.
    $this->hierarchyResolver->method('getDescendantIds')
      ->with(10)
      ->willReturn([10, 14, 15]);

    $result = $this->controller->accessCheck($account, '14');

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests accessCheck() denies tenant_admin for unrelated jurisdiction.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckDeniesTenantAdminForUnrelatedJurisdiction(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('3');
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    // The tenant_admin manages group 20 (unrelated to 14).
    $otherGroup = $this->createMock(GroupInterface::class);
    $otherGroup->method('id')->willReturn('20');
    $otherGroup->method('bundle')->willReturn('jur');

    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroup')->willReturn($otherGroup);

    $this->stubMemberships([$membership]);

    // Group 20 descendants do not include 14.
    $this->hierarchyResolver->method('getDescendantIds')
      ->with(20)
      ->willReturn([20, 21]);

    $result = $this->controller->accessCheck($account, '14');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests accessCheck() skips stale tenant_admin memberships on non-jur groups.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckSkipsStaleTenantAdminMembershipWrongBundle(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('3');
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $orgGroup = $this->createMock(GroupInterface::class);
    $orgGroup->method('id')->willReturn('14');
    $orgGroup->method('bundle')->willReturn('org');

    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroup')->willReturn($orgGroup);

    $this->stubMemberships([$membership]);

    $this->hierarchyResolver->expects($this->never())
      ->method('getDescendantIds');

    $result = $this->controller->accessCheck($account, '14');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests getLanguageSettings() returns 404 for unknown jurisdiction.
   *
   * @covers ::getLanguageSettings
   */
  public function testGetLanguageSettingsReturns404(): void {
    $this->groupStorage->method('load')->willReturn(NULL);
    $request = Request::create('/api/tenant/999/languages', 'GET');

    $response = $this->controller->getLanguageSettings($request, '999');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests getLanguageSettings() returns defaults when no config exists.
   *
   * @covers ::getLanguageSettings
   */
  public function testGetLanguageSettingsReturnsDefaults(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/tenant/14/languages', 'GET');
    $response = $this->controller->getLanguageSettings($request, '14');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(14, $data['jurisdiction_id']);
    $this->assertEquals('de', $data['languages']['default']);
    $this->assertEquals(['de'], $data['languages']['available']);
    $this->assertNotEmpty($data['supported_locales']);
  }

  /**
   * Tests getLanguageSettings() returns config from entity.
   *
   * @covers ::getLanguageSettings
   */
  public function testGetLanguageSettingsFromEntity(): void {
    $nuxtConfig = json_encode([
      'languages' => [
        'default' => 'en',
        'available' => ['en', 'de'],
      ],
    ]);
    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtConfig,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/tenant/14/languages', 'GET');
    $response = $this->controller->getLanguageSettings($request, '14');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('en', $data['languages']['default']);
    $this->assertEquals(['en', 'de'], $data['languages']['available']);
  }

  /**
   * Tests updateLanguageSettings() returns 400 for invalid JSON.
   *
   * @covers ::updateLanguageSettings
   */
  public function testUpdateLanguageSettingsInvalidJson(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/languages',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      'not-json'
    );

    $response = $this->controller->updateLanguageSettings($request, '14');

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests updateLanguageSettings() rejects empty available list.
   *
   * @covers ::updateLanguageSettings
   */
  public function testUpdateLanguageSettingsRejectsEmptyAvailable(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/languages',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['available' => [], 'default' => 'de'])
    );

    $response = $this->controller->updateLanguageSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('non-empty array', $data['error']);
  }

  /**
   * Tests updateLanguageSettings() rejects unsupported locale codes.
   *
   * @covers ::updateLanguageSettings
   */
  public function testUpdateLanguageSettingsRejectsUnsupportedLocale(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/languages',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['available' => ['xx'], 'default' => 'xx'])
    );

    $response = $this->controller->updateLanguageSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('Unsupported locale code', $data['error']);
  }

  /**
   * Tests updateLanguageSettings() rejects default not in available.
   *
   * @covers ::updateLanguageSettings
   */
  public function testUpdateLanguageSettingsDefaultMustBeInAvailable(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/languages',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['available' => ['de'], 'default' => 'en'])
    );

    $response = $this->controller->updateLanguageSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('default locale must be present', $data['error']);
  }

  /**
   * Tests getGeneralSettings() returns 404 for unknown jurisdiction.
   *
   * @covers ::getGeneralSettings
   */
  public function testGetGeneralSettingsReturns404(): void {
    $this->groupStorage->method('load')->willReturn(NULL);
    $request = Request::create('/api/tenant/999/general', 'GET');

    $response = $this->controller->getGeneralSettings($request, '999');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests getGeneralSettings() returns field values.
   *
   * @covers ::getGeneralSettings
   */
  public function testGetGeneralSettingsReturnsFieldValues(): void {
    $group = $this->createMockGroup([
      'field_platform_name' => 'Test City',
      'field_jurisdiction_e_mail' => 'info@test.city',
      'field_email_footer' => 'Footer text',
      'field_visibility' => 'public',
      'field_legal_notice' => '<p>Legal</p>',
      'field_privacy_policy' => '<p>Privacy</p>',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/tenant/14/general', 'GET');
    $response = $this->controller->getGeneralSettings($request, '14');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(14, $data['jurisdiction_id']);
    $this->assertEquals('Test City', $data['field_platform_name']);
    $this->assertEquals('info@test.city', $data['field_jurisdiction_e_mail']);
    $this->assertEquals('Footer text', $data['field_email_footer']);
    $this->assertEquals('public', $data['field_visibility']);
    $this->assertEquals('<p>Legal</p>', $data['field_legal_notice']);
    $this->assertEquals('<p>Privacy</p>', $data['field_privacy_policy']);
  }

  /**
   * Tests updateGeneralSettings() rejects invalid JSON body.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsInvalidJson(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      'not-json'
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests updateGeneralSettings() rejects fields not in allowlist.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsRejectsUnknownFields(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_unknown' => 'value'])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('No valid fields provided.', $data['error']);
  }

  /**
   * Tests updateGeneralSettings() rejects platform name with HTML.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsRejectsHtmlInPlatformName(): void {
    $group = $this->createMockGroup([
      'field_platform_name' => 'Old Name',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_platform_name' => '<script>alert(1)</script>'])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('must not contain HTML', $data['error']);
  }

  /**
   * Tests updateGeneralSettings() rejects invalid email.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsRejectsInvalidEmail(): void {
    $group = $this->createMockGroup([
      'field_jurisdiction_e_mail' => 'old@example.com',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_jurisdiction_e_mail' => 'not-an-email'])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('valid email', $data['error']);
  }

  /**
   * Tests updateGeneralSettings() rejects invalid visibility value.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsRejectsInvalidVisibility(): void {
    $group = $this->createMockGroup([
      'field_visibility' => 'public',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_visibility' => 'secret'])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('must be one of', $data['error']);
  }

  /**
   * Tests tenant admins cannot set workspace visibility to blocked.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsTenantAdminCannotBlockWorkspace(): void {
    $this->currentUser->method('id')->willReturn('7');
    $this->currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $group = $this->createMockGroup([
      'field_visibility' => 'public',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_visibility' => 'blocked'])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('platform administrators', $data['error']);
  }

  /**
   * Tests tenant admins cannot unblock a blocked workspace.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsTenantAdminCannotUnblockWorkspace(): void {
    $this->currentUser->method('id')->willReturn('7');
    $this->currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $group = $this->createMockGroup([
      'field_visibility' => 'blocked',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_visibility' => 'public'])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * Tests the blocked visibility value is accepted by field validation.
   *
   * @covers ::updateGeneralSettings
   */
  public function testValidateFieldValueAllowsBlockedVisibility(): void {
    $method = new \ReflectionMethod($this->controller, 'validateFieldValue');
    $method->setAccessible(TRUE);

    $this->assertNull($method->invoke($this->controller, 'field_visibility', 'blocked'));
  }

  /**
   * C-1 regression: tenant PATCH without field_visibility cannot clobber a
   * concurrent admin block.
   *
   * Without the multi-field guard, $group->save() would write the in-memory
   * field_visibility (initial: 'public') back over the freshly-set 'blocked'
   * state — silently unblocking the workspace from a non-admin caller.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsMultiFieldPatchPreservesAdminBlock(): void {
    $this->currentUser->method('id')->willReturn('7');
    $this->currentUser->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    // Initial load: 'public'. Simulates the read before an admin's concurrent
    // block lands.
    $group = $this->createMockGroup([
      'field_visibility' => 'public',
      'field_platform_name' => 'Old name',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    // loadUnchanged returns the post-admin-block fresh state.
    $fresh = $this->createMockGroup([
      'field_visibility' => 'blocked',
      'field_platform_name' => 'Old name',
    ]);
    $this->groupStorage->method('loadUnchanged')->with(14)->willReturn($fresh);

    // Tenant sends a PATCH that does NOT touch field_visibility — only
    // field_platform_name. Pre-fix this would have slipped past both guards
    // and $group->save() would have re-written the public visibility.
    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_platform_name' => 'New name'])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('platform administrators', $data['error']);
  }

  /**
   * Tests updateGeneralSettings() rejects too-long platform name.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsRejectsTooLongPlatformName(): void {
    $group = $this->createMockGroup([
      'field_platform_name' => 'Short',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['field_platform_name' => str_repeat('a', 101)])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('100 characters', $data['error']);
  }

  /**
   * Tests updateGeneralSettings() validates address country code format.
   *
   * @covers ::updateGeneralSettings
   */
  public function testUpdateGeneralSettingsRejectsInvalidCountryCode(): void {
    $group = $this->createMockGroup([
      'field_jurisdiction_address' => NULL,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/general',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'field_jurisdiction_address' => [
          'country_code' => 'germany',
        ],
      ])
    );

    $response = $this->controller->updateGeneralSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('2-letter ISO country code', $data['error']);
  }

  /**
   * Tests getBrandingSettings() returns 404 for unknown jurisdiction.
   *
   * @covers ::getBrandingSettings
   */
  public function testGetBrandingSettingsReturns404(): void {
    $this->groupStorage->method('load')->willReturn(NULL);
    $request = Request::create('/api/tenant/999/branding', 'GET');

    $response = $this->controller->getBrandingSettings($request, '999');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests getBrandingSettings() returns theme config from entity.
   *
   * @covers ::getBrandingSettings
   */
  public function testGetBrandingSettingsReturnsTheme(): void {
    $nuxtConfig = json_encode([
      'theme' => [
        'primary' => 'cyan',
        'secondary' => 'teal',
        'neutral' => 'slate',
      ],
    ]);
    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtConfig,
      'field_custom_css' => ':root { --color: red; }',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/tenant/14/branding', 'GET');
    $response = $this->controller->getBrandingSettings($request, '14');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(14, $data['jurisdiction_id']);
    $this->assertEquals('cyan', $data['theme']['primary']);
    $this->assertEquals('teal', $data['theme']['secondary']);
    $this->assertEquals('slate', $data['theme']['neutral']);
    $this->assertEquals(':root { --color: red; }', $data['custom_css']);
  }

  /**
   * Tests updateBrandingSettings() preserves top-level branding config.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingPreservesPoweredByFlag(): void {
    $storedNuxtConfig = json_encode([
      'branding' => ['hidePoweredBy' => TRUE],
      'theme' => [
        'primary' => 'blue',
        'secondary' => 'teal',
        'neutral' => 'slate',
      ],
    ]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name) => in_array($name, [
        'field_nuxt_config',
        'field_custom_css',
      ], TRUE));
    $group->method('get')
      ->willReturnCallback(static function (string $name) use (&$storedNuxtConfig) {
        $value = $name === 'field_nuxt_config' ? $storedNuxtConfig : '';
        return new class ($value) {

          /**
           * The field value.
           *
           * @var string
           */
          public string $value;

          /**
           * Constructs a field item stub.
           */
          public function __construct(string $value) {
            $this->value = $value;
          }

          /**
           * Returns whether the field is empty.
           */
          public function isEmpty(): bool {
            return $this->value === '';
          }

        };
      });
    $group->method('set')
      ->willReturnCallback(function (string $field, string $value) use (&$storedNuxtConfig, $group) {
        if ($field === 'field_nuxt_config') {
          $storedNuxtConfig = $value;
        }
        return $group;
      });
    $group->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['theme' => ['primary' => 'cyan']])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');
    $updatedConfig = json_decode($storedNuxtConfig, TRUE);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($updatedConfig['branding']['hidePoweredBy']);
    $this->assertEquals('cyan', $updatedConfig['theme']['primary']);
  }

  /**
   * Tests updateBrandingSettings() rejects invalid color value.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingRejectsInvalidColor(): void {
    $group = $this->createMockGroup([
      'field_nuxt_config' => '{}',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['theme' => ['primary' => 'not-a-color']])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('theme.primary', $data['error']);
  }

  /**
   * Tests updateBrandingSettings() accepts valid Tailwind palette.
   *
   * @covers ::updateBrandingSettings
   *
   * @dataProvider validTailwindColorProvider
   */
  public function testUpdateBrandingAcceptsTailwindPalette(string $color): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $fieldItem->method('isEmpty')->willReturn(TRUE);
    $fieldItem->__set('value', '{}');
    $group->method('get')->willReturn($fieldItem);
    $group->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['theme' => ['primary' => $color]])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    // Should not return 422.
    $this->assertNotEquals(422, $response->getStatusCode());
  }

  /**
   * Provides valid Tailwind color palette names.
   *
   * @return array
   *   Test cases.
   */
  public static function validTailwindColorProvider(): array {
    return [
      'blue' => ['blue'],
      'cyan' => ['cyan'],
      'rose' => ['rose'],
      'slate' => ['slate'],
      'emerald' => ['emerald'],
    ];
  }

  /**
   * Tests updateBrandingSettings() accepts valid HEX colors.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingAcceptsHexColor(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')->willReturn(TRUE);

    $fieldItem = $this->createMock(FieldItemListInterface::class);
    $fieldItem->method('isEmpty')->willReturn(TRUE);
    $fieldItem->__set('value', '{}');
    $group->method('get')->willReturn($fieldItem);
    $group->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['theme' => ['primary' => '#FF5733']])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertNotEquals(422, $response->getStatusCode());
  }

  /**
   * Tests updateBrandingSettings() rejects CSS with @import.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingRejectsCssImport(): void {
    $group = $this->createMockGroup([
      'field_nuxt_config' => '{}',
      'field_custom_css' => '',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['custom_css' => '@import url("evil.css");'])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('@import', $data['error']);
  }

  /**
   * Tests updateBrandingSettings() rejects CSS with javascript: URI.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingRejectsCssJavascriptUri(): void {
    $group = $this->createMockGroup([
      'field_nuxt_config' => '{}',
      'field_custom_css' => '',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['custom_css' => 'background: url(javascript:alert(1))'])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
  }

  /**
   * Tests updateBrandingSettings() rejects CSS with expression().
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingRejectsCssExpression(): void {
    $group = $this->createMockGroup([
      'field_nuxt_config' => '{}',
      'field_custom_css' => '',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['custom_css' => 'width: expression(document.body.clientWidth)'])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
  }

  /**
   * Tests updateBrandingSettings() rejects CSS with script tags.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingRejectsCssScriptTag(): void {
    $group = $this->createMockGroup([
      'field_nuxt_config' => '{}',
      'field_custom_css' => '',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['custom_css' => '<script>alert(1)</script>'])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
  }

  /**
   * Tests updateBrandingSettings() rejects CSS with external URLs.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingRejectsCssExternalUrl(): void {
    $group = $this->createMockGroup([
      'field_nuxt_config' => '{}',
      'field_custom_css' => '',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['custom_css' => 'background: url("https://evil.com/tracker.gif")'])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertEquals(422, $response->getStatusCode());
  }

  /**
   * Tests updateBrandingSettings() rejects empty request.
   *
   * @covers ::updateBrandingSettings
   */
  public function testUpdateBrandingRejectsEmptyRequest(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create(
      '/api/tenant/14/branding',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['other_key' => 'value'])
    );

    $response = $this->controller->updateBrandingSettings($request, '14');

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('No valid fields', $data['error']);
  }

  /**
   * Tests markBrandingSetupCompleted() persists the flag and preserves siblings.
   *
   * @covers ::markBrandingSetupCompleted
   */
  public function testMarkBrandingSetupCompleted(): void {
    $storedNuxtConfig = json_encode([
      'branding' => ['hidePoweredBy' => TRUE],
      'theme' => ['primary' => 'blue'],
    ]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');
    $group->method('bundle')->willReturn('jur');
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name) => $name === 'field_nuxt_config');
    $group->method('get')
      ->willReturnCallback(static function (string $name) use (&$storedNuxtConfig) {
        $value = $name === 'field_nuxt_config' ? $storedNuxtConfig : '';
        return new class ($value) {

          /**
           * The field value.
           *
           * @var string
           */
          public string $value;

          /**
           * Constructs a field item stub.
           */
          public function __construct(string $value) {
            $this->value = $value;
          }

          /**
           * Returns whether the field is empty.
           */
          public function isEmpty(): bool {
            return $this->value === '';
          }

        };
      });
    $group->method('set')
      ->willReturnCallback(function (string $field, string $value) use (&$storedNuxtConfig, $group) {
        if ($field === 'field_nuxt_config') {
          $storedNuxtConfig = $value;
        }
        return $group;
      });
    $group->method('save');

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $response = $this->controller->markBrandingSetupCompleted('14');
    $updatedConfig = json_decode($storedNuxtConfig, TRUE);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($updatedConfig['setup']['brandingCompleted']);
    $this->assertTrue($updatedConfig['branding']['hidePoweredBy']);
    $this->assertEquals('blue', $updatedConfig['theme']['primary']);
    $this->assertSame(14, $body['jurisdiction_id']);
    $this->assertTrue($body['setup']['brandingCompleted']);
  }

  /**
   * Tests markBrandingSetupCompleted() returns 404 for unknown jurisdiction.
   *
   * @covers ::markBrandingSetupCompleted
   */
  public function testMarkBrandingSetupCompletedReturns404(): void {
    $this->groupStorage->method('load')->willReturn(NULL);
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $response = $this->controller->markBrandingSetupCompleted('does-not-exist');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests that SUPPORTED_LOCALES constant is complete.
   *
   * @covers ::getLanguageSettings
   */
  public function testSupportedLocalesConstant(): void {
    $locales = TenantSettingsController::SUPPORTED_LOCALES;
    $this->assertArrayHasKey('de', $locales);
    $this->assertArrayHasKey('en', $locales);
    $this->assertArrayHasKey('cs', $locales);
    $this->assertArrayHasKey('fr', $locales);
    $this->assertArrayHasKey('ar', $locales);
    $this->assertNotEmpty($locales);
  }

  /**
   * Tests that the config schema language enum matches SUPPORTED_LOCALES.
   *
   * @coversNothing
   */
  public function testLanguageSchemaMatchesSupportedLocales(): void {
    $schemaPath = dirname(__DIR__, 3) . '/schema/nuxt_config.schema.json';
    $this->assertFileExists($schemaPath);

    $schema = json_decode((string) file_get_contents($schemaPath), TRUE);
    $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

    $expected = array_keys(TenantSettingsController::SUPPORTED_LOCALES);
    sort($expected);

    $available = $schema['properties']['languages']['properties']['available']['items']['enum'] ?? NULL;
    $default = $schema['properties']['languages']['properties']['default']['enum'] ?? NULL;
    $this->assertIsArray($available);
    $this->assertIsArray($default);
    sort($available);
    sort($default);

    $this->assertSame($expected, $available);
    $this->assertSame($expected, $default);
  }

  /**
   * Tests getFeatureSettings() normalises object-shaped feature flags.
   *
   * @covers ::getFeatureSettings
   */
  public function testGetFeatureSettingsNormalisesObjectFeatures(): void {
    $nuxtConfig = json_encode([
      'features' => [
        'pwaInstallPrompt' => ['enabled' => FALSE],
        'formFirst' => ['enabled' => FALSE],
        'dashboard' => ['enabled' => TRUE],
        'aiProcessing' => ['enabled' => TRUE],
        'piiRedaction' => ['enabled' => FALSE],
      ],
    ]);
    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtConfig,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/tenant/14/features', 'GET');
    $response = $this->controller->getFeatureSettings($request, '14');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['features']['pwaInstallPrompt']);
    $this->assertFalse($data['features']['formFirst']);
    $this->assertTrue($data['features']['dashboard']);
    $this->assertTrue($data['features']['aiProcessing']);
    $this->assertFalse($data['features']['piiRedaction']);
  }

  /**
   * Tests that LOCALE_ISO_CODES has entries for all supported locales.
   *
   * @covers ::getLanguageSettings
   */
  public function testLocaleIsoCodesMatchSupported(): void {
    $supported = array_keys(TenantSettingsController::SUPPORTED_LOCALES);
    $isoCodes = array_keys(TenantSettingsController::LOCALE_ISO_CODES);

    foreach ($supported as $code) {
      $this->assertContains(
        $code,
        $isoCodes,
        "LOCALE_ISO_CODES is missing entry for supported locale: $code"
      );
    }
  }

  /**
   * Tests that VALID_TAILWIND_PALETTES contains expected palettes.
   *
   * @covers ::updateBrandingSettings
   */
  public function testValidTailwindPalettesConstant(): void {
    $palettes = TenantSettingsController::VALID_TAILWIND_PALETTES;
    $this->assertContains('blue', $palettes);
    $this->assertContains('red', $palettes);
    $this->assertContains('green', $palettes);
    $this->assertContains('slate', $palettes);
    // Tailwind v4.2+ neutrals.
    $this->assertContains('mauve', $palettes);
    $this->assertContains('olive', $palettes);
    $this->assertContains('mist', $palettes);
    $this->assertContains('taupe', $palettes);
    $this->assertNotContains('rainbow', $palettes);
  }

  /**
   * Tests deleteLogo() returns 404 for unknown jurisdiction.
   *
   * @covers ::deleteLogo
   */
  public function testDeleteLogoReturns404(): void {
    $this->groupStorage->method('load')->willReturn(NULL);
    $request = Request::create('/api/tenant/999/logo?variant=both', 'DELETE');

    $response = $this->controller->deleteLogo($request, '999');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests deleteLogo() rejects invalid variant parameter.
   *
   * @covers ::deleteLogo
   */
  public function testDeleteLogoRejectsInvalidVariant(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/tenant/14/logo?variant=invalid', 'DELETE');
    $response = $this->controller->deleteLogo($request, '14');

    $this->assertEquals(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertStringContainsString('variant', $data['error']);
  }

  /**
   * Tests deleteLogo() rejects missing variant parameter.
   *
   * @covers ::deleteLogo
   */
  public function testDeleteLogoRejectsMissingVariant(): void {
    $group = $this->createMockGroup([]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/tenant/14/logo', 'DELETE');
    $response = $this->controller->deleteLogo($request, '14');

    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests saveLogoPngFallback() writes a sibling public PNG.
   *
   * @covers ::saveLogoPngFallback
   */
  public function testSaveLogoPngFallbackWritesSiblingPublicPng(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'logo-fallback-');
    $this->assertIsString($tmp);
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    file_put_contents($tmp, base64_decode($png, TRUE));
    $uploaded = new UploadedFile($tmp, 'logo.png', 'image/png', NULL, TRUE);

    $this->fileSystem->expects($this->once())
      ->method('saveData')
      ->with(
        $this->isType('string'),
        'public://jurisdictions/14/logos/logo.png',
        FileExists::Replace,
      )
      ->willReturn('public://jurisdictions/14/logos/logo.png');
    $method = new \ReflectionMethod($this->controller, 'saveLogoPngFallback');
    $result = $method->invoke(
      $this->controller,
      $uploaded,
      'public://jurisdictions/14/logos',
      'logo.svg',
      'logo_light',
    );

    $this->assertTrue($result['success']);
    $this->assertSame('public://jurisdictions/14/logos/logo.png', $result['uri']);
    $this->assertSame('/sites/default/files/jurisdictions/14/logos/logo.png', $result['url']);
    unlink($tmp);
  }

  /**
   * Tests saveLogoPngFallback() rejects files with spoofed PNG metadata.
   *
   * @covers ::saveLogoPngFallback
   */
  public function testSaveLogoPngFallbackRejectsInvalidPngData(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'logo-fallback-');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'not a png');
    $uploaded = new UploadedFile($tmp, 'logo.png', 'image/png', NULL, TRUE);

    $this->fileSystem->expects($this->never())
      ->method('saveData');

    $method = new \ReflectionMethod($this->controller, 'saveLogoPngFallback');
    $result = $method->invoke(
      $this->controller,
      $uploaded,
      'public://jurisdictions/14/logos',
      'logo.svg',
      'logo_light',
    );

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid PNG fallback for logo_light.', $result['error']);
    unlink($tmp);
  }

  /**
   * Tests sanitizeSvgLogoData() rejects non-SVG markup.
   *
   * @covers ::sanitizeSvgLogoData
   */
  public function testSanitizeSvgLogoDataRejectsNonSvgMarkup(): void {
    $method = new \ReflectionMethod($this->controller, 'sanitizeSvgLogoData');
    $result = $method->invoke($this->controller, '<script>alert(1)</script>', 'logo_light');

    $this->assertNull($result);
  }

}
