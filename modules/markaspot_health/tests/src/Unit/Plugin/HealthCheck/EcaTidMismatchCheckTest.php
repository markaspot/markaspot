<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\EcaTidMismatchCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the ECA-tid-mismatch detector and its conservative walker.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\EcaTidMismatchCheck
 */
class EcaTidMismatchCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'eca_tid_mismatch',
    'label' => 'ECA conditions referencing missing taxonomy IDs',
    'severity' => 'warning',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenEcaModuleAbsent(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('eca')->willReturn(FALSE);
    $plugin = new EcaTidMismatchCheck(
      [],
      'eca_tid_mismatch',
      $this->definition,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $modules,
    );

    $this->assertTrue($plugin->run()->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunPassesWhenAllReferencedTidsExist(): void {
    $model = [
      'label' => 'Status workflow',
      'conditions' => [
        'cond_one' => [
          'plugin' => 'eca_taxonomy_term',
          'field_name' => 'field_status',
          'value' => 5,
        ],
      ],
    ];
    $plugin = $this->buildPlugin(['eca.model.status_workflow' => $model], [5]);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('1 ECA-referenced', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenReferencedTidIsMissing(): void {
    $model = [
      'label' => 'Status workflow',
      'conditions' => [
        'cond_one' => [
          'plugin' => 'eca_taxonomy_term',
          'field_name' => 'field_status',
          'value' => 5,
        ],
        'cond_two' => [
          'plugin' => 'eca_taxonomy_term',
          'field_name' => 'field_status',
          'value' => 99,
        ],
      ],
    ];
    $plugin = $this->buildPlugin(['eca.model.status_workflow' => $model], [5]);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(1, $result->count);
    $this->assertStringContainsString('tid=99', $result->message);
    $this->assertStringContainsString('Status workflow', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testWalkerIgnoresIntegersOutsideTaxonomyContext(): void {
    $model = [
      'label' => 'Delay-only model',
      'actions' => [
        'wait' => [
          'plugin' => 'eca_delay',
          'value' => 30,
        ],
      ],
    ];
    $plugin = $this->buildPlugin(['eca.model.delay_only' => $model], []);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('No taxonomy-coupled', $result->message);
  }

  /**
   * Builds a plugin with the given eca configs and existing taxonomy term ids.
   */
  private function buildPlugin(array $rawConfigs, array $existingTermIds): EcaTidMismatchCheck {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('listAll')->with('eca.model.')->willReturn(array_keys($rawConfigs));
    $configMap = [];
    foreach ($rawConfigs as $name => $raw) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('getRawData')->willReturn($raw);
      $configMap[] = [$name, $config];
    }
    $factory->method('get')->willReturnMap($configMap);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine($existingTermIds, $existingTermIds) ?: []);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->with('taxonomy_term')->willReturn($storage);

    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    return new EcaTidMismatchCheck([], 'eca_tid_mismatch', $this->definition, $factory, $etm, $modules);
  }

}
