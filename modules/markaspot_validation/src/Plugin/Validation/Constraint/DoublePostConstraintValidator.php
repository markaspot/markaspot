<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use AnthonyMartin\GeoLocation\GeoLocation as GeoLocation;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the LatLon constraint.
 *
 * Todo: Make this possible for polygons
 * with something like geoPHP or
 *    this: http://assemblysys.com/php-point-in-polygon-algorithm/
 * 1. Get Place in Nomintim, check details, get relation id
 * 2. via https://www.openstreetmap.org/relation/175905
 * 3. http://polygons.openstreetmap.fr/index.py?id=175905
 */

/**
 * Class DoublePostConstraintValidator.
 */
class DoublePostConstraintValidator extends ConstraintValidator {

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
    $status = $this->configFactory->get('duplicate_check');
    if ($status === 0){
      return;
    }
    $user = \Drupal::currentUser();
    $user->hasPermission('bypass mas validation');
    if (!$user->hasPermission('bypass mas validation')) {
      $nids = $this->checkEnvironment(floatval($field->lng), floatval($field->lat));
      $session_ident = !empty($nids) ? end($nids) : '';
    }
    else {
      $session_ident = '';
      $nids = [];
    }

    if (count($nids) > 0) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadMultiple($nids);
      foreach ($nodes as $node) {
        $options = array('absolute' => TRUE);
        $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], $options);
        $link_options = [
          'attributes' => [
            'class' => [
              'doublepost',
              'use-ajax'
            ],
            'data-dialog-type' => 'modal',
            'data-history-node-id' => [
              $node->id(),
            ],
          ],
        ];
        $url->setOptions($link_options);
        $unit = ($this->unit == 'yards') ? 'yards' : 'meters';

        $message_string = $this->t('We found a recently added report of the same category with ID @id within a radius of @radius @unit.', [
          '@id' => $node->request_id->value,
          '@radius' => $this->radius,
          '@unit' => $unit,
        ]);
        $link = Link::fromTextAndUrl($message_string, $url);
        $message =  $link->toString();
      }
      $iteration = $session->get('ignore_dublicate_' . $session_ident);
      $treshold = $this->configFactory->get('treshold') ? $this->configFactory->get('treshold') : '0';
      if ($iteration <= $treshold && $this->configFactory->get('hint') == TRUE) {
        $message_append = $this->t('You can ignore this message or help us by comparing the possible duplicate and clicking on the link.');
        $this->context->addViolation(implode("\n", $message) . '</br>' . $message_append);
        $session->set('ignore_dublicate_' . $session_ident, $iteration + 1);
      } else if ($this->configFactory->get('hint') == FALSE){
        $message_append = $this->t('We are grateful for your efforts and will soon review this location anyway. Thank you!');
        $this->context->addViolation($message. '</br>' . $message_append);
      }
    }
    else {
      $session->set('ignore_dublicate_' . $session_ident, 0);
      return TRUE;
    }
  }

  /**
   * Check environment.
   *
   * @param float $lng
   *    The longitude value.
   * @param float $lat
   *    The latitude value.
   *
   * @return array|int
   *    Return the nid.
   */
  public function checkEnvironment($lng, $lat) {
    /* load all nodes
     *  > radius of 10m
     *  > same service_code
     *  > created or updated < period
     *  > updated true|false
     */

    // Filter posted category from context object.
    $entity = $this->context->getRoot();
    $category = $entity->get('field_category')->getValue();
    $target_id = isset($category[0]['target_id']) ? $category[0]['target_id'] : NULL;

    $this->radius = $this->configFactory->get('radius');
    $this->unit   = $this->configFactory->get('unit');
    $this->days   = $this->configFactory->get('days');

    $unit = ($this->unit == 'yards') ? 'miles' : 'kilometers';
    // $radius = ($unit == 'kilometers') ? (1000/ $this->radius) : (1760 / $this->radius);.
    $point = GeoLocation::fromDegrees($lat, $lng);

    $radius = ($unit == 'meters') ? ($this->radius / 1000) : ($this->radius / 1760);

    $coordinates = $point->boundingCoordinates($radius, $unit);

    $minLat = $coordinates[0]->getLatitudeInDegrees();
    $minLon = $coordinates[0]->getLongitudeInDegrees();

    $maxLat = $coordinates[1]->getLatitudeInDegrees();
    $maxLon = $coordinates[1]->getLongitudeInDegrees();

    $query = \Drupal::entityQuery('node')
      // only published requests get validated as positive:
      ->condition('status', 1)
      ->condition('changed', REQUEST_TIME, '<')
      ->condition('type', 'service_request')
      ->condition('field_geolocation.lat', $minLat, '>')
      ->condition('field_geolocation.lat', $maxLat, '<')
      ->condition('field_geolocation.lng', $minLon, '>')
      ->condition('field_geolocation.lng', $maxLon, '<')
      ->condition('field_category.target_id', $target_id)
      ->condition('created', REQUEST_TIME - (24 * 60 * 60 * $this->days), '>=');

    $nids = $query->execute();
    return $nids;
  }

}
