<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\markaspot_validation\Plugin\Validation\Geo\Polygon;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use \geoPHP\geoPHP;

/**
 * Validates the LatLon constraint.
 *
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
    };
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
  static public function polygonCheck($lng, $lat) {
    // Looking for a valid WKT polygon:
    $config = \Drupal::configFactory()->getEditable('markaspot_validation.settings');

    $wkt = $config->get('wkt');
    if ($wkt !== '') {
      // Transform wkt to json.
      $geom = geoPHP::load($wkt, 'wkt');
      $json = $geom->out('json');
      $data = json_decode($json);
      $polygon = new Polygon($data->coordinates[0]);
      return $polygon->contain($lng,$lat);

      // $polygon = new Polygon($data->coordinates[0]);
      // return $polygon->contain($lng, $lat);
    }
    else {
      return TRUE;
    }
  }

}
