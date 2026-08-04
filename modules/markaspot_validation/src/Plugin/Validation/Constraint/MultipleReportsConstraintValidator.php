<?php

namespace Drupal\markaspot_validation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\node\NodeInterface;
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
 * 1. Get Place in Nomintim, check details, get relation id
 * 2. via https://www.openstreetmap.org/relation/175905
 * 3. http://polygons.openstreetmap.fr/index.py?id=175905
 */

/**
 * Class MultipleReportsConstraintValidator.
 *
 * Validates new service request's committed e-mail against being used
 *  several times.
 */
class MultipleReportsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

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
   * The configuration factory used for the jurisdiction group type.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactoryService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Resolves tenant-specific citizen terminology for visible violations.
   *
   * @var \Drupal\markaspot_nuxt\Service\CitizenWordingResolver|null
   */
  protected $citizenWordingResolver;

  /**
   * The active content-language resolver for JSON:API responses.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|null
   */
  protected $languageManager;

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
   * @param \Drupal\markaspot_nuxt\Service\CitizenWordingResolver|null $citizen_wording_resolver
   *   The optional resolver for tenant-specific citizen terminology.
   * @param \Drupal\Core\Language\LanguageManagerInterface|null $language_manager
   *   The optional resolver for the active content language.
   */
  public function __construct(TimeInterface $time, RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, AccountInterface $account, ?CitizenWordingResolver $citizen_wording_resolver = NULL, ?LanguageManagerInterface $language_manager = NULL) {
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactoryService = $config_factory;
    $this->configFactory = $config_factory->getEditable('markaspot_validation.settings');
    $this->account = $account;
    $this->citizenWordingResolver = $citizen_wording_resolver;
    $this->languageManager = $language_manager;
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
      $container->has('markaspot_nuxt.citizen_wording_resolver')
        ? $container->get('markaspot_nuxt.citizen_wording_resolver')
        : NULL,
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($field, Constraint $constraint) {
    // $session = \Drupal::requestStack()->getCurrentRequest()->getSession();
    $status = $this->configFactory->get('multiple_reports');
    $max_count = $this->configFactory->get('max_count') ? $this->configFactory->get('max_count') : 5;
    if ($status === FALSE || $status === 0) {
      return;
    }
    $user = $this->account;
    if (!$user->hasPermission('bypass mas validation')) {
      $nids = $this->countReports();
    }
    else {
      $nids = 0;
    }

    if ($nids >= $max_count) {

      $message = $this->resolveCitizenLimitMessage();
      if ($message === NULL) {
        $message = (string) $this->t('We have noticed that @count requests have already been reported using this email address within the last 24h. Please try again some other day.', [
          '@count' => $nids,
        ]);
      }
      else {
        $message = strtr($message, ['@count' => (string) $nids]);
      }
      $this->context->addViolation($message);
    }

  }

  /**
   * Resolves a locale-correct limit detail for the validated service request.
   *
   * Stable technical constraint IDs intentionally keep their existing names.
   * Only the human-readable 422 detail follows the tenant wording.
   */
  protected function resolveCitizenLimitMessage(): ?string {
    if ($this->citizenWordingResolver === NULL) {
      return NULL;
    }

    $entity = $this->context->getRoot();
    if (!$entity instanceof NodeInterface) {
      return NULL;
    }

    $groupType = trim((string) $this->configFactoryService
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type'));
    $jurisdiction = $this->citizenWordingResolver->resolveJurisdictionFromNode(
      $entity,
      $groupType !== '' ? $groupType : 'jur',
    );

    return $this->citizenWordingResolver->formatValidationMessage(
      CitizenWordingResolver::VALIDATION_EMAIL_DAILY_LIMIT,
      $jurisdiction,
      $this->resolveResponseLangcode($entity),
    );
  }

  /**
   * Resolves the active content language for a JSON:API validation response.
   *
   * A newly created service request may carry the site default language rather
   * than Nuxt's request locale. TYPE_CONTENT follows the latter; the entity
   * language is retained as a compatibility fallback for direct construction.
   */
  protected function resolveResponseLangcode(NodeInterface $entity): string {
    if ($this->languageManager !== NULL) {
      return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    }

    return $entity->language()->getId();
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
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      // Only published requests get validated as positive:
      // ->condition('status', 1)
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('field_e_mail', $email)
      ->condition('created', $this->time->getRequestTime() - (24 * 60 * 60), '>=');

    $nids = $query->execute();
    return count($nids);
  }

}
