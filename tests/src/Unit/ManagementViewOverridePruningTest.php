<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';
require_once dirname(__DIR__, 3) . '/markaspot.install';

/**
 * Tests that translated management View handlers never outlive their base.
 *
 * @group markaspot
 * @coversDefaultClass \Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber
 */
final class ManagementViewOverridePruningTest extends UnitTestCase {

  /**
   * Tests that labels for handlers missing from the base View are removed.
   *
   * @covers ::pruneOrphanViewOverrideHandlers
   */
  public function testOrphanDefaultHandlersArePruned(): void {
    $base = $this->baseView();
    $override = [
      'label' => 'Verwaltung',
      'display' => [
        'default' => [
          'display_title' => 'Standard',
          'display_options' => [
            'title' => 'Meldungen',
            'fields' => [
              'title' => ['label' => 'Titel'],
              'status' => ['label' => 'Veröffentlicht'],
            ],
            'filters' => [
              'status' => ['expose' => ['label' => 'Veröffentlichungsstatus']],
            ],
            'header' => [
              'result' => ['content' => '@total Ergebnisse'],
            ],
          ],
        ],
      ],
    ];

    $pruned = ProfileConfigGuardSubscriber::pruneOrphanViewOverrideHandlers($override, $base, $removed);

    $this->assertSame([
      'display.default.display_options.fields.status',
      'display.default.display_options.filters.status',
    ], $removed);
    $options = $pruned['display']['default']['display_options'];
    $this->assertSame(['title' => ['label' => 'Titel']], $options['fields']);
    $this->assertArrayNotHasKey('filters', $options);
    $this->assertSame('@total Ergebnisse', $options['header']['result']['content']);
    $this->assertSame('Meldungen', $options['title']);
    $this->assertSame('Standard', $pruned['display']['default']['display_title']);
    $this->assertSame('Verwaltung', $pruned['label']);
  }

  /**
   * Tests display-owned handlers against inherited and missing displays.
   *
   * @covers ::pruneOrphanViewOverrideHandlers
   */
  public function testDisplayOwnedHandlersArePrunedPerDisplay(): void {
    $base = $this->baseView();
    $base['display']['page_1']['display_options']['defaults']['fields'] = FALSE;
    $base['display']['page_1']['display_options']['fields'] = [
      'page_only' => ['id' => 'page_only', 'table' => 'node', 'field' => 'nid'],
    ];
    $override = [
      'display' => [
        'default' => [
          'display_options' => [
            'fields' => ['title' => ['label' => 'Titel']],
            'sorts' => ['created' => ['expose' => ['label' => 'Erstellt']]],
          ],
        ],
        'page_1' => [
          'display_options' => [
            'fields' => [
              'page_only' => ['label' => 'Nur Seite'],
              'title' => ['label' => 'Titel'],
            ],
            // page_1 inherits its filters from the default display.
            'filters' => ['title' => ['expose' => ['label' => 'Titel']]],
            'menu' => ['title' => 'Meldungen verwalten'],
          ],
        ],
        'block_1' => [
          'display_title' => 'Block',
          'display_options' => [
            'fields' => ['title' => ['label' => 'Titel']],
          ],
        ],
      ],
    ];

    $pruned = ProfileConfigGuardSubscriber::pruneOrphanViewOverrideHandlers($override, $base, $removed);

    $this->assertSame([
      'display.page_1.display_options.fields.title',
      'display.page_1.display_options.filters.title',
      'display.block_1',
    ], $removed);
    $this->assertSame($override['display']['default'], $pruned['display']['default']);
    $this->assertSame([
      'display_options' => [
        'fields' => ['page_only' => ['label' => 'Nur Seite']],
        'menu' => ['title' => 'Meldungen verwalten'],
      ],
    ], $pruned['display']['page_1']);
    $this->assertArrayNotHasKey('block_1', $pruned['display']);
  }

  /**
   * Tests that pruning is idempotent and leaves consistent overrides alone.
   *
   * @covers ::pruneOrphanViewOverrideHandlers
   */
  public function testPruningIsIdempotent(): void {
    $base = $this->baseView();
    $override = [
      'display' => [
        'default' => [
          'display_options' => [
            'fields' => ['status' => ['label' => 'Veröffentlicht']],
          ],
        ],
      ],
    ];

    $once = ProfileConfigGuardSubscriber::pruneOrphanViewOverrideHandlers($override, $base, $removed);
    $this->assertSame([], $once);
    $this->assertCount(1, $removed);

    $twice = ProfileConfigGuardSubscriber::pruneOrphanViewOverrideHandlers($once, $base, $removed);
    $this->assertSame($once, $twice);
    $this->assertSame([], $removed);

    $consistent = ['label' => 'Verwaltung'];
    $this->assertSame($consistent, ProfileConfigGuardSubscriber::pruneOrphanViewOverrideHandlers($consistent, $base, $removed));
    $this->assertSame([], $removed);
  }

  /**
   * Tests that the shipped German labels match the shipped View handlers.
   *
   * @covers ::pruneOrphanViewOverrideHandlers
   */
  public function testShippedGermanLabelsHaveNoOrphans(): void {
    $profilePath = dirname(__DIR__, 3);
    $view = (new FileStorage($profilePath . '/config/optional'))->read('views.view.management');
    $translation = (new FileStorage($profilePath . '/config/optional/language/de'))->read('views.view.management');

    ProfileConfigGuardSubscriber::pruneOrphanViewOverrideHandlers($translation, $view, $removed);

    $this->assertSame([], $removed);
  }

  /**
   * Tests that a config import never ships labels without a base handler.
   *
   * @covers ::protectManagementViewTranslation
   */
  public function testImportDropsLabelsMissingFromImportedView(): void {
    $profilePath = dirname(__DIR__, 3);
    $view = (new FileStorage($profilePath . '/config/optional'))->read('views.view.management');
    unset($view['display']['default']['display_options']['fields']['status']);
    unset($view['display']['default']['display_options']['filters']['status']);
    $view['display']['default']['display_options']['fields']['field_tenant_reference'] = [
      'id' => 'field_tenant_reference',
      'field' => 'field_tenant_reference',
    ];

    $active = new MemoryStorage();
    $active->write('language.entity.de', ['id' => 'de']);
    $active->createCollection('language.de')->write('views.view.management', [
      'display' => [
        'default' => [
          'display_options' => [
            'fields' => [
              'field_tenant_reference' => ['label' => 'Mandantenreferenz'],
              'field_removed' => ['label' => 'Entfernt'],
            ],
          ],
        ],
      ],
    ]);
    $import = new MemoryStorage();
    $import->write('views.view.management', $view);

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
    $options = $translation['display']['default']['display_options'];
    $this->assertSame('Mandantenreferenz', $options['fields']['field_tenant_reference']['label']);
    $this->assertSame('Erstellt von', $options['fields']['uid']['label']);
    $this->assertArrayNotHasKey('field_removed', $options['fields']);
    $this->assertArrayNotHasKey('status', $options['fields']);
    $this->assertArrayNotHasKey('status', $options['filters']);
    $this->assertSame('Kategorie', $options['filters']['field_category']['expose']['label']);
  }

  /**
   * Tests that the update helper prunes every language collection.
   */
  public function testUpdateHelperPrunesAllLanguageCollections(): void {
    $storage = new MemoryStorage();
    $storage->write('views.view.management', $this->baseView());
    $storage->createCollection('language.de')->write('views.view.management', [
      'display' => [
        'default' => [
          'display_options' => [
            'fields' => [
              'title' => ['label' => 'Titel'],
              'status' => ['label' => 'Veröffentlicht'],
            ],
            'filters' => ['status' => ['expose' => ['label' => 'Status']]],
          ],
        ],
      ],
    ]);
    $storage->createCollection('language.nl')->write('views.view.management', [
      'display' => [
        'default' => [
          'display_options' => ['fields' => ['title' => ['label' => 'Titel']]],
        ],
      ],
    ]);
    $storage->createCollection('language.fr')->write('views.view.management', [
      'display' => [
        'default' => [
          'display_options' => ['fields' => ['status' => ['label' => 'Publié']]],
        ],
      ],
    ]);

    $saved = [];
    $deleted = [];
    $languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $languageManager->method('getLanguageConfigOverride')
      ->willReturnCallback(function (string $langcode, string $name) use (&$saved, &$deleted): LanguageConfigOverride {
        $this->assertSame('views.view.management', $name);
        $override = $this->createMock(LanguageConfigOverride::class);
        $override->method('setData')->willReturnCallback(function (array $data) use ($override, $langcode, &$saved) {
          $saved[$langcode] = $data;
          return $override;
        });
        $override->method('delete')->willReturnCallback(function () use ($override, $langcode, &$deleted) {
          $deleted[] = $langcode;
          return $override;
        });
        return $override;
      });

    $container = new ContainerBuilder();
    $container->set('config.storage', $storage);
    $container->set('language_manager', $languageManager);
    \Drupal::setContainer($container);

    $this->assertSame(3, _markaspot_prune_management_view_override_orphans());
    $this->assertSame([
      'de' => [
        'display' => [
          'default' => [
            'display_options' => ['fields' => ['title' => ['label' => 'Titel']]],
          ],
        ],
      ],
    ], $saved);
    $this->assertSame(['fr'], $deleted);
  }

  /**
   * Tests that the update restores the base View before touching labels.
   */
  public function testUpdateSyncsViewBeforeLabels(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/markaspot.install');
    $this->assertIsString($install);
    $matched = preg_match(
      '/function markaspot_update_11942\(\): string \{(?<body>.*?)^\}/ms',
      $install,
      $matches,
    );

    $this->assertSame(1, $matched);
    $sync = strpos($matches['body'], "_markaspot_sync_management_package_config([\$view_name])");
    $labels = strpos($matches['body'], '_markaspot_ensure_management_view_translations()');
    $prune = strpos($matches['body'], '_markaspot_prune_management_view_override_orphans()');
    $this->assertIsInt($sync);
    $this->assertIsInt($labels);
    $this->assertIsInt($prune);
    $this->assertLessThan($labels, $sync);
    $this->assertLessThan($prune, $labels);
    $this->assertStringContainsString("Settings::get('markaspot_manage_management_view', TRUE)", $matches['body']);
  }

  /**
   * Builds a minimal base View without the publication state handlers.
   *
   * @return array<string, mixed>
   *   The base View config.
   */
  private function baseView(): array {
    return [
      'id' => 'management',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'display_options' => [
            'fields' => [
              'title' => ['id' => 'title', 'table' => 'node', 'field' => 'title'],
            ],
            'filters' => [
              'title' => ['id' => 'title', 'table' => 'node', 'field' => 'title'],
            ],
            'sorts' => [
              'created' => ['id' => 'created', 'table' => 'node', 'field' => 'created'],
            ],
            'header' => [
              'result' => ['id' => 'result', 'plugin_id' => 'result'],
            ],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'display_options' => [
            'path' => 'admin/content/management',
          ],
        ],
      ],
    ];
  }

}
