<?php

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the Mark-a-Spot installation profile update helpers.
 *
 * @group markaspot
 */
class MarkaspotInstallTest extends UnitTestCase {

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
   * Mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleHandler;

  /**
   * Mocked module installer.
   *
   * @var object|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleInstaller;

  /**
   * Mocked transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $transliteration;

  /**
   * Mocked key value factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $keyValueFactory;

  /**
   * Mocked system.schema key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $schemaStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/markaspot.install';

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->moduleInstaller = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['install'])
      ->getMock();
    $this->transliteration = $this->createMock(TransliterationInterface::class);
    $this->keyValueFactory = $this->createMock(KeyValueFactoryInterface::class);
    $this->schemaStore = $this->createMock(KeyValueStoreInterface::class);

    $this->keyValueFactory->method('get')
      ->with('system.schema')
      ->willReturn($this->schemaStore);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static function (string $string, array $args = []) {
        return $args ? strtr($string, $args) : $string;
      });

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('module_handler', $this->moduleHandler);
    $container->set('module_installer', $this->moduleInstaller);
    $container->set('transliteration', $this->transliteration);
    $container->set('keyvalue', $this->keyValueFactory);
    $container->set('string_translation', $translation);
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Tests json_form_widget installation for existing Nuxt sites.
   */
  public function testUpdate11800InstallsJsonFormWidget(): void {
    $this->moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn(string $module) => match ($module) {
        'markaspot_nuxt' => TRUE,
        'json_form_widget' => FALSE,
        default => FALSE,
      });

    $this->moduleInstaller->expects($this->once())
      ->method('install')
      ->with(['json_form_widget']);

    markaspot_update_11800();
  }

  /**
   * Tests default jurisdiction creation preserves zero coordinates.
   */
  public function testCreateDefaultJurisdictionKeepsZeroCoordinates(): void {
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')
      ->willReturnCallback(static fn(string $key) => $key === 'name' ? 'Zero City' : NULL);

    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('isNew')->willReturn(FALSE);
    $nuxtConfig->method('get')
      ->willReturnCallback(static fn(string $key) => match ($key) {
        'center_lat' => 0.0,
        'center_lng' => 0.0,
        'zoom_initial' => 9,
        default => NULL,
      });

    $this->configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'system.site' => $siteConfig,
        'markaspot_nuxt.settings' => $nuxtConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $this->transliteration->method('transliterate')
      ->with('Zero City')
      ->willReturn('Zero City');

    $captured = [];
    $group = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['hasField', 'set', 'save', 'label'])
      ->getMock();
    $group->method('hasField')
      ->willReturnCallback(static fn(string $field) => in_array($field, ['field_slug', 'field_nuxt_config'], TRUE));
    $group->method('set')
      ->willReturnCallback(function (string $field, $value) use (&$captured, $group) {
        $captured[$field] = $value;
        return $group;
      });
    $group->expects($this->once())->method('save');
    $group->method('label')->willReturn('Zero City');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('create')
      ->with([
        'type' => 'jur',
        'label' => 'Zero City',
        'status' => 1,
      ])
      ->willReturn($group);

    $created = _markaspot_create_default_jurisdiction('jur', $groupStorage);

    $this->assertSame($group, $created);
    $this->assertSame('zero-city', $captured['field_slug']);
    $this->assertArrayHasKey('field_nuxt_config', $captured);

    $nuxtJson = json_decode($captured['field_nuxt_config'], TRUE);
    $this->assertSame([0, 0], $nuxtJson['map']['center']);
    $this->assertSame(9, $nuxtJson['map']['zoom']);
  }

  /**
   * Tests legacy group-type update configuration and default creation.
   */
  public function testUpdate11801ConfiguresLegacyGroupTypes(): void {
    $configUpdates = [];
    $editableConfig = $this->createMock(Config::class);
    $editableConfig->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(function (string $key, $value) use (&$configUpdates, $editableConfig) {
        $configUpdates[$key] = $value;
        return $editableConfig;
      });
    $editableConfig->expects($this->once())->method('save');

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')
      ->willReturnCallback(static fn(string $key) => $key === 'name' ? 'Legacy City' : NULL);

    $nuxtConfig = $this->createMock(ImmutableConfig::class);
    $nuxtConfig->method('isNew')->willReturn(FALSE);
    $nuxtConfig->method('get')
      ->willReturnCallback(static fn(string $key) => match ($key) {
        'center_lat' => 50.94,
        'center_lng' => 6.96,
        'zoom_initial' => 13,
        default => NULL,
      });

    $this->configFactory->method('getEditable')
      ->with('markaspot_open311.settings')
      ->willReturn($editableConfig);
    $this->configFactory->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'system.site' => $siteConfig,
        'markaspot_nuxt.settings' => $nuxtConfig,
        default => $this->createMock(ImmutableConfig::class),
      });

    $this->moduleHandler->method('moduleExists')
      ->with('group')
      ->willReturn(TRUE);

    $this->transliteration->method('transliterate')
      ->with('Legacy City')
      ->willReturn('Legacy City');

    $groupTypeStorage = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage->method('load')
      ->willReturnCallback(static fn(string $id) => in_array($id, ['organisation', 'jurisdiction'], TRUE) ? new \stdClass() : NULL);

    $createdGroup = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['hasField', 'set', 'save', 'label'])
      ->getMock();
    $createdGroup->method('hasField')->willReturn(FALSE);
    $createdGroup->expects($this->once())->method('save');
    $createdGroup->method('label')->willReturn('Legacy City');

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['type' => 'jurisdiction'])
      ->willReturn([]);
    $groupStorage->expects($this->once())
      ->method('create')
      ->willReturn($createdGroup);

    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $entityType) => match ($entityType) {
        'group_type' => $groupTypeStorage,
        'group' => $groupStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    markaspot_update_11801();

    $this->assertSame('organisation', $configUpdates['group_filter_type']);
    $this->assertSame('jurisdiction', $configUpdates['jurisdiction_group_type']);
  }

  /**
   * Tests markaspot_group installation resets schema for follow-up updates.
   */
  public function testUpdate11802InstallsGroupModuleAndResetsSchema(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('markaspot_group')
      ->willReturn(FALSE);

    $this->moduleInstaller->expects($this->once())
      ->method('install')
      ->with(['markaspot_group']);

    $this->schemaStore->expects($this->once())
      ->method('set')
      ->with('markaspot_group', 11801);

    $fieldStorageConfig = $this->createMock(EntityStorageInterface::class);
    $fieldStorageConfig->method('load')->willReturn(new \stdClass());

    $fieldConfig = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage = $this->createMock(EntityStorageInterface::class);
    $groupTypeStorage->method('load')->willReturn(NULL);

    $this->entityTypeManager->method('getStorage')
      ->willReturnCallback(fn(string $entityType) => match ($entityType) {
        'field_storage_config' => $fieldStorageConfig,
        'field_config' => $fieldConfig,
        'group_type' => $groupTypeStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    markaspot_update_11802();
  }

}
