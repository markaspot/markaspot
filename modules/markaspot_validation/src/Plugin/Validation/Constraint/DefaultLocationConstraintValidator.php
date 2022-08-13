<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the LatLon constraint.
 */

/**
 * Class DefaultLocationConstraintValidator.
 *
 * Checks if submitted coordinates differ from starting/default point.
 */
class DefaultLocationConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

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
    $user = $this->account;
    $user->hasPermission('bypass mas validation');
    $defaultLocationCheck = $this->configFactory->get('defaultLocation') ? $this->configFactory->get('defaultLocation') : '0';

    if (!$user->hasPermission('bypass mas validation') && $defaultLocationCheck == 1) {
      $isDefaultLocation = $this->checkDefaultLocation(floatval($field->lng), floatval($field->lat));
      if ($isDefaultLocation === TRUE) {
        $message = $this->t('Please select the address and location of the issue.');
        $this->context->addViolation($message);
      }
      else {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Check environment.
   *
   * @param float $lng
   *   The longitude value.
   * @param float $lat
   *   The latitude value.
   *
   * @return bool
   *   Returns if submitted point equal default location.
   */
  public function checkDefaultLocation(float $lng, float $lat) {
    /* Load config from geolocation setting
    compare lat/lng
     */
    $fieldLocationConfig = $this->entityTypeManager->getStorage('field_config')->load('node.service_request.field_geolocation');
    $default_values = $fieldLocationConfig->get('default_value');
    if ($lng == $default_values[0]["lng"] && $lat == $default_values[0]["lat"]) {
      return TRUE;
    }
    return FALSE;
  }

}
