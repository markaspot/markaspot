<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\markaspot_tenant_import\Service\TenantLogoAsset;
use Drupal\markaspot_tenant_import\Service\TenantRuntimeConfiguration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests runtime mapping and fail-closed asset validation.
 */
#[Group('markaspot_tenant_import')]
final class TenantRuntimeConfigurationTest extends UnitTestCase {

  /**
   * Unified reporting keeps explicit photo and AI consent choices.
   */
  public function testUnifiedReportingContract(): void {
    $config = ['enabled' => TRUE, 'aiMode' => 'opt_in', 'photoPolicy' => 'optional'];
    $tenant = ['features' => ['unifiedReporting' => $config]];
    $this->assertSame([], TenantRuntimeConfiguration::validate($tenant));
    $this->assertSame($config, TenantRuntimeConfiguration::merge([], $tenant)['features']['unifiedReporting']);
    foreach ([
      TRUE, ['enabled' => TRUE],
      array_replace($config, ['aiMode' => 'always']),
      $config + ['script' => 'unsafe'],
    ] as $invalid) {
      $this->assertNotEmpty(TenantRuntimeConfiguration::validate(['features' => ['unifiedReporting' => $invalid]]));
    }
  }

  /**
   * Rejects unsafe CSS, coordinates and unknown switches.
   */
  public function testValidationRejectsUnsupportedAndUnsafeValues(): void {
    foreach ([
      ['logo_file' => ['invalid']],
      ['logo_dark_file' => ['invalid']],
      ['font_family' => 'Arial; color:red'],
      ['font_family' => "Arial\nbody"],
      ['primary_color' => 'red'],
      ['map_center' => [181, 30]],
      ['map_center' => ['10', 30]],
      ['map_center' => [NAN, 30]],
      ['map_zoom' => '12'],
      ['map_zoom' => 0],
      ['features' => ['invented' => TRUE]],
      ['ai' => NULL],
      ['ai' => 'azure'],
      ['ai' => ['sentiment_analysis']],
      ['ai' => ['sentiment_analysis' => 'true']],
      ['ai' => ['api_key' => 'not-allowed']],
      ['ai' => ['pii_provider' => 'invented']],
      ['ai' => ['detect_names' => TRUE]],
      ['ai' => ['sentiment_analysis' => TRUE]],
      ['features' => ['aiDuplicates' => TRUE]],
      ['languages' => ['xx']],
      ['legal_notice_url' => 'javascript:alert(1)'],
    ] as $tenant) {
      $this->assertNotEmpty(TenantRuntimeConfiguration::validate($tenant));
    }
  }

  /**
   * Both local PNG references are accepted and not marked informational.
   */
  public function testSeparateThemeLogoConfiguration(): void {
    $tenant = ['logo_file' => 'logos/light.png', 'logo_dark_file' => 'logos/dark.png'];
    $this->assertSame([], TenantRuntimeConfiguration::validate($tenant));
    $this->assertSame([], TenantRuntimeConfiguration::warnings($tenant));
  }

  /**
   * Preserves unknown stored keys, replaces lists, and honors explicit false.
   */
  public function testRuntimeMergePreservesUnspecifiedConfiguration(): void {
    $existing = [
      'theme' => [
        'neutral' => '#999999',
        'fonts' => ['bodyUrl' => '/font.woff2'],
      ],
      'languages' => ['available' => ['de', 'en', 'fr']],
      'features' => ['aiAnalysis' => TRUE, 'feedback' => TRUE],
    ];
    $tenant = [
      'secondary_color' => '#FFCC00',
      'font_family' => 'Arial, Helvetica, sans-serif',
      'languages' => ['de'],
      'features' => ['aiAnalysis' => FALSE],
      'map_center' => [11.0, 50.0],
      'map_zoom' => 12,
    ];
    $this->assertSame([], TenantRuntimeConfiguration::validate($tenant));
    $merged = TenantRuntimeConfiguration::merge($existing, $tenant);
    $this->assertSame('#999999', $merged['theme']['neutral']);
    $this->assertSame('/font.woff2', $merged['theme']['fonts']['bodyUrl']);
    $this->assertFalse($merged['features']['aiAnalysis']);
    $this->assertTrue($merged['features']['feedback']);
    $this->assertSame(['de'], $merged['languages']['available']);
    $this->assertSame($merged, TenantRuntimeConfiguration::merge($merged, $tenant));
  }

  /**
   * Existing imports retain their public-policy input as explicit metadata.
   */
  public function testExistingImportDoesNotApplyPublicVisibility(): void {
    $tenant = ['features' => ['publicReports' => FALSE]];
    $this->assertSame([], TenantRuntimeConfiguration::validate($tenant));
    $this->assertSame([], TenantRuntimeConfiguration::merge([], $tenant));
    $this->assertStringContainsString('not applied', TenantRuntimeConfiguration::warnings($tenant)[0]);
  }

  /**
   * Dedicated feature policy preserves explicit false and provider separation.
   */
  public function testDedicatedAnalysisSettings(): void {
    $tenant = [
      'features' => [
        'aiProcessing' => TRUE,
        'aiDuplicates' => TRUE,
        'piiRedaction' => TRUE,
        'privacyBlockOnFlag' => FALSE,
      ],
      'ai' => ['sentiment_analysis' => TRUE, 'detect_names' => FALSE, 'pii_provider' => 'azure'],
    ];
    $this->assertSame([], TenantRuntimeConfiguration::validate($tenant));
    $settings = TenantRuntimeConfiguration::dedicatedSettings($tenant);
    $this->assertSame([
      'duplicate_detection.enabled' => TRUE,
      'pii_redaction.enabled' => TRUE,
      'sentiment_analysis.enabled' => TRUE,
      'pii_redaction.detect_names' => FALSE,
      'pii_redaction.provider' => 'azure',
    ], $settings['markaspot_ai.settings']);
    $this->assertFalse($settings['markaspot_nuxt.settings']['platform_features.privacyBlockOnFlag']);
    $this->assertSame([], TenantRuntimeConfiguration::dedicatedSettings([]));
    $tenant['features']['aiDuplicates'] = FALSE;
    $tenant['ai']['sentiment_analysis'] = FALSE;
    $settings = TenantRuntimeConfiguration::dedicatedSettings($tenant);
    $this->assertFalse($settings['markaspot_ai.settings']['duplicate_detection.enabled']);
    $this->assertFalse($settings['markaspot_ai.settings']['sentiment_analysis.enabled']);
  }

  /**
   * Traversal and symlink escapes are denied; valid PNG bytes remain stable.
   */
  public function testAssetContainmentAndValidation(): void {
    $directory = sys_get_temp_dir() . '/tenant-logo-test-' . bin2hex(random_bytes(8));
    mkdir($directory);
    mkdir($directory . '/assets');
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2uoAAAAASUVORK5CYII=');
    file_put_contents($directory . '/outside.png', $bytes);
    file_put_contents($directory . '/assets/logo.png', $bytes);
    file_put_contents($directory . '/assets/bad.png', '<svg onload="alert(1)"/>');
    symlink($directory . '/outside.png', $directory . '/assets/link.png');
    try {
      $asset = TenantLogoAsset::read('logo.png', $directory . '/assets');
      $this->assertSame(hash('sha256', $bytes), $asset['hash']);
      foreach (['../outside.png', 'link.png', 'bad.png'] as $name) {
        try {
          TenantLogoAsset::read($name, $directory . '/assets');
          $this->fail('Invalid asset was accepted: ' . $name);
        }
        catch (\RuntimeException $exception) {
          $this->assertNotEmpty($exception->getMessage());
        }
      }
    }
    finally {
      foreach (['logo.png', 'bad.png', 'link.png'] as $name) {
        unlink($directory . '/assets/' . $name);
      }
      unlink($directory . '/outside.png');
      rmdir($directory . '/assets');
      rmdir($directory);
    }
  }

}
