<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Geo;

/**
 * Simple polygon containment check using ray-casting.
 *
 * Points are [x, y] pairs. For geographic coordinates, this means [lng, lat].
 * This class handles a single polygon ring without holes. For GeoJSON
 * boundaries with holes and MultiPolygon support, use GeoJsonBoundary instead.
 */
class Polygon {

  /**
   * The polygon ring as [lng, lat] coordinate pairs.
   *
   * @var array
   */
  protected array $points = [];

  /**
   * Whether the polygon has valid geometry.
   *
   * @var bool
   */
  protected bool $valid = FALSE;

  /**
   * Constructs a Polygon.
   *
   * @param array|null $points
   *   Array of [lng, lat] coordinate pairs.
   */
  public function __construct(?array $points = NULL) {
    if ($points) {
      $this->setPoints($points);
    }
  }

  /**
   * Sets the polygon ring coordinates.
   *
   * @param array $points
   *   Array of [lng, lat] coordinate pairs. Needs at least 3 points.
   *
   * @return $this
   */
  public function setPoints(array $points): static {
    $this->valid = FALSE;
    if (count($points) < 3) {
      return $this;
    }

    foreach ($points as $point) {
      if (!is_array($point) || count($point) !== 2
        || !is_numeric($point[0]) || !is_numeric($point[1])) {
        return $this;
      }
    }

    $this->points = $points;
    $this->valid = TRUE;
    return $this;
  }

  /**
   * Returns the polygon coordinates.
   *
   * @return array
   *   The [lng, lat] coordinate pairs.
   */
  public function getPoints(): array {
    return $this->points;
  }

  /**
   * Whether this polygon has valid geometry.
   *
   * @return bool
   *   TRUE if the polygon has at least 3 valid coordinate pairs.
   */
  public function isValid(): bool {
    return $this->valid;
  }

  /**
   * Checks if a point is inside this polygon ring.
   *
   * Uses the ray-casting algorithm (even-odd rule). A ray is cast eastward
   * from the test point; if it crosses an odd number of polygon edges,
   * the point is inside.
   *
   * @param float $lng
   *   Longitude of the test point (x-axis).
   * @param float $lat
   *   Latitude of the test point (y-axis).
   *
   * @return bool
   *   TRUE if the point is inside the polygon.
   */
  public function contain(float $lng, float $lat): bool {
    $count = 0;
    $points = $this->points;
    $points[] = reset($points);
    $point1 = reset($points);

    while ($point2 = next($points)) {
      $x1 = $point1[0];
      $y1 = $point1[1];
      $x2 = $point2[0];
      $y2 = $point2[1];

      if ($lng >= min($x1, $x2) && $lng <= max($x1, $x2) && $x1 != $x2) {
        $tmp = $y1 + ($lng - $x1) / ($x2 - $x1) * ($y2 - $y1);
        if ($tmp < $lat) {
          $count++;
        }
        elseif ($tmp == $lat) {
          return TRUE;
        }
      }
      $point1 = $point2;
    }

    return $count % 2 === 1;
  }

}
