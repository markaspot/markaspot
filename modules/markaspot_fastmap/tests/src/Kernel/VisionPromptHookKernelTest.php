<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the real wiring of hook_markaspot_vision_prompt_alter() for FastMap.
 *
 * The unit tests call VisionPurposeRules through a mocked module handler.
 * This test goes through hook discovery, the markaspot_fastmap service
 * definition and the module handler the vision service gets from the
 * container.
 *
 * @covers ::markaspot_fastmap_markaspot_vision_prompt_alter
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class VisionPromptHookKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'image',
    'media',
    'taxonomy',
    'options',
    'datetime',
    'language',
    'color_field',
    'geolocation',
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_fastmap',
    'markaspot_vision',
  ];

  /**
   * Default parts as the vision service builds them.
   */
  private const DEFAULTS = [
    'project_context' => '',
    'relevance' => 'DEFAULT-RELEVANCE',
    'framing' => '',
    'user_prefix' => 'DEFAULT-PREFIX ',
    'user_framing' => '',
  ];

  /**
   * FastMap's procedural implementation is discovered.
   */
  public function testHookImplementationIsDiscovered(): void {
    $this->assertTrue($this->container->get('module_handler')->hasImplementations('markaspot_vision_prompt_alter', 'markaspot_fastmap'));
  }

  /**
   * The container's vision service runs the FastMap rules.
   */
  public function testVisionServiceRunsTheFastmapRules(): void {
    $service = $this->container->get('markaspot_vision.image_processing');
    $this->assertInstanceOf(ImageProcessingService::class, $service);
    $property = new \ReflectionProperty(ImageProcessingService::class, 'moduleHandler');
    $this->assertInstanceOf(ModuleHandlerInterface::class, $property->getValue($service));

    $parts = $this->promptParts($service, 'entry');
    $this->assertStringStartsWith("\n\nPROJECT RELEVANCE: This workspace collects entries;", $parts['relevance']);
    $this->assertStringStartsWith("\n\nDESCRIPTION FRAMING: ", $parts['framing']);
    $this->assertStringStartsWith('This is not a defect-reporting map: it collects entries', $parts['user_framing']);
    $this->assertSame('The map "Courtyard cats" collects entries in these categories: Cats.', $parts['project_context']);

    // Report workspaces keep the vision defaults.
    $this->assertSame(self::DEFAULTS, $this->promptParts($service, 'report'));
  }

  /**
   * Runs the service's prompt part assembly for a workspace preset.
   */
  private function promptParts(ImageProcessingService $service, string $preset): array {
    $method = new \ReflectionMethod(ImageProcessingService::class, 'promptParts');
    return $method->invoke($service, self::DEFAULTS, [
      'jurisdiction_id' => 7,
      'group' => $this->workspace($preset),
      'preset' => $preset,
      'langcode' => 'en',
      'language' => 'English',
      'image_count' => 1,
      'categories' => [['tid' => 1, 'path' => 'Cats', 'label' => 'Cats']],
      'tenant_context' => '',
      'global_context' => '',
    ]);
  }

  /**
   * Builds a workspace whose Nuxt config carries the wording preset.
   */
  private function workspace(string $preset): GroupInterface {
    $config = $this->createMock(FieldItemListInterface::class);
    $config->method('isEmpty')->willReturn(FALSE);
    $config->method('__get')->willReturnCallback(static fn (string $name) => $name === 'value'
      ? json_encode($preset === 'report' ? [] : ['i18n' => ['wording' => $preset]])
      : NULL);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(7);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('label')->willReturn('Courtyard cats');
    $group->method('hasField')->willReturnCallback(static fn (string $name) => $name === 'field_nuxt_config');
    $group->method('get')->willReturn($config);
    return $group;
  }

}
