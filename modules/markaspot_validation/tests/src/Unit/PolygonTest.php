<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Unit;

use Drupal\markaspot_validation\Plugin\Validation\Geo\Polygon;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Polygon ray-casting containment check.
 *
 * @group markaspot_validation
 * @coversDefaultClass \Drupal\markaspot_validation\Plugin\Validation\Geo\Polygon
 */
class PolygonTest extends UnitTestCase {

  /**
   * A simple square polygon around the origin for reuse.
   *
   * Vertices: (-1,-1), (1,-1), (1,1), (-1,1).
   *
   * @var array
   */
  protected array $square = [
    [-1, -1],
    [1, -1],
    [1, 1],
    [-1, 1],
  ];

  /**
   * Tests that a point inside a square polygon is detected.
   *
   * @covers ::contain
   */
  public function testPointInsideSquare(): void {
    $polygon = new Polygon($this->square);
    $this->assertTrue($polygon->contain(0.0, 0.0));
  }

  /**
   * Tests that a point outside a square polygon is rejected.
   *
   * @covers ::contain
   */
  public function testPointOutsideSquare(): void {
    $polygon = new Polygon($this->square);
    $this->assertFalse($polygon->contain(5.0, 5.0));
  }

  /**
   * Tests a point near the edge but still inside.
   *
   * @covers ::contain
   */
  public function testPointNearEdgeInside(): void {
    $polygon = new Polygon($this->square);
    $this->assertTrue($polygon->contain(0.99, 0.99));
  }

  /**
   * Tests a point clearly outside a negative quadrant.
   *
   * @covers ::contain
   */
  public function testPointFarOutside(): void {
    $polygon = new Polygon($this->square);
    $this->assertFalse($polygon->contain(-5.0, -5.0));
  }

  /**
   * Tests a point on the polygon edge returns true.
   *
   * The ray-casting algorithm considers boundary points as inside when
   * the intersection value equals the test coordinate.
   *
   * @covers ::contain
   */
  public function testPointOnEdgeReturnsTrue(): void {
    $polygon = new Polygon($this->square);
    // Point on the bottom edge at y=-1, x=0.
    $this->assertTrue($polygon->contain(0.0, -1.0));
  }

  /**
   * Tests containment with a triangle polygon.
   *
   * Triangle with vertices at (0,0), (10,0), (5,10).
   *
   * @covers ::contain
   */
  public function testTriangleContainment(): void {
    $triangle = [
      [0, 0],
      [10, 0],
      [5, 10],
    ];
    $polygon = new Polygon($triangle);

    // Center of triangle.
    $this->assertTrue($polygon->contain(5.0, 3.0));
    // Outside to the right.
    $this->assertFalse($polygon->contain(15.0, 5.0));
    // Outside above and to the left.
    $this->assertFalse($polygon->contain(-5.0, 15.0));
  }

  /**
   * Tests containment with real-world geographic coordinates.
   *
   * Uses a simplified polygon around Cologne, Germany.
   *
   * @covers ::contain
   */
  public function testGeographicCoordinates(): void {
    // Simplified boundary around Cologne city center.
    $cologne = [
      [6.90, 50.90],
      [7.00, 50.90],
      [7.00, 50.97],
      [6.90, 50.97],
    ];
    $polygon = new Polygon($cologne);

    // Cologne Cathedral: inside.
    $this->assertTrue($polygon->contain(6.9578, 50.9413));
    // Berlin: outside.
    $this->assertFalse($polygon->contain(13.405, 52.52));
  }

  /**
   * Tests that constructor with NULL points creates an invalid polygon.
   *
   * @covers ::__construct
   * @covers ::isValid
   */
  public function testConstructorWithNull(): void {
    $polygon = new Polygon(NULL);
    $this->assertFalse($polygon->isValid());
    $this->assertSame([], $polygon->getPoints());
  }

  /**
   * Tests that constructor with no arguments creates an invalid polygon.
   *
   * @covers ::__construct
   * @covers ::isValid
   */
  public function testConstructorWithNoArguments(): void {
    $polygon = new Polygon();
    $this->assertFalse($polygon->isValid());
  }

  /**
   * Tests that fewer than 3 points makes the polygon invalid.
   *
   * @covers ::setPoints
   * @covers ::isValid
   */
  public function testFewerThanThreePointsInvalid(): void {
    $polygon = new Polygon([
      [0, 0],
      [1, 1],
    ]);
    $this->assertFalse($polygon->isValid());
  }

  /**
   * Tests that exactly 3 points creates a valid polygon.
   *
   * @covers ::setPoints
   * @covers ::isValid
   */
  public function testExactlyThreePointsValid(): void {
    $polygon = new Polygon([
      [0, 0],
      [1, 0],
      [0, 1],
    ]);
    $this->assertTrue($polygon->isValid());
  }

  /**
   * Tests that non-numeric coordinates make the polygon invalid.
   *
   * @covers ::setPoints
   * @covers ::isValid
   */
  public function testNonNumericCoordinatesInvalid(): void {
    $polygon = new Polygon([
      ['abc', 0],
      [1, 0],
      [0, 1],
    ]);
    $this->assertFalse($polygon->isValid());
  }

  /**
   * Tests that a point with wrong array size makes polygon invalid.
   *
   * @covers ::setPoints
   * @covers ::isValid
   */
  public function testWrongArraySizeInvalid(): void {
    $polygon = new Polygon([
      [0, 0, 0],
      [1, 0],
      [0, 1],
    ]);
    $this->assertFalse($polygon->isValid());
  }

  /**
   * Tests that non-array point makes polygon invalid.
   *
   * @covers ::setPoints
   * @covers ::isValid
   */
  public function testNonArrayPointInvalid(): void {
    $polygon = new Polygon([
      'not-an-array',
      [1, 0],
      [0, 1],
    ]);
    $this->assertFalse($polygon->isValid());
  }

  /**
   * Tests the getPoints method returns the stored coordinates.
   *
   * @covers ::getPoints
   */
  public function testGetPointsReturnsCoordinates(): void {
    $polygon = new Polygon($this->square);
    $this->assertSame($this->square, $polygon->getPoints());
  }

  /**
   * Tests setPoints is chainable and returns the polygon instance.
   *
   * @covers ::setPoints
   */
  public function testSetPointsIsChainable(): void {
    $polygon = new Polygon();
    $result = $polygon->setPoints($this->square);
    $this->assertSame($polygon, $result);
  }

  /**
   * Tests an L-shaped (concave) polygon.
   *
   * @covers ::contain
   */
  public function testConcavePolygon(): void {
    // L-shape: bottom-left corner cut out.
    $lShape = [
      [0, 0],
      [10, 0],
      [10, 10],
      [5, 10],
      [5, 5],
      [0, 5],
    ];
    $polygon = new Polygon($lShape);

    // Inside the bottom bar.
    $this->assertTrue($polygon->contain(7.0, 2.0));
    // Inside the right tower.
    $this->assertTrue($polygon->contain(7.0, 7.0));
    // In the cutout area (outside).
    $this->assertFalse($polygon->contain(2.0, 7.0));
  }

}
