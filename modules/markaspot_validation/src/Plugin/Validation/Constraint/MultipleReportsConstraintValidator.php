<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the LatLon constraint.
 *
 * @todo Make this possible for polygons
 * with something like geoPHP or
 *    this: http://assemblysys.com/php-point-in-polygon-algorithm/
 * 1. Get Place in Nomintim, check details, get relation id
 * 2. via https://www.openstreetmap.org/relation/175905
 * 3. http://polygons.openstreetmap.fr/index.py?id=175905
 */

/**
 * Class MultipleReportsConstraintValidator.
 */
class MultipleReportsConstraintValidator extends ConstraintValidator {

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
    $this->configFactory = \Drupal::config('markaspot_validation.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function validate($field, Constraint $constraint) {
    $session = \Drupal::requestStack()->getCurrentRequest()->getSession();
    $status = $this->configFactory->get('multiple_reports');
    $max_count = ($this->configFactory->get('max_count') != FALSE) ? $this->configFactory->get('max_count') : 5 ;
    if ($status === 0) {
      return;
    }
    $user = \Drupal::currentUser();
    $permission = $user->hasPermission('bypass mas validation');
    if (!$user->hasPermission('bypass mas validation')) {
      $nids = $this->countReports();
      $session_ident = !empty($nids) ? $nids : '';
    }
    else {
      $session_ident = '';
      $nids = 0;
    }

    if ($nids > $max_count) {

      $message = $this->t('We have noticed that @count requests have already been reported using this email address within the last 24h. Please try again some other day.', [
        '@count' => $nids,
      ]);
      $this->context->addViolation($message);
    }

  }

  /**
   * Check environment.
   *
   * @return int
   *   Return the nid.
   */
  public function countReports() {
    /* load all nodes
     *  > radius of 10m
     *  > same service_code
     *  > created or updated < period
     *  > updated true|false
     */

    // Filter posted category from context object.
    $entity = $this->context->getRoot();
    $email_field = $entity->get('field_e_mail')->getValue();
    $email = $email_field[0]['value'] ?? '';
    $count = $this->configFactory->get('count');
    $this->count = $count ?? 5;
    $query = \Drupal::entityQuery('node')
      // Only published requests get validated as positive:
      // ->condition('status', 1)
      ->condition('type', 'service_request')
      ->condition('field_e_mail', $email)
      ->condition('created', \Drupal::time()->getRequestTime() - (24 * 60 * 60), '>=');

    $nids = $query->execute();
    return count($nids);
  }

}
