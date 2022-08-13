<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that LatLon values are within a given bbox.
 *
 * @Constraint(
 *   id = "DefaultLocation",
 *   label = @Translation("Default Location", context = "Validation"),
 * )
 */
class DefaultLocationConstraint extends Constraint {
  /**
   * Maps error codes to the names of their constants.
   *
   * @var geoReportErrorCode
   */
  public $geoReportErrorCode = '101 - Lat/Lon';
  /**
   * Message.
   *
   * @var noValidViewboxMessage
   */
  public $noValidViewboxMessage = 'The submitted service request needs a different location than the default one.';

}
