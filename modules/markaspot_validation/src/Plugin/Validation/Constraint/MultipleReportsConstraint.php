<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that LatLon values are within a given bbox.
 *
 * @Constraint(
 *   id = "MultipleReports",
 *   label = @Translation("Double Post", context = "Validation"),
 * )
 */
class MultipleReportsConstraint extends Constraint {
  /**
   * Maps error codes to the names of their constants.
   *
   * @var geoReportErrorCode
   */
  public $geoReportErrorCode = '107 - Multiple Reports';
  /**
   * Message.
   *
   * @var noValidViewboxMessage
   */
  public $noValidViewboxMessage = 'Multiple Reports are prohibited.';

}
