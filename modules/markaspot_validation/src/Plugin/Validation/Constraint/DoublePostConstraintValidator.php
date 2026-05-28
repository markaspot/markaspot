<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_validation\EventSubscriber\ViolationCauseResponseSubscriber;
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
    return new self(
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

    // Structured payload surfaced to headless clients under the `meta` key
    // of the JSON:API error object. `setCause()` keeps it separate from
    // message placeholders, and we also stash it in a request attribute so
    // the response subscriber can inject it once the JSON has been rendered
    // (core's JSON:API normalizer is sealed off from third-party extension).
    //
    // When the matched node is unpublished and the anonymous submitter cannot
    // view it, `existing_report_id` / `existing_report_url` are NULL so we do
    // not disclose the identity of pending reports.
    $cause = [
      'duplicate_hint' => $isHintMode,
      'existing_report_id' => $duplicateInfo['request_id'],
      'existing_report_url' => $duplicateInfo['url'],
    ];
    $this->stashCause($request, 'field_geolocation', $cause);

    // Plain-text message for the violation. Rich HTML (link, modal trigger)
    // lives on the admin Drupal form render path only, not in the API
    // response — `detail` in a JSON:API error object is plain text per spec.
    // Frontend clients build their own link from `meta.existing_report_url`.
    $hardBlockSuffix = $this->t('We are grateful for your efforts and will soon review this location anyway. Thank you!');
    $hintSuffix = $this->t('You can ignore this message by resubmitting. To help us, please compare the possible duplicate at the linked report.');

    $suffix = $isHintMode ? $hintSuffix : $hardBlockSuffix;
    $this->context->buildViolation((string) $duplicateInfo['message'] . ' ' . (string) $suffix)
      ->setCause($cause)
      ->addViolation();
  }

  /**
   * Record a structured cause on the request keyed by property path.
   *
   * The JSON:API exception pipeline discards ConstraintViolation::getCause()
   * when serializing the 422 response, so we hand the payload to the
   * ViolationCauseResponseSubscriber via the request attribute bag.
   */
  protected function stashCause($request, string $property_path, array $cause): void {
    if (!$request) {
      return;
    }
    $key = ViolationCauseResponseSubscriber::ATTR_KEY;
    $all = $request->attributes->get($key, []);
    $all[$property_path] = $cause;
    $request->attributes->set($key, $all);
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
   *   Plain-text message plus request_id and absolute URL for JSON:API meta.
   *   When the matched node is unpublished and the current user cannot view
   *   it, request_id and url are NULL to avoid leaking the identity of
   *   pending/archived reports via the 422 response.
   */
  protected function buildDuplicateInfo(array $nids, ImmutableConfig $config): array {
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $node = reset($nodes);

    $unit = $config->get('unit') === 'yards' ? 'yards' : 'meters';

    // Access check: a matched unpublished/archived node may be invisible to
    // the anonymous submitter, so do not include its URL or request_id in
    // the structured payload. The message falls back to generic text in that
    // case to avoid disclosing a specific pending-report ID.
    $viewable = $node->access('view', $this->account);

    if ($viewable) {
      $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['absolute' => TRUE])->toString();
      $request_id = $this->duplicateNodeRequestId($node);
      $message = $this->t('We found a recently added report of the same category with ID @id within a radius of @radius @unit.', [
        '@id' => $request_id,
        '@radius' => (string) ($config->get('radius') ?? ''),
        '@unit' => $unit,
      ]);
      return [
        'message' => $message,
        'request_id' => $request_id,
        'url' => $url,
      ];
    }

    return [
      'message' => $this->t('We found a recently added report of the same category within a radius of @radius @unit.', [
        '@radius' => (string) ($config->get('radius') ?? ''),
        '@unit' => $unit,
      ]),
      'request_id' => NULL,
      'url' => NULL,
    ];
  }

  /**
   * Resolves a duplicate's public request id without NULL placeholders.
   */
  protected function duplicateNodeRequestId(ContentEntityInterface $node): string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      $value = $node->get('request_id')->value;
      if ($value !== NULL && trim((string) $value) !== '') {
        return (string) $value;
      }
    }

    return (string) $node->id();
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
      ->condition('changed', $this->time->getRequestTime(), '<')
      ->condition('type', 'service_request')
      ->condition('field_geolocation.lat', $minLat, '>')
      ->condition('field_geolocation.lat', $maxLat, '<')
      ->condition('field_geolocation.lng', $minLon, '>')
      ->condition('field_geolocation.lng', $maxLon, '<')
      ->condition('field_category.target_id', $target_id)
      ->condition('created', $this->time->getRequestTime() - (24 * 60 * 60 * (int) $days), '>=')
      ->accessCheck(FALSE);

    // Only published requests get validated as positive by default. When the
    // moderation workflow creates reports as unpublished, set
    // `check_unpublished` to TRUE so pending reports also count as duplicates.
    if (!$config->get('check_unpublished')) {
      $query->condition('status', 1);
    }

    $excludedStatuses = $config->get('excluded_statuses') ?? [];
    if (!empty($excludedStatuses)) {
      $query->condition('field_status.target_id', $excludedStatuses, 'NOT IN');
    }

    $nids = $query->execute();
    return $nids;
  }

}
