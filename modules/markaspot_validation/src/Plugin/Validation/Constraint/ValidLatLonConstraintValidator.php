<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\markaspot_validation\Plugin\Validation\Geo\Polygon;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the LatLon constraint.
 */
class ValidLatLonConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    if ($this->polygonCheck(floatval($value->lng), floatval($value->lat))) {
      $validLatLng = TRUE;
    }
    if (!isset($validLatLng)) {
      $this->context->addViolation($constraint->noValidViewboxMessage);
    }
  }

  /**
   * Check if coordinates are within polygon.
   *
   * @param float $lng
   *   The longitude coordinate.
   * @param float $lat
   *   The latitude coordinate.
   *
   * @return bool
   *   Validates or not.
   */
  public static function polygonCheck($lng, $lat) {
    // Looking for a valid WKT polygon:
    $config = \Drupal::configFactory()->getEditable('markaspot_validation.settings');
    $wkt = $config->get('wkt');
    if ($wkt !== '') {
      $coordinates = self::parse_wkt($wkt);
      $polygon = new Polygon($coordinates);
      return $polygon->contain($lng, $lat);
    }
    else {
      return TRUE;
    }
  }

  /**
   * Parse WKT into an array of coordinates.
   *
   * @param string $wkt
   *   The Well-Known Text string.
   *
   * @return array
   *   An array of coordinates.
   */
  private static function parse_wkt($wkt) {
    // Remove "POLYGON ((" at start and "))" at end.
    $polygon = substr($wkt, 9, -2);
    // Split into points.
    $points = explode(',', $polygon);
    $coords = array_map(function ($point) {
      // Split each point into lat and lon.
      return array_map('floatval', explode(' ', trim($point)));
    }, $points);
    return $coords;
  }

}
