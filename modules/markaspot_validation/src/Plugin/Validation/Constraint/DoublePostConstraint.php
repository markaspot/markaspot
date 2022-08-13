<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that LatLon values are within a given bbox.
 *
 * @Constraint(
 *   id = "DoublePost",
 *   label = @Translation("Double Post", context = "Validation"),
 * )
 */
class DoublePostConstraint extends Constraint {
  /**
   * Maps error codes to the names of their constants.
   *
   * @var geoReportErrorCode
   */
  public $geoReportErrorCode = '100 - Duplicate';
  /**
   * Message.
   *
   * @var noValidViewboxMessage
   */
  public $noValidViewboxMessage = 'The submitted service category and location suggests this request as duplicate.';

}
