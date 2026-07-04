<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\markaspot_nuxt\Service\BoundaryGeoJsonValidator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the BoundaryGeoJsonValidator normalization and validation rules.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Service\BoundaryGeoJsonValidator
 */
final class BoundaryGeoJsonValidatorTest extends UnitTestCase {

  /**
   * A simple closed square ring, exterior only.
   *
   * @var array
   */
  protected array $squareRing = [
    [0.0, 0.0],
    [10.0, 0.0],
    [10.0, 10.0],
    [0.0, 10.0],
    [0.0, 0.0],
  ];

  /**
   * Tests NULL clears the boundary.
   *
   * @covers ::validate
   */
  public function testNullIsValidAndClears(): void {
    $result = BoundaryGeoJsonValidator::validate(NULL);
    $this->assertTrue($result['valid']);
    $this->assertNull($result['error']);
    $this->assertNull($result['normalized']);
  }

  /**
   * Tests a bare Polygon is wrapped into a FeatureCollection.
   *
   * @covers ::validate
   */
  public function testPolygonIsWrappedIntoFeatureCollection(): void {
    $polygon = ['type' => 'Polygon', 'coordinates' => [$this->squareRing]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertTrue($result['valid']);
    $this->assertSame('FeatureCollection', $result['normalized']['type']);
    $this->assertCount(1, $result['normalized']['features']);
    $this->assertSame('Feature', $result['normalized']['features'][0]['type']);
    $this->assertSame('Polygon', $result['normalized']['features'][0]['geometry']['type']);
    $this->assertSame([$this->squareRing], $result['normalized']['features'][0]['geometry']['coordinates']);
  }

  /**
   * Tests a bare MultiPolygon is wrapped into a FeatureCollection.
   *
   * @covers ::validate
   */
  public function testMultiPolygonIsWrappedIntoFeatureCollection(): void {
    $second = [[[20, 20], [25, 20], [25, 25], [20, 25], [20, 20]]];
    $multi = [
      'type' => 'MultiPolygon',
      'coordinates' => [[$this->squareRing], $second],
    ];
    $result = BoundaryGeoJsonValidator::validate($multi);

    $this->assertTrue($result['valid']);
    $this->assertCount(1, $result['normalized']['features']);
    $this->assertSame('MultiPolygon', $result['normalized']['features'][0]['geometry']['type']);
    $this->assertCount(2, $result['normalized']['features'][0]['geometry']['coordinates']);
  }

  /**
   * Tests a single Feature is wrapped into a FeatureCollection.
   *
   * @covers ::validate
   */
  public function testFeatureIsWrappedIntoFeatureCollection(): void {
    $feature = [
      'type' => 'Feature',
      'properties' => ['name' => 'Zone A'],
      'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->squareRing]],
    ];
    $result = BoundaryGeoJsonValidator::validate($feature);

    $this->assertTrue($result['valid']);
    $this->assertSame('FeatureCollection', $result['normalized']['type']);
    $this->assertSame(['name' => 'Zone A'], $result['normalized']['features'][0]['properties']);
  }

  /**
   * Tests a FeatureCollection with a hole passes through with the hole intact.
   *
   * @covers ::validate
   */
  public function testFeatureCollectionWithHolePreservesHole(): void {
    $hole = [[3.0, 3.0], [7.0, 3.0], [7.0, 7.0], [3.0, 7.0], [3.0, 3.0]];
    $collection = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => [],
          'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->squareRing, $hole]],
        ],
      ],
    ];
    $result = BoundaryGeoJsonValidator::validate($collection);

    $this->assertTrue($result['valid']);
    $this->assertCount(2, $result['normalized']['features'][0]['geometry']['coordinates']);
    $this->assertSame($hole, $result['normalized']['features'][0]['geometry']['coordinates'][1]);
  }

  /**
   * Tests an open ring (first != last position) is auto-closed, not rejected.
   *
   * @covers ::validate
   */
  public function testOpenRingIsAutoClosed(): void {
    $openRing = [[0, 0], [10, 0], [10, 10], [0, 10]];
    $polygon = ['type' => 'Polygon', 'coordinates' => [$openRing]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertTrue($result['valid']);
    $closedRing = $result['normalized']['features'][0]['geometry']['coordinates'][0];
    $this->assertCount(5, $closedRing);
    $this->assertSame($closedRing[0], $closedRing[4]);
  }

  /**
   * Tests Point geometry inside a Feature is rejected.
   *
   * @covers ::validate
   */
  public function testPointGeometryIsRejected(): void {
    $feature = [
      'type' => 'Feature',
      'properties' => [],
      'geometry' => ['type' => 'Point', 'coordinates' => [5, 5]],
    ];
    $result = BoundaryGeoJsonValidator::validate($feature);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Point', $result['error']);
  }

  /**
   * Tests LineString geometry is rejected.
   *
   * @covers ::validate
   */
  public function testLineStringGeometryIsRejected(): void {
    $polygon = ['type' => 'LineString', 'coordinates' => [[0, 0], [10, 10]]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('LineString', $result['error']);
  }

  /**
   * Tests a top-level GeometryCollection is rejected.
   *
   * @covers ::validate
   */
  public function testGeometryCollectionTypeIsRejected(): void {
    $result = BoundaryGeoJsonValidator::validate(['type' => 'GeometryCollection', 'geometries' => []]);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('GeometryCollection', $result['error']);
  }

  /**
   * Tests out-of-range longitude is rejected.
   *
   * @covers ::validate
   */
  public function testOutOfRangeLongitudeIsRejected(): void {
    $ring = [[0, 0], [200, 0], [200, 10], [0, 10], [0, 0]];
    $polygon = ['type' => 'Polygon', 'coordinates' => [$ring]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertFalse($result['valid']);
  }

  /**
   * Tests out-of-range latitude is rejected.
   *
   * @covers ::validate
   */
  public function testOutOfRangeLatitudeIsRejected(): void {
    $ring = [[0, 0], [10, 0], [10, 100], [0, 10], [0, 0]];
    $polygon = ['type' => 'Polygon', 'coordinates' => [$ring]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertFalse($result['valid']);
  }

  /**
   * Tests a non-numeric coordinate is rejected.
   *
   * @covers ::validate
   */
  public function testNonNumericCoordinateIsRejected(): void {
    $ring = [[0, 0], ['x', 0], [10, 10], [0, 10], [0, 0]];
    $polygon = ['type' => 'Polygon', 'coordinates' => [$ring]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertFalse($result['valid']);
  }

  /**
   * Tests a ring with too few positions to ever close into a valid ring.
   *
   * @covers ::validate
   */
  public function testRingWithTooFewPositionsIsRejected(): void {
    $ring = [[0, 0], [10, 10]];
    $polygon = ['type' => 'Polygon', 'coordinates' => [$ring]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('at least 4 positions', $result['error']);
  }

  /**
   * Tests more than the allowed number of features is rejected.
   *
   * @covers ::validate
   */
  public function testTooManyFeaturesIsRejected(): void {
    $features = [];
    for ($i = 0; $i < BoundaryGeoJsonValidator::MAX_FEATURES + 1; $i++) {
      $features[] = [
        'type' => 'Feature',
        'properties' => [],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->squareRing]],
      ];
    }
    $collection = ['type' => 'FeatureCollection', 'features' => $features];
    $result = BoundaryGeoJsonValidator::validate($collection);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('features', $result['error']);
  }

  /**
   * Tests exceeding the total vertex cap is rejected.
   *
   * @covers ::validate
   */
  public function testTooManyVerticesIsRejected(): void {
    // Build a single ring whose vertex count alone exceeds the cap.
    $ring = [];
    $count = BoundaryGeoJsonValidator::MAX_VERTICES + 10;
    for ($i = 0; $i < $count; $i++) {
      // Small, valid, monotonically increasing longitude keeps this a
      // trivially valid coordinate stream; containment correctness of the
      // resulting (self-intersecting) shape is irrelevant to this cap test.
      $ring[] = [($i % 100) * 0.01, ($i % 100) * 0.01];
    }
    // Close the ring explicitly.
    $ring[] = $ring[0];

    $polygon = ['type' => 'Polygon', 'coordinates' => [$ring]];
    $result = BoundaryGeoJsonValidator::validate($polygon);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('vertices', $result['error']);
  }

  /**
   * Tests a boundary value that is not an array is rejected.
   *
   * @covers ::validate
   */
  public function testNonArrayValueIsRejected(): void {
    $result = BoundaryGeoJsonValidator::validate('not a geojson object');

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('GeoJSON object', $result['error']);
  }

  /**
   * Tests a value missing the type key is rejected.
   *
   * @covers ::validate
   */
  public function testMissingTypeKeyIsRejected(): void {
    $result = BoundaryGeoJsonValidator::validate(['coordinates' => []]);

    $this->assertFalse($result['valid']);
  }

  /**
   * Tests a FeatureCollection missing the features key is rejected.
   *
   * @covers ::validate
   */
  public function testFeatureCollectionMissingFeaturesIsRejected(): void {
    $result = BoundaryGeoJsonValidator::validate(['type' => 'FeatureCollection']);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('features array', $result['error']);
  }

  /**
   * Tests Feature properties are preserved 1:1 into the normalized output.
   *
   * @covers ::validate
   */
  public function testFeaturePropertiesArePreserved(): void {
    $collection = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => ['name' => 'Downtown', 'zone_id' => 42],
          'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->squareRing]],
        ],
      ],
    ];
    $result = BoundaryGeoJsonValidator::validate($collection);

    $this->assertTrue($result['valid']);
    $this->assertSame(['name' => 'Downtown', 'zone_id' => 42], $result['normalized']['features'][0]['properties']);
  }

  /**
   * Tests an empty FeatureCollection is treated as clearing the boundary.
   *
   * A stored FeatureCollection with zero features would make a downstream
   * GeoJsonBoundary::contains() always return FALSE, rejecting every
   * citizen report in the jurisdiction. Normalizing it to NULL instead
   * matches "delete all shapes and save" on the frontend canvas.
   *
   * @covers ::validate
   */
  public function testEmptyFeatureCollectionClearsBoundary(): void {
    $result = BoundaryGeoJsonValidator::validate([
      'type' => 'FeatureCollection',
      'features' => [],
    ]);

    $this->assertTrue($result['valid']);
    $this->assertNull($result['error']);
    $this->assertNull($result['normalized']);
  }

  /**
   * Tests properties exactly at the size cap are accepted.
   *
   * @covers ::validate
   */
  public function testPropertiesAtCapIsAccepted(): void {
    $overhead = strlen(json_encode(['note' => '']));
    $properties = ['note' => str_repeat('a', BoundaryGeoJsonValidator::MAX_PROPERTIES_BYTES - $overhead)];
    // Sanity-check the fixture actually sits exactly on the cap.
    $this->assertSame(BoundaryGeoJsonValidator::MAX_PROPERTIES_BYTES, strlen(json_encode($properties)));

    $collection = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => $properties,
          'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->squareRing]],
        ],
      ],
    ];
    $result = BoundaryGeoJsonValidator::validate($collection);

    $this->assertTrue($result['valid']);
  }

  /**
   * Tests properties one byte over the size cap are rejected.
   *
   * @covers ::validate
   */
  public function testPropertiesOverCapIsRejected(): void {
    $overhead = strlen(json_encode(['note' => '']));
    $properties = ['note' => str_repeat('a', BoundaryGeoJsonValidator::MAX_PROPERTIES_BYTES - $overhead + 1)];
    // Sanity-check the fixture actually sits one byte over the cap.
    $this->assertSame(BoundaryGeoJsonValidator::MAX_PROPERTIES_BYTES + 1, strlen(json_encode($properties)));

    $collection = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => $properties,
          'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->squareRing]],
        ],
      ],
    ];
    $result = BoundaryGeoJsonValidator::validate($collection);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('properties', $result['error']);
  }

}
