<?php

namespace Drupal\Tests\markaspot_emergency\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_emergency\Controller\EmergencyModeController;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('markaspot_emergency')
      ->willReturn($this->logger);

    $this->controller = new EmergencyModeController(
      $this->configFactory,
      $this->entityTypeManager,
      $this->currentUser,
      $loggerFactory
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

    $ref = new \ReflectionMethod($this->controller, 'getBannerData');
    $ref->setAccessible(TRUE);
    $result = $ref->invoke($this->controller, $config, TRUE);

    $this->assertNull($result);
  }

  /**
   * Tests getBannerData returns NULL when message is empty.
   *
   * @covers ::getBannerData
   */
  public function testGetBannerDataReturnsNullWhenMessageEmpty(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['banner', ['enabled' => TRUE, 'message' => '']],
        ['emergency_mode.mode_type', 'disaster'],
      ]);

    $ref = new \ReflectionMethod($this->controller, 'getBannerData');
    $ref->setAccessible(TRUE);
    $result = $ref->invoke($this->controller, $config, TRUE);

    $this->assertNull($result);
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

    $ref = new \ReflectionMethod($this->controller, 'getBannerData');
    $ref->setAccessible(TRUE);
    $result = $ref->invoke($this->controller, $config, TRUE);

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

    $ref = new \ReflectionMethod($this->controller, 'getBannerData');
    $ref->setAccessible(TRUE);
    $result = $ref->invoke($this->controller, $config, TRUE);

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

  /**
   * Tests getStateKey returns correct keys.
   *
   * @covers ::getStateKey
   */
  public function testGetStateKeyWithoutJurisdiction(): void {
    $ref = new \ReflectionMethod($this->controller, 'getStateKey');
    $ref->setAccessible(TRUE);

    $key = $ref->invoke($this->controller, NULL);
    $this->assertEquals('markaspot_emergency.original_published_tids', $key);
  }

  /**
   * Tests getStateKey with jurisdiction ID.
   *
   * @covers ::getStateKey
   */
  public function testGetStateKeyWithJurisdiction(): void {
    $ref = new \ReflectionMethod($this->controller, 'getStateKey');
    $ref->setAccessible(TRUE);

    $key = $ref->invoke($this->controller, 42);
    $this->assertEquals('markaspot_emergency.original_published_tids.42', $key);
  }

}
