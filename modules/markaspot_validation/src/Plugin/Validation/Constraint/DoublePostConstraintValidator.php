<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use AnthonyMartin\GeoLocation\GeoLocation as GeoLocation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the LatLon constraint.
 *
 * @todo Make this possible for polygons
 * with something like geoPHP or
 *    this: http://assemblysys.com/php-point-in-polygon-algorithm/
 * 1. Get Place in Geocoder, check details, get relation id
 * 2. via https://www.openstreetmap.org/relation/175905
 * 3. http://polygons.openstreetmap.fr/index.py?id=175905
 */

/**
 * Class DoublePostConstraintValidator.
 *
 *  Validates new service request against identical existing requests.
 */
class DoublePostConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;


  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Constructs a Validation object.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently authenticated user.
   */
  public function __construct(TimeInterface $time, RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, AccountInterface $account) {
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory->getEditable('markaspot_validation.settings');
    $this->account = $account;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($field, Constraint $constraint) {
    $session = $this->requestStack->getCurrentRequest()->getSession();
    $config = $this->configFactory;
    $status = $config->get('status');
    if ($status === 0) {
      return;
    }
    $user = $this->account;
    // var_dump($this->$current_user);.
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
      $nodes = $this->entityTypeManager->getStorage('node')
        ->loadMultiple($nids);
      foreach ($nodes as $node) {
        $options = ['absolute' => TRUE];
        $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], $options);
        $link_options = [
          'attributes' => [
            'class' => [
              'doublepost',
              'use-ajax',
            ],
            'data-dialog-type' => 'modal',
            'data-history-node-id' => [
              $node->id(),
            ],
          ],
        ];
        $url->setOptions($link_options);
        $unit = ($unit == 'yards') ? 'yards' : 'meters';

        $message_string = $this->t('We found a recently added report of the same category with ID @id within a radius of @radius @unit.', [
          '@id' => $node->request_id->value,
          '@radius' => $config->get('radius'),
          '@unit' => $config->get('unit'),
        ]);
        $link = Link::fromTextAndUrl($message_string, $url);
        $message = $link->toString();
      }
      $iteration = $session->get('ignore_dublicate_' . $session_ident);
      $treshold = $config->get('treshold') ? $config->get('treshold') : '0';
      if ($iteration <= $treshold && $config->get('hint') == TRUE) {
        $message_append = $this->t('You can ignore this message or help us by comparing the possible duplicate and clicking on the link.');
        $this->context->addViolation($message . '</br>' . $message_append);
        $session->set('ignore_dublicate_' . $session_ident, $iteration + 1);
      }
      elseif ($config->get('hint') == FALSE) {
        $message_append = $this->t('We are grateful for your efforts and will soon review this location anyway. Thank you!');
        $this->context->addViolation($message . '</br>' . $message_append);
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
   *   The longitude value.
   * @param float $lat
   *   The latitude value.
   *
   * @return array|int
   *   Return the nid.
   */
  public function checkEnvironment(float $lng, float $lat) {
    /* load all nodes
     *  > radius of 10m
     *  > same service_code
     *  > created or updated < period
     *  > updated true|false
     */
    $config = $this->configFactory;
    // Filter posted category from context object.
    $entity = $this->context->getRoot();
    $category = $entity->get('field_category')->getValue();
    $target_id = $category[0]['target_id'] ?? NULL;

    $radius = (int) $config->get('radius');
    $unit   = $config->get('unit');
    $days   = (int) $config->get('days');

    $unit = ($unit == 'yards') ? 'miles' : 'kilometers';

    $point = GeoLocation::fromDegrees($lat, $lng);

    $radius = ($unit == 'meters') ? ((int) radius / 1000) : ((int) $radius / 1760);

    $coordinates = $point->boundingCoordinates($radius, $unit);

    $minLat = $coordinates[0]->getLatitudeInDegrees();
    $minLon = $coordinates[0]->getLongitudeInDegrees();

    $maxLat = $coordinates[1]->getLatitudeInDegrees();
    $maxLon = $coordinates[1]->getLongitudeInDegrees();

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      // Only published requests get validated as positive:
      ->condition('status', 1)
      ->condition('changed', $this->time->getRequestTime(), '<')
      ->condition('type', 'service_request')
      ->condition('field_geolocation.lat', $minLat, '>')
      ->condition('field_geolocation.lat', $maxLat, '<')
      ->condition('field_geolocation.lng', $minLon, '>')
      ->condition('field_geolocation.lng', $maxLon, '<')
      ->condition('field_category.target_id', $target_id)
      ->condition('created', $this->time->getRequestTime() - (24 * 60 * 60 * (int) $days), '>=')
      ->accessCheck(FALSE);

    $nids = $query->execute();
    return $nids;
  }

}
