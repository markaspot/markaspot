<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';

/**
 * Tests deploy protection for the management View package config.
 *
 * @group markaspot
 * @coversDefaultClass \Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber
 */
final class ManagementPackageConfigGuardTest extends UnitTestCase {

  /**
   * Tests that the shipped Search API fields use export-stable ordering.
   */
  public function testShippedSearchApiFieldsUseCanonicalOrder(): void {
    $profilePath = dirname(__DIR__, 3);
    $source = new FileStorage($profilePath . '/config/optional');
    $index = $source->read('search_api.index.service_requests');

    $actual = array_keys($index['field_settings']);
    $expected = $actual;
    sort($expected);

    $this->assertSame($expected, $actual);
  }

  /**
   * Tests that shipped management config replaces stale import copies.
   *
   * @covers ::protectManagementPackageConfig
   */
  public function testShippedManagementPackageConfigWins(): void {
    $profilePath = dirname(__DIR__, 3);
    $source = new FileStorage($profilePath . '/config/optional');
    $active = new MemoryStorage();
    $activeView = ['uuid' => 'view-uuid'] + $source->read('views.view.management');
    $activeView['display'] = ['active'];
    $activeIndex = ['uuid' => 'index-uuid'] + $source->read('search_api.index.service_requests');
    $activeIndex['field_settings'] = [];
    $active->write('views.view.management', $activeView);
    $active->write('search_api.index.service_requests', $activeIndex);

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

    $expectedView = ['uuid' => 'view-uuid'] + $source->read('views.view.management');
    $expectedIndex = ['uuid' => 'index-uuid'] + $source->read('search_api.index.service_requests');
    $this->assertSame($expectedView, $import->read('views.view.management'));
    $this->assertSame($expectedIndex, $import->read('search_api.index.service_requests'));
    $this->assertSame(['display' => ['custom']], $import->read('views.view.tenant_custom'));
  }

}
