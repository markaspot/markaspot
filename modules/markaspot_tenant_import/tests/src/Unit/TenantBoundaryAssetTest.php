<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\markaspot_tenant_import\Service\TenantBoundaryAsset;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests fail-closed reading of local jurisdiction boundary files.
 */
#[Group('markaspot_tenant_import')]
final class TenantBoundaryAssetTest extends UnitTestCase {

  /**
   * Temporary asset directory.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/boundary-asset-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->directory . '/*') ?: [] as $file) {
      unlink($file);
    }
    rmdir($this->directory);
    parent::tearDown();
  }

  /**
   * A polygon around the map center is normalized and hashed.
   */
  public function testValidBoundaryIsNormalized(): void {
    $this->write('boundary.geojson', $this->square());
    $this->assertNull(TenantBoundaryAsset::read('', $this->directory, [11.0, 50.0]));
    $boundary = TenantBoundaryAsset::read('boundary.geojson', $this->directory, [11.0, 50.0]);
    $decoded = json_decode($boundary['json'], TRUE);
    $this->assertSame('FeatureCollection', $decoded['type']);
    $this->assertSame('Polygon', $decoded['features'][0]['geometry']['type']);
    $this->assertSame(hash('sha256', $boundary['json']), $boundary['hash']);
    // The normalized form is stable, so reruns can detect "unchanged".
    $this->write('again.json', $boundary['json']);
    $this->assertSame($boundary['json'], TenantBoundaryAsset::read('again.json', $this->directory, [11.0, 50.0])['json']);
  }

  /**
   * A boundary of another municipality fails before any write.
   */
  public function testMapCenterOutsideBoundaryIsRejected(): void {
    $this->write('boundary.geojson', $this->square());
    $this->expectExceptionMessage('tenant.map_center lies outside the boundary file');
    TenantBoundaryAsset::read('boundary.geojson', $this->directory, [7.08, 51.17]);
  }

  /**
   * A center inside an enclave (polygon hole) is outside the municipality.
   */
  public function testMapCenterInsideHoleIsRejected(): void {
    $this->write('enclave.geojson', json_encode([
      'type' => 'MultiPolygon',
      'coordinates' => [[
        [[10.5, 49.5], [11.5, 49.5], [11.5, 50.5], [10.5, 50.5], [10.5, 49.5]],
        [[10.9, 49.9], [11.1, 49.9], [11.1, 50.1], [10.9, 50.1], [10.9, 49.9]],
      ]],
    ]));
    $this->assertNotNull(TenantBoundaryAsset::read('enclave.geojson', $this->directory, [10.6, 49.6]));
    $this->expectExceptionMessage('tenant.map_center lies outside the boundary file');
    TenantBoundaryAsset::read('enclave.geojson', $this->directory, [11.0, 50.0]);
  }

  /**
   * Paths, extensions, JSON and geometry types are checked fail-closed.
   */
  public function testInvalidInputIsRejected(): void {
    $this->write('boundary.geojson', $this->square());
    $this->write('point.geojson', json_encode(['type' => 'Point', 'coordinates' => [11.0, 50.0]]));
    $this->write('broken.geojson', '{"type":');
    $this->write('boundary.txt', $this->square());
    foreach ([
      ['../boundary.geojson', NULL],
      ['/etc/passwd', NULL],
      ['boundary.txt', 'extension'],
      ['missing.geojson', 'readable file'],
      ['broken.geojson', 'not valid JSON'],
      ['point.geojson', 'not a usable polygon'],
    ] as [$name, $message]) {
      try {
        TenantBoundaryAsset::read($name, $this->directory, [11.0, 50.0]);
        $this->fail("Expected rejection of $name.");
      }
      catch (\RuntimeException $exception) {
        if ($message !== NULL) {
          $this->assertStringContainsString($message, $exception->getMessage());
        }
      }
    }
    $this->expectExceptionMessage('existing --assets-dir');
    TenantBoundaryAsset::read('boundary.geojson', NULL, [11.0, 50.0]);
  }

  /**
   * Writes a fixture file into the asset directory.
   */
  private function write(string $name, string $contents): void {
    file_put_contents($this->directory . '/' . $name, $contents);
  }

  /**
   * Returns a small square polygon around [11, 50] as GeoJSON.
   */
  private function square(): string {
    return json_encode([
      'type' => 'Feature',
      'properties' => ['name' => 'Test'],
      'geometry' => [
        'type' => 'Polygon',
        'coordinates' => [[[10.9, 49.9], [11.1, 49.9], [11.1, 50.1], [10.9, 50.1], [10.9, 49.9]]],
      ],
    ]);
  }

}
