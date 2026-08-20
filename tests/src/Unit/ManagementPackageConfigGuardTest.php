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
   * Tests that the shipped View exposes the publication state contract.
   */
  public function testShippedManagementViewExposesPublicationState(): void {
    $profilePath = dirname(__DIR__, 3);
    $source = new FileStorage($profilePath . '/config/optional');
    $view = $source->read('views.view.management');

    $field = $view['display']['default']['display_options']['fields']['status'];
    $this->assertSame('status', $field['field']);
    $this->assertSame('boolean', $field['type']);

    $filter = $view['display']['default']['display_options']['filters']['status'];
    $this->assertSame('search_api_boolean', $filter['plugin_id']);
    $this->assertSame('status', $filter['expose']['identifier']);
    $this->assertSame('status', $filter['group_info']['identifier']);
    $this->assertSame('1', $filter['group_info']['group_items'][1]['value']);
    $this->assertSame('0', $filter['group_info']['group_items'][2]['value']);

    $table = $view['display']['page_1']['display_options']['style']['options'];
    $this->assertSame('status', $table['columns']['status']);
    $this->assertArrayHasKey('status', $table['info']);
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

  /**
   * Tests nested plugin option order follows the active config entity.
   *
   * @covers ::protectManagementPackageConfig
   * @covers ::orderConfigKeysLike
   */
  public function testNestedManagementConfigOrderIsStable(): void {
    $profilePath = dirname(__DIR__, 3);
    $source = new FileStorage($profilePath . '/config/optional');
    $shippedView = $source->read('views.view.management');
    $activeView = ['uuid' => 'view-uuid'] + $shippedView;
    $path = &$activeView['display']['default']['display_options']['fields']['views_bulk_operations_bulk_form']['selected_actions'][1]['preconfiguration'];
    $path = [
      'add_confirmation' => $path['add_confirmation'],
      'confirm_help_text' => $path['confirm_help_text'],
      'label_override' => $path['label_override'],
      'message_override' => $path['message_override'],
    ];

    $active = new MemoryStorage();
    $active->write('views.view.management', $activeView);
    $active->write('search_api.index.service_requests', $source->read('search_api.index.service_requests'));
    $import = new MemoryStorage();
    $import->write('views.view.management', ['display' => ['old']]);
    $import->write('search_api.index.service_requests', ['field_settings' => []]);

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

    $expected = ['uuid' => 'view-uuid'] + $shippedView;
    $expected['display']['default']['display_options']['fields']['views_bulk_operations_bulk_form']['selected_actions'][1]['preconfiguration'] = $path;
    $this->assertSame($expected, $import->read('views.view.management'));
  }

}
