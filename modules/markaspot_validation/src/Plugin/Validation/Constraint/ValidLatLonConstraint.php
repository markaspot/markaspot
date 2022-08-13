<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that LatLon values are within a given bbox.
 *
 * @Constraint(
 *   id = "ValidLatLon",
 *   label = @Translation("Valid LatLon", context = "Validation"),
 * )
 */
class ValidLatLonConstraint extends Constraint {
  /**
   * Maps error codes to the names of their constants.
   *
   * @var geoReportErrorCode
   */
  public string $geoReportErrorCode = '102 - Lat/Lon';
  /**
   * Message.
   *
   * @var noValidViewboxMessage
   */
  public string $noValidViewboxMessage = 'The submitted location is outside our range of activity.';

}
