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
 *   id = "DefaultLocation",
 *   label = @Translation("Default Location", context = "Validation"),
 * )
 */
class DefaultLocationConstraint extends Constraint {
  public $noValidViewboxMessage = 'The submitted service request needs a different location than the default one.';
}
