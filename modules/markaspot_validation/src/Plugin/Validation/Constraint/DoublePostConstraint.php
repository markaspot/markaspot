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
 *   id = "DoublePost",
 *   label = @Translation("Double Post", context = "Validation"),
 * )
 */
class DoublePostConstraint extends Constraint {
  public $geoReportErrorCode = '100 - Duplicate';
  public $noValidViewboxMessage = 'The submitted service category and location suggests this request as duplicate.';
}