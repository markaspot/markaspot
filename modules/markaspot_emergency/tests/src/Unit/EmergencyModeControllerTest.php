<?php

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_emergency\Controller\EmergencyModeController;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
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
   * Mocked state service.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

  /**
   * Mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

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
   * Mocked entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->emergencyService = $this->createMock(EmergencyModeService::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_emergency')
      ->willReturn($this->logger);

    $this->controller = new EmergencyModeController(
      $this->configFactory,
      $this->state,
      $this->entityTypeManager,
      $this->currentUser,
      $loggerFactory,
      $this->emergencyService,
      $this->entityFieldManager,
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
