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
 *   id = "ValidLatLon",
 *   label = @Translation("Valid LatLon", context = "Validation"),
 * )
 */
class ValidLatLonConstraint extends Constraint {
  public $noValidViewboxMessage = 'The submitted location is outside our range of activity.';
}