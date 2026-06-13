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
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the MarkASpotSettingsController.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController
 */
class MarkASpotSettingsControllerTest extends UnitTestCase {

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
   * The mocked taxonomy term storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $termStorage;

  /**
   * The mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_nuxt\Controller\MarkASpotSettingsController
   */
  protected MarkASpotSettingsController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Build nuxt config.
    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('isNew')->willReturn(FALSE);
    $nuxtConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'mapbox_style' => 'https://tiles.example.com/style.json',
        'mapbox_style_dark' => 'https://tiles.example.com/dark.json',
        'zoom_initial' => 13,
        'center_lat' => 50.9,
        'center_lng' => 6.9,
        'geocoding_country' => 'DE',
        'geocoding_region' => '',
        default => NULL,
      });

    // Build open311 config.
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
    $this->termStorage = $this->createMock(EntityStorageInterface::class);
    $this->termStorage->method('loadByProperties')->willReturn([]);
    $this->termStorage->method('loadMultiple')->willReturn([]);

    $groupTypeStorage = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage->method('load')->willReturn(NULL);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'group' => $this->groupStorage,
        'taxonomy_term' => $this->termStorage,
        'group_type' => $groupTypeStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->hierarchyResolver->method('getRootJurisdictionId')
      ->willReturnCallback(fn(int $id) => $id);

    // Module handler: reports no modules installed.
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->moduleHandler->method('moduleExists')->willReturn(FALSE);
    $this->moduleHandler->method('alter');

    // Language manager.
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    // Set up container for CacheableJsonResponse cache contexts.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    // Set up the Drupal container.
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('stream_wrapper_manager', $this->streamWrapperManager);
    $container->set('markaspot_group.hierarchy_resolver', $this->hierarchyResolver);
    $container->set('module_handler', $this->moduleHandler);
    $container->set('language_manager', $languageManager);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $this->controller = new MarkASpotSettingsController(
      $this->entityTypeManager,
      $this->configFactory,
      $this->streamWrapperManager,
      $this->hierarchyResolver,
    );
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
    $group->method('label')->willReturn('Test Jurisdiction');
    $group->method('isPublished')->willReturn(TRUE);
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $group->method('getCacheContexts')->willReturn([]);

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
   * Tests getMarkASpotSettings() returns base config without jurisdiction.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsReturnsBaseConfig(): void {
    // No jurisdiction param, default group query returns empty.
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $this->groupStorage->method('getQuery')->willReturn($query);

    $request = Request::create('/api/mark-a-spot-settings', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $this->assertEquals(200, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('https://tiles.example.com/style.json', $data['mapbox_style']);
    $this->assertEquals(13, $data['zoom_initial']);
    $this->assertEquals(50.9, $data['center_lat']);
    $this->assertEquals(6.9, $data['center_lng']);
    $this->assertEquals('DE', $data['geocoding_country']);
    $this->assertContains(
      'config:markaspot_sso.settings',
      $response->getCacheableMetadata()->getCacheTags()
    );
  }

  /**
   * Tests getMarkASpotSettings() returns 404 for missing config.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsReturns404ForMissingConfig(): void {
    $newConfig = $this->createMock(ImmutableConfig::class);
    $newConfig->method('isNew')->willReturn(TRUE);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_nuxt.settings' => $newConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $controller = new MarkASpotSettingsController(
      $this->entityTypeManager,
      $configFactory,
      $this->streamWrapperManager,
      $this->hierarchyResolver,
    );

    $request = Request::create('/api/mark-a-spot-settings', 'GET');
    $response = $controller->getMarkASpotSettings($request);

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests getMarkASpotSettings() returns 404 for unknown jurisdiction param.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsReturns404ForUnknownJurisdiction(): void {
    $this->groupStorage->method('load')->willReturn(NULL);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=999', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests getMarkASpotSettings() returns 404 for unpublished jurisdiction.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsReturns404ForUnpublishedJurisdiction(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('isPublished')->willReturn(FALSE);

    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Tests getMarkASpotSettings() fails closed for invalid hierarchies.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsReturns409ForInvalidJurisdictionHierarchy(): void {
    $group = $this->createMockGroup([], 14);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchyResolver->expects($this->once())
      ->method('getRootJurisdictionId')
      ->with(14)
      ->willReturn(NULL);

    $controller = new MarkASpotSettingsController(
      $this->entityTypeManager,
      $this->configFactory,
      $this->streamWrapperManager,
      $hierarchyResolver,
    );

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $controller->getMarkASpotSettings($request);

    $this->assertEquals(409, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'Jurisdiction hierarchy invalid'],
      json_decode($response->getContent(), TRUE)
    );
  }

  /**
   * Tests getMarkASpotSettings() merges jurisdiction config correctly.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsMergesJurisdictionConfig(): void {
    // The facilities key intentionally stays in field_nuxt_config to assert
    // that MarkASpotSettingsController no longer propagates it. Facility
    // payloads are delivered exclusively by markaspot_facility via the
    // settings alter hook (covered by FacilityManagerTest).
    $nuxtJson = json_encode([
      'client' => ['name' => 'Stadt Bonn', 'shortName' => 'Bonn'],
      'branding' => ['hidePoweredBy' => TRUE],
      'theme' => ['primary' => 'cyan', 'secondary' => 'teal'],
      'features' => ['voting' => TRUE],
      'facilities' => [
        'enabled' => TRUE,
        'items' => [
          [
            'id' => 'stadsloket-centrum',
            'label' => 'Stadsloket Centrum',
            'lat' => 52.3676842,
            'lng' => 4.9002256,
          ],
        ],
      ],
      'map' => [
        'center' => ['lat' => 50.73, 'lng' => 7.1],
        'zoomInitial' => 14,
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
      'field_slug' => 'bonn',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    // Jurisdiction info.
    $this->assertEquals(14, $data['jurisdiction']['id']);
    $this->assertEquals('Test Jurisdiction', $data['jurisdiction']['name']);
    $this->assertEquals('bonn', $data['jurisdiction']['slug']);

    // Merged config keys.
    $this->assertEquals('Stadt Bonn', $data['client']['name']);
    $this->assertTrue($data['branding']['hidePoweredBy']);
    $this->assertEquals('cyan', $data['theme']['primary']);
    $this->assertTrue($data['features']['voting']);

    // Facilities MUST NOT leak through from field_nuxt_config. The payload
    // is owned by markaspot_facility and delivered via the settings alter
    // hook, which is not invoked in this unit context.
    $this->assertArrayNotHasKey('facilities', $data);

    // Map center synced to top-level.
    $this->assertEquals(50.73, $data['center_lat']);
    $this->assertEquals(7.1, $data['center_lng']);
    $this->assertEquals(14, $data['zoom_initial']);
  }

  /**
   * Tests custom WMS layers are enabled for operator-managed tenants.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testCustomWmsLayersEnabledWithoutTierField(): void {
    $nuxtJson = json_encode([
      'features' => [
        'map' => [
          'wmsLayers' => [
            [
              'title' => 'NKF Objekte',
              'layerName' => 'v_od_staedtische_liegenschaften_p_27315',
              'url' => 'https://gdi.bonn.de/geoserver/nkf_objektverwaltung/wms',
            ],
          ],
        ],
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['features']['customWmsLayers']);
  }

  /**
   * Tests custom WMS layers remain tier-gated when field_tier exists.
   *
   * @covers ::getMarkASpotSettings
   *
   * @dataProvider customWmsLayerTierProvider
   */
  public function testCustomWmsLayersRespectTierField(string $tier, bool $expected): void {
    $nuxtJson = json_encode([
      'features' => [
        'map' => [
          'wmsLayers' => [
            [
              'title' => 'NKF Objekte',
              'layerName' => 'v_od_staedtische_liegenschaften_p_27315',
              'url' => 'https://gdi.bonn.de/geoserver/nkf_objektverwaltung/wms',
            ],
          ],
        ],
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
      'field_tier' => $tier,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame($expected, $data['features']['customWmsLayers']);
  }

  /**
   * Data provider for tier-based custom WMS access.
   *
   * @return array<string, array{string, bool}>
   *   Test cases.
   */
  public static function customWmsLayerTierProvider(): array {
    return [
      'starter' => ['starter', FALSE],
      'pro' => ['pro', TRUE],
      'heart' => ['heart', TRUE],
    ];
  }

  /**
   * Tests the per-layer visibility property survives the config merge.
   *
   * The wmsLayers array is copied wholesale as part of the 'map' key, so a
   * new 'visibility' property on a layer item must pass through untouched.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testWmsLayerVisibilityPropertySurvivesMerge(): void {
    $nuxtJson = json_encode([
      'map' => [
        'wmsLayers' => [
          [
            'id' => 'bike-paths',
            'title' => 'Bike Paths',
            'layerName' => 'cycle_network',
            'visibility' => 'public',
          ],
          [
            'id' => 'tree-cadastre',
            'title' => 'Tree Cadastre',
            'layerName' => 'tree_cadastre',
            'visibility' => 'authenticated',
          ],
          [
            'id' => 'staff-overlay',
            'title' => 'Staff Overlay',
            'layerName' => 'internal_assets',
            'visibility' => 'staff',
          ],
        ],
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $layers = $data['map']['wmsLayers'];
    $this->assertCount(3, $layers);
    $this->assertSame('public', $layers[0]['visibility']);
    $this->assertSame('authenticated', $layers[1]['visibility']);
    $this->assertSame('staff', $layers[2]['visibility']);

    // The pre-existing layer properties must remain intact alongside it.
    $this->assertSame('bike-paths', $layers[0]['id']);
    $this->assertSame('cycle_network', $layers[0]['layerName']);
  }

  /**
   * Tests map center parsing in array format [lng, lat].
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsParsesMapCenterArrayFormat(): void {
    $nuxtJson = json_encode([
      'map' => [
        'center' => [7.1, 50.73],
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    // Array format is [lng, lat] (GeoJSON convention).
    $this->assertEquals(50.73, $data['center_lat']);
    $this->assertEquals(7.1, $data['center_lng']);
  }

  /**
   * Tests map center parsing with separate centerLat/centerLng keys.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsParsesMapCenterSeparateKeys(): void {
    $nuxtJson = json_encode([
      'map' => [
        'centerLat' => 52.52,
        'centerLng' => 13.405,
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(52.52, $data['center_lat']);
    $this->assertEquals(13.405, $data['center_lng']);
  }

  /**
   * Tests that feature flags are forced FALSE when modules are not installed.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testFeatureFlagsForcedFalseWithoutModules(): void {
    // moduleExists() always returns FALSE in our setUp().
    $nuxtJson = json_encode([
      'features' => [
        'aiAnalysis' => TRUE,
        'aiProcessing' => TRUE,
        'piiRedaction' => TRUE,
        'statistics' => TRUE,
        'dashboard' => TRUE,
        'operationsDashboard' => TRUE,
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    // These should be forced FALSE because the modules are not installed.
    $this->assertFalse($data['features']['aiAnalysis']);
    $this->assertFalse($data['features']['aiProcessing']);
    $this->assertFalse($data['features']['piiRedaction']);
    $this->assertFalse($data['features']['statistics']);
    $this->assertFalse($data['features']['dashboard']);
    $this->assertFalse($data['features']['operationsDashboard']);
  }

  /**
   * Tests that AI feature flags are exposed as booleans.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testAiFeatureFlagsAreNormalizedToBooleans(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module): bool => in_array($module, ['markaspot_ai', 'markaspot_dashboard'], TRUE));
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $nuxtJson = json_encode([
      'features' => [
        'aiProcessing' => ['enabled' => TRUE],
        'piiRedaction' => ['enabled' => FALSE],
        'operationsDashboard' => ['enabled' => TRUE],
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(TRUE, $data['features']['aiProcessing']);
    $this->assertSame(FALSE, $data['features']['piiRedaction']);
    $this->assertSame(TRUE, $data['features']['operationsDashboard']);
  }

  /**
   * Tests operations dashboard is tier-gated in public settings.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testOperationsDashboardForcedFalseForStarterTier(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module): bool => $module === 'markaspot_dashboard');
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $nuxtJson = json_encode([
      'features' => [
        'dashboard' => TRUE,
        'operationsDashboard' => TRUE,
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
      'field_tier' => 'starter',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(TRUE, $data['features']['dashboard']);
    $this->assertSame(FALSE, $data['features']['operationsDashboard']);
  }

  /**
   * Tests operations dashboard is fail-closed for FastMap demo workspaces.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testOperationsDashboardForcedFalseForEmptyFastMapTier(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module): bool => $module === 'markaspot_dashboard');
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $nuxtJson = json_encode([
      'features' => [
        'dashboard' => TRUE,
        'operationsDashboard' => TRUE,
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
      'field_tier' => NULL,
      'field_expiry_date' => '2026-06-20',
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(TRUE, $data['features']['dashboard']);
    $this->assertSame(FALSE, $data['features']['operationsDashboard']);
  }

  /**
   * Tests geocoding settings from jurisdiction features.geocoding override.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGeocodingSettingsFromJurisdiction(): void {
    $nuxtJson = json_encode([
      'features' => [
        'geocoding' => [
          'country' => 'NL',
          'region' => 'north-holland',
        ],
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('NL', $data['geocoding_country']);
    $this->assertEquals('north-holland', $data['geocoding_region']);
  }

  /**
   * Tests getMarkASpotSettings() loads default jurisdiction when no param.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testGetSettingsLoadsDefaultJurisdiction(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([14]);

    $this->groupStorage->method('getQuery')->willReturn($query);

    $group = $this->createMockGroup([
      'field_nuxt_config' => json_encode(['client' => ['name' => 'Default']]),
      'field_slug' => 'default',
    ]);
    $this->groupStorage->method('load')
      ->with(14)
      ->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Default', $data['client']['name']);
    $this->assertEquals(14, $data['jurisdiction']['id']);
  }

  /**
   * Tests map style sync from jurisdiction config.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testMapStyleSyncFromJurisdiction(): void {
    $nuxtJson = json_encode([
      'map' => [
        'style' => 'https://custom-tiles.example.com/style.json',
        'styleDark' => 'https://custom-tiles.example.com/dark.json',
      ],
    ]);

    $group = $this->createMockGroup([
      'field_nuxt_config' => $nuxtJson,
    ]);
    $this->groupStorage->method('load')->with(14)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=14', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('https://custom-tiles.example.com/style.json', $data['mapbox_style']);
    $this->assertEquals('https://custom-tiles.example.com/dark.json', $data['mapbox_style_dark']);
  }

  /**
   * Management form-mode settings require staff access.
   *
   * @covers ::getFormModeSettings
   */
  public function testManagementFormSettingsRequireStaffAccess(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/MarkASpotSettingsController.php');
    $this->assertIsString($source);
    $this->assertStringContainsString("\$form_mode === 'management'", $source);
    $this->assertStringContainsString('currentUserCanAccessManagementFormSettings', $source);
    $this->assertStringContainsString('AccessDeniedHttpException', $source);
    $this->assertStringContainsString("\$cache_metadata->addCacheContexts(['user.permissions', 'user.roles'])", $source);
    $this->assertStringContainsString('edit any service_request content', $source);
  }

  /**
   * Anonymous users cannot access management form-mode settings.
   *
   * @covers ::getFormModeSettings
   */
  public function testManagementFormSettingsRejectsAnonymousUsers(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);
    $account->method('getRoles')->willReturn(['anonymous']);
    \Drupal::getContainer()->set('current_user', $account);

    $this->expectException(AccessDeniedHttpException::class);
    $this->controller->getFormModeSettings('node', 'service_request', 'management');
  }

  /**
   * Edit-own node permissions do not expose management form-mode settings.
   *
   * @covers ::getFormModeSettings
   */
  public function testManagementFormSettingsRejectsEditOwnOnlyUsers(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn(['authenticated']);
    $account->method('hasPermission')
      ->willReturnCallback(fn(string $permission): bool => $permission === 'edit own service_request content');
    \Drupal::getContainer()->set('current_user', $account);

    $this->expectException(AccessDeniedHttpException::class);
    $this->controller->getFormModeSettings('node', 'service_request', 'management');
  }

  /**
   * Management form-mode settings vary caches by permissions.
   *
   * @covers ::getFormModeSettings
   */
  public function testManagementFormSettingsCacheVaryByPermissions(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn(['moderator']);
    \Drupal::getContainer()->set('current_user', $account);

    $response = $this->controller->getFormModeSettings('node', 'service_request', 'management');

    $this->assertSame(404, $response->getStatusCode());
    $this->assertContains('user.permissions', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('user.roles', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Tests that aiDuplicates is FALSE when markaspot_ai is absent.
   *
   * Vision-only tenant: markaspot_vision is installed, markaspot_ai is not.
   * The backend must emit features.aiDuplicates = false so the frontend
   * skips the Duplicate Review nav, duplicates page, and SimilarRequestsCard.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testAiDuplicatesIsFalseWhenMarkaSpotAiAbsent(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(fn(string $module) => match ($module) {
        // Vision enabled for photo/AI analysis, AI text not installed.
        'markaspot_vision' => TRUE,
        'markaspot_ai' => FALSE,
        default => FALSE,
      });
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $group = $this->createMockGroup([
      'field_nuxt_config' => json_encode([
        'features' => ['aiAnalysis' => TRUE],
      ]),
    ], 1);
    $this->groupStorage->method('load')->with(1)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=1', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $this->assertFalse(
      $data['features']['aiDuplicates'] ?? TRUE,
      'aiDuplicates must be false when markaspot_ai is not installed'
    );
    // Vision-based flags must still reflect the module being present.
    $this->assertTrue(
      $data['features']['aiAnalysis'] ?? FALSE,
      'aiAnalysis must still be true when markaspot_vision is installed'
    );
  }

  /**
   * Creates a config factory with markaspot_ai.settings duplicate_detection.
   *
   * Used by tests that need markaspot_ai installed and enabled.
   *
   * @param bool $duplicateDetectionEnabled
   *   Whether duplicate_detection.enabled is TRUE.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   *   A config factory mock.
   */
  protected function createConfigFactoryWithAiSettings(bool $duplicateDetectionEnabled): ConfigFactoryInterface {
    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('isNew')->willReturn(FALSE);
    $nuxtConfig->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'mapbox_style' => 'https://tiles.example.com/style.json',
        'mapbox_style_dark' => 'https://tiles.example.com/dark.json',
        'zoom_initial' => 13,
        'center_lat' => 50.9,
        'center_lng' => 6.9,
        'geocoding_country' => 'DE',
        'geocoding_region' => '',
        default => NULL,
      });

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'jurisdiction_group_type' => 'jur',
        'organisation_group_type' => 'org',
        default => NULL,
      });

    $aiSettings = $this->createMock(ImmutableConfig::class);
    $aiSettings->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'duplicate_detection.enabled' => $duplicateDetectionEnabled,
        default => NULL,
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'markaspot_nuxt.settings' => $nuxtConfig,
        'markaspot_open311.settings' => $open311Config,
        'markaspot_ai.settings' => $aiSettings,
        default => $this->createMock(ImmutableConfig::class),
      });

    return $factory;
  }

  /**
   * Tests that aiDuplicates is TRUE when the tenant and global config allow it.
   *
   * Full-AI tenant: both markaspot_vision and markaspot_ai are installed, and
   * duplicate_detection.enabled = true in markaspot_ai.settings.
   * The backend must emit features.aiDuplicates = true (default-on).
   *
   * @covers ::getMarkASpotSettings
   */
  public function testAiDuplicatesIsTrueWhenMarkaSpotAiPresent(): void {
    // Inject a configFactory that returns duplicate_detection.enabled = TRUE
    // for markaspot_ai.settings by replacing the property on the controller.
    $configFactory = $this->createConfigFactoryWithAiSettings(TRUE);
    (new \ReflectionProperty($this->controller, 'configFactory'))
      ->setValue($this->controller, $configFactory);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(fn(string $module) => match ($module) {
        'markaspot_vision' => TRUE,
        'markaspot_ai' => TRUE,
        default => FALSE,
      });
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $group = $this->createMockGroup([
      'field_nuxt_config' => json_encode([
        'features' => ['aiAnalysis' => TRUE, 'aiProcessing' => TRUE],
      ]),
    ], 1);
    $this->groupStorage->method('load')->with(1)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=1', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $this->assertTrue(
      $data['features']['aiDuplicates'] ?? FALSE,
      'aiDuplicates must be true when markaspot_ai is installed and duplicate_detection enabled'
    );
  }

  /**
   * Tests aiDuplicates is FALSE when tenant AI processing is disabled.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testAiDuplicatesIsFalseWhenAiProcessingDisabled(): void {
    $configFactory = $this->createConfigFactoryWithAiSettings(TRUE);
    (new \ReflectionProperty($this->controller, 'configFactory'))
      ->setValue($this->controller, $configFactory);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(fn(string $module) => match ($module) {
        'markaspot_ai' => TRUE,
        default => FALSE,
      });
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $group = $this->createMockGroup([
      'field_nuxt_config' => json_encode([
        'features' => ['aiProcessing' => FALSE],
      ]),
    ], 1);
    $this->groupStorage->method('load')->with(1)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=1', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $this->assertFalse(
      $data['features']['aiDuplicates'] ?? TRUE,
      'aiDuplicates must be false when tenant aiProcessing is false'
    );
  }

  /**
   * Tests aiDuplicates is FALSE when markaspot_ai is installed but disabled.
   *
   * Module installed but duplicate_detection.enabled = false (the install
   * default). The backend must emit false so the UI stays hidden.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testAiDuplicatesIsFalseWhenDuplicateDetectionDisabled(): void {
    // duplicate_detection.enabled = false is the install default; the default
    // configFactory mock returns NULL for 'markaspot_ai.settings' which casts
    // to false — this test documents the intent explicitly.
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(fn(string $module) => match ($module) {
        'markaspot_ai' => TRUE,
        default => FALSE,
      });
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $group = $this->createMockGroup([
      'field_nuxt_config' => json_encode([
        'features' => ['aiProcessing' => TRUE],
      ]),
    ], 1);
    $this->groupStorage->method('load')->with(1)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=1', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $this->assertFalse(
      $data['features']['aiDuplicates'] ?? TRUE,
      'aiDuplicates must be false when duplicate_detection.enabled is false'
    );
  }

  /**
   * Tests tenant opt-out: aiDuplicates can be disabled even with markaspot_ai.
   *
   * A tenant with markaspot_ai installed and duplicate_detection enabled, but
   * features.aiDuplicates = false in their field_nuxt_config, should have
   * aiDuplicates forced to false.
   *
   * @covers ::getMarkASpotSettings
   */
  public function testAiDuplicatesCanBeOptedOutViaNuxtConfig(): void {
    // Inject duplicate_detection.enabled = TRUE so we test the opt-out path.
    $configFactory = $this->createConfigFactoryWithAiSettings(TRUE);
    (new \ReflectionProperty($this->controller, 'configFactory'))
      ->setValue($this->controller, $configFactory);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(fn(string $module) => match ($module) {
        'markaspot_ai' => TRUE,
        default => FALSE,
      });
    $moduleHandler->method('alter');
    \Drupal::getContainer()->set('module_handler', $moduleHandler);

    $group = $this->createMockGroup([
      'field_nuxt_config' => json_encode([
        'features' => ['aiProcessing' => TRUE, 'aiDuplicates' => FALSE],
      ]),
    ], 1);
    $this->groupStorage->method('load')->with(1)->willReturn($group);

    $request = Request::create('/api/mark-a-spot-settings?jurisdiction=1', 'GET');
    $response = $this->controller->getMarkASpotSettings($request);

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);

    $this->assertFalse(
      $data['features']['aiDuplicates'] ?? TRUE,
      'aiDuplicates must honour explicit false in field_nuxt_config'
    );
  }

}
