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

  /**
   * Tests that tenant-added fields and handlers extend the shared package.
   */
  public function testTenantManagementExtensionsSurvive(): void {
    $source = new FileStorage(dirname(__DIR__, 3) . '/config/optional');
    $view = $source->read('views.view.management');
    $index = $source->read('search_api.index.service_requests');

    $index['field_settings']['field_tenant_reference'] = [
      'label' => 'Tenant reference',
      'property_path' => 'field_tenant_reference',
      'type' => 'string',
    ];
    $index['dependencies']['config'][] = 'field.storage.node.field_tenant_reference';
    $view['display']['default']['display_options']['fields']['field_tenant_reference'] = [
      'id' => 'field_tenant_reference',
      'field' => 'field_tenant_reference',
    ];
    $view['display']['default']['display_options']['filters']['field_tenant_reference'] = [
      'id' => 'field_tenant_reference',
      'field' => 'field_tenant_reference',
    ];
    $view['display']['page_1']['display_options']['style']['options']['columns']['field_tenant_reference'] = 'field_tenant_reference';
    $view['display']['page_1']['display_options']['style']['options']['info']['field_tenant_reference'] = ['sortable' => TRUE];

    $mergedIndex = ProfileConfigGuardSubscriber::mergeManagementPackageExtensions(
      'search_api.index.service_requests',
      $source->read('search_api.index.service_requests'),
      $index,
    );
    $mergedView = ProfileConfigGuardSubscriber::mergeManagementPackageExtensions(
      'views.view.management',
      $source->read('views.view.management'),
      $view,
    );

    $this->assertArrayHasKey('field_tenant_reference', $mergedIndex['field_settings']);
    $this->assertContains('field.storage.node.field_tenant_reference', $mergedIndex['dependencies']['config']);
    $this->assertArrayHasKey('field_tenant_reference', $mergedView['display']['default']['display_options']['fields']);
    $this->assertArrayHasKey('field_tenant_reference', $mergedView['display']['default']['display_options']['filters']);
    $this->assertSame('field_tenant_reference', $mergedView['display']['page_1']['display_options']['style']['options']['columns']['field_tenant_reference']);
    $this->assertTrue($mergedView['display']['page_1']['display_options']['style']['options']['info']['field_tenant_reference']['sortable']);
  }

  /**
   * Tests import-only tenant extensions survive a fresh config deployment.
   */
  public function testImportOnlyTenantManagementExtensionSurvives(): void {
    $profilePath = dirname(__DIR__, 3);
    $source = new FileStorage($profilePath . '/config/optional');
    $active = new MemoryStorage();
    $active->write('views.view.management', $source->read('views.view.management'));
    $active->write('search_api.index.service_requests', $source->read('search_api.index.service_requests'));

    $import = new MemoryStorage();
    $importView = $source->read('views.view.management');
    $importView['display']['default']['display_options']['fields']['field_tenant_reference'] = [
      'id' => 'field_tenant_reference',
      'field' => 'field_tenant_reference',
    ];
    $import->write('views.view.management', $importView);
    $importIndex = $source->read('search_api.index.service_requests');
    $importIndex['field_settings']['field_tenant_reference'] = [
      'label' => 'Tenant reference',
      'property_path' => 'field_tenant_reference',
      'type' => 'string',
    ];
    $import->write('search_api.index.service_requests', $importIndex);

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
    $method->invoke($subscriber, $import);

    $this->assertArrayHasKey(
      'field_tenant_reference',
      $import->read('views.view.management')['display']['default']['display_options']['fields'],
    );
    $this->assertArrayHasKey(
      'field_tenant_reference',
      $import->read('search_api.index.service_requests')['field_settings'],
    );
  }

  /**
   * Tests that the shared management package has complete German labels.
   */
  public function testGermanManagementLabelsCoverNewSharedHandlers(): void {
    $source = new FileStorage(dirname(__DIR__, 3) . '/config/optional/language/de');
    $translation = $source->read('views.view.management');

    $fields = $translation['display']['default']['display_options']['fields'];
    $filters = $translation['display']['default']['display_options']['filters'];
    $this->assertSame('Massenoperationen', $fields['views_bulk_operations_bulk_form']['label']);
    $this->assertSame('Erstellt von', $fields['uid']['label']);
    $this->assertSame('Vorschau', $fields['thumbnail']['label']);
    $this->assertSame('Zuständigkeitsbereich', $fields['field_jurisdiction']['label']);
    $this->assertSame('Veröffentlichungsstatus', $filters['status']['expose']['label']);
    $this->assertSame('Veröffentlicht', $filters['status']['group_info']['group_items'][1]['title']);
    $this->assertSame('Unveröffentlicht', $filters['status']['group_info']['group_items'][2]['title']);
  }

  /**
   * Tests stale sync cannot remove shared German management labels.
   */
  public function testGermanManagementLabelsSurviveStaleTenantImport(): void {
    $profilePath = dirname(__DIR__, 3);
    $active = new MemoryStorage();
    $active->write('language.entity.de', ['id' => 'de']);
    $active->createCollection('language.de')->write('views.view.management', [
      'display' => [
        'default' => [
          'display_options' => [
            'fields' => [
              'field_active_only' => ['label' => 'Aktiv'],
              'uid' => ['label' => 'Alt'],
            ],
          ],
        ],
      ],
    ]);
    $import = new MemoryStorage();
    $import->createCollection('language.de')->write('views.view.management', [
      'display' => [
        'default' => [
          'display_options' => [
            'fields' => [
              'field_import_only' => ['label' => 'Import'],
              'uid' => ['label' => 'Veraltet'],
            ],
          ],
        ],
      ],
    ]);

    $profileExtensionList = $this->createMock(ProfileExtensionList::class);
    $profileExtensionList->method('getPath')->with('markaspot')->willReturn($profilePath);
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      $profileExtensionList,
    );
    $protect = new \ReflectionMethod($subscriber, 'protectManagementViewTranslation');
    $protect->invoke($subscriber, $import);

    $translation = $import->createCollection('language.de')->read('views.view.management');
    $fields = $translation['display']['default']['display_options']['fields'];
    $this->assertSame('Erstellt von', $fields['uid']['label']);
    $this->assertSame('Aktiv', $fields['field_active_only']['label']);
    $this->assertSame('Import', $fields['field_import_only']['label']);
  }

  /**
   * Tests that the repair update rebuilds and indexes the complete tracker.
   */
  public function testManagementIndexRepairUsesTrackerRebuild(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/markaspot.install');
    $this->assertIsString($install);
    $matched = preg_match(
      '/function markaspot_update_11937\(\): string \{(?<body>.*?)^\}/ms',
      $install,
      $matches,
    );

    $this->assertSame(1, $matched);
    $this->assertStringContainsString('->rebuildTracker()', $matches['body']);
    $this->assertStringContainsString("->addItemsAll(\$index)", $matches['body']);
    $this->assertStringContainsString('->indexItems(1000)', $matches['body']);
    $this->assertStringNotContainsString('->reindex()', $matches['body']);
  }

  /**
   * Tests the follow-up repair completes the index through a Drupal batch.
   */
  public function testManagementIndexCompletionUsesSandboxBatch(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/markaspot.install');
    $this->assertIsString($install);
    $matched = preg_match(
      '/function markaspot_update_11939\(array &\$sandbox\): string \{(?<body>.*?)^\}/ms',
      $install,
      $matches,
    );

    $this->assertSame(1, $matched);
    $this->assertStringContainsString('_markaspot_complete_management_index($sandbox)', $matches['body']);
    $this->assertStringContainsString("['#finished']", $install);
  }

}
