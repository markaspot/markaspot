<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\VisionPurposeRules;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\markaspot_vision\Service\ImageProcessingService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the purpose-based vision rules for non-report workspaces.
 */
#[CoversClass(VisionPurposeRules::class)]
#[Group('markaspot_fastmap')]
final class VisionPurposeRulesTest extends UnitTestCase {

  /**
   * Default parts as the vision service passes them.
   */
  private const DEFAULTS = [
    'project_context' => 'DEFAULT-CONTEXT',
    'relevance' => 'DEFAULT-RELEVANCE',
    'framing' => '',
    'user_prefix' => 'DEFAULT-PREFIX ',
    'user_framing' => '',
  ];

  /**
   * Runs the rules for one preset and returns the parts.
   */
  private function apply(string $preset, array $context = [], array $parts = self::DEFAULTS): array {
    $context += [
      'preset' => $preset,
      'group' => $this->workspace($preset, 'Südstadt-Katzen'),
      'image_count' => 2,
      'tenant_context' => 'Cats in courtyards. Exclude dogs.',
      'global_context' => '',
      'categories' => [
        ['tid' => 1, 'path' => 'Getigert', 'label' => 'Getigert'],
        ['tid' => 2, 'path' => 'Schwarz', 'label' => 'Schwarz'],
      ],
    ];
    (new VisionPurposeRules(new CitizenWordingResolver()))->alter($parts, $context);
    return $parts;
  }

  /**
   * Builds a workspace group with a wording preset.
   */
  private function workspace(string $preset, string $label): GroupInterface {
    $config = $this->createMock(FieldItemListInterface::class);
    $config->method('isEmpty')->willReturn(FALSE);
    $config->method('__get')->willReturnCallback(static fn (string $name) => $name === 'value'
      ? json_encode($preset === 'report' ? [] : ['i18n' => ['wording' => $preset]])
      : NULL);
    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('label')->willReturn($label);
    $group->method('hasField')->willReturnCallback(static fn (string $name) => $name === 'field_nuxt_config');
    $group->method('get')->willReturn($config);
    return $group;
  }

  /**
   * Report maps and requests without a workspace keep every default.
   */
  public function testReportMapsKeepTheDefaults(): void {
    $this->assertSame(self::DEFAULTS, $this->apply('report', ['tenant_context' => '']));
    $this->assertSame(self::DEFAULTS, $this->apply('unknown', ['tenant_context' => '']));
    $this->assertSame(self::DEFAULTS, $this->apply('entry', ['group' => NULL, 'tenant_context' => '']));

    $parts = self::DEFAULTS;
    (new VisionPurposeRules())->alter($parts, ['preset' => 'entry', 'group' => $this->workspace('entry', 'X'), 'tenant_context' => '']);
    $this->assertSame(self::DEFAULTS, $parts);
  }

  /**
   * Non-report presets.
   */
  public static function nonReportPresets(): array {
    return [
      'suggestion' => ['suggestion', 'suggestions'],
      'entry' => ['entry', 'entries'],
      'contribution' => ['contribution', 'contributions'],
    ];
  }

  /**
   * Relevance follows the purpose and categories, without fixed examples.
   */
  #[DataProvider('nonReportPresets')]
  public function testRelevanceComesFromThePurpose(string $preset, string $plural): void {
    $relevance = $this->apply($preset)['relevance'];
    $this->assertStringStartsWith("\n\nPROJECT RELEVANCE: This workspace collects {$plural}, not defects.", $relevance);
    $this->assertStringContainsString('only when the image shows a real subject that fits the purpose in the project context and at least one of the current tenant categories', $relevance);
    $this->assertStringContainsString(ImageProcessingService::RELEVANCE_OFF_DOMAIN_RULE, $relevance);
    $this->assertStringContainsString('anything the project context excludes; exclusions take precedence', $relevance);
    $this->assertStringContainsString('The category you must return is never a reason to set is_reportable_issue to true.', $relevance);
    // The 11.9.222 examples were climate and neighbourhood specific.
    foreach (['tree pit', 'sealed surface', 'standing water', 'neighbourhood', 'courtyard', 'shade'] as $example) {
      $this->assertStringNotContainsString($example, $relevance, $example);
    }
  }

  /**
   * Idea and observation maps keep the strict off-domain rule of 11.9.222.
   */
  public function testIdeaAndObservationMapsRefuseDocumentsAndMissingSubjects(): void {
    foreach (['suggestion', 'entry'] as $preset) {
      $relevance = $this->apply($preset)['relevance'];
      $this->assertStringContainsString('documents, screenshots, tables, charts, text and other non-photographic content', $relevance, $preset);
      $this->assertStringContainsString('do not show the subject this project is about', $relevance, $preset);
      $this->assertStringNotContainsString('unless the project context explicitly collects', $relevance, $preset);
    }
    $this->assertStringContainsString('nothing needs to be damaged or wrong', $this->apply('suggestion')['relevance']);
    $this->assertStringContainsString('Positive and neutral conditions count', $this->apply('entry')['relevance']);
  }

  /**
   * Contribution maps refuse screenshots unless the purpose collects them.
   */
  public function testContributionMapsLetThePurposeDecideAboutReproductions(): void {
    $relevance = $this->apply('contribution')['relevance'];
    $this->assertStringContainsString('screenshots, tables, charts, text and other non-photographic content unless the project context explicitly collects such material', $relevance);
    $this->assertStringNotContainsString('documents, screenshots', $relevance);
  }

  /**
   * Each preset frames the description and limits hazards to acute ones.
   */
  #[DataProvider('nonReportPresets')]
  public function testFramingAndHazardRule(string $preset): void {
    $parts = $this->apply($preset);
    $this->assertStringStartsWith("\n\nDESCRIPTION FRAMING: ", $parts['framing']);
    $this->assertStringContainsString('only for an acute hazard that is clearly visible in the image now', $parts['framing']);
    $this->assertStringContainsString('hazard_level to 0, hazard_category to null and hazard_issues to an empty array', $parts['framing']);
    $this->assertStringContainsString([
      'suggestion' => 'what could be suggested there',
      'entry' => 'as an observation, not as a defect',
      'contribution' => 'as a contribution to this map',
    ][$preset], $parts['framing']);
  }

  /**
   * The user prompt gets a neutral frame; the image prompt stays untouched.
   */
  #[DataProvider('nonReportPresets')]
  public function testNeutralUserFraming(string $preset, string $plural): void {
    $parts = $this->apply($preset);
    $this->assertSame('The following set of 2 images shows one scene. Please analyze them together as one complete scene. Consider how the images relate to and complement each other. ', $parts['user_prefix']);
    $this->assertStringStartsWith("This map does not collect defects: it collects {$plural}", $parts['user_framing']);
    $this->assertStringEndsWith("\n\n", $parts['user_framing']);
    $this->assertStringNotContainsString('issue.', $parts['user_prefix']);
  }

  /**
   * A purpose the workspace has is never replaced.
   */
  #[DataProvider('nonReportPresets')]
  public function testWorkspacePurposeIsKept(string $preset): void {
    $this->assertSame('DEFAULT-CONTEXT', $this->apply($preset)['project_context']);
  }

  /**
   * Without a purpose one sentence is built from name, noun and categories.
   */
  public function testFallbackPurpose(): void {
    $parts = $this->apply('entry', ['tenant_context' => '', 'global_context' => 'Municipal defects.']);
    $this->assertSame('The map "Südstadt-Katzen" collects entries in these categories: Getigert, Schwarz.', $parts['project_context']);

    $parts = $this->apply('suggestion', ['tenant_context' => '', 'categories' => []]);
    $this->assertSame('The map "Südstadt-Katzen" collects suggestions.', $parts['project_context']);
  }

  /**
   * The fallback purpose stays one bounded line of plain text.
   */
  public function testFallbackPurposeIsBounded(): void {
    $categories = [];
    for ($i = 1; $i <= 40; $i++) {
      $categories[] = ['tid' => $i, 'label' => "Cat\n{$i}\x07"];
    }
    $categories[] = ['tid' => 99, 'label' => "Cat\n1\x07"];
    $parts = $this->apply('contribution', [
      'tenant_context' => '',
      'group' => $this->workspace('contribution', "Hof\r\nkatzen\x00" . str_repeat('x', 300)),
      'categories' => $categories,
    ]);
    $context = $parts['project_context'];
    $this->assertDoesNotMatchRegularExpression('/\p{Cc}/u', $context);
    $this->assertStringContainsString('"Hof katzen ' . str_repeat('x', 109) . '"', $context);
    $this->assertStringContainsString('Cat 30, and more.', $context);
    $this->assertStringNotContainsString('Cat 31', $context);
    $this->assertSame(1, substr_count($context, 'Cat 1,'));
  }

}
