<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';

/**
 * Tests that a deliberate module uninstall through cim keeps working.
 *
 * @group markaspot
 * @coversDefaultClass \Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber
 */
final class UninstalledModuleConfigGuardTest extends UnitTestCase {

  /**
   * Tests shipped config of an uninstalled module is not restored.
   *
   * @covers ::protectShippedConfig
   * @covers ::getExtensionsUninstalledByImport
   * @covers ::dependsOnExtensions
   */
  public function testShippedConfigOfUninstalledModuleIsNotRestored(): void {
    $active = $this->activeWithLazy();
    // config/sync drops the module, its settings and the dependent format,
    // and lacks node.settings (a stale export).
    $import = new MemoryStorage();
    $import->write('core.extension', ['module' => ['node' => 0, 'markaspot' => 1000], 'theme' => ['claro' => 0]]);

    $subscriber = $this->subscriber($active, $this->createMock(ModuleExtensionList::class));
    $this->invoke($subscriber, 'protectShippedConfig', $import);

    $this->assertFalse($import->exists('lazy.settings'));
    $this->assertFalse($import->exists('filter.format.lazy_html'));
    $this->assertFalse($import->exists('image.style.lazy_enforced'));
    $this->assertSame(['use_admin_theme' => TRUE], $import->read('node.settings'));
  }

  /**
   * Tests nothing is skipped when the import carries no core.extension.
   *
   * @covers ::protectShippedConfig
   * @covers ::getExtensionsUninstalledByImport
   */
  public function testImportWithoutCoreExtensionRestoresEverything(): void {
    $import = new MemoryStorage();
    $subscriber = $this->subscriber($this->activeWithLazy(), $this->createMock(ModuleExtensionList::class));
    $this->invoke($subscriber, 'protectShippedConfig', $import);

    $this->assertTrue($import->exists('lazy.settings'));
    $this->assertTrue($import->exists('node.settings'));
  }

  /**
   * Tests the full transform: required modules stay, the rest may go.
   *
   * @covers ::onImportTransform
   * @covers ::protectRequiredModules
   * @covers ::protectShippedConfig
   * @covers ::protectUserSwitching
   */
  public function testImportTransformUninstallsOnlyNonRequiredModules(): void {
    $active = $this->activeWithLazy();
    $active->write('block.block.gin_switch_user', [
      'id' => 'gin_switch_user',
      'theme' => 'gin',
      'plugin' => 'devel_switch_user',
      'dependencies' => ['module' => ['devel', 'user']],
    ]);
    $extension = $active->read('core.extension');
    $extension['module']['devel'] = 0;
    $active->write('core.extension', $extension);

    // The export dropped lazy and devel deliberately and, stale, also node,
    // which the profile requires.
    $import = new MemoryStorage();
    $import->write('core.extension', ['module' => ['markaspot' => 1000], 'theme' => ['claro' => 0]]);

    $node = $this->createMock(Extension::class);
    $node->required_by = ['markaspot' => TRUE];
    $lazy = $this->createMock(Extension::class);
    $lazy->required_by = [];
    $modules = $this->createMock(ModuleExtensionList::class);
    $modules->method('getList')->willReturn(['node' => $node, 'lazy' => $lazy]);

    $logger = new class() extends AbstractLogger {

      /**
       * Collected warning messages.
       *
       * @var string[]
       */
      public array $warnings = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        if ($level === 'warning') {
          $this->warnings[] = strtr((string) $message, $context);
        }
      }

    };
    $subscriber = $this->subscriber($active, $modules, $logger);
    $subscriber->onImportTransform(new StorageTransformEvent($import));

    $this->assertArrayHasKey('node', $import->read('core.extension')['module']);
    $this->assertSame(['use_admin_theme' => TRUE], $import->read('node.settings'));
    $this->assertFalse($import->exists('lazy.settings'));
    $this->assertFalse($import->exists('block.block.gin_switch_user'));
    $this->assertCount(1, $logger->warnings);
    $this->assertStringContainsString('lazy.settings', $logger->warnings[0]);
  }

  /**
   * Builds active config with the lazy module and its dependents.
   */
  private function activeWithLazy(): MemoryStorage {
    $active = new MemoryStorage();
    $active->write('core.extension', [
      'module' => ['lazy' => 0, 'node' => 0, 'markaspot' => 1000],
      'theme' => ['claro' => 0],
    ]);
    $active->write('lazy.settings', ['alter_tag' => ['img' => 'img']]);
    $active->write('filter.format.lazy_html', [
      'format' => 'lazy_html',
      'dependencies' => ['module' => ['lazy']],
    ]);
    $active->write('image.style.lazy_enforced', [
      'name' => 'lazy_enforced',
      'dependencies' => ['enforced' => ['module' => ['lazy']]],
    ]);
    $active->write('node.settings', ['use_admin_theme' => TRUE]);
    return $active;
  }

  /**
   * Creates the subscriber with a fixed list of shipped config names.
   */
  private function subscriber(MemoryStorage $active, ModuleExtensionList $modules, ?AbstractLogger $logger = NULL): ProfileConfigGuardSubscriber {
    $subscriber = new ProfileConfigGuardSubscriber($modules, $active, 'markaspot', $logger ?? new NullLogger());
    $shipped = new \ReflectionProperty($subscriber, 'shippedConfigNames');
    $shipped->setValue($subscriber, array_combine(
      ['lazy.settings', 'filter.format.lazy_html', 'image.style.lazy_enforced', 'node.settings'],
      ['lazy.settings', 'filter.format.lazy_html', 'image.style.lazy_enforced', 'node.settings'],
    ));
    return $subscriber;
  }

  /**
   * Invokes a private guard method.
   */
  private function invoke(ProfileConfigGuardSubscriber $subscriber, string $method, MemoryStorage $import): void {
    (new \ReflectionMethod($subscriber, $method))->invoke($subscriber, $import);
  }

}
