<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';

/**
 * Tests deploy protection for the management View package config.
 *
 * @group markaspot
 * @coversDefaultClass \Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber
 */
final class ManagementPackageConfigGuardTest extends TestCase {

  /**
   * Tests that shipped management config replaces stale import copies.
   *
   * @covers ::protectManagementPackageConfig
   */
  public function testShippedManagementPackageConfigWins(): void {
    $profilePath = dirname(__DIR__, 3);
    $source = new FileStorage($profilePath . '/config/optional');
    $active = new MemoryStorage();
    $active->write('views.view.management', ['uuid' => 'view-uuid', 'display' => ['active']]);
    $active->write('search_api.index.service_requests', ['uuid' => 'index-uuid', 'field_settings' => []]);

    $import = new MemoryStorage();
    $import->write('views.view.management', ['display' => ['old']]);
    $import->write('search_api.index.service_requests', ['field_settings' => []]);
    $import->write('views.view.tenant_custom', ['display' => ['custom']]);

    $profileExtensionList = $this->createMock(ProfileExtensionList::class);
    $profileExtensionList->method('getPath')->with('markaspot')->willReturn($profilePath);
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      $profileExtensionList,
    );
    $method = new \ReflectionMethod($subscriber, 'protectManagementPackageConfig');
    $method->setAccessible(TRUE);
    $method->invoke($subscriber, $import);

    $expectedView = $source->read('views.view.management');
    $expectedView['uuid'] = 'view-uuid';
    $expectedIndex = $source->read('search_api.index.service_requests');
    $expectedIndex['uuid'] = 'index-uuid';
    $this->assertSame($expectedView, $import->read('views.view.management'));
    $this->assertSame($expectedIndex, $import->read('search_api.index.service_requests'));
    $this->assertSame(['display' => ['custom']], $import->read('views.view.tenant_custom'));
  }

}
