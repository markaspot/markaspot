<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Geo;

/**
 * Temporarily taken from weiyongsheng/polygon.
 */
class Polygon {
  /**
   * Checks if point is within polygon.
   */

  /**
   * The polygon points.
   *
   * @var array
   */
  protected $points = [];

  /**
   * Is point within polygon.
   *
   * @var bool
   */
  protected $valid;

  /**
   * Polygon constructor.
   *
   * @param array|null $points
   *   The polygon points.
   */
  public function __construct(array $points = NULL) {
    if ($points) {
      $this->setPoints($points);
    }
    else {
      $this->valid = FALSE;
    }
  }

  /**
   * Set points of polygon.
   *
   * @param array $points
   *   The polygon points.
   *
   * @return $this
   *   Return result.
   */
  public function setPoints(array $points) {
    $this->valid = FALSE;
    if (count($points) >= 3) {
      $this->valid = TRUE;
      foreach ($points as $point) {
        if (!$this->checkPoint($point)) {
          $this->valid = FALSE;

          return $this;
        }
      }
    }
    else {
      return $this;
    }
    $this->points = $points;

    return $this;
  }

  /**
   * Contain all points of min rectangle points.
   *
   * @return array
   *   return polygon as array.
   */
  public function rectanglePoints() {
    $lats = array_column($this->points, 0);
    $lngs = array_column($this->points, 1);
    $min_lat = min($lats);
    $min_lng = min($lngs);
    $max_lat = max($lats);
    $max_lng = max($lngs);

    return [
      [$min_lat, $min_lng],
      [$min_lat, $max_lng],
      [$max_lat, $max_lng],
      [$max_lat, $min_lng],
    ];
  }

  /**
   * Get points of poloygon.
   *
   * @return array
   *   The poloygon.
   */
  public function getPoints() {
    return $this->points;
  }

  /**
   * Return status if valid.
   *
   * @return bool
   *   valid or not.
   */
  public function isValid() {
    return $this->valid;
  }

  /**
   * Check if point is part of polygon.
   *
   * @param float $lat
   *   The latitude value.
   * @param float $lng
   *   The longitude value.
   *
   * @return bool
   *   Return result.
   */
  public function contain(float $lat, float $lng): bool {
    $count = 0;
    $points = $this->points;
    $points[] = reset($points);
    $point1 = reset($points);

    while ($point2 = next($points)) {
      $x1 = $point1[0];
      $y1 = $point1[1];
      $x2 = $point2[0];
      $y2 = $point2[1];
      if ($lat >= min($x1, $x2) && $lat <= max($x1, $x2) && $x1 != $x2) {
        $tmp = $y1 + ($lat - $x1) / ($x2 - $x1) * ($y2 - $y1);
        if ($tmp < $lng) {
          $count++;
        }
        elseif ($tmp == $lng) {
          // In line.
          return TRUE;
        }
      }
      $point1 = $point2;
    }

    return $count % 2 === 1;
  }

  /**
   * Check if submitted values are valid coordinates.
   *
   * @param array $point
   *   The point.
   *
   * @return bool
   *   return result.
   */
  private function checkPoint(array $point) {
    return is_array($point) && count($point) == 2 && is_numeric($point[0]) && is_numeric($point[1]);
  }

}
