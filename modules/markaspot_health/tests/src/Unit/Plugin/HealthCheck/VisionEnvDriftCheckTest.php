<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\VisionEnvDriftCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the vision-env-drift detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\VisionEnvDriftCheck
 */
class VisionEnvDriftCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'vision_env_drift',
    'label' => 'Vision ENV overrides missing',
    'severity' => 'warning',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->clearRuntimeEnv();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->clearRuntimeEnv();
    parent::tearDown();
  }

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenModuleDisabled(): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('markaspot_vision')->willReturn(FALSE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenApiKeyEmpty(): void {
    $config = $this->createConfig([
      'api_key' => '',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => TRUE]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString(
      'MARKASPOT_VISION_API_KEY is not active',
      $result->message,
    );
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenApiUrlEmpty(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => '',
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => TRUE]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString(
      'MARKASPOT_VISION_API_URL is not active',
      $result->message,
    );
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenAiAnalysisRequiresBlur(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => TRUE]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('blur preprocessing is disabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenAiAnalysisStillUsesLocalBlurDefault(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => 'http://markaspot-vision:8200/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => TRUE]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('local markaspot-vision default', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenBlurEnvIsSetButRuntimeStillUsesLocalDefault(): void {
    putenv('MARKASPOT_BLUR_URL=https://blur.example.test/blur');
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => 'http://markaspot-vision:8200/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => TRUE]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('local markaspot-vision default', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenAiAnalysisHasNoBlurBearer(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => TRUE]);

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('set MARKASPOT_BLUR_API_KEY', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenApiKeyResolved(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => '',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => FALSE]);

    $this->assertTrue($result->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenAiAnalysisRuntimeResolved(): void {
    putenv('MARKASPOT_BLUR_API_KEY=blur-fake-runtime-secret');
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $plugin = $this->createPlugin($factory, $modules);
    $result = $plugin->run(['ai_analysis_required' => TRUE]);

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('blur ENV overrides are active', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunDetectsAiAnalysisFromJurisdictionConfig(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createEntityTypeManagerForNuxtConfigs([
      ['features' => ['aiAnalysis' => TRUE]],
    ]);

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('blur preprocessing is disabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunTreatsMissingAiAnalysisAsRequired(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createEntityTypeManagerForNuxtConfigs([
      ['features' => []],
    ]);

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('blur preprocessing is disabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunTreatsMissingNuxtConfigFieldAsRequired(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createEntityTypeManagerForRawNuxtConfigs([
      NULL,
    ]);

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('blur preprocessing is disabled', $result->message);
  }


  /**
   * @covers ::run
   */
  public function testRunDetectsNestedAiAnalysisEnabled(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createEntityTypeManagerForNuxtConfigs([
      ['features' => ['aiAnalysis' => ['enabled' => TRUE]]],
    ]);

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('blur preprocessing is disabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunSkipsBlurChecksWhenAiAnalysisExplicitlyFalse(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => '',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createEntityTypeManagerForNuxtConfigs([
      ['features' => ['aiAnalysis' => ['enabled' => FALSE]]],
      ['features' => ['aiAnalysis' => FALSE]],
    ]);

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('markaspot_vision ENV override is active', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunTreatsInvalidNuxtConfigAsRequired(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createEntityTypeManagerForRawNuxtConfigs([
      '{not-json',
    ]);

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('blur preprocessing is disabled', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsClosedWhenAiAnalysisCannotBeDetected(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => TRUE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager
      ->method('getStorage')
      ->willThrowException(new \RuntimeException('storage unavailable'));

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('Unable to determine features.aiAnalysis', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunUsesConfiguredJurisdictionGroupType(): void {
    $config = $this->createConfig([
      'api_key' => 'sk-fake-runtime-secret',
      'api_url' => 'https://example.test/v1/chat/completions',
      'enable_blur_preprocessing' => FALSE,
      'blur_service_url' => 'https://blur.example.test/blur',
    ]);
    $factory = $this->createConfigFactory($config, 'municipality');
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $entityTypeManager = $this->createEntityTypeManagerForNuxtConfigs([
      ['features' => ['aiAnalysis' => TRUE]],
    ], 'municipality');

    $plugin = $this->createPlugin($factory, $modules, $entityTypeManager);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame('error', $result->severity);
    $this->assertStringContainsString('blur preprocessing is disabled', $result->message);
  }

  /**
   * Creates a config mock returning values by key.
   *
   * @param array<string, mixed> $values
   *   Config values keyed by config name.
   */
  private function createConfig(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(FALSE);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => $values[$key] ?? NULL,
    );
    return $config;
  }

  /**
   * Creates a config factory returning vision and Open311 config.
   */
  private function createConfigFactory(
    ImmutableConfig $visionConfig,
    string $jurisdictionGroupType = 'jur',
  ): ConfigFactoryInterface {
    $open311Config = $this->createConfig([
      'jurisdiction_group_type' => $jurisdictionGroupType,
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['markaspot_vision.settings', $visionConfig],
      ['markaspot_open311.settings', $open311Config],
    ]);
    return $factory;
  }

  /**
   * Creates the health check under test.
   */
  private function createPlugin(
    ConfigFactoryInterface $factory,
    ModuleHandlerInterface $modules,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ): VisionEnvDriftCheck {
    return new VisionEnvDriftCheck(
      [],
      'vision_env_drift',
      $this->definition,
      $factory,
      $modules,
      $entityTypeManager,
    );
  }

  /**
   * Creates an entity type manager returning jurisdiction config groups.
   *
   * @param array<int, array<string, mixed>> $configs
   *   Decoded field_nuxt_config values.
   */
  private function createEntityTypeManagerForNuxtConfigs(
    array $configs,
    string $jurisdictionGroupType = 'jur',
  ): EntityTypeManagerInterface {
    return $this->createEntityTypeManagerForRawNuxtConfigs(array_map(
      static fn (array $config): string => (string) json_encode($config),
      $configs,
    ), $jurisdictionGroupType);
  }

  /**
   * Creates an entity type manager returning raw field_nuxt_config values.
   *
   * @param array<int, string|null> $rawConfigs
   *   Raw field_nuxt_config values.
   */
  private function createEntityTypeManagerForRawNuxtConfigs(
    array $rawConfigs,
    string $jurisdictionGroupType = 'jur',
  ): EntityTypeManagerInterface {
    $ids = [];
    $groups = [];
    foreach ($rawConfigs as $index => $rawConfig) {
      $id = $index + 1;
      $ids[] = $id;
      $groups[$id] = $rawConfig === NULL
        ? $this->createGroupWithoutNuxtConfig()
        : $this->createGroupWithRawNuxtConfig($rawConfig);
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->with('type', $jurisdictionGroupType)->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->with($ids)->willReturn($groups);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('group')->willReturn($storage);
    return $entityTypeManager;
  }

  /**
   * Creates a fieldable group mock with field_nuxt_config.
   *
   * @param array<string, mixed> $config
   *   Decoded field_nuxt_config value.
   */
  private function createGroupWithRawNuxtConfig(string $rawConfig): FieldableEntityInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->value = $rawConfig;

    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('hasField')->with('field_nuxt_config')->willReturn(TRUE);
    $group->method('get')->with('field_nuxt_config')->willReturn($field);
    return $group;
  }

  /**
   * Creates a fieldable group mock without field_nuxt_config.
   */
  private function createGroupWithoutNuxtConfig(): FieldableEntityInterface {
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('hasField')->with('field_nuxt_config')->willReturn(FALSE);
    return $group;
  }

  /**
   * Clears process-level ENV used by the runtime check.
   */
  private function clearRuntimeEnv(): void {
    putenv('MARKASPOT_BLUR_API_KEY');
    putenv('MARKASPOT_BLUR_URL');
    putenv('AI_API_KEY');
  }

}
