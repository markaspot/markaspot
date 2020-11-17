<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;


use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the LatLon constraint.
 *
 */

/**
 * Class DefaultLocationConstraintValidator.
 */
class DefaultLocationConstraintValidator extends ConstraintValidator {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the Validator with config options.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct() {
    $this->configFactory = \Drupal::service('config.factory')->get('markaspot_validation.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function validate($field, Constraint $constraint) {
    $user = \Drupal::currentUser();
    $user->hasPermission('bypass mas validation');
    $defaultLocationCheck = $this->configFactory->get('defaultLocation') ? $this->configFactory->get('defaultLocation') : '0';

    if (!$user->hasPermission('bypass mas validation') && $defaultLocationCheck == 1) {
      $isDefaultLocation = $this->checkDefaultLocation(floatval($field->lng), floatval($field->lat));
      if ($isDefaultLocation === TRUE) {
        $message = $this->t('Please select the address and location of the issue.');
        $this->context->addViolation($message);
      } else {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Check environment.
   *
   * @param float $lng
   *    The longitude value.
   * @param float $lat
   *    The latitude value.
   *
   * @return boolean
   */
  public function checkDefaultLocation(float $lng, float $lat) {
    /* Load config from geolocation setting
       compare lat/lng
     */
    $fieldLocationConfig = \Drupal::entityTypeManager()->getStorage('field_config')->load('node.service_request.field_geolocation');
    $default_values = $fieldLocationConfig->get('default_value');
    if ($lng == $default_values[0]["lng"] && $lat == $default_values[0]["lat"]) {
      return TRUE;
    }
    return FALSE;
  }

}
