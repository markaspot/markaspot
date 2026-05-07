<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Controller\DashboardAlertsController;
use Drupal\markaspot_nuxt\Plugin\TenantAlert\BrandingAlert;
use Drupal\markaspot_nuxt\Service\AlertStateStore;
use Drupal\markaspot_nuxt\TenantAlertPluginManager;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the DashboardAlertsController and its supporting service.
 *
 * Covers the alert list/state endpoints, the per-user user.data state
 * store, and the access pattern shared with TenantSettingsController.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Controller\DashboardAlertsController
 */
class DashboardAlertsControllerTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked group storage.
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The mocked membership loader.
   */
  protected GroupMembershipLoaderInterface $membershipLoader;

  /**
   * The mocked hierarchy resolver.
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The mocked current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The mocked user.data service.
   */
  protected UserDataInterface $userData;

  /**
   * The mocked module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The mocked plugin manager.
   */
  protected TenantAlertPluginManager $alertPluginManager;

  /**
   * The alert state store under test (real, not mocked).
   */
  protected AlertStateStore $alertStateStore;

  /**
   * The mocked cache tags invalidator.
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * The controller under test.
   */
  protected DashboardAlertsController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->membershipLoader = $this->createMock(GroupMembershipLoaderInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('id')->willReturn('42');
    $this->currentUser->method('getDisplayName')->willReturn('testuser');
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->userData = $this->createMock(UserDataInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(fn(string $name) => $name === 'markaspot_fastmap');
    $this->alertPluginManager = $this->createMock(TenantAlertPluginManager::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);
    $this->alertStateStore = new AlertStateStore($this->userData, $time);
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);

    // Required for AccessResult cache contexts.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    // Config factory for the JurisdictionIdResolverTrait fallback path.
    $config = $this->createMock(ImmutableConfig::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('group.membership_loader', $this->membershipLoader);
    $container->set('current_user', $this->currentUser);
    $container->set('markaspot_group.hierarchy_resolver', $this->hierarchyResolver);
    $container->set('plugin.manager.markaspot_tenant_alert', $this->alertPluginManager);
    $container->set('markaspot_nuxt.alert_state_store', $this->alertStateStore);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('cache_tags.invalidator', $this->cacheTagsInvalidator);
    $container->set('datetime.time', $time);
    $container->set('module_handler', $this->moduleHandler);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $container->set('logger.factory', $loggerFactory);

    \Drupal::setContainer($container);

    $this->controller = DashboardAlertsController::create($container);
  }

  /**
   * Builds a mock jurisdiction group entity.
   *
   * @param array<string, mixed> $fields
   *   Field name => raw value. Pass NULL for an empty field; absent keys
   *   make hasField() return FALSE.
   * @param int $id
   *   The group ID.
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The mocked group.
   */
  protected function createMockGroup(array $fields = [], int $id = 14): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn('jur');
    $group->method('isDefaultTranslation')->willReturn(TRUE);
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(-1);
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
   * Returns a real BrandingAlert plugin instance bound to the mocked manager.
   *
   * Wires the manager so getDefinitions() advertises the branding plugin and
   * createInstance() returns a working BrandingAlert. Tests still control
   * the group state and user.data layer.
   */
  protected function wireBrandingPlugin(): void {
    $definition = [
      'id' => 'branding',
      'category' => 'setup',
      'severity' => 'info',
      'class' => BrandingAlert::class,
    ];
    $this->alertPluginManager->method('getDefinitions')
      ->willReturn(['branding' => $definition]);
    $this->alertPluginManager->method('createInstance')
      ->with('branding')
      ->willReturnCallback(fn() => new BrandingAlert([], 'branding', $definition, $this->moduleHandler));
  }

  /**
   * Tests the list endpoint surfaces an open branding alert.
   *
   * @covers ::list
   */
  public function testListReturnsBrandingAlertWhenIncomplete(): void {
    $this->wireBrandingPlugin();
    $group = $this->createMockGroup([
      'field_tier' => 'starter',
      'field_nuxt_config' => json_encode(['setup' => ['brandingCompleted' => FALSE]]),
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);
    $this->userData->method('get')->willReturn(NULL);

    $response = $this->controller->list(Request::create('/api/dashboard/alerts/14', 'GET'), '14');

    $this->assertEquals(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $body['alerts']);
    $this->assertSame('branding', $body['alerts'][0]['id']);
    $this->assertSame('setup', $body['alerts'][0]['category']);
    $this->assertFalse($body['alerts'][0]['handled']);
    $this->assertNull($body['alerts'][0]['handled_at']);
    $this->assertSame('/dashboard/settings/branding', $body['alerts'][0]['cta']['path_template']);
    $this->assertSame(['open' => 1, 'handled' => 0, 'total' => 1], $body['summary']);
  }

  /**
   * Tests completed branding state suppresses the alert globally.
   *
   * @covers ::list
   */
  public function testListSkipsBrandingAlertWhenCompleted(): void {
    $this->wireBrandingPlugin();
    $group = $this->createMockGroup([
      'field_tier' => 'starter',
      'field_nuxt_config' => json_encode(['setup' => ['brandingCompleted' => TRUE]]),
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);
    $this->userData->method('get')->willReturn(NULL);

    $response = $this->controller->list(Request::create('/api/dashboard/alerts/14', 'GET'), '14');

    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame([], $body['alerts']);
    $this->assertSame(['open' => 0, 'handled' => 0, 'total' => 0], $body['summary']);
  }

  /**
   * Tests the per-user handled flag from user.data is layered onto the alert.
   *
   * @covers ::list
   */
  public function testListReturnsHandledFlagFromUserData(): void {
    $this->wireBrandingPlugin();
    $group = $this->createMockGroup([
      'field_tier' => 'starter',
      'field_nuxt_config' => json_encode(['setup' => ['brandingCompleted' => FALSE]]),
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->userData->method('get')
      ->willReturnCallback(function (string $module, int $uid, string $name) {
        $this->assertSame('markaspot_nuxt', $module);
        $this->assertSame(42, $uid);
        $this->assertSame('alert_state.branding.14', $name);
        return ['handled' => TRUE, 'handled_at' => 1699999000];
      });

    $response = $this->controller->list(Request::create('/api/dashboard/alerts/14', 'GET'), '14');

    $body = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $body['alerts']);
    $this->assertTrue($body['alerts'][0]['handled']);
    $this->assertSame(1699999000, $body['alerts'][0]['handled_at']);
    $this->assertSame(['open' => 0, 'handled' => 1, 'total' => 1], $body['summary']);
  }

  /**
   * Tests setState writes through to user.data with the correct shape.
   *
   * @covers ::setState
   */
  public function testSetStateWritesUserData(): void {
    $this->wireBrandingPlugin();
    $group = $this->createMockGroup([
      'field_tier' => 'starter',
      'field_nuxt_config' => json_encode(['setup' => ['brandingCompleted' => FALSE]]),
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $this->userData->expects($this->once())
      ->method('set')
      ->with(
        'markaspot_nuxt',
        42,
        'alert_state.branding.14',
        $this->callback(function ($value) {
          return is_array($value)
            && $value['handled'] === TRUE
            && $value['handled_at'] === 1700000000;
        }),
      );
    // After write, the read path inside decorate() observes the new state.
    $this->userData->method('get')
      ->willReturn(['handled' => TRUE, 'handled_at' => 1700000000]);

    $request = Request::create(
      '/api/dashboard/alerts/14/branding/state',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['handled' => TRUE]),
    );
    $response = $this->controller->setState($request, '14', 'branding');

    $this->assertEquals(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('branding', $body['id']);
    $this->assertTrue($body['handled']);
    $this->assertSame(1700000000, $body['handled_at']);
  }

  /**
   * Tests setState invalidates the user-scoped cache tag.
   *
   * @covers ::setState
   */
  public function testSetStateInvalidatesCacheTags(): void {
    $this->wireBrandingPlugin();
    $group = $this->createMockGroup([
      'field_tier' => 'starter',
      'field_nuxt_config' => json_encode(['setup' => ['brandingCompleted' => FALSE]]),
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);
    $this->userData->method('get')->willReturn(NULL);

    $this->cacheTagsInvalidator->expects($this->atLeastOnce())
      ->method('invalidateTags')
      ->with($this->callback(static fn(array $tags) => in_array('user:42', $tags, TRUE)));

    $request = Request::create(
      '/api/dashboard/alerts/14/branding/state',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['handled' => FALSE]),
    );
    $response = $this->controller->setState($request, '14', 'branding');

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests accessCheck() denies a tenant_admin outside their hierarchy.
   *
   * Mirrors TenantSettingsController's denial pattern verbatim so any
   * future drift on the alert routes is caught here.
   *
   * @covers ::accessCheck
   */
  public function testAccessCheckDeniesCrossTenant(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('5');
    $account->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $otherGroup = $this->createMock(GroupInterface::class);
    $otherGroup->method('id')->willReturn('20');
    $otherGroup->method('bundle')->willReturn('jur');

    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroup')->willReturn($otherGroup);

    $this->membershipLoader->method('loadByUser')
      ->with($account, ['jur-tenant_admin'])
      ->willReturn([$membership]);

    $this->hierarchyResolver->method('getDescendantIds')
      ->with(20)
      ->willReturn([20, 21]);

    $result = $this->controller->accessCheck($account, '14');

    $this->assertFalse($result->isAllowed());
  }

}
