<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the GeoJsonBoundary geo-containment logic.
 *
 * @group markaspot_validation
 * @coversDefaultClass \Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary
 */
class GeoJsonBoundaryTest extends UnitTestCase {

  /**
   * A simple square polygon GeoJSON for reuse.
   *
   * Square from (0,0) to (10,10).
   *
   * @var array
   */
  protected array $squareGeoJson = [
    'type' => 'Polygon',
    'coordinates' => [
      [
        [0, 0],
        [10, 0],
        [10, 10],
        [0, 10],
        [0, 0],
      ],
    ],
  ];

  /**
   * Tests point inside a simple Polygon geometry.
   *
   * @covers ::contains
   */
  public function testPointInsidePolygon(): void {
    $boundary = new GeoJsonBoundary($this->squareGeoJson);
    $this->assertTrue($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests point outside a simple Polygon geometry.
   *
   * @covers ::contains
   */
  public function testPointOutsidePolygon(): void {
    $boundary = new GeoJsonBoundary($this->squareGeoJson);
    $this->assertFalse($boundary->contains(15.0, 5.0));
  }

  /**
   * Tests point inside a Feature wrapping a Polygon.
   *
   * @covers ::contains
   */
  public function testPointInsideFeature(): void {
    $feature = [
      'type' => 'Feature',
      'properties' => [],
      'geometry' => $this->squareGeoJson,
    ];
    $boundary = new GeoJsonBoundary($feature);
    $this->assertTrue($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests point outside a Feature wrapping a Polygon.
   *
   * @covers ::contains
   */
  public function testPointOutsideFeature(): void {
    $feature = [
      'type' => 'Feature',
      'properties' => [],
      'geometry' => $this->squareGeoJson,
    ];
    $boundary = new GeoJsonBoundary($feature);
    $this->assertFalse($boundary->contains(15.0, 15.0));
  }

  /**
   * Tests point inside a FeatureCollection.
   *
   * @covers ::contains
   */
  public function testPointInsideFeatureCollection(): void {
    $collection = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => [],
          'geometry' => $this->squareGeoJson,
        ],
      ],
    ];
    $boundary = new GeoJsonBoundary($collection);
    $this->assertTrue($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests point outside all features in a FeatureCollection.
   *
   * @covers ::contains
   */
  public function testPointOutsideFeatureCollection(): void {
    $collection = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => [],
          'geometry' => $this->squareGeoJson,
        ],
      ],
    ];
    $boundary = new GeoJsonBoundary($collection);
    $this->assertFalse($boundary->contains(15.0, 15.0));
  }

  /**
   * Tests MultiPolygon with two separate polygons.
   *
   * @covers ::contains
   */
  public function testMultiPolygonContainment(): void {
    $multi = [
      'type' => 'MultiPolygon',
      'coordinates' => [
        // First polygon: square at (0,0)-(5,5).
        [
          [
            [0, 0],
            [5, 0],
            [5, 5],
            [0, 5],
            [0, 0],
          ],
        ],
        // Second polygon: square at (20,20)-(25,25).
        [
          [
            [20, 20],
            [25, 20],
            [25, 25],
            [20, 25],
            [20, 20],
          ],
        ],
      ],
    ];
    $boundary = new GeoJsonBoundary($multi);

    // Inside first polygon.
    $this->assertTrue($boundary->contains(2.0, 2.0));
    // Inside second polygon.
    $this->assertTrue($boundary->contains(22.0, 22.0));
    // Between both polygons (outside).
    $this->assertFalse($boundary->contains(10.0, 10.0));
  }

  /**
   * Tests polygon with a hole excludes points in the hole.
   *
   * @covers ::contains
   */
  public function testPolygonWithHole(): void {
    $polygonWithHole = [
      'type' => 'Polygon',
      'coordinates' => [
        // Exterior ring: (0,0)-(10,10).
        [
          [0, 0],
          [10, 0],
          [10, 10],
          [0, 10],
          [0, 0],
        ],
        // Hole: (3,3)-(7,7).
        [
          [3, 3],
          [7, 3],
          [7, 7],
          [3, 7],
          [3, 3],
        ],
      ],
    ];
    $boundary = new GeoJsonBoundary($polygonWithHole);

    // Inside exterior but outside hole.
    $this->assertTrue($boundary->contains(1.0, 1.0));
    // Inside the hole.
    $this->assertFalse($boundary->contains(5.0, 5.0));
    // Completely outside.
    $this->assertFalse($boundary->contains(15.0, 15.0));
  }

  /**
   * Tests that an unknown geometry type returns false.
   *
   * @covers ::contains
   */
  public function testUnknownGeometryTypeReturnsFalse(): void {
    $unknown = [
      'type' => 'LineString',
      'coordinates' => [[0, 0], [10, 10]],
    ];
    $boundary = new GeoJsonBoundary($unknown);
    $this->assertFalse($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests that empty geometry returns false.
   *
   * @covers ::contains
   */
  public function testEmptyGeometryReturnsFalse(): void {
    $boundary = new GeoJsonBoundary(['type' => 'Polygon', 'coordinates' => []]);
    $this->assertFalse($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests fromJson with valid JSON.
   *
   * @covers ::fromJson
   */
  public function testFromJsonValid(): void {
    $json = json_encode($this->squareGeoJson);
    $boundary = GeoJsonBoundary::fromJson($json);
    $this->assertNotNull($boundary);
    $this->assertTrue($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests fromJson with invalid JSON returns null.
   *
   * @covers ::fromJson
   */
  public function testFromJsonInvalidReturnsNull(): void {
    $this->assertNull(GeoJsonBoundary::fromJson('not valid json'));
  }

  /**
   * Tests fromJson with empty string returns null.
   *
   * @covers ::fromJson
   */
  public function testFromJsonEmptyStringReturnsNull(): void {
    $this->assertNull(GeoJsonBoundary::fromJson(''));
  }

  /**
   * Tests fromJson with JSON missing type returns null.
   *
   * @covers ::fromJson
   */
  public function testFromJsonMissingTypeReturnsNull(): void {
    $this->assertNull(GeoJsonBoundary::fromJson('{"coordinates": []}'));
  }

  /**
   * Tests fromJson strips HTML tags (CKEditor widget scenario).
   *
   * @covers ::fromJson
   */
  public function testFromJsonStripsHtmlTags(): void {
    $json = '<p>' . json_encode($this->squareGeoJson) . '</p>';
    $boundary = GeoJsonBoundary::fromJson($json);
    $this->assertNotNull($boundary);
    $this->assertTrue($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests FeatureCollection with empty features list.
   *
   * @covers ::contains
   */
  public function testEmptyFeatureCollection(): void {
    $boundary = new GeoJsonBoundary([
      'type' => 'FeatureCollection',
      'features' => [],
    ]);
    $this->assertFalse($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests MultiPolygon with empty coordinates.
   *
   * @covers ::contains
   */
  public function testEmptyMultiPolygon(): void {
    $boundary = new GeoJsonBoundary([
      'type' => 'MultiPolygon',
      'coordinates' => [],
    ]);
    $this->assertFalse($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests Feature with empty geometry.
   *
   * @covers ::contains
   */
  public function testFeatureWithEmptyGeometry(): void {
    $boundary = new GeoJsonBoundary([
      'type' => 'Feature',
      'properties' => [],
      'geometry' => [],
    ]);
    $this->assertFalse($boundary->contains(5.0, 5.0));
  }

  /**
   * Tests FeatureCollection with multiple features returns true for any match.
   *
   * @covers ::contains
   */
  public function testFeatureCollectionMultipleFeatures(): void {
    $collection = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => [],
          'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [
              [[0, 0], [5, 0], [5, 5], [0, 5], [0, 0]],
            ],
          ],
        ],
        [
          'type' => 'Feature',
          'properties' => [],
          'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [
              [[20, 20], [25, 20], [25, 25], [20, 25], [20, 20]],
            ],
          ],
        ],
      ],
    ];
    $boundary = new GeoJsonBoundary($collection);

    // In first feature.
    $this->assertTrue($boundary->contains(2.0, 2.0));
    // In second feature.
    $this->assertTrue($boundary->contains(22.0, 22.0));
    // In neither.
    $this->assertFalse($boundary->contains(10.0, 10.0));
  }

  /**
   * Tests Polygon with empty first ring returns false.
   *
   * @covers ::contains
   */
  public function testPolygonWithEmptyRing(): void {
    $boundary = new GeoJsonBoundary([
      'type' => 'Polygon',
      'coordinates' => [[]],
    ]);
    $this->assertFalse($boundary->contains(5.0, 5.0));
  }

}
