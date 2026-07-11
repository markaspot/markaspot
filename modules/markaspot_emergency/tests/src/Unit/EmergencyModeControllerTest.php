<?php

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_emergency\Controller\EmergencyModeController;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the EmergencyModeController.
 *
 * @group markaspot_emergency
 * @coversDefaultClass \Drupal\markaspot_emergency\Controller\EmergencyModeController
 */
class EmergencyModeControllerTest extends UnitTestCase {

  /**
   * The controller under test.
   *
   * @var \Drupal\markaspot_emergency\Controller\EmergencyModeController
   */
  protected EmergencyModeController $controller;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mocked logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mocked emergency mode service.
   *
   * @var \Drupal\markaspot_emergency\Service\EmergencyModeService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $emergencyService;

  /**
   * Mocked entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityRepository;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->emergencyService = $this->createMock(EmergencyModeService::class);
    $this->emergencyService->method('getPolicy')->willReturn([
      'mode_type' => 'disaster',
      'force_redirect' => TRUE,
      'lite_ui' => TRUE,
      'unpublish_regular' => TRUE,
      'auto_deactivate' => ['enabled' => TRUE, 'duration' => 72],
      'allowed_urls' => ['/sos'],
      'network_detection' => ['enabled' => TRUE, 'auto_switch_threshold' => '2g'],
      'maintenance' => [
        'unpublish_non_selected' => FALSE,
        'show_only_categories' => [],
        'force_redirect' => FALSE,
        'banner_text' => '',
      ],
      'banner' => [
        'enabled' => FALSE,
        'message' => '',
        'level' => 'info',
        'title' => '',
        'display_conditions' => [
          'emergency_mode_only' => FALSE,
          'maintenance_mode' => TRUE,
          'always_visible' => FALSE,
        ],
      ],
    ]);
    $this->entityRepository = $this->createMock(EntityRepositoryInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_emergency')
      ->willReturn($this->logger);

    $this->controller = new EmergencyModeController(
      $this->configFactory,
      $this->currentUser,
      $loggerFactory,
      $this->emergencyService,
      $this->entityRepository,
    );
  }

  /**
   * @covers ::activate
   */
  public function testActivateDeniesAccessWithoutPermission(): void {
    $this->currentUser->method('hasPermission')
      ->with('administer emergency mode')
      ->willReturn(FALSE);

    $request = Request::create('/api/emergency/activate', 'POST', [], [], [], [], json_encode([
      'mode_type' => 'disaster',
    ]));

    $response = $this->controller->activate($request);

    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Access denied', $data['error']);
  }

  /**
   * @covers ::deactivate
   */
  public function testDeactivateDeniesAccessWithoutPermission(): void {
    $this->currentUser->method('hasPermission')
      ->with('administer emergency mode')
      ->willReturn(FALSE);

    $request = Request::create('/api/emergency/deactivate', 'POST');

    $response = $this->controller->deactivate($request);

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * @covers ::getStatus
   */
  public function testStatusIncludesScopedVersionedContract(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['allowed_urls', ['/sos']],
      ['banner', ['enabled' => FALSE]],
    ]);
    $this->configFactory->method('get')
      ->with('markaspot_emergency.settings')
      ->willReturn($config);
    $this->emergencyService->method('resolveRootJurisdictionId')->willReturn(7);
    $this->emergencyService->method('getModeState')->willReturn([
      'jurisdiction_id' => 7,
      'status' => 'active',
      'activated_at' => 1_700_000_000,
      'activated_by' => 1,
      'mode_type' => 'crisis',
      'force_redirect' => TRUE,
      'lite_ui' => TRUE,
      'revision' => 4,
      'snapshot' => [1, 2],
    ]);
    $this->emergencyService->method('getAvailableCategoryTerms')->willReturn([]);
    $this->currentUser->method('hasPermission')->willReturn(FALSE);

    $response = $this->controller->getStatus(Request::create(
      '/api/emergency-mode/status?jurisdiction_id=demo',
    ));
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(2, $data['contract_version']);
    $this->assertSame(7, $data['jurisdiction_id']);
    $this->assertSame(4, $data['revision']);
    $this->assertSame('crisis', $data['mode_type']);
    $this->assertSame([], $data['available_categories']);
    $contexts = $response->getCacheableMetadata()->getCacheContexts();
    $this->assertContains('languages:language_content', $contexts);
    $this->assertContains('languages:language_interface', $contexts);
  }

  /**
   * @covers ::getStatus
   */
  public function testStatusRejectsArrayJurisdiction(): void {
    $this->emergencyService->expects($this->never())->method('resolveRootJurisdictionId');

    $response = $this->controller->getStatus(Request::create(
      '/api/emergency-mode/status?jurisdiction_id[]=7',
    ));

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::getStatus
   */
  public function testStatusUsesContextTranslationAndNullableServiceCode(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['allowed_urls', ['/', '/sos']],
      ['banner', ['enabled' => FALSE]],
    ]);
    $this->configFactory->method('get')->willReturn($config);
    $this->emergencyService->method('resolveRootJurisdictionId')->willReturn(7);
    $this->emergencyService->method('getModeState')->willReturn([
      'status' => 'active',
      'mode_type' => 'disaster',
      'lite_ui' => TRUE,
      'force_redirect' => TRUE,
      'revision' => 1,
      'snapshot' => [],
    ]);

    $original = $this->createMock(TermInterface::class);
    $translated = $this->createMock(TermInterface::class);
    $translated->method('id')->willReturn(11);
    $translated->method('uuid')->willReturn('category-uuid');
    $translated->method('label')->willReturn('Verletzte Personen');
    $translated->method('getWeight')->willReturn(0);
    $translated->method('hasField')->willReturn(FALSE);
    $this->emergencyService->method('getAvailableCategoryTerms')->willReturn([$original]);
    $this->entityRepository->expects($this->once())
      ->method('getTranslationFromContext')
      ->with($original)
      ->willReturn($translated);
    $this->currentUser->method('hasPermission')->willReturn(FALSE);

    $response = $this->controller->getStatus(Request::create(
      '/api/emergency-mode/status?jurisdiction_id=7',
    ));
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame('Verletzte Personen', $data['available_categories'][0]['label']);
    $this->assertNull($data['available_categories'][0]['service_code']);
    $this->assertTrue($data['available_categories'][0]['lite_compatible']);
    $this->assertSame(['/sos'], $data['allowed_urls']);
  }

  /**
   * Required variable service attributes make a category unavailable to Lite.
   *
   * @covers ::getStatus
   */
  public function testCategoryContractMarksRequiredServiceAttributesAsLiteIncompatible(): void {
    $definition = $this->createMock(FieldItemListInterface::class);
    $definition->method('isEmpty')->willReturn(FALSE);
    $definition->method('__get')->with('value')->willReturn(json_encode([
      'attributes' => [[
        'code' => 'lamp_id',
        'variable' => TRUE,
        'required' => TRUE,
      ]],
    ], JSON_THROW_ON_ERROR));

    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn(11);
    $term->method('uuid')->willReturn('category-uuid');
    $term->method('label')->willReturn('Street light');
    $term->method('getWeight')->willReturn(0);
    $term->method('hasField')->willReturnCallback(
      static fn(string $name): bool => $name === 'field_service_definition',
    );
    $term->method('get')->with('field_service_definition')->willReturn($definition);
    $this->entityRepository->method('getTranslationFromContext')->with($term)->willReturn($term);

    $method = new \ReflectionMethod($this->controller, 'mapCategory');
    $mapped = $method->invoke($this->controller, $term);

    $this->assertFalse($mapped['lite_compatible']);
  }

  /**
   * Tests getBannerData returns NULL when banner is disabled.
   *
   * @covers ::getBannerData
   */
  public function testGetBannerDataReturnsNullWhenDisabled(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['banner', ['enabled' => FALSE]],
        ['emergency_mode.mode_type', 'disaster'],
      ]);

    $result = $this->controller->getBannerData($config, TRUE);

    $this->assertNull($result);
  }

  /**
   * Tests getBannerData returns NULL when both messages are empty.
   *
   * Covers the case where banner.message and maintenance.banner_text are both
   * empty strings.
   *
   * @covers ::getBannerData
   */
  public function testGetBannerDataReturnsNullWhenBothMessagesEmpty(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['banner', [
          'enabled' => TRUE,
          'message' => '',
          'display_conditions' => ['always_visible' => TRUE],
        ]],
        ['emergency_mode.mode_type', 'disaster'],
        ['maintenance.banner_text', ''],
      ]);

    $result = $this->controller->getBannerData($config, TRUE);

    $this->assertNull($result);
  }

  /**
   * Tests getBannerData uses maintenance.banner_text as fallback.
   *
   * @covers ::getBannerData
   */
  public function testGetBannerDataUsesMaintFallbackWhenBannerMessageEmpty(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['banner', [
          'enabled' => TRUE,
          'message' => '',
          'level' => 'info',
          'title' => '',
          'display_conditions' => ['maintenance_mode' => TRUE],
        ]],
        ['emergency_mode.mode_type', 'maintenance'],
        ['maintenance.banner_text', 'Maintenance in progress.'],
      ]);

    $result = $this->controller->getBannerData($config, TRUE);

    $this->assertNotNull($result);
    $this->assertEquals('Maintenance in progress.', $result['message']);
  }

  /**
   * Maintenance fallback must disappear as soon as runtime State is off.
   *
   * @covers ::getBannerData
   */
  public function testInactiveMaintenanceStateDoesNotKeepBannerVisible(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['banner', [
          'enabled' => TRUE,
          'message' => '',
          'level' => 'info',
          'display_conditions' => ['maintenance_mode' => TRUE],
        ]],
        ['maintenance.banner_text', 'Maintenance in progress.'],
      ]);

    $result = $this->controller->getBannerData($config, FALSE, 'maintenance');

    $this->assertNull($result);
  }

  /**
   * The SOS render array is invalidated with the selected runtime State.
   *
   * @covers ::sosRedirect
   */
  public function testSosPageCarriesJurisdictionEmergencyCacheability(): void {
    $this->emergencyService->method('resolveRootJurisdictionId')->willReturn(7);
    $this->emergencyService->method('isActive')->with(7)->willReturn(TRUE);

    $build = $this->controller->sosRedirect(Request::create('/sos?jurisdiction_id=7'));

    $this->assertContains(EmergencyModeService::CACHE_TAG, $build['#cache']['tags']);
    $this->assertContains(EmergencyModeService::cacheTag(7), $build['#cache']['tags']);
    $this->assertContains('url.query_args:jurisdiction_id', $build['#cache']['contexts']);
    $this->assertSame(5, $build['#cache']['max-age']);
  }

  /**
   * Tests getBannerData returns data when always_visible is set.
   *
   * @covers ::getBannerData
   */
  public function testGetBannerDataReturnsDataWhenAlwaysVisible(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['banner', [
          'enabled' => TRUE,
          'message' => 'Test alert',
          'title' => 'Alert',
          'level' => 'info',
          'display_conditions' => ['always_visible' => TRUE],
        ],
        ],
        ['emergency_mode.mode_type', 'disaster'],
      ]);

    $result = $this->controller->getBannerData($config, TRUE);

    $this->assertNotNull($result);
    $this->assertEquals('Test alert', $result['message']);
    // Emergency active + level info -> level should be upgraded.
    $this->assertEquals('extreme', $result['level']);
  }

  /**
   * Tests banner level escalation by mode type.
   *
   * @covers ::getBannerData
   * @dataProvider bannerLevelProvider
   */
  public function testBannerLevelEscalation(string $modeType, string $expectedLevel): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['banner', [
          'enabled' => TRUE,
          'message' => 'Alert',
          'level' => 'info',
          'display_conditions' => ['always_visible' => TRUE],
        ],
        ],
        ['emergency_mode.mode_type', $modeType],
      ]);

    $result = $this->controller->getBannerData($config, TRUE);

    $this->assertEquals($expectedLevel, $result['level']);
  }

  /**
   * Provides banner level test data.
   *
   * @return array
   *   Mode type to expected level mapping.
   */
  public static function bannerLevelProvider(): array {
    return [
      'disaster' => ['disaster', 'extreme'],
      'crisis' => ['crisis', 'severe'],
      'maintenance' => ['maintenance', 'warning'],
      'unknown' => ['other', 'moderate'],
    ];
  }

}
