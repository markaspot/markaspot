<?php
/**
 * @file
 * Contains \Drupal\entity_validation\Plugin\Validation\Constraint\EvenNumberConstraint.
 */
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
  public $geoReportErrorCode = '103 - Multiple Reports';
  public $noValidViewboxMessage = 'Multiple Reports are prohibted.';
}
