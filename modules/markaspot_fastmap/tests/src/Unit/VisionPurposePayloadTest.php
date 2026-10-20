<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_fastmap\Service\VisionPurposeRules;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\Tests\markaspot_vision\Traits\ProviderPayloadCaptureTrait;
use Drupal\Tests\markaspot_vision\Unit\ProviderPayloadGoldenTest;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs the FastMap rules through the real vision request assembly.
 *
 * Compares with the golden fixtures of markaspot_vision, recorded from the
 * 11.9.222 code: report workspaces send the same bytes as installs without
 * FastMap, the other presets keep the category part byte for byte.
 */
#[CoversClass(VisionPurposeRules::class)]
#[Group('markaspot_fastmap')]
final class VisionPurposePayloadTest extends UnitTestCase {

  use ProviderPayloadCaptureTrait;

  /**
   * A module handler that runs only the FastMap implementation.
   */
  private function fastmapHandler(): ModuleHandlerInterface {
    $rules = new VisionPurposeRules(new CitizenWordingResolver());
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('alter')->willReturnCallback(static function (string $hook, array &$parts, array $context) use ($rules): void {
      if ($hook === 'markaspot_vision_prompt') {
        $rules->alter($parts, $context);
      }
    });
    return $handler;
  }

  /**
   * Report workspaces on FastMap send the recorded 11.9.222 request.
   */
  #[DataProvider('reportCases')]
  public function testReportPayloadIsUnchanged(array $case): void {
    $name = $this->dataName();
    $expected = (string) file_get_contents(ProviderPayloadGoldenTest::fixturePath('report-' . $name));
    $this->assertSame($expected, $this->captureProviderBody($case, $this->fastmapHandler()), $name);
  }

  /**
   * Report cases of the vision golden test.
   */
  public static function reportCases(): array {
    return ProviderPayloadGoldenTest::reportCases();
  }

  /**
   * Category part cases of the vision golden test.
   */
  public static function categoryPartCases(): array {
    return ProviderPayloadGoldenTest::categoryPartCases();
  }

  /**
   * Other presets change their framing, never the category part.
   */
  #[DataProvider('categoryPartCases')]
  public function testCategoryPartIsUnchanged(array $case): void {
    $name = $this->dataName();
    $payload = json_decode($this->captureProviderBody($case, $this->fastmapHandler()), TRUE, 512, JSON_THROW_ON_ERROR);
    $expected = (string) file_get_contents(ProviderPayloadGoldenTest::fixturePath('category-' . $name));
    $this->assertSame($expected, ProviderPayloadGoldenTest::categoryPart($payload), $name);

    $system = $payload['messages'][0]['content'][0]['text'];
    $user = $payload['messages'][1]['content'][0]['text'];
    $this->assertStringContainsString('This workspace collects', $system);
    $this->assertStringContainsString('DESCRIPTION FRAMING', $system);
    $this->assertStringContainsString('shows one scene.', $user);
    $this->assertStringContainsString("Read the scene description and the hazard assessment below in that sense; the categorization rules apply unchanged. Describe what is visible and matters for this purpose; mention damage, infrastructure issues or problems only when they are actually visible. The hazard assessment applies only to an acute hazard that is visible in the image.\n\nAnalyze the provided image(s)", $user);
    // The platform rules stay in front of every editable part.
    $this->assertLessThan(strpos($system, 'PROJECT RELEVANCE'), strpos($system, 'PLATFORM RULES'));
    $this->assertStringEndsWith('Describe the situation and the issue, not the people.', $system);
  }

  /**
   * Empty workspace purpose: the analysis gets one sentence instead.
   */
  public function testFallbackPurposeReachesTheProvider(): void {
    $case = [
      'preset' => 'entry',
      'langcode' => 'en',
      'tenant_prompt' => '',
      'group_label' => 'Courtyard cats',
      'settings' => ['system_prompt' => 'Municipal defects.'],
    ];
    $payload = json_decode($this->captureProviderBody($case, $this->fastmapHandler()), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertStringContainsString(
      'Project context (JSON string): "The map \"Courtyard cats\" collects entries in these categories: Road - Pothole, Road - Pavement \"uneven\", Green & trees."',
      $payload['messages'][0]['content'][0]['text'],
    );

    // A report workspace keeps the global context.
    $payload = json_decode($this->captureProviderBody(['preset' => 'report'] + $case, $this->fastmapHandler()), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertStringContainsString('Project context (JSON string): "Municipal defects."', $payload['messages'][0]['content'][0]['text']);
  }

}
