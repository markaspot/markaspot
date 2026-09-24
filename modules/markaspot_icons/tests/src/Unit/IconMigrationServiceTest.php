<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_icons\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\iconify_field\Service\IconResolverInterface;
use Drupal\markaspot_icons\IconMigrationService;
use Drupal\Tests\UnitTestCase;
use Iconify\IconsJSON\Finder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests deterministic icon migration mapping and legacy notation handling.
 */
#[CoversClass(IconMigrationService::class)]
#[Group('markaspot_icons')]
class IconMigrationServiceTest extends UnitTestCase {

  /**
   * Tests known aliases are rewritten to canonical i-lucide-* values.
   */
  public function testLucideAliasesAreCanonicalized(): void {
    $service = $this->createService();

    $colon = $service->planIconRepair('lucide:child');
    $this->assertSame('i-lucide-baby', $colon['replacement']);
    $this->assertSame('alias', $colon['repair']);

    $iconify = $service->planIconRepair('i-lucide-tree');
    $this->assertSame('i-lucide-trees', $iconify['replacement']);
    $this->assertSame('alias', $iconify['repair']);

    $legacyCircle = $service->planIconRepair('i-lucide-plus-circle');
    $this->assertSame('i-lucide-circle-plus', $legacyCircle['replacement']);
  }

  /**
   * Tests aliases supplied by the installed Lucide collection.
   */
  public function testCollectionAliasIsCanonicalized(): void {
    $resolver = $this->createMock(IconResolverInterface::class);
    $resolver->expects($this->once())
      ->method('loadCollection')
      ->with('lucide')
      ->willReturn([
        'aliases' => [
          'circle-help' => ['parent' => 'circle-question-mark'],
        ],
      ]);

    $plan = $this->createService($resolver)
      ->inspectIconValue('lucide:circle-help');

    $this->assertSame('i-lucide-circle-question-mark', $plan['replacement']);
    $this->assertSame('alias', $plan['repair']);
  }

  /**
   * Tests canonical names stay unchanged when they resolve.
   */
  public function testResolvableTreePineRemainsUnchanged(): void {
    $plan = $this->createService()->planIconRepair(
      'i-lucide-tree-pine',
      TRUE,
    );

    $this->assertNull($plan['issue']);
    $this->assertNull($plan['replacement']);
  }

  /**
   * Tests unresolvable Lucide names are reported and kept.
   */
  public function testUnresolvableLucideIconsAreKept(): void {
    $service = $this->createService();

    foreach (['lucide:not-an-icon', 'i-lucide-not-an-icon'] as $icon) {
      $plan = $service->planIconRepair($icon, FALSE);
      $this->assertSame('lucide-unresolved', $plan['issue'], $icon);
      $this->assertNull($plan['replacement'], $icon);
      $this->assertSame('manual-review', $plan['repair'], $icon);
      $this->assertFalse($plan['fixable'], $icon);
    }
  }

  /**
   * Tests FontAwesome leftovers use aliases and keep unknown names.
   */
  public function testFontAwesomeLeftoversUseAliasesAndKeepUnknownNames(): void {
    $service = $this->createService();

    $alias = $service->planIconRepair('fa-child');
    $this->assertSame('i-lucide-baby', $alias['replacement']);
    $this->assertSame('alias', $alias['repair']);
    $this->assertTrue($alias['fixable']);

    $unknown = $service->planIconRepair('fa-made-up', FALSE);
    $this->assertSame('fontawesome', $unknown['issue']);
    $this->assertNull($unknown['replacement']);
    $this->assertSame('manual-review', $unknown['repair']);
    $this->assertFalse($unknown['fixable']);
  }

  /**
   * Tests missing resolver behavior is conservative.
   */
  public function testMissingResolverDoesNotGuess(): void {
    $service = $this->createService();
    $plan = $service->planIconRepair('lucide:custom-name');

    $this->assertSame('resolver-unavailable', $plan['issue']);
    $this->assertNull($plan['replacement']);
    $this->assertFalse($plan['fixable']);

    $fontAwesome = $service->planIconRepair('fa-made-up');
    $this->assertSame('manual-review', $fontAwesome['repair']);
    $this->assertNull($fontAwesome['replacement']);
    $this->assertFalse($fontAwesome['fixable']);

    $bareName = $service->planIconRepair('fire-extinguisher');
    $this->assertSame('legacy-name', $bareName['issue']);
    $this->assertNull($bareName['replacement']);
    $this->assertFalse($bareName['fixable']);
  }

  /**
   * Tests legacy notations resolve through the mapping table and aliases.
   */
  #[DataProvider('legacyNotationProvider')]
  public function testLegacyNotationsAreNormalized(string $icon, string $expected, string $issue, string $repair): void {
    $plan = $this->createService()->planIconRepair($icon);

    $this->assertSame($expected, $plan['replacement']);
    $this->assertSame($issue, $plan['issue']);
    $this->assertSame($repair, $plan['repair']);
    $this->assertTrue($plan['fixable']);
  }

  /**
   * Provides legacy icon values found in 11.7 databases.
   *
   * @return array<string, array{string, string, string, string}>
   *   Stored value, replacement, issue and repair.
   */
  public static function legacyNotationProvider(): array {
    return [
      // FontAwesome 5 class strings, as stored by Kamp-Lintfort.
      'fas map-signs' => ['fas fa-map-signs', 'i-lucide-signpost', 'fontawesome', 'mapping'],
      'far trash-alt' => ['far fa-trash-alt', 'i-lucide-trash-2', 'fontawesome', 'mapping'],
      'fas car-side' => ['fas fa-car-side', 'i-lucide-car', 'fontawesome', 'mapping'],
      'fas child' => ['fas fa-child', 'i-lucide-baby', 'fontawesome', 'alias'],
      'fas walking' => ['fas fa-walking', 'i-lucide-footprints', 'fontawesome', 'mapping'],
      'fas traffic-light' => ['fas fa-traffic-light', 'i-lucide-traffic-cone', 'fontawesome', 'mapping'],
      'fas tree' => ['fas fa-tree', 'i-lucide-tree-pine', 'fontawesome', 'mapping'],
      'fas bicycle' => ['fas fa-bicycle', 'i-lucide-bike', 'fontawesome', 'mapping'],
      'far lightbulb' => ['far fa-lightbulb', 'i-lucide-lightbulb', 'fontawesome', 'mapping'],
      'fab stack-overflow' => ['fab fa-stack-overflow', 'i-lucide-archive', 'fontawesome', 'mapping'],
      'fal walking' => ['fal fa-walking', 'i-lucide-footprints', 'fontawesome', 'mapping'],
      'fal car-side' => ['fal fa-car-side', 'i-lucide-car', 'fontawesome', 'mapping'],
      // FontAwesome 4 and FontAwesome 6 variants.
      'fa check' => ['fa-check', 'i-lucide-check', 'fontawesome', 'mapping'],
      'fa with modifiers' => ['fa fa-home fa-fw fa-2x', 'i-lucide-house', 'fontawesome', 'mapping'],
      'fa6 style class' => ['fa-solid fa-universal-access', 'i-lucide-accessibility', 'fontawesome', 'mapping'],
      'outline suffix' => ['fa-stop-circle-o', 'i-lucide-circle-stop', 'fontawesome', 'mapping'],
      'alt suffix' => ['fas fa-map-marker-alt', 'i-lucide-map-pin', 'fontawesome', 'mapping'],
      'fa colon' => ['fa:trash', 'i-lucide-trash', 'fontawesome', 'mapping'],
      'casing and whitespace' => [' FAS FA-TREE ', 'i-lucide-tree-pine', 'fontawesome', 'mapping'],
      // Bare names, as stored in page icons.
      'bare walking' => ['walking', 'i-lucide-footprints', 'legacy-name', 'mapping'],
      'bare child' => ['child', 'i-lucide-baby', 'legacy-name', 'alias'],
      'bare info-circle' => ['info-circle', 'i-lucide-info', 'legacy-name', 'mapping'],
      'bare universal-access' => ['universal-access', 'i-lucide-accessibility', 'legacy-name', 'mapping'],
      // Set:name pairs the Nuxt UI derives from FontAwesome classes.
      'map:signs' => ['map:signs', 'i-lucide-signpost', 'legacy-name', 'mapping'],
      'trash:alt' => ['trash:alt', 'i-lucide-trash-2', 'legacy-name', 'mapping'],
      'car:side' => ['car:side', 'i-lucide-car', 'legacy-name', 'mapping'],
      // Lucide colon notation.
      'lucide:tree' => ['lucide:tree', 'i-lucide-trees', 'lucide-alias', 'alias'],
    ];
  }

  /**
   * Tests names that exist unchanged in Lucide need a positive lookup.
   */
  public function testIdentityNamesRequireResolvableName(): void {
    $service = $this->createService();

    $resolvable = $service->planIconRepair('fas fa-fire-extinguisher', TRUE);
    $this->assertSame('i-lucide-fire-extinguisher', $resolvable['replacement']);
    $this->assertSame('notation', $resolvable['repair']);

    $colon = $service->planIconRepair('lucide:tree-pine', TRUE);
    $this->assertSame('i-lucide-tree-pine', $colon['replacement']);
    $this->assertSame('lucide-notation', $colon['issue']);

    $casing = $service->planIconRepair('I-Lucide-Trash-2', TRUE);
    $this->assertSame('i-lucide-trash-2', $casing['replacement']);

    $unresolvable = $service->planIconRepair('fa-pied-piper-alt', FALSE);
    $this->assertNull($unresolvable['replacement']);
    $this->assertSame('manual-review', $unresolvable['repair']);
  }

  /**
   * Tests inspection checks derived names against the Lucide collection.
   */
  public function testInspectionUsesLucideCollection(): void {
    $resolver = $this->createMock(IconResolverInterface::class);
    $resolver->method('loadCollection')
      ->with('lucide')
      ->willReturn([
        'icons' => [
          'bug' => ['body' => ''],
          'fire-extinguisher' => ['body' => ''],
        ],
        'aliases' => [],
      ]);
    $service = $this->createService($resolver);

    $this->assertSame('i-lucide-bug', $service->inspectIconValue('fa-bug')['replacement']);
    $this->assertSame('i-lucide-fire-extinguisher', $service->inspectIconValue('fas fa-fire-extinguisher')['replacement']);
    $this->assertNull($service->inspectIconValue('i-lucide-bug')['issue']);

    $unknown = $service->inspectIconValue('fa-pied-piper-alt');
    $this->assertSame('fontawesome', $unknown['issue']);
    $this->assertNull($unknown['replacement']);
    $this->assertFalse($unknown['fixable']);

    $unresolved = $service->inspectIconValue('i-lucide-made-up');
    $this->assertSame('lucide-unresolved', $unresolved['issue']);
    $this->assertNull($unresolved['replacement']);
  }

  /**
   * Tests resolver failures are reported without a replacement.
   */
  public function testResolverErrorIsReported(): void {
    $resolver = $this->createMock(IconResolverInterface::class);
    $resolver->method('loadCollection')
      ->willThrowException(new \RuntimeException('Collection unreadable.'));

    $plan = $this->createService($resolver)->inspectIconValue('i-lucide-bug');

    $this->assertSame('resolver-error', $plan['issue']);
    $this->assertNull($plan['replacement']);
    $this->assertFalse($plan['fixable']);
  }

  /**
   * Tests values the UI renders or nobody can interpret stay untouched.
   */
  public function testForeignAndUnrecognizedValuesAreNotRewritten(): void {
    $service = $this->createService();

    foreach (['i-heroicons-trash', 'heroicons:trash', 'i-fa6-solid-house'] as $icon) {
      $this->assertNull($service->planIconRepair($icon, FALSE)['issue'], $icon);
    }

    foreach (['fa-trash fa-car', 'i-lucide-tree_pine', '<svg></svg>'] as $icon) {
      $plan = $service->planIconRepair($icon, TRUE);
      $this->assertSame('unrecognized', $plan['issue'], $icon);
      $this->assertNull($plan['replacement'], $icon);
      $this->assertFalse($plan['fixable'], $icon);
    }
  }

  /**
   * Tests every replacement is canonical, so a second run changes nothing.
   */
  public function testReplacementsAreIdempotent(): void {
    $service = $this->createService();

    foreach (self::legacyNotationProvider() as $case) {
      $replacement = $service->planIconRepair($case[0])['replacement'];
      $this->assertIsString($replacement);
      $second = $service->planIconRepair($replacement, TRUE);
      $this->assertNull($second['issue'], $case[0] . ' -> ' . $replacement);
    }
  }

  /**
   * Tests every Lucide mapping target exists in the installed collection.
   */
  public function testLucideMappingTargetsExist(): void {
    if (!class_exists(Finder::class) || !is_file(Finder::locate('lucide'))) {
      $this->markTestSkipped('The iconify/json Lucide collection is not installed.');
    }
    $collection = json_decode((string) file_get_contents(Finder::locate('lucide')), TRUE);
    $service = $this->createService();

    foreach ($service->getIconMappingPreview('fa_to_iconify', 'lucide') as $source => $target) {
      $this->assertMatchesRegularExpression('/^i-lucide-[a-z0-9]+(?:-[a-z0-9]+)*$/', $target, $source);
      $name = substr($target, strlen('i-lucide-'));
      $this->assertArrayHasKey($name, $collection['icons'], "$source maps to missing Lucide icon $target.");
    }
  }

  /**
   * Tests bare names that are Lucide icons are not rewritten by the table.
   */
  #[DataProvider('bareLucideNameProvider')]
  public function testBareLucideNamesKeepTheirLucideIcon(string $name): void {
    $plan = $this->createService($this->createLucideResolver())->inspectIconValue($name);

    $this->assertSame('i-lucide-' . $name, $plan['replacement']);
    $this->assertSame('legacy-name', $plan['issue']);
    $this->assertSame('notation', $plan['repair']);
  }

  /**
   * Provides Lucide names that also have a FontAwesome table entry.
   *
   * @return array<string, array{string}>
   *   Bare icon names.
   */
  public static function bareLucideNameProvider(): array {
    $names = [
      'circle', 'cog', 'cloud-rain', 'road', 'chart-bar',
      'line-chart', 'pie-chart', 'area-chart', 'university', 'bolt',
    ];
    return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
  }

  /**
   * Tests FontAwesome classes keep FontAwesome semantics, bare names Lucide's.
   */
  public function testFontAwesomeAndBareNamesUseTheirOwnSemantics(): void {
    $service = $this->createService($this->createLucideResolver());

    $this->assertSame('i-lucide-zap', $service->inspectIconValue('fa-bolt')['replacement']);
    $this->assertSame('i-lucide-circle-dot', $service->inspectIconValue('fas fa-circle')['replacement']);
    $this->assertSame('i-lucide-chart-bar', $service->inspectIconValue('fas fa-chart-bar')['replacement']);
    $this->assertSame('i-lucide-square-check', $service->inspectIconValue('fas fa-check-square')['replacement']);
    $this->assertSame('i-lucide-tram-front', $service->inspectIconValue('fas fa-train')['replacement']);

    $this->assertSame('i-lucide-square-check-big', $service->inspectIconValue('check-square')['replacement']);
    $this->assertSame('i-lucide-tram-front', $service->inspectIconValue('train')['replacement']);
    $this->assertSame('i-lucide-footprints', $service->inspectIconValue('walking')['replacement']);
  }

  /**
   * Tests a rejected save is reported and does not abort the run.
   */
  public function testSaveFailureIsReportedAndDoesNotAbortTheRun(): void {
    $blocked = $this->createPageEntity(1, 'Blocked page', 'info-circle');
    $blocked->expects($this->once())
      ->method('save')
      ->willThrowException(new \RuntimeException('Workspace is blocked.'));
    $open = $this->createPageEntity(2, 'Open page', 'universal-access');
    $open->expects($this->once())->method('save');

    $condition = $this->createMock(ConditionInterface::class);
    $condition->method('exists')->willReturnSelf();
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('orConditionGroup')->willReturn($condition);
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([11 => 1, 12 => 2]);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([1 => $blocked, 2 => $open]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->willReturn(TRUE);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldMapByFieldType')
      ->with('fa_icon_class')
      ->willReturn(['node' => ['field_page_icon' => ['type' => 'fa_icon_class', 'bundles' => ['page' => 'page']]]]);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('could not save'),
        $this->callback(static fn (array $context): bool => $context['@id'] === 1
          && $context['@label'] === 'Blocked page'
          && $context['@message'] === 'Workspace is blocked.'),
      );
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('markaspot_icons')->willReturn($logger);

    $service = new IconMigrationService(
      $this->createMock(Connection::class),
      $entityTypeManager,
      $loggerFactory,
      $this->createMock(MessengerInterface::class),
      NULL,
      NULL,
      NULL,
      $fieldManager,
    );
    $report = $service->validateAndRepair(TRUE);

    $this->assertSame([1, 2], array_column($report, 'entity_id'));
    $this->assertSame(['error', 'fixed'], array_column($report, 'action'));
    $this->assertSame(['i-lucide-info', 'i-lucide-accessibility'], array_column($report, 'replacement'));
  }

  /**
   * Creates a page entity mock with one untranslated icon value.
   */
  private function createPageEntity(int $id, string $label, string $icon): ContentEntityInterface&MockObject {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('__isset')->willReturn(TRUE);
    $item->method('__get')->with('value')->willReturn($icon);
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('isTranslatable')->willReturn(TRUE);
    $field = $this->createMock(FieldItemList::class);
    $field->method('getFieldDefinition')->willReturn($definition);
    $field->method('getIterator')->willReturn(new \ArrayIterator([$item]));
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getTranslationLanguages')->willReturn(['en' => $language]);
    $entity->method('getTranslation')->willReturnSelf();
    $entity->method('hasField')->willReturn(TRUE);
    $entity->method('get')->with('field_page_icon')->willReturn($field);
    $entity->method('language')->willReturn($language);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn($id);
    $entity->method('label')->willReturn($label);
    return $entity;
  }

  /**
   * Creates a resolver whose Lucide collection mirrors the real one.
   */
  private function createLucideResolver(): IconResolverInterface {
    $icons = [
      'circle', 'cog', 'cloud-rain', 'road', 'chart-bar', 'line-chart',
      'pie-chart', 'area-chart', 'university', 'bolt', 'zap', 'circle-dot',
      'square-check', 'square-check-big', 'tram-front', 'footprints',
    ];
    $resolver = $this->createMock(IconResolverInterface::class);
    $resolver->method('loadCollection')
      ->with('lucide')
      ->willReturn([
        'icons' => array_fill_keys($icons, ['body' => '']),
        'aliases' => [
          'check-square' => ['parent' => 'square-check-big'],
          'train' => ['parent' => 'tram-front'],
        ],
      ]);
    return $resolver;
  }

  /**
   * Creates the service with an optional Iconify resolver.
   */
  private function createService(?IconResolverInterface $resolver = NULL): IconMigrationService {
    return new IconMigrationService(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(MessengerInterface::class),
      $resolver,
    );
  }

}
