<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use AnthonyMartin\GeoLocation\GeoLocation as GeoLocation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Class DoublePostConstraintValidator.
 *
 * Validates new service request against identical existing requests.
 * Supports headless/JSON:API via X-Acknowledge-Duplicate header.
 */
class DoublePostConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Header name for acknowledging duplicate warning in headless mode.
   */
  const ACKNOWLEDGE_HEADER = 'X-Acknowledge-Duplicate';

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
   * The module configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Constructs a DoublePostConstraintValidator object.
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
    // Use get() for read-only access, not getEditable().
    $this->config = $config_factory->get('markaspot_validation.settings');
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
    $config = $this->config;
    $request = $this->requestStack->getCurrentRequest();

    // Check if duplicate validation is enabled.
    if (!$config->get('duplicate_check')) {
      return;
    }

    // Users with bypass permission skip validation entirely.
    if ($this->account->hasPermission('bypass mas validation')) {
      return;
    }

    // Find potential duplicates.
    $nids = $this->checkEnvironment(floatval($field->lng), floatval($field->lat));

    if (empty($nids)) {
      return;
    }

    // Check if this is a headless request with acknowledgment header.
    $isAcknowledged = $this->isAcknowledgedDuplicate($request);
    $isHintMode = (bool) $config->get('hint');

    // In hint mode with acknowledgment: allow submission.
    if ($isHintMode && $isAcknowledged) {
      return;
    }

    // Build the duplicate info for response.
    $duplicateInfo = $this->buildDuplicateInfo($nids, $config);

    // Hard block mode: always reject.
    if (!$isHintMode) {
      $this->context->addViolation(
        $duplicateInfo['message'] . '</br>' .
        $this->t('We are grateful for your efforts and will soon review this location anyway. Thank you!')
      );
      return;
    }

    // Hint mode without acknowledgment: show warning with instructions.
    $hintMessage = $this->t(
      'You can ignore this message by resubmitting. To help us, please compare the possible duplicate by clicking the link above.'
    );

    $this->context->addViolation(
      $duplicateInfo['message'] . '</br>' . $hintMessage,
      [
        'duplicate_hint' => TRUE,
        'existing_report_id' => $duplicateInfo['request_id'],
        'existing_report_nid' => $duplicateInfo['nid'],
        'existing_report_url' => $duplicateInfo['url'],
      ]
    );
  }

  /**
   * Check if the request acknowledges a duplicate warning.
   *
   * Supports both header-based (headless) and session-based (traditional form).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return bool
   *   TRUE if duplicate was acknowledged.
   */
  protected function isAcknowledgedDuplicate($request): bool {
    // Check for headless acknowledgment header.
    $headerValue = $request->headers->get(self::ACKNOWLEDGE_HEADER);
    if ($headerValue && strtolower($headerValue) === 'true') {
      return TRUE;
    }

    // Check for form-based acknowledgment via request body.
    $content = $request->getContent();
    if ($content) {
      $data = json_decode($content, TRUE);
      if (isset($data['acknowledge_duplicate']) && $data['acknowledge_duplicate'] === TRUE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Build duplicate information for the validation message.
   *
   * @param array $nids
   *   Array of duplicate node IDs.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   *
   * @return array
   *   Array with message, request_id, nid, and url.
   */
  protected function buildDuplicateInfo(array $nids, ImmutableConfig $config): array {
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $node = reset($nodes);

    $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['absolute' => TRUE]);
    $link_options = [
      'attributes' => [
        'class' => ['doublepost', 'use-ajax'],
        'data-dialog-type' => 'modal',
        'data-history-node-id' => [$node->id()],
      ],
    ];
    $url->setOptions($link_options);

    $unit = $config->get('unit') === 'yards' ? 'yards' : 'meters';
    $message_string = $this->t('We found a recently added report of the same category with ID @id within a radius of @radius @unit.', [
      '@id' => $node->request_id->value,
      '@radius' => $config->get('radius'),
      '@unit' => $unit,
    ]);

    $link = Link::fromTextAndUrl($message_string, $url);

    return [
      'message' => $link->toString(),
      'request_id' => $node->request_id->value,
      'nid' => $node->id(),
      'url' => $url->toString(),
    ];
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
  public function checkEnvironment(float $lng, float $lat): array {
    // Find nodes within radius, same category, created within configured days.
    $config = $this->config;
    // Filter posted category from context object.
    $entity = $this->context->getRoot();
    $category = $entity->get('field_category')->getValue();
    $target_id = $category[0]['target_id'] ?? NULL;

    $radius = (int) $config->get('radius');
    $unit   = $config->get('unit');
    $days   = (int) $config->get('days');

    $unit = ($unit == 'yards') ? 'miles' : 'kilometers';

    $point = GeoLocation::fromDegrees($lat, $lng);

    $radius = ($unit == 'kilometers') ? ((int) $radius / 1000) : ((int) $radius / 1760);

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
