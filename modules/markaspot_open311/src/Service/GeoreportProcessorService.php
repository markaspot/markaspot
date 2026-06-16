<?php

namespace Drupal\markaspot_open311\Service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileExists;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\media\MediaInterface;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\Exception\FileException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Datetime\Time;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class GeoreportProcessorService.
 *
 * This class is responsible for processing georeport requests and mapping data
 * between Drupal entities and the Open311 data format.
 */
class GeoreportProcessorService implements GeoreportProcessorServiceInterface {
  use StringTranslationTrait;

  /**
   * Sort fields supported by the request list endpoint.
   */
  protected const REQUEST_LIST_SORT_FIELDS = [
    'created' => 'created',
    'updated' => 'changed',
    'status' => 'field_status',
    'service_code' => 'field_category',
    'request_id' => 'request_id',
    'nid' => 'nid',
  ];

  /**
   * Cursor payload version for request list pagination.
   */
  protected const REQUEST_LIST_CURSOR_VERSION = 1;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\Time
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
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The markaspot_open311.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * File url generator object.
   *
   * @var \Drupal\Core\File\FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  private $streamWrapperManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  private $entityFieldManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected $hierarchyResolver;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * Per-request memo of jurisdiction membership checks, keyed "gid:uid".
   *
   * Avoids repeated group loads / membership queries when serializing
   * large request lists (markaspot-ui#427).
   *
   * @var array<string, bool>
   */
  protected array $jurisdictionMembershipCache = [];

  /**
   * Per-request memo of resolved node jurisdictions, keyed by node ID.
   *
   * @var array<int, int|null>
   */
  protected array $nodeJurisdictionIdCache = [];

  /**
   * Memoized result of the "any jurisdiction groups exist" install check.
   *
   * In long-running PHP runtimes (FrankenPHP worker mode, RoadRunner) this
   * memo persists across requests and could serve a stale FALSE after the
   * first jur group is created. If such a runtime is adopted, reset this
   * property at request boundaries or declare the service shared: false.
   *
   * @var bool|null
   */
  protected ?bool $jurisdictionGroupsExist = NULL;

  /**
   * GeoreportProcessorService constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user instance.
   * @param \Drupal\Component\Datetime\Time $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher service.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface|null $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger channel.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    AccountProxyInterface $currentUser,
    Time $time,
    RequestStack $requestStack,
    EntityTypeManagerInterface $entityTypeManager,
    FileUrlGeneratorInterface $fileUrlGenerator,
    ModuleHandlerInterface $moduleHandler,
    EntityFieldManagerInterface $entityFieldManager,
    StreamWrapperManagerInterface $streamWrapperManager,
    Token $token,
    LanguageManagerInterface $languageManager,
    Connection $database,
    FileSystemInterface $fileSystem,
    ClientInterface $httpClient,
    MessengerInterface $messenger,
    AccountSwitcherInterface $accountSwitcher,
    ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->configFactory = $configFactory;
    $this->currentUser = $currentUser;
    $this->time = $time;
    $this->requestStack = $requestStack;
    $this->entityTypeManager = $entityTypeManager;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->moduleHandler = $moduleHandler;
    $this->entityFieldManager = $entityFieldManager;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->token = $token;
    $this->languageManager = $languageManager;
    $this->database = $database;
    $this->fileSystem = $fileSystem;
    $this->httpClient = $httpClient;
    $this->messenger = $messenger;
    $this->accountSwitcher = $accountSwitcher;
    $this->hierarchyResolver = $hierarchyResolver;
    $this->logger = $logger;
  }

  /**
   * Get discovery from configuration.
   *
   * @return array
   *   Returns the discovery configuration.
   */
  public function getDiscovery(): array {
    $discovery = $this->configFactory->get('markaspot_open311.settings')->get('discovery');
    return $discovery ?: [];
  }

  /**
   * Prepares node properties for a service request.
   *
   * @param array $requestData
   *   The request data in the form of an associative array.
   * @param string $operation
   *   The operation to be performed (create, update, etc.).
   *
   * @return array
   *   An associative array containing the node property values.
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   If there is an error in the request data.
   */
  public function prepareNodeProperties(array $requestData, string $operation): array {
    // Service requests default to language-neutral (UND) as citizen-submitted
    // content should not be associated with a specific language.
    // Note: This value may be overridden by hook_node_presave() in
    // service_request.module based on the content type language settings.
    // Taxonomy terms (categories, statuses) are translated separately.
    $values = [
      'type' => 'service_request',
      'langcode' => 'und',
      'changed' => $this->time->getCurrentTime(),
      'field_first_name' => $this->getSafeValue($requestData, 'first_name'),
      'field_last_name' => $this->getSafeValue($requestData, 'last_name'),
      'field_phone' => $this->getSafeValue($requestData, 'phone'),
    ];

    $values['title'] = isset($requestData['service_code']) ? Html::escape(stripslashes($requestData['service_code'])) : NULL;

    if (array_key_exists('email', $requestData)) {
      // Assuming getSafeValue sanitizes the input.
      $sanitizedValue = $this->getSafeValue($requestData, 'email');
      $values['field_e_mail'] = [
        'value' => $sanitizedValue,
      ];
    }

    // Privacy consent: pass through from the payload so hook_node_presave
    // in markaspot_nuxt can enforce the features.privacyNotice.enabled flag.
    if (array_key_exists('field_gdpr', $requestData)) {
      $values['field_gdpr'] = (bool) $requestData['field_gdpr'];
    }

    if ($operation === 'create') {
      $facilityId = $this->resolveSubmittedFacilityId($requestData);
      if ($facilityId !== NULL) {
        $values['field_facility'] = $facilityId;
        if (isset($requestData['jurisdiction_id']) && is_numeric($requestData['jurisdiction_id'])) {
          $values['field_jurisdiction'] = (int) $requestData['jurisdiction_id'];
        }
      }
    }

    // Creating a tmp title to be created later via request_id
    // $values['title'] = $operation === 'create'  ? Html::escape(stripslashes($requestData['service_code'])) : NULL;.
    if (array_key_exists('description', $requestData)) {
      // Assuming getSafeValue sanitizes the input.
      $sanitizedValue = $this->getSafeValue($requestData, 'description');
      $values['body'] = [
        'value' => $sanitizedValue,
      // Set the format explicitly.
        'format' => 'plain_text',
      ];
    }
    $hasCoordinates = array_key_exists('lat', $requestData) && array_key_exists('long', $requestData);
    if ($hasCoordinates) {
      $lat = filter_var($requestData['lat'], FILTER_VALIDATE_FLOAT);
      $lng = filter_var($requestData['long'], FILTER_VALIDATE_FLOAT);
      if ($lat === FALSE || $lng === FALSE) {
        throw new GeoreportException('Coordinates must be numeric values', 400);
      }
      if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new GeoreportException('Coordinates out of range: lat must be -90..90, long must be -180..180', 400);
      }
      $values['field_geolocation'] = [
        'lat' => $lat,
        'lng' => $lng,
      ];
    }

    // Location is optional: if no coordinates are provided, the field_geolocation
    // default value (map center) is used. The DefaultLocationConstraintValidator
    // can optionally warn when the submitted point equals the default.
    $addressString = $requestData['address_string'] ?? ($requestData['address'] ?? NULL);

    // Handle Media URL file creation.
    if (array_key_exists('media_url', $requestData)) {
      $values['field_request_media'] = $this->handleMediaUrls($requestData);
    }
    // $values['created'] = isset($request_data['requested_datetime']) && $operation == 'update' ? strtotime($request_data['requested_datetime']) : '';
    if ($addressString) {
      $address = $this->addressParser(Html::escape(stripslashes($addressString)));
      if (!empty($address)) {
        $values['field_address']['address_line1'] = $address['address_line1'];
        $values['field_address']['address_line2'] = $address['address_line2'];
        $values['field_address']['postal_code'] = $address['postal_code'];
        $values['field_address']['locality'] = $address['locality'];
        // Resolve country_code: explicit param > jurisdiction config > site default.
        $countryCode = $requestData['country_code'] ?? '';
        if (empty($countryCode)) {
          $countryCode = $this->resolveJurisdictionCountry($requestData['jurisdiction_id'] ?? NULL);
        }
        $values['field_address']['country_code'] = $countryCode;
      }
    }

    if (array_key_exists('service_code', $requestData)) {
      $jurisdictionId = isset($requestData['jurisdiction_id']) ? (int) $requestData['jurisdiction_id'] : NULL;
      $category_tid = $this->mapServiceCodeToTaxonomy($requestData['service_code'], $jurisdictionId);
      $values['field_category'] = $category_tid;
      if ($values['field_category'] == NULL && $operation !== 'update') {
        throw new GeoreportException('Service-Code empty or not valid', 400);
      }
      if ($values['field_category'] == NULL && $operation == 'update') {
        throw new GeoreportException('Service Code not valid', 400);
      }
    }

    // Map the Open311 status string ("open" / "closed") to field_status.
    // Only applied on update; create sets the initial status separately after
    // node save via getInitialStatusTid(). The permission guard (access open311
    // advanced properties) is enforced in GeoreportRequestResource::post().
    if ($operation === 'update' && array_key_exists('status', $requestData) && $requestData['status'] !== '') {
      $jurisdictionId = isset($requestData['jurisdiction_id']) ? (int) $requestData['jurisdiction_id'] : NULL;
      $tids = $this->mapStatusToTaxonomyIds((string) $requestData['status'], $jurisdictionId);
      if (!empty($tids)) {
        // field_status is a single-value entity_reference; use the first match.
        $values['field_status'] = (int) reset($tids);
      }
    }

    // Map status_notes to field_status_notes (plain string).
    // GeoreportRequestResource::specialFieldHandling() wraps this in a
    // paragraph entity and appends it to the field_status_notes paragraph list.
    if ($operation === 'update' && array_key_exists('status_notes', $requestData) && $requestData['status_notes'] !== '') {
      $values['field_status_notes'] = $this->getSafeValue($requestData, 'status_notes');
    }

    if (
      $operation === 'update'
      && $this->currentUser->hasPermission('access open311 advanced properties')
      && array_key_exists('extended_attributes', $requestData)
    ) {
      // Check for revision_log_message at multiple possible locations.
      $revisionLogMessage = $requestData['extended_attributes']['revision_log_message']
        ?? $requestData['extended_attributes']['drupal']['revision_log_message']
        ?? NULL;
      // Only set if not null to avoid Html::escape() errors.
      if ($revisionLogMessage !== NULL) {
        $values['revision_log_message'] = $revisionLogMessage;
      }

      // Extract extended attributes, handling field_request_media specially.
      $extendedDrupal = $requestData['extended_attributes']['drupal'] ?? [];

      // Check if field_request_media contains status/published updates.
      if (isset($extendedDrupal['field_request_media']) && is_array($extendedDrupal['field_request_media'])) {
        $mediaUpdates = [];
        foreach ($extendedDrupal['field_request_media'] as $delta => $mediaData) {
          // Check if this is a status update (has 'status' or 'published' key)
          if (is_array($mediaData) && (isset($mediaData['status']) || isset($mediaData['published']))) {
            // Convert to media update format.
            $mediaUpdate = [];

            // Get media ID if provided.
            if (isset($mediaData['target_id'])) {
              $mediaUpdate['mid'] = $mediaData['target_id'];
            }
            elseif (isset($mediaData['mid'])) {
              $mediaUpdate['mid'] = $mediaData['mid'];
            }
            // If no mid provided, store delta for later lookup
            // The delta from the foreach loop will be used in updateMediaPublishedStatus.
            // Handle published status (convert TRUE/FALSE strings to boolean)
            if (isset($mediaData['published'])) {
              $mediaUpdate['published'] = filter_var($mediaData['published'], FILTER_VALIDATE_BOOLEAN);
            }
            elseif (isset($mediaData['status'])) {
              $mediaUpdate['published'] = filter_var($mediaData['status'], FILTER_VALIDATE_BOOLEAN);
            }

            // Add media update - delta will be used for lookup if no mid.
            $mediaUpdates[$delta] = $mediaUpdate;
          }
        }

        // If we found media updates, set them and remove from regular field processing.
        if (!empty($mediaUpdates)) {
          $values['_media_updates'] = $mediaUpdates;
          unset($extendedDrupal['field_request_media']);
        }
      }

      // Public Open311 clients may submit service definition attributes only
      // through the top-level attributes payload, where they are allowlisted
      // against the public service category. Do not accept raw Drupal writes to
      // field_request_attributes here, otherwise internal-status attributes
      // could be mass-assigned by citizens.
      unset($extendedDrupal['field_request_attributes']);

      $values += $this->handleExtendedAttributes($extendedDrupal);

      // Handle media published status updates (original path)
      if (isset($requestData['extended_attributes']['media'])) {
        $values['_media_updates'] = $requestData['extended_attributes']['media'];
      }
    }

    // Handle service definition attributes.
    $attributes = $requestData['attributes'] ?? $requestData['attribute'] ?? NULL;
    if ($attributes) {
      // Validate and normalize to a JSON string.
      if (is_string($attributes)) {
        // Verify the string is valid JSON before storing.
        $decoded = json_decode($attributes, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          $decoded = $this->validateImagelistAttributes($decoded, $requestData);
          if ($decoded !== []) {
            $values['field_request_attributes'] = ['value' => json_encode($decoded)];
          }
        }
      }
      elseif (is_array($attributes) || is_object($attributes)) {
        $decoded = (array) $attributes;
        $decoded = $this->validateImagelistAttributes($decoded, $requestData);
        if ($decoded !== []) {
          $values['field_request_attributes'] = ['value' => json_encode($decoded)];
        }
      }
    }

    return array_filter($values, function ($value) {
      return ($value !== NULL && $value !== FALSE && $value !== '');
    });
  }

  /**
   * Validates public service definition attribute values.
   *
   * Only attributes declared on the public service category are accepted. For
   * attributes with datatype 'imagelist', checks that the submitted value is a
   * valid media entity UUID of the correct bundle. Invalid or unknown values
   * are stripped from the attributes array.
   *
   * @param array $attributes
   *   The submitted attribute key-value pairs.
   * @param array $requestData
   *   The full request data (used to look up service_code for definition).
   *
   * @return array
   *   The validated attributes with invalid imagelist values removed.
   */
  private function validateImagelistAttributes(array $attributes, array $requestData): array {
    $serviceCode = $requestData['service_code'] ?? NULL;
    if (!$serviceCode) {
      return [];
    }

    $jurisdictionId = isset($requestData['jurisdiction_id']) ? (int) $requestData['jurisdiction_id'] : NULL;
    $definitionAttributes = $this->getPublicServiceDefinitionAttributes($serviceCode, $jurisdictionId);
    if ($definitionAttributes === []) {
      return [];
    }

    // Build a map of imagelist attribute codes to their media types and groups.
    $allowedCodes = [];
    $imagelistAttrs = [];
    foreach ($definitionAttributes as $attr) {
      if (!is_array($attr) || empty($attr['code'])) {
        continue;
      }
      $allowedCodes[$attr['code']] = TRUE;
      if (($attr['datatype'] ?? '') === 'imagelist' && !empty($attr['media_type'])) {
        $imagelistAttrs[$attr['code']] = [
          'media_type' => $attr['media_type'],
          'media_group' => $attr['media_group'] ?? NULL,
        ];
      }
    }
    $attributes = array_intersect_key($attributes, $allowedCodes);

    // Validate each imagelist attribute value.
    foreach ($imagelistAttrs as $code => $attrConfig) {
      if (empty($attributes[$code])) {
        continue;
      }

      // Support both single UUID and array of UUIDs.
      $submittedValues = (array) $attributes[$code];

      $lookupProperties = [
        'uuid' => $submittedValues,
        'bundle' => $attrConfig['media_type'],
        'status' => 1,
      ];
      if ($attrConfig['media_group'] !== NULL) {
        $lookupProperties['field_definition_group'] = $attrConfig['media_group'];
      }

      $mediaEntities = $this->entityTypeManager->getStorage('media')
        ->loadByProperties($lookupProperties);

      // Filter to only valid, published UUIDs.
      $validUuids = array_map(fn($entity) => $entity->uuid(), $mediaEntities);
      $filtered = array_values(array_intersect($submittedValues, $validUuids));

      if (empty($filtered)) {
        unset($attributes[$code]);
      }
      else {
        $attributes[$code] = count($filtered) === 1 ? $filtered[0] : $filtered;
      }
    }

    return $attributes;
  }

  /**
   * Returns public definition attributes for a service category.
   *
   * Internal status definitions intentionally do not participate in Open311
   * public request attributes.
   *
   * @param string $serviceCode
   *   The Open311 service code.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to scope the category lookup.
   *
   * @return array
   *   Parsed public service definition attributes.
   */
  private function getPublicServiceDefinitionAttributes(string $serviceCode, ?int $jurisdictionId = NULL): array {
    $term = $this->loadServiceCategoryByCode($serviceCode, $jurisdictionId);
    if (
      !$term ||
      !$term->hasField('field_service_definition') ||
      $term->get('field_service_definition')->isEmpty()
    ) {
      return [];
    }

    $definition = json_decode($term->get('field_service_definition')->value, TRUE);
    if (!is_array($definition)) {
      return [];
    }

    $attributes = array_is_list($definition)
      ? $definition
      : ($definition['attributes'] ?? []);

    return is_array($attributes) ? $attributes : [];
  }

  /**
   * Filters request attributes to the public service definition.
   *
   * @param array $attributes
   *   Stored request attributes.
   * @param object $node
   *   The service request node.
   *
   * @return array
   *   Attributes safe for public Open311 responses.
   */
  private function filterPublicRequestAttributes(array $attributes, object $node): array {
    if ($attributes === []) {
      return [];
    }

    $serviceCode = NULL;
    if (
      $node->hasField('field_category') &&
      !$node->get('field_category')->isEmpty() &&
      ($category = $node->get('field_category')->entity) instanceof ContentEntityInterface &&
      $category->hasField('field_service_code') &&
      !$category->get('field_service_code')->isEmpty()
    ) {
      $serviceCode = (string) $category->get('field_service_code')->value;
    }
    if (!$serviceCode) {
      return [];
    }

    $jurisdictionId = $this->getJurisdictionIdFromNode($node);
    $definitionAttributes = $this->getPublicServiceDefinitionAttributes($serviceCode, $jurisdictionId);
    if ($definitionAttributes === []) {
      return [];
    }

    $allowedCodes = [];
    foreach ($definitionAttributes as $attribute) {
      if (is_array($attribute) && !empty($attribute['code'])) {
        $allowedCodes[(string) $attribute['code']] = TRUE;
      }
    }

    return array_intersect_key($attributes, $allowedCodes);
  }

  /**
   * Parses an address string into an associative array.
   *
   * @param string $addressString
   *   The address string to be parsed.
   *
   * @return array
   *   An associative array containing the parsed address components.
   */
  private function parseAddress(string $addressString): array {
    $addressArray = $addressString !== '' ? explode(',', $addressString) : [];
    $address = [];

    if (is_array($addressArray) && count($addressArray) >= 2) {
      $zipCity = explode(' ', trim($addressArray[1]));

      $address = [
        'street' => $addressArray[0],
        'zip' => trim($zipCity[0] ?? ''),
        'city' => trim($zipCity[1] ?? ''),
      ];
    }

    return $address;
  }

  /**
   * Maps a service code to a Drupal taxonomy term ID.
   *
   * @param string $serviceCode
   *   The service code to be mapped.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to scope the lookup.
   *
   * @return int|null
   *   The taxonomy term ID, or null if not found.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the service code is not found in the taxonomy.
   */
  public function mapServiceCodeToTaxonomy(string $serviceCode, ?int $jurisdictionId = NULL): ?int {
    $term = $this->loadServiceCategoryByCode($serviceCode, $jurisdictionId);
    if ($term) {
      return (int) $term->id();
    }

    throw new NotFoundHttpException('Service code not found');
  }

  /**
   * Loads a public service category by Open311 service code.
   *
   * @param string $serviceCode
   *   The service code to resolve. Comma-separated legacy values are supported.
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to scope the lookup.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The matching service category term, if found.
   */
  private function loadServiceCategoryByCode(string $serviceCode, ?int $jurisdictionId = NULL): ?ContentEntityInterface {
    $effectiveJurisdictionId = NULL;
    if ($jurisdictionId) {
      // Resolve to root jurisdiction for child jurisdictions (taxonomy inheritance).
      $effectiveJurisdictionId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($effectiveJurisdictionId === NULL) {
        return NULL;
      }
    }

    $serviceCodes = explode(',', $serviceCode);
    foreach ($serviceCodes as $code) {
      $code = trim($code);
      if ($code === '') {
        continue;
      }

      $properties = [
        'vid' => 'service_category',
        'field_service_code' => $code,
      ];
      if ($effectiveJurisdictionId) {
        $properties['field_jurisdiction'] = $effectiveJurisdictionId;
      }

      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties($properties);
      $term = reset($terms);
      if ($term instanceof ContentEntityInterface) {
        return $term;
      }
    }

    return NULL;
  }

  /**
   * Maps a status value to a Drupal taxonomy term ID.
   *
   * @param string $statuses
   *   A comma-separated list of status values.
   *
   * @return array
   *   An array of taxonomy term IDs.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the status value is not found in the taxonomy.
   */
  public function mapStatusToTaxonomy(string $statuses): array {
    $statusValues = explode(',', $statuses);
    $termIds = [];

    foreach ($statusValues as $status) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $status]);
      if (!empty($terms)) {
        $termIds = array_merge($termIds, array_keys($terms));
      }
      else {
        throw new NotFoundHttpException('Status not found');
      }
    }

    return $termIds;
  }

  /**
   * Returns a taxonomy tree for a given vocabulary.
   *
   * @param string $vocabulary
   *   The machine name of the vocabulary.
   * @param string|null $langcode
   *   The language code for the vocabulary. Defaults to site default language.
   * @param int $parent
   *   The ID of the parent taxonomy term (default: 0).
   * @param int|null $maxDepth
   *   The maximum depth for the taxonomy tree (default: null).
   * @param int|null $jurisdictionId
   *   Optional jurisdiction group ID to filter terms.
   *
   * @return array
   *   An array of service definitions.
   */
  public function getTaxonomyTree(string $vocabulary = 'tags', ?string $langcode = NULL, int $parent = 0, ?int $maxDepth = NULL, ?int $jurisdictionId = NULL): array {
    // Use site default language if no langcode provided.
    $langcode = $langcode ?? $this->languageManager->getDefaultLanguage()->getId();

    $properties = ['vid' => $vocabulary, 'status' => 1];
    // Preserve the original jurisdiction ID before root resolution.
    // Child jurisdictions may have category restrictions that reference
    // the child's own ID, not the root's.
    $originalJurisdictionId = $jurisdictionId;
    if ($jurisdictionId && $this->hierarchyResolver) {
      // Resolve to root jurisdiction for child jurisdictions (taxonomy inheritance).
      $effectiveId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($effectiveId === NULL) {
        return [];
      }
      $properties['field_jurisdiction'] = $effectiveId;
    }
    $tree = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties($properties);

    if (empty($tree)) {
      return [];
    }

    // Apply category allow-list for child jurisdictions.
    if ($originalJurisdictionId && $this->hierarchyResolver) {
      $allowedIds = $this->hierarchyResolver->getAllowedCategoryIds($originalJurisdictionId);
      if ($allowedIds !== NULL) {
        $tree = array_filter($tree, fn($term) => in_array((int) $term->id(), $allowedIds, TRUE));
      }
    }

    $services = [];
    foreach ($tree as $term) {
      $services[] = $this->mapTaxonomyToService($term->id(), $langcode);
    }

    return $services;
  }

  /**
   * Maps a taxonomy term to a service definition.
   *
   * @param int $tid
   *   The taxonomy term ID.
   * @param string $langcode
   *   The language code for the taxonomy term.
   *
   * @return array
   *   An associative array representing the service definition.
   */
  public function mapTaxonomyToService(int $tid, string $langcode): array {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);

    // Load the translation if available.
    if ($term->hasTranslation($langcode)) {
      $term = $term->getTranslation($langcode);
    }

    // Check for service definition attributes.
    $hasDefinition = $term->hasField('field_service_definition')
      && !$term->get('field_service_definition')->isEmpty();
    $attributes = [];
    if ($hasDefinition) {
      $definitionJson = $term->get('field_service_definition')->value;
      $definition = json_decode($definitionJson, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && !empty($definition['attributes'])) {
        $attributes = $definition['attributes'];
      }
    }

    $service = [
      'service_code' => $term->field_service_code->value,
      'service_name' => $term->getName(),
      'metadata' => !empty($attributes) ? 'true' : 'false',
      'type' => 'realtime',
      'description' => $term->getDescription(),
      'keywords' => $term->field_keywords->value ?? '',
    ];

    // Include attributes inline when present.
    if (!empty($attributes)) {
      $service['attributes'] = $attributes;
    }

    // Exclude field_service_definition from extended_attributes since its
    // parsed content is already exposed as top-level 'attributes'.
    foreach ($term->getFields() as $key => $value) {
      if ($key === 'field_service_definition') {
        continue;
      }
      $fieldType = $value->getFieldDefinition()->getType();
      $service['extended_attributes'][$key] = ($fieldType === 'color_field_type') ? $value->color : $value->value;
    }

    return $service;
  }

  /**
   * Queries the database for service request nodes.
   *
   * @param object $query
   *   The database query object.
   * @param object $user
   *   The user object.
   * @param array $parameters
   *   An array of query parameters.
   * @param int|null $readScope
   *   Optional jurisdiction group ID that scopes the serialized response shape.
   *
   * @return array
   *   An array of service request definitions, or structured response with
   *   metadata when meta=true.
   */
  public function getResults(
    object $query,
    object $user,
    array $parameters,
    ?int $readScope = NULL,
  ): array {
    // Check if meta parameter requests wrapped response with metadata.
    // Note: extensions=true alone returns a plain array for backwards
    // compatibility.
    $includeMetadata = !empty($parameters['meta']) &&
                       (strtolower($parameters['meta']) === 'true' || $parameters['meta'] === '1');

    // Get total count before applying range, if metadata is requested.
    $totalCount = 0;
    $limit = 0;
    $offset = 0;
    $requestListPagination = $parameters['_request_list_pagination'] ?? [];
    $requestListSort = $parameters['_request_list_sort'] ?? NULL;
    $requestListTotal = $parameters['_request_list_total'] ?? NULL;

    if ($includeMetadata) {
      // Extract limit/offset from parameters. They were set before query
      // creation.
      if (!empty($requestListPagination)) {
        $limit = (int) $requestListPagination['limit'];
        $offset = (int) $requestListPagination['offset'];
      }
      else {
        $limit = isset($parameters['limit']) ? (int) $parameters['limit'] : 100;

        // Support both 'page' (1-based) and 'offset' (0-based) parameters.
        if (isset($parameters['page']) && $parameters['page'] > 0) {
          $page = (int) $parameters['page'];
          $offset = ($page - 1) * $limit;
        }
        elseif (isset($parameters['offset']) && $parameters['offset'] >= 0) {
          $offset = (int) $parameters['offset'];
        }
      }

      if ($requestListTotal !== NULL) {
        $totalCount = (int) $requestListTotal;
      }
      else {
        // Clone query to get total count without range.
        $countQuery = clone $query;
        $countQuery->range(NULL, NULL);
        $totalCount = (int) $countQuery->count()->execute();
      }
    }

    $nids = $query->execute();

    if (empty($nids)) {
      // Return empty structure based on metadata flag.
      if ($includeMetadata) {
        return [
          'requests' => [],
          'meta' => $this->buildRequestListMetadata(
            $totalCount,
            $limit,
            $offset,
            $parameters
          ),
        ];
      }
      return [];
    }

    // Load nodes - for privileged users we need to bypass entity access.
    $storage = $this->entityTypeManager->getStorage('node');
    $bypass_access = $user->id() == 1;

    if ($bypass_access) {
      // Switch to root user account to bypass all access checks during node loading.
      $root_user = User::load(1);
      $this->accountSwitcher->switchTo($root_user);
      try {
        $nodes = $storage->loadMultiple($nids);
      }
      finally {
        $this->accountSwitcher->switchBack();
      }
    }
    else {
      // For regular users, load with normal access checks.
      $nodes = $storage->loadMultiple($nids);
      // Additional filtering for authenticated users' own unpublished content.
      if (!$user->isAnonymous()) {
        foreach ($nids as $nid) {
          if (!isset($nodes[$nid])) {
            $this->accountSwitcher->switchTo(User::load(1));
            try {
              $node = $storage->load($nid);
            }
            finally {
              $this->accountSwitcher->switchBack();
            }

            if ($node && !$node->isPublished() && $node->getOwnerId() == $user->id()) {
              $nodes[$nid] = $node;
            }
          }
        }
      }
    }

    $nodes = $this->orderLoadedNodes($nodes, $nids);

    // Use the proper role determination method, scoped to the response's
    // jurisdiction read scope (markaspot-ui#427).
    $extendedRole = $this->scopeExtendedRoleToReadScope(
      $this->determineExtendedRole($user),
      $readScope,
      $user
    );

    // Without an explicit jurisdiction claim the manager shape must be
    // scoped per NODE: in multi-tenant installs the unclaimed list spans
    // all tenants (jur-outsider grants), so each node's own jurisdiction
    // decides whether this caller gets the extended or the public shape.
    $unclaimedManagerScope = $extendedRole === 'manager'
      && empty($readScope);

    // Preload all taxonomy terms needed by these nodes.
    $this->preloadTaxonomyTerms($nodes);

    $serviceRequests = [];
    $lastNode = NULL;
    foreach ($nodes as $node) {
      $nodeRole = $unclaimedManagerScope
        ? $this->scopeManagerRoleToNode($node, $user)
        : $extendedRole;
      $serviceRequests[] = $this->mapNodeToServiceRequest($node, $nodeRole, $parameters);
      $lastNode = $node;
    }

    // Return structured response with metadata if extensions enabled.
    if ($includeMetadata) {
      $meta = $this->buildRequestListMetadata(
        $totalCount,
        $limit,
        $offset,
        $parameters,
        $lastNode,
        $requestListSort
      );

      return [
        'requests' => $serviceRequests,
        'meta' => $meta,
      ];
    }

    return $serviceRequests;
  }

  /**
   * Reorders loaded entities to match the entity query result order.
   *
   * EntityStorage::loadMultiple() returns keyed entities, but storage backends
   * do not guarantee that the returned array keeps the query order.
   *
   * @param array $nodes
   *   Loaded node entities keyed by node ID.
   * @param array $nids
   *   Node IDs in query result order.
   *
   * @return array
   *   Loaded nodes in query result order.
   */
  protected function orderLoadedNodes(array $nodes, array $nids): array {
    $ordered = [];
    foreach ($nids as $nid) {
      if (isset($nodes[$nid])) {
        $ordered[$nid] = $nodes[$nid];
      }
    }
    return $ordered;
  }

  /**
   * Normalizes request-list pagination parameters.
   *
   * Offset and page stay supported for backwards compatibility. When a cursor
   * is supplied, keyset pagination starts from offset 0 because the cursor
   * itself defines the continuation point.
   *
   * @param array $parameters
   *   Request query parameters.
   * @param array $sort
   *   Normalized request-list sort metadata.
   *
   * @return array
   *   Pagination metadata with limit, offset, and optional decoded cursor.
   */
  public function normalizeRequestListPagination(array $parameters, array $sort): array {
    $limit = isset($parameters['limit']) ? (int) $parameters['limit'] : 100;
    $offset = 0;

    // Support both 'page' (1-based) and 'offset' (0-based) parameters.
    if (isset($parameters['page']) && (int) $parameters['page'] > 0) {
      $page = (int) $parameters['page'];
      $offset = ($page - 1) * $limit;
    }
    elseif (isset($parameters['offset']) && (int) $parameters['offset'] >= 0) {
      $offset = (int) $parameters['offset'];
    }

    // Performance protection: require explicit limits for queries without
    // date filters.
    if (!isset($parameters['start_date']) && !isset($parameters['updated'])) {
      $limit = min($limit, 100);
    }
    else {
      // Apply limit for date-filtered queries. These can be larger since
      // they are more specific.
      $limit = min($limit, 500);
    }

    if (!empty($parameters['cursor']) && !empty($parameters['q'])) {
      throw new GeoreportException('Cursor pagination is not available for text search.', 400);
    }

    $cursor = $this->decodeRequestListCursor($parameters['cursor'] ?? NULL, $sort);
    if ($cursor !== NULL) {
      $offset = 0;
    }

    return [
      'limit' => $limit,
      'offset' => $offset,
      'cursor' => $cursor,
    ];
  }

  /**
   * Normalizes request-list sort parameters.
   *
   * @param array $parameters
   *   Request query parameters.
   *
   * @return array
   *   Sort metadata with API field, entity field, and direction.
   */
  public function normalizeRequestListSort(array $parameters): array {
    // The updated filter is the legacy "changes since" path and always sorts
    // newest changed entities first.
    if (isset($parameters['updated'])) {
      return [
        'api_field' => 'updated',
        'field' => 'changed',
        'direction' => 'DESC',
      ];
    }

    $apiField = 'created';
    $sortField = 'created';
    $sortDirection = 'ASC';

    if (isset($parameters['sort'])) {
      $sortParam = (string) $parameters['sort'];

      // DEPRECATED: Legacy sort=DESC or sort=ASC (backward compatibility).
      // Maps to 'created' field only. Use JSON:API style for other fields.
      if (strcasecmp($sortParam, 'DESC') === 0) {
        $sortDirection = 'DESC';
      }
      elseif (strcasecmp($sortParam, 'ASC') === 0) {
        $sortDirection = 'ASC';
      }
      else {
        // JSON:API style: '-' prefix indicates descending order.
        if (str_starts_with($sortParam, '-')) {
          $sortDirection = 'DESC';
          $sortParam = substr($sortParam, 1);
        }
        else {
          $sortDirection = 'ASC';
        }

        if (isset(static::REQUEST_LIST_SORT_FIELDS[$sortParam])) {
          $apiField = $sortParam;
          $sortField = static::REQUEST_LIST_SORT_FIELDS[$sortParam];
        }
      }
    }

    return [
      'api_field' => $apiField,
      'field' => $sortField,
      'direction' => $sortDirection,
    ];
  }

  /**
   * Applies stable request-list sorting to an entity query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The query to sort.
   * @param array $sort
   *   Normalized sort metadata.
   */
  public function applyRequestListSort(QueryInterface $query, array $sort): void {
    $query->sort($sort['field'], $sort['direction']);

    // EntityQuery order for equal timestamps/reference values is undefined.
    // Add nid as a deterministic tie-breaker so offset callers stop drifting
    // across rows that share the primary sort value.
    if ($sort['field'] !== 'nid') {
      $query->sort('nid', $sort['direction']);
    }
  }

  /**
   * Applies a decoded request-list cursor to an entity query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The query to constrain.
   * @param array|null $cursor
   *   Decoded cursor metadata.
   * @param array $sort
   *   Normalized sort metadata.
   */
  public function applyRequestListCursor(QueryInterface $query, ?array $cursor, array $sort): void {
    if ($cursor === NULL) {
      return;
    }

    $operator = $sort['direction'] === 'DESC' ? '<' : '>';

    if ($sort['field'] === 'nid') {
      $query->condition('nid', $cursor['nid'], $operator);
      return;
    }

    $cursorGroup = $query->orConditionGroup();
    $cursorGroup->condition($sort['field'], $cursor['value'], $operator);

    $tieGroup = $query->andConditionGroup();
    $tieGroup->condition($sort['field'], $cursor['value']);
    $tieGroup->condition('nid', $cursor['nid'], $operator);
    $cursorGroup->condition($tieGroup);

    $query->condition($cursorGroup);
  }

  /**
   * Builds an opaque cursor for the last node in a response page.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The last returned node.
   * @param array $sort
   *   Normalized sort metadata.
   *
   * @return string|null
   *   URL-safe opaque cursor, or NULL when the sort value cannot be read.
   */
  public function buildRequestListCursor(ContentEntityInterface $node, array $sort): ?string {
    $value = $this->getRequestListCursorValue($node, $sort['field']);
    if ($value === NULL) {
      return NULL;
    }

    $payload = [
      'v' => static::REQUEST_LIST_CURSOR_VERSION,
      'field' => $sort['field'],
      'direction' => $sort['direction'],
      'value' => $value,
      'nid' => (int) $node->id(),
    ];

    return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
  }

  /**
   * Decodes and validates an opaque request-list cursor.
   *
   * @param string|null $cursor
   *   Cursor query parameter.
   * @param array $sort
   *   Normalized sort metadata.
   *
   * @return array|null
   *   Decoded cursor metadata, or NULL when no cursor was supplied.
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   Thrown when the supplied cursor is invalid for the current sort.
   */
  public function decodeRequestListCursor(?string $cursor, array $sort): ?array {
    if ($cursor === NULL || $cursor === '') {
      return NULL;
    }

    $normalized = strtr($cursor, '-_', '+/');
    $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
    $decoded = base64_decode($normalized, TRUE);
    if ($decoded === FALSE) {
      throw new GeoreportException('Invalid pagination cursor.', 400);
    }

    $payload = json_decode($decoded, TRUE);
    if (!is_array($payload)
      || ($payload['v'] ?? NULL) !== static::REQUEST_LIST_CURSOR_VERSION
      || ($payload['field'] ?? NULL) !== $sort['field']
      || ($payload['direction'] ?? NULL) !== $sort['direction']
      || !array_key_exists('value', $payload)
      || empty($payload['nid'])
      || !is_scalar($payload['value'])
      || !is_numeric($payload['nid'])
    ) {
      throw new GeoreportException('Invalid pagination cursor.', 400);
    }

    $value = $payload['value'];
    if (in_array($sort['field'], ['created', 'changed', 'field_status', 'field_category', 'nid'], TRUE)) {
      if (!is_numeric($value)) {
        throw new GeoreportException('Invalid pagination cursor.', 400);
      }
      $value = (int) $value;
    }
    else {
      $value = (string) $value;
    }

    return [
      'value' => $value,
      'nid' => (int) $payload['nid'],
    ];
  }

  /**
   * Builds metadata for request-list responses.
   *
   * @param int $totalCount
   *   Total result count for the current query.
   * @param int $limit
   *   Effective page limit.
   * @param int $offset
   *   Effective offset.
   * @param array $parameters
   *   Request query parameters.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $lastNode
   *   Last node in the returned page.
   * @param array|null $requestListSort
   *   Normalized request-list sort metadata.
   *
   * @return array
   *   Response metadata.
   */
  protected function buildRequestListMetadata(
    int $totalCount,
    int $limit,
    int $offset,
    array $parameters,
    ?ContentEntityInterface $lastNode = NULL,
    ?array $requestListSort = NULL,
  ): array {
    $meta = [
      'total' => $totalCount,
      'limit' => $limit,
      'offset' => $offset,
    ];

    if (!empty($parameters['cursor'])) {
      $meta['cursor'] = $parameters['cursor'];
    }

    if ($lastNode !== NULL && $requestListSort !== NULL && empty($parameters['q'])) {
      $nextCursor = $this->buildRequestListCursor($lastNode, $requestListSort);
      if ($nextCursor !== NULL) {
        $meta['next_cursor'] = $nextCursor;
      }
    }

    return $meta;
  }

  /**
   * Reads the cursor value for a node and sort field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node to inspect.
   * @param string $sortField
   *   Entity field used as primary sort.
   *
   * @return int|string|null
   *   Cursor value, or NULL when unavailable.
   */
  protected function getRequestListCursorValue(ContentEntityInterface $node, string $sortField): int|string|null {
    if ($sortField === 'nid') {
      return (int) $node->id();
    }

    if (!$node->hasField($sortField) || $node->get($sortField)->isEmpty()) {
      return NULL;
    }

    $field = $node->get($sortField);
    if (isset($field->target_id)) {
      return (int) $field->target_id;
    }
    if (isset($field->value)) {
      if (in_array($sortField, ['created', 'changed'], TRUE)) {
        return (int) $field->value;
      }
      return (string) $field->value;
    }

    return NULL;
  }

  /**
   * Preloads taxonomy terms for a collection of nodes to avoid individual loads.
   *
   * @param array $nodes
   *   Array of node entities.
   */
  private function preloadTaxonomyTerms(array $nodes): void {
    $categoryIds = [];
    $statusIds = [];

    // Collect all term IDs used in the nodes.
    foreach ($nodes as $node) {
      if ($node->hasField('field_category') && !$node->field_category->isEmpty()) {
        $categoryIds[] = $node->field_category->target_id;
      }

      if ($node->hasField('field_status') && !$node->field_status->isEmpty()) {
        $statusIds[] = $node->field_status->target_id;
      }
    }

    // Preload all terms in a single operation.
    if (!empty($categoryIds)) {
      $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_unique($categoryIds));
    }

    if (!empty($statusIds)) {
      $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_unique($statusIds));
    }
  }

  /**
   * Determines the extended role based on user permissions.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account to check.
   *
   * @return string
   *   The determined role ('anonymous', 'user', or 'manager').
   */
  private function determineExtendedRole($user): string {
    // First check if user is anonymous regardless of permissions.
    if ($user->isAnonymous()) {
      return 'anonymous';
    }

    // Then check permissions for authenticated users only.
    if ($user->hasPermission('access open311 advanced properties')) {
      return 'manager';
    }

    if ($user->hasPermission('access open311 extension')) {
      return 'user';
    }

    return 'anonymous';
  }

  /**
   * Scopes the extended role to the response's jurisdiction read scope.
   *
   * Tenant isolation of the serialization shape (markaspot-ui#427): the
   * extended "manager" shape is member-only per jurisdiction. Read
   * resources resolve the effective jurisdiction scope of the response
   * (explicit jurisdiction_id claim, or the single request's own
   * jurisdiction) into an explicit read scope passed to getResults().
   * A dashboard-capable user from another tenant keeps read access to
   * public data, but is serialized with the anonymous/public shape
   * instead of receiving a hard 403 (degrade, don't deny). Elevated
   * request parameters (extended_attributes, extensions, fields) cannot
   * re-elevate the shape because every shape decision keys off the role,
   * not the permission.
   *
   * The 'user' role (access open311 extension, API service identities) is
   * intentionally not membership-scoped; it was never subject to the
   * jurisdiction isolation gate.
   *
   * @param string $extendedRole
   *   The role determined by determineExtendedRole().
   * @param int|null $readScope
   *   Optional jurisdiction group ID that scopes the serialized response shape.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The account the response is serialized for.
   *
   * @return string
   *   The effective role: unchanged, or 'anonymous' when a manager is not
   *   a member of the scoped jurisdiction.
   */
  private function scopeExtendedRoleToReadScope(string $extendedRole, ?int $readScope, $user): string {
    if ($extendedRole !== 'manager' || empty($readScope)) {
      return $extendedRole;
    }

    if ($this->isJurisdictionMember($readScope, $user)) {
      return 'manager';
    }

    return 'anonymous';
  }

  /**
   * Scopes the manager role to a single node's own jurisdiction.
   *
   * Used for reads WITHOUT an explicit jurisdiction claim
   * (markaspot-ui#427): in multi-tenant installs the unclaimed request
   * list spans every tenant's published nodes via the jur-outsider query
   * grants, so a manager-shaped serialization must be decided per node.
   * Nodes of jurisdictions the caller belongs to keep the manager shape;
   * foreign nodes get the anonymous/public shape.
   *
   * Fail-closed: a node whose jurisdiction cannot be resolved is
   * serialized as public — but only when jurisdiction groups exist at
   * all. Legacy single-tenant installs (Group module enabled, zero jur
   * groups) keep the manager shape, because zero tenants means zero
   * cross-tenant exposure and staff there legitimately read without a
   * jurisdiction_id.
   *
   * @param object $node
   *   The service request node being serialized.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The account the response is serialized for.
   *
   * @return string
   *   'manager' or 'anonymous'.
   */
  private function scopeManagerRoleToNode(object $node, $user): string {
    // Uid 1 and installs without any jurisdiction groups are never scoped.
    if ($user->id() == 1 || !$this->hasJurisdictionGroups()) {
      return 'manager';
    }

    $jurisdictionId = $this->resolveNodeJurisdictionId($node);
    if ($jurisdictionId === NULL) {
      return 'anonymous';
    }

    return $this->isJurisdictionMember($jurisdictionId, $user) ? 'manager' : 'anonymous';
  }

  /**
   * Creates a node query with proper access checks.
   *
   * @param array $parameters
   *   Query parameters.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The configured query object.
   */
  public function createNodeQuery(array $parameters, $user): QueryInterface {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'service_request');

    // Check if group filtering is requested and enabled.
    $use_group_filter = FALSE;
    if (!empty($parameters['group_filter']) && !$user->isAnonymous()) {
      $config = $this->configFactory->get('markaspot_open311.settings');
      $use_group_filter = $config->get('group_filter_enabled') ?? FALSE;
    }

    // Only super-admin (uid 1) bypasses all access checks.
    // Tenant admins rely on Group module for jurisdiction-scoped access.
    if ($user->id() == 1) {
      $query->accessCheck(FALSE);
    }
    // When group filtering is active, let Group module handle access control.
    // Group module's EntityQueryAlter adds conditions for grouped content,
    // including author access to own unpublished content.
    elseif ($use_group_filter) {
      $query->accessCheck(TRUE);
    }
    // Authenticated users: let Group module handle access control.
    // Group module's EntityQueryAlter applies outsider/insider permissions,
    // including 'view unpublished group_node:service_request entity' for
    // moderators with the org-moderator outsider role.
    elseif (!$user->isAnonymous()) {
      $query->accessCheck(TRUE);
    }
    // Anonymous users can only see published nodes.
    else {
      $query->condition('status', 1);
      $query->accessCheck(TRUE);
    }

    // Apply group membership filter.
    if ($use_group_filter) {
      $config = $this->configFactory->get('markaspot_open311.settings');
      $group_type = $config->get('group_filter_type') ?? 'org';
      $node_ids = $this->getNodeIdsInUserGroups($user, $group_type);
      if (!empty($node_ids)) {
        $query->condition('nid', $node_ids, 'IN');
      }
      else {
        // User has no group memberships - return no results.
        $query->condition('nid', [0], 'IN');
      }
    }

    // Apply jurisdiction filter (gid or jurisdiction slug).
    // This filters by a specific group (jurisdiction type) for multi-tenant setups.
    // Uses hierarchy resolver to include child jurisdiction nodes (Phase 2).
    $jurisdiction_gid = $this->resolveJurisdictionId($parameters);
    if ($jurisdiction_gid) {
      $node_ids = $this->hierarchyResolver
        ? $this->hierarchyResolver->getNodeIdsInJurisdiction($jurisdiction_gid)
        : $this->getNodeIdsInGroup($jurisdiction_gid);
      if (!empty($node_ids)) {
        $query->condition('nid', $node_ids, 'IN');
      }
      else {
        // No nodes in this jurisdiction - return empty results.
        $query->condition('nid', [0], 'IN');
      }
    }

    // Apply organisation group filter (group_id parameter).
    // This filters by a specific organisation group for department/agency filtering.
    // Unlike jurisdiction filter, this uses organisation groups (type 'org').
    $org_group_id = $this->resolveOrganisationGroupId($parameters);
    if ($org_group_id !== NULL) {
      $node_ids = $this->getNodeIdsInGroup($org_group_id);
      if (!empty($node_ids)) {
        $query->condition('nid', $node_ids, 'IN');
      }
      else {
        // No nodes in this organisation group - return empty results.
        $query->condition('nid', [0], 'IN');
      }
    }

    return $query;
  }

  /**
   * Resolves jurisdiction parameter to a group ID.
   *
   * Checks parameters in priority order:
   * 1. 'jurisdiction_id' (new canonical name, numeric or slug)
   * 2. 'jurisdiction' (deprecated alias)
   * 3. 'gid' (deprecated legacy)
   *
   * @param array $parameters
   *   Query parameters.
   *
   * @return int|null
   *   The group ID or NULL if not specified/found.
   */
  public function resolveJurisdictionId(array $parameters): ?int {
    // New canonical parameter.
    $value = $parameters['jurisdiction_id'] ?? NULL;

    // Deprecated aliases (backward compat, one release cycle).
    if (empty($value) && !empty($parameters['jurisdiction'])) {
      $value = $parameters['jurisdiction'];
      $this->logger?->notice('Deprecated API parameter "jurisdiction". Use "jurisdiction_id" instead.');
    }
    if (empty($value) && !empty($parameters['gid'])) {
      $value = $parameters['gid'];
      $this->logger?->notice('Deprecated API parameter "gid". Use "jurisdiction_id" instead.');
    }

    if (empty($value)) {
      return NULL;
    }

    // Numeric = direct group ID.
    if (is_numeric($value)) {
      return (int) $value;
    }

    // Validate slug format (alphanumeric, hyphens, underscores, max 64 chars).
    if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $value)) {
      return NULL;
    }

    // Load jurisdiction group type from config (supports legacy 'jurisdiction' naming).
    // Lookup by slug.
    $groups = $this->entityTypeManager->getStorage('group')->loadByProperties([
      'type' => $this->jurisdictionGroupType(),
      'field_slug' => $value,
    ]);
    $group = reset($groups);
    if ($group) {
      return (int) $group->id();
    }

    return NULL;
  }

  /**
   * Resolves organisation group parameter to a group ID.
   *
   * Checks parameters in priority order:
   * 1. 'org_id' (new canonical name)
   * 2. 'group_id' (deprecated alias)
   *
   * @param array $parameters
   *   Query parameters.
   *
   * @return int|null
   *   The organisation group ID or NULL if not specified.
   *   Returns -1 if the org_id was specified but the group doesn't exist.
   */
  protected function resolveOrganisationGroupId(array $parameters): ?int {
    // New canonical parameter.
    $group_id_value = $parameters['org_id'] ?? NULL;

    // Deprecated alias (backward compat, one release cycle).
    if (empty($group_id_value) && !empty($parameters['group_id'])) {
      $group_id_value = $parameters['group_id'];
      $this->logger?->notice('Deprecated API parameter "group_id". Use "org_id" instead.');
    }

    if (empty($group_id_value) || !is_numeric($group_id_value)) {
      return NULL;
    }

    $group_id = (int) $group_id_value;

    // Validate that this group exists.
    $group = $this->entityTypeManager->getStorage('group')->load($group_id);
    if ($group) {
      // Security: Verify user has membership in the requested group.
      // This prevents unauthorized access to other groups' requests.
      $member = $group->getMember($this->currentUser);
      if ($member) {
        return $group_id;
      }

      // User is not a member of this group - deny access by returning -1.
      return -1;
    }

    // Group doesn't exist - return -1 to signal that filtering was requested
    // but the group is invalid. This will result in an empty result set.
    return -1;
  }

  /**
   * Gets node IDs that belong to a specific group.
   *
   * Uses direct database query for performance - avoids loading full entities.
   *
   * @param int $group_id
   *   The group ID.
   *
   * @return array
   *   Array of node IDs belonging to the group.
   */
  protected function getNodeIdsInGroup(int $group_id): array {
    if (!$this->moduleHandler->moduleExists('group')) {
      return [];
    }

    // Direct database query for entity_id only - much faster than loading entities.
    $node_ids = $this->database->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['entity_id'])
      ->condition('gid', $group_id)
      ->condition('plugin_id', 'group_node:service_request')
      ->execute()
      ->fetchCol();

    return array_map('intval', $node_ids);
  }

  /**
   * Gets node IDs that belong to the user's groups of the specified type.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param string $group_type
   *   The group type machine name to filter by (e.g., 'org').
   *
   * @return array
   *   Array of node IDs belonging to user's groups of the specified type.
   */
  protected function getNodeIdsInUserGroups($user, string $group_type = 'org'): array {
    // Check if Group module is available.
    if (!$this->moduleHandler->moduleExists('group')) {
      return [];
    }

    // Load user's group memberships.
    $memberships = GroupMembership::loadByUser($user);
    $group_ids = [];

    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      // Only include groups of the specified type.
      if ($group && $group->bundle() === $group_type) {
        $group_ids[] = $membership->getGroupId();
      }
    }

    if (empty($group_ids)) {
      return [];
    }

    // Use Entity Query API for group relationships.
    $relationship_storage = $this->entityTypeManager->getStorage('group_relationship');

    $relationship_ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group_ids, 'IN')
      ->condition('plugin_id', 'group_node:service_request')
      ->execute();

    if (empty($relationship_ids)) {
      return [];
    }

    // Load relationships and extract entity IDs.
    $relationships = $relationship_storage->loadMultiple($relationship_ids);

    $node_ids = [];
    foreach ($relationships as $relationship) {
      $node_ids[] = $relationship->get('entity_id')->target_id;
    }

    return array_unique($node_ids);
  }

  /**
   * Checks if user has a specific permission via Group module membership.
   *
   * @param object $node
   *   The node object.
   * @param \Drupal\Core\Session\AccountProxyInterface $user
   *   The user to check.
   * @param string $operation
   *   The operation to check: 'view', 'update', or 'delete'.
   *
   * @return bool
   *   TRUE if user has the permission via group membership.
   */
  protected function checkGroupPermission(object $node, $user, string $operation): bool {
    try {
      // Get the groups this node belongs to.
      $relationship_storage = $this->entityTypeManager->getStorage('group_relationship');
      $relationships = $relationship_storage->loadByProperties([
        'entity_id' => $node->id(),
        'plugin_id' => 'group_node:service_request',
      ]);

      if (empty($relationships)) {
        return FALSE;
      }

      // Get user's group memberships.
      $memberships = GroupMembership::loadByUser($user);
      $user_group_ids = [];
      foreach ($memberships as $membership) {
        $user_group_ids[] = $membership->getGroupId();
      }

      if (empty($user_group_ids)) {
        return FALSE;
      }

      // Check if node is in any of user's groups.
      foreach ($relationships as $relationship) {
        $group_id = $relationship->getGroupId();
        if (in_array($group_id, $user_group_ids)) {
          // User is in this group - check their role permissions.
          foreach ($memberships as $membership) {
            if ($membership->getGroupId() == $group_id) {
              $group = $membership->getGroup();
              if ($group) {
                // Check for 'any' permission first.
                $any_permission = "{$operation} any group_node:service_request entity";
                if ($group->hasPermission($any_permission, $user)) {
                  return TRUE;
                }
                // Check for 'own' permission if user owns the node.
                if ($node->getOwnerId() == $user->id()) {
                  $own_permission = "{$operation} own group_node:service_request entity";
                  if ($group->hasPermission($own_permission, $user)) {
                    return TRUE;
                  }
                }
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // If Group module throws an error, fall back to FALSE.
      return FALSE;
    }

    return FALSE;
  }

  /**
   * Gets all permissions (view, update, delete) for a node.
   *
   * @param object $node
   *   The node object.
   * @param \Drupal\Core\Session\AccountProxyInterface $user
   *   The user to check.
   *
   * @return array
   *   Array with 'view', 'update', 'delete' boolean values.
   */
  protected function getNodePermissions(object $node, $user): array {
    // Use Drupal's entity access API which is the authoritative source for
    // permission checks. This API automatically:
    // - Calls hook_node_access() implementations
    // - Checks node_access grants
    // - Respects Group module's entity-level restrictions when enabled
    // - Falls back to standard node permissions when Group module is disabled
    //
    // This approach ensures consistency between what the API reports and
    // what users can actually do, avoiding permission mismatches.
    return [
      'view' => $node->access('view', $user),
      'update' => $node->access('update', $user),
      'delete' => $node->access('delete', $user),
    ];
  }

  /**
   * Maps a node object to a service request definition.
   *
   * @param object $node
   *   The node object.
   * @param string $extendedRole
   *   The extended role for rendering additional fields.
   * @param array $parameters
   *   An array of query parameters.
   *
   * @return array
   *   An associative array representing the service request definition.
   */

  /**
   * Maps a node object to a service request definition.
   */
  public function mapNodeToServiceRequest(object $node, string $extendedRole, array $parameters): array {
    // Get translated node if translation exists for requested language.
    // Falls back to site default language if no langcode provided.
    $langcode = $parameters['langcode'] ?? $this->languageManager->getDefaultLanguage()->getId();
    if ($node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }

    // Get core field values efficiently.
    $categoryId = !$node->get('field_category')->isEmpty() ? $node->get('field_category')->target_id : NULL;
    $statusId = !$node->get('field_status')->isEmpty() ? $node->get('field_status')->target_id : NULL;

    // Use a static cache for this node's details to avoid recalculation on repeated calls.
    // Include langcode in cache key to properly cache translated content.
    static $serviceRequestCache = [];
    $cacheKey = $node->id() . '_' . $langcode . '_' . $extendedRole . '_' . md5(serialize($parameters));

    if (isset($serviceRequestCache[$cacheKey])) {
      return $serviceRequestCache[$cacheKey];
    }

    // Build base request data - only include fields that are actually used.
    $request = [
      'service_request_id' => $node->get('request_id')->value,
      'title' => $node->getTitle(),
      'requested_datetime' => $this->formatDateTime($node->get('created')->value),
      'updated_datetime' => $this->formatDateTime($node->get('changed')->value),
      'status' => $this->mapStatusToOpenClosedValue($statusId),
    ];

    // Add description if the field exists and isn't empty.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $request['description'] = $node->get('body')->value ?? '';
    }

    // Add geolocation data if available.
    if ($node->hasField('field_geolocation') && !$node->get('field_geolocation')->isEmpty()) {
      $request['lat'] = (float) $node->get('field_geolocation')->lat;
      $request['long'] = (float) $node->get('field_geolocation')->lng;
    }

    // Add address fields if available.
    if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
      $request['address_string'] = $this->formatAddress($node->get('field_address'));

      // Add standard spec fields.
      $request['address'] = $this->formatAddress($node->get('field_address'));

      // Add zipcode if available.
      $postalCode = $node->get('field_address')->postal_code;
      if (!empty($postalCode)) {
        $request['zipcode'] = $postalCode;
      }
    }

    // Add agency_responsible if available.
    if ($node->hasField('field_agency_responsible') && !$node->get('field_agency_responsible')->isEmpty()) {
      $request['agency_responsible'] = $node->get('field_agency_responsible')->value ?? '';
    }

    // Add service_notice if available.
    if ($node->hasField('field_service_notice') && !$node->get('field_service_notice')->isEmpty()) {
      $request['service_notice'] = $node->get('field_service_notice')->value ?? '';
    }

    // Add expected_datetime if available.
    if ($node->hasField('field_expected_datetime') && !$node->get('field_expected_datetime')->isEmpty()) {
      $request['expected_datetime'] = $this->formatDateTime($node->get('field_expected_datetime')->value);
    }

    // Add address_id if available.
    if ($node->hasField('field_address_id') && !$node->get('field_address_id')->isEmpty()) {
      $request['address_id'] = $node->get('field_address_id')->value ?? '';
    }

    // Add service details if available.
    if ($categoryId) {
      $request['service_name'] = $this->getTranslatedTaxonomyTermField($categoryId, 'name', $langcode);
      $request['service_code'] = $this->getTaxonomyTermField($categoryId, 'field_service_code');
    }

    // Add media_url if available (standard optional field per GeoReport v2 spec)
    $mediaUrls = $this->getMediaUrls($node);
    if (!empty($mediaUrls)) {
      $request['media_url'] = $mediaUrls;
    }

    // Add status_notes if available (standard optional field per GeoReport v2 spec)
    // Note: spec uses 'status_notes' not 'status_note'.
    $statusNote = $this->getStatusNote($node);
    if (!empty($statusNote)) {
      $request['status_notes'] = $statusNote;
    }

    // Add manager-only PII fields.
    if ($extendedRole === 'manager') {
      $email = $this->viewableFieldValue($node, 'field_e_mail');
      if ($email !== NULL) {
        $request['email'] = $email;
        $request['extended_attributes']['e-mail'] = $email;
      }

      $phone = $this->viewableFieldValue($node, 'field_phone');
      if ($phone !== NULL) {
        $request['phone'] = $phone;
      }

      $firstName = $this->viewableFieldValue($node, 'field_first_name');
      if ($firstName !== NULL) {
        $request['first_name'] = $firstName;
      }

      $lastName = $this->viewableFieldValue($node, 'field_last_name');
      if ($lastName !== NULL) {
        $request['last_name'] = $lastName;
      }

      if ($node->hasField('uid') && !$node->get('uid')->isEmpty() && $node->get('uid')->entity) {
        $request['extended_attributes']['author'] = $node->get('uid')->entity->label();
      }

      $owner = $node->getOwner();
      if ($owner !== NULL) {
        $request['extended_attributes']['markaspot']['created_by'] = [
          'display_name' => $owner->label(),
          'uid' => (int) $owner->id(),
        ];
      }

      // Expose the latest revision author ("last edited by") to staff. The
      // dashboard edits via JSON:API PATCH, which creates a new revision and
      // records the editing user as the revision user (the bundle defaults to
      // new_revision = true). Falls back gracefully when the revision user has
      // been deleted. These keys live under extended_attributes.markaspot to
      // match the frontend contract; the getExtendedAttributes() call below
      // merges (rather than replaces) so they survive when extensions=true.
      $revisionUser = $node->getRevisionUser();
      if ($revisionUser !== NULL) {
        $request['extended_attributes']['markaspot']['last_editor'] = $revisionUser->label();
      }
      // getRevisionCreationTime() is nullable (e.g. revisions created before
      // the revision metadata key was populated, or partially-built test
      // doubles). Only expose last_edited when a timestamp is actually present;
      // formatDateTime() requires a non-null int.
      $revisionCreated = $node->getRevisionCreationTime();
      if ($revisionCreated !== NULL) {
        $request['extended_attributes']['markaspot']['last_edited'] = $this->formatDateTime((int) $revisionCreated);
      }
    }

    // Organisation and jurisdiction: visible to managers always,
    // visible to all users when configured via response_visibility.
    $visibilityConfig = $this->configFactory->get('markaspot_open311.settings')->get('response_visibility') ?? [];

    $showOrganisation = $extendedRole === 'manager'
      || !empty($visibilityConfig['public_organisation']);
    $includeOrganisationJurisdiction = $extendedRole === 'manager'
      || !empty($visibilityConfig['public_jurisdiction']);
    if ($showOrganisation && $node->hasField('field_organisation') && !$node->get('field_organisation')->isEmpty()) {
      $organisations = [];
      foreach ($node->get('field_organisation')->referencedEntities() as $organisationEntity) {
        if ($organisationEntity instanceof GroupInterface) {
          $organisations[] = $this->buildOrganisationReference(
            $organisationEntity,
            $includeOrganisationJurisdiction,
          );
        }
      }
      if (!empty($organisations)) {
        // Backward compatibility: single-value key uses the first org.
        $request['organisation'] = $organisations[0];
        $request['organisations'] = $organisations;
      }
    }

    $showJurisdiction = $extendedRole === 'manager' || !empty($visibilityConfig['public_jurisdiction']);
    if ($showJurisdiction && $this->moduleHandler->moduleExists('group')) {
      $jurGroup = $this->resolveNodeJurisdiction($node);
      if ($jurGroup) {
        $request['jurisdiction'] = [
          'id' => (string) $jurGroup->id(),
          'label' => $jurGroup->label(),
        ];

        // Build jurisdiction chain when configured.
        $jurisdictionDisplay = $visibilityConfig['jurisdiction_display'] ?? 'leaf';
        if ($jurisdictionDisplay === 'chain') {
          $request['jurisdiction']['chain'] = $this->buildJurisdictionChain($jurGroup);
        }
      }
    }

    // Add district and sublocality taxonomy references.
    $showDistrict = $extendedRole === 'manager' || !empty($visibilityConfig['public_district']);
    if ($showDistrict) {
      if ($node->hasField('field_district') && !$node->get('field_district')->isEmpty()) {
        $districtTid = $node->get('field_district')->target_id;
        $request['district'] = $this->getTranslatedTaxonomyTermField($districtTid, 'name', $langcode);
        $request['district_id'] = (int) $districtTid;
      }
      if ($node->hasField('field_sublocality') && !$node->get('field_sublocality')->isEmpty()) {
        $sublocalityTid = $node->get('field_sublocality')->target_id;
        $request['sublocality'] = $this->getTranslatedTaxonomyTermField($sublocalityTid, 'name', $langcode);
        $request['sublocality_id'] = (int) $sublocalityTid;
      }
    }

    // Add extended attributes if extensions parameter is set.
    if ($extendedRole !== 'anonymous' && isset($parameters['extensions'])) {
      // Merge rather than overwrite so any manager-gated keys already set on
      // extended_attributes.markaspot (e.g. last_editor / last_edited) survive.
      $request['extended_attributes']['markaspot'] = ($request['extended_attributes']['markaspot'] ?? [])
        + $this->getExtendedAttributes($node, $langcode);

      // Add permissions - checks what operations the current user can perform.
      // We avoid using $node->access() as it triggers Group module's
      // buggy node access handler. Instead, we check manually.
      $permissions = $this->getNodePermissions($node, $this->currentUser);
      $request['extended_attributes']['markaspot']['permissions'] = $permissions;
      // Keep backward compatibility with 'editable' flag.
      $request['extended_attributes']['markaspot']['editable'] = $permissions['update'];

      // Add media details with published status.
      $mediaDetails = $this->getMediaDetails($node);
      if (!empty($mediaDetails)) {
        $request['extended_attributes']['media'] = $mediaDetails;
      }

      // Add drupal extended attributes when extensions=true
      // Priority: 1) full parameter, 2) specific fields from allowed list.
      // The ?full path exposes the complete entity including citizen PII, so
      // it is gated by the dedicated 'access open311 full export' permission
      // (editorial_board + administrator only) — not by the broad 'manager'
      // extended role, which moderators also reach via 'access open311
      // advanced properties'. A user without the permission falls through to
      // the ?fields= branch.
      if (isset($parameters['full']) && $this->currentUser->hasPermission('access open311 full export')) {
        $request['extended_attributes']['drupal'] = $this->getAllFieldValues($node);
      }
      elseif (isset($parameters['fields'])) {
        $allowedFields = $this->getAllowedFields($extendedRole);
        $requestedFields = explode(',', $parameters['fields']);
        $accessibleFields = array_intersect($requestedFields, $allowedFields);

        if (!empty($accessibleFields)) {
          $request['extended_attributes']['drupal'] = $this->getFieldValues($node, implode(',', $accessibleFields));
        }
      }
    }

    // Include service definition attributes if present.
    if ($node->hasField('field_request_attributes') && !$node->get('field_request_attributes')->isEmpty()) {
      $attributesJson = $node->get('field_request_attributes')->value;
      $attributesData = json_decode($attributesJson, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && !empty($attributesData)) {
        $publicAttributes = is_array($attributesData)
          ? $this->filterPublicRequestAttributes($attributesData, $node)
          : [];
        if ($publicAttributes !== []) {
          $request['extended_attributes']['attributes'] = $publicAttributes;
        }
      }
    }

    // Allow other modules to alter the request data.
    $this->moduleHandler->alter('markaspot_open311_request', $request, $node);

    // Store in cache for repeated use.
    $serviceRequestCache[$cacheKey] = $request;

    // Limit cache size to avoid memory issues.
    if (count($serviceRequestCache) > 50) {
      array_shift($serviceRequestCache);
    }

    return $request;
  }

  /**
   * Gets the list of allowed fields based on role.
   *
   * @param string $extendedRole
   *   The extended role of the user.
   *
   * @return array
   *   Array of allowed field names.
   */
  private function getAllowedFields(string $extendedRole): array {
    $config = $this->configFactory->get('markaspot_open311.settings');

    switch ($extendedRole) {
      case 'manager':
        return $config->get('field_access.manager_fields') ?: [];

      case 'user':
        return $config->get('field_access.user_fields') ?: [];

      case 'anonymous':
        return $config->get('field_access.public_fields') ?: [];

      default:
        return [];
    }
  }

  /**
   * Retrieves the complete values of public fields from a node.
   *
   * @param object $node
   *   The node object.
   * @param string $fieldNames
   *   A comma-separated list of field names.
   *
   * @return array
   *   An associative array of complete field values.
   */
  private function getPublicFieldValues(object $node, string $fieldNames): array {
    $fieldValues = [];
    $fieldNames = explode(',', $fieldNames);

    foreach ($fieldNames as $fieldName) {
      if ($node->hasField($fieldName)) {
        $field = $node->get($fieldName);
        $fieldAccess = $field->access('view', NULL, TRUE);

        if ($fieldAccess->isAllowed()) {
          // Return the complete field value array including all properties.
          $fieldValues[$fieldName] = $field->getValue();
        }
      }
    }

    return $fieldValues;
  }

  /**
   * Formats an address field value as a string.
   *
   * Builds address string from components, handling empty values gracefully.
   * Format: "{address_line1} {address_line2}, {postal_code} {locality}"
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $address
   *   The address field value.
   *
   * @return string
   *   The formatted address string.
   */
  public function formatAddress(FieldItemListInterface $address): string {
    $parts = [];

    // Street address (address_line1 + address_line2).
    $streetParts = array_filter([
      $address->address_line1,
      $address->address_line2,
    ]);
    if (!empty($streetParts)) {
      $parts[] = implode(' ', $streetParts);
    }

    // City with postal code.
    $cityParts = array_filter([
      $address->postal_code,
      $address->locality,
    ]);
    if (!empty($cityParts)) {
      $parts[] = implode(' ', $cityParts);
    }

    return implode(', ', $parts);
  }

  /**
   * Retrieves a field value from a taxonomy term.
   *
   * @param int $tid
   *   The taxonomy term ID.
   * @param string $fieldName
   *   The field name.
   *
   * @return mixed
   *   The field value, or null if the field or term is not found.
   */
  public function getTaxonomyTermField(?int $tid, string $fieldName): mixed {
    // Early return if $tid is null or not positive.
    if (is_null($tid) || $tid <= 0) {
      return NULL;
    }

    // Use static cache to avoid repeated loads of the same terms.
    static $termCache = [];

    // If term is not in cache, load it.
    if (!isset($termCache[$tid])) {
      $termCache[$tid] = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);

      // Limit cache size to avoid memory issues.
      if (count($termCache) > 100) {
        array_shift($termCache);
      }
    }

    $term = $termCache[$tid];

    // Check if the term exists and if the specified field exists on the term.
    if ($term !== NULL && $term->hasField($fieldName)) {
      // Special case for name field which doesn't have a value property.
      if ($fieldName === 'name') {
        return $term->getName();
      }

      // Safely return the field value, ensuring null is returned if the field is not set.
      return $term->get($fieldName)->value ?? NULL;
    }

    // Return null if the term doesn't exist, the field doesn't exist, or $tid is invalid.
    return NULL;
  }

  /**
   * Retrieves a translated field value from a taxonomy term.
   *
   * @param int|null $tid
   *   The taxonomy term ID.
   * @param string $fieldName
   *   The field name.
   * @param string $langcode
   *   The language code for translation.
   *
   * @return mixed
   *   The translated field value, or null if the field or term is not found.
   */
  public function getTranslatedTaxonomyTermField(?int $tid, string $fieldName, string $langcode): mixed {
    // Early return if $tid is null or not positive.
    if (is_null($tid) || $tid <= 0) {
      return NULL;
    }

    // Use static cache keyed by tid and langcode.
    static $translatedTermCache = [];
    $cacheKey = $tid . '_' . $langcode;

    // If translated term is not in cache, load it.
    if (!isset($translatedTermCache[$cacheKey])) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);

      if ($term && $term->hasTranslation($langcode)) {
        $translatedTermCache[$cacheKey] = $term->getTranslation($langcode);
      }
      else {
        // Fall back to original term if translation not available.
        $translatedTermCache[$cacheKey] = $term;
      }

      // Limit cache size to avoid memory issues.
      if (count($translatedTermCache) > 200) {
        array_shift($translatedTermCache);
      }
    }

    $term = $translatedTermCache[$cacheKey];

    // Check if the term exists and if the specified field exists on the term.
    if ($term !== NULL && $term->hasField($fieldName)) {
      // Special case for name field which doesn't have a value property.
      if ($fieldName === 'name') {
        return $term->getName();
      }

      // Safely return the field value, ensuring null is returned if the field is not set.
      return $term->get($fieldName)->value ?? NULL;
    }

    // Return null if the term doesn't exist, the field doesn't exist, or $tid is invalid.
    return NULL;
  }

  /**
   * Maps a taxonomy term ID to an "open" or "closed" status value.
   *
   * @param int|null $taxonomyId
   *   The taxonomy term ID, or null if no status is set.
   *
   * @return string
   *   The status value ("open" or "closed").
   */
  public function mapStatusToOpenClosedValue(?int $taxonomyId): string {
    if ($taxonomyId === NULL) {
      // Default to 'open' for requests without status.
      return 'open';
    }

    // Use field_open311_mapping on the term if available (jurisdiction-aware).
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($taxonomyId);
    if ($term && $term->hasField('field_open311_mapping') && !$term->get('field_open311_mapping')->isEmpty()) {
      $mapping = $term->get('field_open311_mapping')->value;
      // 'initial' and 'open' both count as Open311 "open".
      return ($mapping === 'closed') ? 'closed' : 'open';
    }

    // Fallback to config-based lookup for backward compatibility.
    $statusOpen = array_values($this->configFactory->get('markaspot_open311.settings')->get('status_open') ?? []);
    return in_array($taxonomyId, $statusOpen) ? 'open' : 'closed';
  }

  /**
   * Maps a status value ("open" or "closed") to an array of taxonomy term IDs.
   *
   * @param string $status
   *   The status value ("open" or "closed").
   * @param int|null $jurisdictionId
   *   Optional jurisdiction ID for scoped lookup.
   *
   * @return array
   *   An array of taxonomy term IDs.
   */
  public function mapStatusToTaxonomyIds(string $status, ?int $jurisdictionId = NULL): array {
    // Use field_open311_mapping for jurisdiction-aware status lookup.
    $properties = ['vid' => 'service_status', 'status' => 1];
    if ($jurisdictionId) {
      // Resolve to root jurisdiction for child jurisdictions (taxonomy inheritance).
      $effectiveId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($effectiveId === NULL) {
        return [];
      }
      $properties['field_jurisdiction'] = $effectiveId;
    }

    if ($status === 'open') {
      // Open311 "open" includes both 'initial' and 'open' mapping values.
      $tids = [];
      foreach (['initial', 'open'] as $mapping) {
        $props = $properties + ['field_open311_mapping' => $mapping];
        $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties($props);
        foreach ($terms as $term) {
          $tids[] = (int) $term->id();
        }
      }
      if (!empty($tids)) {
        return $tids;
      }
    }
    else {
      $props = $properties + ['field_open311_mapping' => 'closed'];
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties($props);
      $tids = array_map(fn($t) => (int) $t->id(), $terms);
      if (!empty($tids)) {
        return array_values($tids);
      }
    }

    // Fallback to config-based lookup for backward compatibility.
    $config = $this->configFactory->get('markaspot_open311.settings');
    return array_values($config->get($status === 'open' ? 'status_open' : 'status_closed') ?? []);
  }

  /**
   * Gets the initial status term ID for a jurisdiction.
   *
   * @param int|null $jurisdictionId
   *   The jurisdiction group ID, or NULL for config fallback.
   *
   * @return int|null
   *   The taxonomy term ID for the initial status, or NULL if not found.
   */
  public function getInitialStatusTid(?int $jurisdictionId = NULL): ?int {
    $properties = [
      'vid' => 'service_status',
      'status' => 1,
      'field_open311_mapping' => 'initial',
    ];
    if ($jurisdictionId) {
      // Resolve to root jurisdiction for child jurisdictions (taxonomy inheritance).
      $effectiveId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($effectiveId === NULL) {
        return NULL;
      }
      $properties['field_jurisdiction'] = $effectiveId;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties($properties);

    if (!empty($terms)) {
      $term = reset($terms);
      return (int) $term->id();
    }

    // Fallback to config.
    $startStatus = $this->configFactory->get('markaspot_open311.settings')->get('status_open_start');
    return $startStatus ? (int) $startStatus : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getInitialBoilerplateId(?int $jurisdictionId = NULL): ?int {
    if (!$jurisdictionId || !$this->hierarchyResolver) {
      return NULL;
    }
    if (!$this->moduleHandler->moduleExists('markaspot_boilerplate')) {
      return NULL;
    }
    $effectiveId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
    if ($effectiveId === NULL) {
      return NULL;
    }
    $group = $this->entityTypeManager->getStorage('group')->load($effectiveId);
    if (!$group || !$group->hasField('field_initial_boilerplate') || $group->get('field_initial_boilerplate')->isEmpty()) {
      return NULL;
    }
    return (int) $group->get('field_initial_boilerplate')->target_id;
  }

  /**
   * Validates that the authenticated user has access to the given jurisdiction.
   *
   * Checks that the user (resolved from API key or session) is a member of
   * the jurisdiction group. Skips the check for admin users, anonymous users
   * (who have their own permission checks), and when no jurisdiction is given.
   *
   * @param int|null $jurisdictionId
   *   The jurisdiction group ID to check, or NULL to skip validation.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user account to validate. Defaults to current user.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the user is not a member of the jurisdiction group.
   */
  public function validateJurisdictionAccess(?int $jurisdictionId, $account = NULL): void {
    // Skip if no jurisdiction specified (single-tenant mode).
    if (!$jurisdictionId) {
      return;
    }

    // Skip if Group module is not available.
    if (!$this->moduleHandler->moduleExists('group')) {
      return;
    }

    $account = $account ?? $this->currentUser;

    // Only super-admin (uid 1) bypasses jurisdiction checks.
    if ($account->id() == 1) {
      return;
    }

    // Only users with dashboard-level access are subject to tenant
    // isolation. The "access open311 advanced properties" permission is
    // the canonical capability gate used by determineExtendedRole() to
    // distinguish managers from basic users. Accounts without it —
    // anonymous, API service identities (api_user role), and regular
    // authenticated citizens — are governed by Group module's query-level
    // access grants (jur-outsider = published-only view). This is
    // role-agnostic: any future role that gains the permission
    // automatically inherits the isolation check.
    if (!$account->hasPermission('access open311 advanced properties')) {
      return;
    }

    // Load the jurisdiction group.
    $group = $this->entityTypeManager->getStorage('group')->load($jurisdictionId);
    if (!$group) {
      throw new AccessDeniedHttpException(
        'Invalid jurisdiction_id: group not found.'
      );
    }

    // Verify it's a jurisdiction group type.
    if (!$this->isJurisdictionGroup($group)) {
      throw new AccessDeniedHttpException(
        'Invalid jurisdiction_id: not a jurisdiction group.'
      );
    }

    // Check if user is a member of this jurisdiction group. Delegated to
    // the memoized boolean counterpart so both gates share exactly one
    // membership semantic (markaspot-ui#427).
    if (!$this->isJurisdictionMember($jurisdictionId, $account)) {
      throw new AccessDeniedHttpException(
        'Access denied: user is not a member of this jurisdiction.'
      );
    }
  }

  /**
   * Checks whether an account is a member of a jurisdiction group.
   *
   * Mirrors the membership semantics of validateJurisdictionAccess() but
   * returns a boolean instead of throwing, so read paths can degrade the
   * response shape to the public/anonymous serialization for non-members
   * instead of denying access outright (markaspot-ui#427).
   *
   * Semantics to be aware of:
   * - Any group membership counts, including pending or self-joined
   *   (opt-in) memberships if such a flow ever exists for jur groups.
   *   Jurisdiction memberships must therefore remain strictly
   *   admin-assigned; do not enable open/request joining on the jur
   *   group type.
   * - Direct membership only, no hierarchy walk: an admin of a PARENT
   *   jurisdiction is not a member of its children, so a child-scoped
   *   read serializes as public for them. This mirrors the previous hard
   *   403 gate, which used the same direct getMember() check.
   *
   * Results are memoized per request (gid:uid), so repeated checks while
   * serializing large lists cost one group load at most.
   *
   * @param int|null $jurisdictionId
   *   The jurisdiction group ID, or NULL/0 when no tenant scope applies.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user account. Defaults to current user.
   *
   * @return bool
   *   TRUE if the account is a direct member of the jurisdiction group,
   *   is uid 1, or no tenant scoping applies (no jurisdiction given,
   *   Group module missing). FALSE for non-members and for IDs that do
   *   not resolve to a jurisdiction group.
   */
  public function isJurisdictionMember(?int $jurisdictionId, $account = NULL): bool {
    // No jurisdiction scope (single-tenant mode): nothing to isolate.
    if (!$jurisdictionId) {
      return TRUE;
    }

    // Without the Group module there is no tenant concept.
    if (!$this->moduleHandler->moduleExists('group')) {
      return TRUE;
    }

    $account = $account ?? $this->currentUser;

    // Only super-admin (uid 1) bypasses jurisdiction scoping.
    if ($account->id() == 1) {
      return TRUE;
    }

    $cacheKey = $jurisdictionId . ':' . $account->id();
    if (isset($this->jurisdictionMembershipCache[$cacheKey])) {
      return $this->jurisdictionMembershipCache[$cacheKey];
    }

    $group = $this->entityTypeManager->getStorage('group')->load($jurisdictionId);
    if (!$group instanceof GroupInterface || !$this->isJurisdictionGroup($group)) {
      return $this->jurisdictionMembershipCache[$cacheKey] = FALSE;
    }

    return $this->jurisdictionMembershipCache[$cacheKey] = (bool) $group->getMember($account);
  }

  /**
   * Resolves the most specific jurisdiction group ID for a request node.
   *
   * Public, memoized counterpart of resolveNodeJurisdiction(): primary
   * lookup via the node's field_jurisdiction, secondary via direct
   * jur-type group_relationship rows (deepest child wins), fallback via
   * the organisation's field_jurisdiction. Richer than
   * getJurisdictionIdFromNode(), which only follows the category chain.
   *
   * @param object $node
   *   The service request node.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if none can be resolved.
   */
  public function resolveNodeJurisdictionId(object $node): ?int {
    $nodeId = (int) $node->id();
    if (array_key_exists($nodeId, $this->nodeJurisdictionIdCache)) {
      return $this->nodeJurisdictionIdCache[$nodeId];
    }

    $group = $this->resolveNodeJurisdiction($node);

    return $this->nodeJurisdictionIdCache[$nodeId] = $group ? (int) $group->id() : NULL;
  }

  /**
   * Checks whether any jurisdiction groups exist in this install.
   *
   * Multi-tenant guard for fail-closed serialization decisions: with zero
   * jur groups (legacy single-tenant installs) there is no cross-tenant
   * exposure, so an unresolvable node jurisdiction must not degrade staff
   * responses there. Memoized per request.
   *
   * @return bool
   *   TRUE when at least one jurisdiction group exists.
   */
  public function hasJurisdictionGroups(): bool {
    if ($this->jurisdictionGroupsExist !== NULL) {
      return $this->jurisdictionGroupsExist;
    }

    if (!$this->moduleHandler->moduleExists('group')) {
      return $this->jurisdictionGroupsExist = FALSE;
    }

    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->jurisdictionGroupType())
      ->range(0, 1)
      ->execute();

    return $this->jurisdictionGroupsExist = !empty($ids);
  }

  /**
   * Gets all category term IDs that belong to a jurisdiction.
   *
   * @param int $jurisdictionId
   *   The jurisdiction group ID.
   *
   * @return array
   *   Array of taxonomy term IDs, or empty array if none found.
   */
  public function getCategoryTidsForJurisdiction(int $jurisdictionId): array {
    // Resolve to root jurisdiction for child jurisdictions (taxonomy inheritance).
    $effectiveId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
    if ($effectiveId === NULL) {
      return [];
    }

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'service_category',
        'status' => 1,
        'field_jurisdiction' => $effectiveId,
      ]);

    return array_map(fn($term) => (int) $term->id(), $terms);
  }

  /**
   * Gets the jurisdiction ID from an existing service request node.
   *
   * Derives the jurisdiction from the node's category term's field_jurisdiction.
   *
   * @param object $node
   *   The service request node.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL if not found.
   */
  public function getJurisdictionIdFromNode(object $node): ?int {
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $categoryTerm = $node->get('field_category')->entity;
      if ($categoryTerm && $categoryTerm->hasField('field_jurisdiction') && !$categoryTerm->get('field_jurisdiction')->isEmpty()) {
        return (int) $categoryTerm->get('field_jurisdiction')->target_id;
      }
    }
    return NULL;
  }

  /**
   * Resolves the jurisdiction group for a service request node.
   *
   * Looks up the node's direct jur-type group_relationship to find the most
   * specific (child) jurisdiction. Falls back to the organisation entity's
   * field_jurisdiction if no direct jur relationship exists.
   *
   * Uses loadByProperties() which bypasses entity access checks. This is
   * intentional: the method is called for managers unconditionally, and for
   * all users when response_visibility.public_jurisdiction is enabled by an
   * administrator. Only group id and label are exposed, no sensitive data.
   *
   * @param object $node
   *   The service request node.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The jurisdiction group entity, or NULL if not found.
   */
  protected function resolveNodeJurisdiction(object $node): ?object {
    // Primary: use field_jurisdiction which holds the most-specific
    // jurisdiction (set by _markaspot_group_set_jurisdiction_field).
    if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
      $jurEntity = $node->get('field_jurisdiction')->entity;
      if ($jurEntity) {
        return $jurEntity;
      }
    }

    // Secondary: look for a direct jur-type group_relationship on the node.
    // When multiple jur relationships exist (nested boundaries), pick the
    // deepest child (the one whose ID is not referenced as parent by another).
    $relationship_storage = $this->entityTypeManager->getStorage('group_relationship');
    $relationships = $relationship_storage->loadByProperties([
      'entity_id' => $node->id(),
      'plugin_id' => 'group_node:service_request',
    ]);

    $jurGroups = [];
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if ($group && $this->isJurisdictionGroup($group)) {
        $jurGroups[(int) $group->id()] = $group;
      }
    }

    if (count($jurGroups) === 1) {
      return reset($jurGroups);
    }

    if (count($jurGroups) > 1) {
      // Find deepest: the one not referenced as parent by any other match.
      $parentIds = [];
      foreach ($jurGroups as $g) {
        if ($g->hasField('field_parent_jurisdiction') && !$g->get('field_parent_jurisdiction')->isEmpty()) {
          $parentIds[(int) $g->get('field_parent_jurisdiction')->target_id] = TRUE;
        }
      }
      foreach ($jurGroups as $id => $g) {
        if (!isset($parentIds[$id])) {
          return $g;
        }
      }
      return reset($jurGroups);
    }

    // Fallback: derive from organisation's field_jurisdiction.
    if ($node->hasField('field_organisation') && !$node->get('field_organisation')->isEmpty()) {
      $org = $node->get('field_organisation')->entity;
      if ($org && $org->hasField('field_jurisdiction') && !$org->get('field_jurisdiction')->isEmpty()) {
        return $org->get('field_jurisdiction')->entity;
      }
    }

    return NULL;
  }

  /**
   * Builds a stable organisation reference for API responses.
   *
   * @param \Drupal\group\Entity\GroupInterface $organisationEntity
   *   The organisation group.
   * @param bool $includeJurisdictionContext
   *   Whether to include jurisdiction context.
   *
   * @return array<string, mixed>
   *   Serialized organisation reference.
   */
  protected function buildOrganisationReference(
    GroupInterface $organisationEntity,
    bool $includeJurisdictionContext,
  ): array {
    $reference = [
      'id' => (string) $organisationEntity->id(),
      'uuid' => $organisationEntity->uuid(),
      'label' => $organisationEntity->label(),
      'name' => $organisationEntity->label(),
    ];

    if ($includeJurisdictionContext) {
      $jurisdictionId = $this->getOrganisationJurisdictionId($organisationEntity);
      $reference['jurisdiction_id'] = $jurisdictionId;
      $reference['orphan'] = $jurisdictionId === NULL;
    }

    return $reference;
  }

  /**
   * Gets a valid jurisdiction ID from an organisation group.
   *
   * @param \Drupal\group\Entity\GroupInterface $organisationEntity
   *   The organisation group.
   *
   * @return int|null
   *   The jurisdiction group ID, or NULL when missing or invalid.
   */
  protected function getOrganisationJurisdictionId(GroupInterface $organisationEntity): ?int {
    if (!$organisationEntity->hasField('field_jurisdiction')
      || $organisationEntity->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $jurisdiction = $organisationEntity->get('field_jurisdiction')->entity;
    if (!$jurisdiction instanceof GroupInterface
      || !$this->isJurisdictionGroup($jurisdiction)) {
      return NULL;
    }

    return (int) $jurisdiction->id();
  }

  /**
   * Builds the jurisdiction chain from leaf to root.
   *
   * Traverses field_parent_jurisdiction upward and returns an array
   * ordered from root to leaf (e.g., ["Stadt Köln", "Lindenthal"]).
   *
   * @param object $leafGroup
   *   The leaf jurisdiction group entity.
   *
   * @return array
   *   Array of jurisdiction objects with id and label, root first.
   */
  protected function buildJurisdictionChain(object $leafGroup): array {
    $chain = [];
    $group = $leafGroup;
    $visited = [];

    // Collect from leaf upward.
    while ($group) {
      $groupId = (int) $group->id();

      // Circular reference guard.
      if (in_array($groupId, $visited, TRUE)) {
        break;
      }
      $visited[] = $groupId;

      $chain[] = [
        'id' => (string) $group->id(),
        'label' => $group->label(),
      ];

      // Walk up to parent.
      if ($group->hasField('field_parent_jurisdiction')
          && !$group->get('field_parent_jurisdiction')->isEmpty()) {
        $parentId = (int) $group->get('field_parent_jurisdiction')->target_id;
        $group = $this->entityTypeManager->getStorage('group')->load($parentId);
        if (!$group || !$this->isJurisdictionGroup($group)) {
          break;
        }
      }
      else {
        break;
      }
    }

    // Reverse so root comes first.
    return array_reverse($chain);
  }

  /**
   * Checks whether a group is the configured jurisdiction bundle.
   */
  protected function isJurisdictionGroup(GroupInterface $group): bool {
    return $group->bundle() === $this->jurisdictionGroupType();
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

  /**
   * Creates a status note paragraph entity.
   *
   * Central factory for all status note paragraphs. All code paths that create
   * status paragraphs should use this method to ensure consistent langcode,
   * format, and field handling.
   *
   * @param array $fields
   *   Associative array with keys:
   *   - 'status_term_id' (int|string): Taxonomy term ID for field_status_term.
   *   - 'note' (string): The status note text. If empty and status_term_id is
   *     set, falls back to the status term's description.
   *   - 'format' (string): Text format. Defaults to 'plain_text'.
   *   - 'boilerplate_id' (int|string): Optional boilerplate node ID.
   *   - 'author_id' (int|string): Optional author user ID.
   *   - 'status_attributes' (string): Optional JSON object with internal
   *     status transition attributes. This is dashboard-only process data and
   *     is intentionally not exposed by getExtendedAttributes().
   * @param string $langcode
   *   The language code for the paragraph. Defaults to site default language.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph
   *   The created paragraph entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If there is an error saving the paragraph entity.
   */
  public function createStatusNoteParagraph(array $fields, string $langcode = ''): Paragraph {
    $langcode = $langcode ?: $this->languageManager->getDefaultLanguage()->getId();

    $paragraph = Paragraph::create([
      'type' => 'status',
      'langcode' => $langcode,
    ]);

    if (!empty($fields['status_term_id'])) {
      $paragraph->set('field_status_term', $fields['status_term_id']);
    }

    // Resolve note text: explicit note > boilerplate > status term description > empty.
    $noteText = $fields['note'] ?? '';
    if (empty($noteText) && !empty($fields['boilerplate_id'])) {
      $noteText = $this->getBoilerplateBody((int) $fields['boilerplate_id'], $langcode);
    }
    if (empty($noteText) && !empty($fields['status_term_id'])) {
      $noteText = $this->getStatusTermDescription((int) $fields['status_term_id'], $langcode);
    }

    if (!empty($noteText)) {
      $paragraph->set('field_status_note', [
        'value' => $noteText,
        'format' => 'plain_text',
      ]);
    }

    if (!empty($fields['boilerplate_id']) && $paragraph->hasField('field_boilerplate')) {
      $paragraph->set('field_boilerplate', $fields['boilerplate_id']);
    }

    if (array_key_exists('status_attributes', $fields) && $paragraph->hasField('field_status_attributes')) {
      $paragraph->set('field_status_attributes', [
        'value' => (string) $fields['status_attributes'],
        'format' => 'plain_text',
      ]);
    }

    if (!empty($fields['author_id'])) {
      if ($paragraph->hasField('field_author')) {
        $paragraph->set('field_author', $fields['author_id']);
      }
      else {
        // Audit-trail concern (BSI APP.3.1, GDPR Art. 5(1)(f) integrity):
        // surface schema drift in watchdog so a tenant missing field_author
        // on the `status` paragraph bundle is visible to operators rather
        // than silently dropping author attribution on status notes.
        $this->logger?->warning(
          'Paragraph bundle @bundle is missing field_author; author uid @uid not recorded for status note. Run markaspot_status_paragraph update to restore the field.',
          ['@bundle' => $paragraph->bundle(), '@uid' => $fields['author_id']]
        );
      }
    }

    $paragraph->save();
    return $paragraph;
  }

  /**
   * Gets the translated description of a status taxonomy term.
   *
   * @param int $termId
   *   The taxonomy term ID.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The term description, or empty string if not available.
   */
  private function getStatusTermDescription(int $termId, string $langcode): string {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($termId);
    if (!$term) {
      return '';
    }
    // Normalize 'und' (undetermined) to site default language.
    if ($langcode === 'und') {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    if ($term->hasTranslation($langcode)) {
      $term = $term->getTranslation($langcode);
    }
    $description = $term->getDescription();
    return $description ? strip_tags($description) : '';
  }

  /**
   * Gets the translated body text of a boilerplate node.
   *
   * @param int $boilerplateId
   *   The boilerplate node ID.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The boilerplate body text (plain text), or empty string if not available.
   */
  private function getBoilerplateBody(int $boilerplateId, string $langcode): string {
    $node = $this->entityTypeManager->getStorage('node')->load($boilerplateId);
    if (!$node || $node->bundle() !== 'boilerplate' || !$node->isPublished()) {
      return '';
    }
    // If langcode is 'und' (undetermined), use site default language.
    if ($langcode === 'und') {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    if ($node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }
    $body = $node->body->value ?? '';
    return $body ? strip_tags($body) : '';
  }

  /**
   * Retrieves the media URLs associated with a node.
   *
   * @param object $node
   *   The node object.
   *
   * @return string
   *   A comma-separated list of media URLs.
   */
  private function getMediaUrls(object $node): string {
    $mediaUrls = [];

    if ($node->hasField('field_request_image') && !$node->get('field_request_image')->isEmpty()) {
      $mediaUrls[] = $this->fileUrlGenerator->generateAbsoluteString($node->get('field_request_image')->entity->getFileUri());
    }

    if ($node->hasField('field_request_media') && !$node->get('field_request_media')->isEmpty()) {
      foreach ($node->get('field_request_media')->referencedEntities() as $media) {
        if ($media->isPublished() && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
          $mediaUrls[] = $this->fileUrlGenerator->generateAbsoluteString($media->get('field_media_image')->entity->getFileUri());
        }
      }
    }

    return implode(',', $mediaUrls);
  }

  /**
   * Retrieves detailed media information including published status.
   *
   * @param object $node
   *   The node object.
   *
   * @return array
   *   An array of media details with mid, url, and published status.
   */
  private function getMediaDetails(object $node): array {
    $mediaDetails = [];

    // Include legacy field_request_image if present.
    if ($node->hasField('field_request_image') && !$node->get('field_request_image')->isEmpty()) {
      $file = $node->get('field_request_image')->entity;
      if ($file) {
        $mediaDetails[] = [
          'mid' => 'legacy',
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'published' => TRUE,
        ];
      }
    }

    // Process field_request_media.
    if ($node->hasField('field_request_media') && !$node->get('field_request_media')->isEmpty()) {
      foreach ($node->get('field_request_media')->referencedEntities() as $media) {
        // Check if current user has permission to view this media entity.
        if (!$media || !$media->access('view')) {
          continue;
        }

        if ($media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
          $file = $media->get('field_media_image')->entity;
          if ($file) {
            $mediaDetails[] = [
              'mid' => (int) $media->id(),
              'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
              'published' => (bool) $media->isPublished(),
            ];
          }
        }
      }
    }

    return $mediaDetails;
  }

  /**
   * Retrieves the alt texts for media associated with a node.
   *
   * @param object $node
   *   The node object.
   *
   * @return array
   *   An array of alt text strings corresponding to media URLs.
   */
  private function getMediaAltTexts(object $node): array {
    $mediaAltTexts = [];

    if ($node->hasField('field_request_image') && !$node->get('field_request_image')->isEmpty()) {
      // For legacy field_request_image, use fallback text.
      $mediaAltTexts[] = $this->t('Situation documented in image according to description')->render();
    }

    if ($node->hasField('field_request_media') && !$node->get('field_request_media')->isEmpty()) {
      foreach ($node->get('field_request_media')->referencedEntities() as $media) {
        if ($media->isPublished() && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
          $fieldMediaImage = $media->get('field_media_image');

          // Get alt text from media entity or use fallback.
          if (!$fieldMediaImage->isEmpty() && !empty($fieldMediaImage->alt)) {
            $mediaAltTexts[] = $fieldMediaImage->alt;
          }
          else {
            // Use translatable fallback for missing alt text.
            $mediaAltTexts[] = $this->t('Situation documented in image according to description')->render();
          }
        }
      }
    }

    return $mediaAltTexts;
  }

  /**
   * Retrieves the latest status note from a node.
   *
   * @param object $node
   *   The node object.
   *
   * @return string
   *   The latest status note text.
   */
  private function getStatusNote(object $node): string {
    if ($node->hasField('field_status_notes') && !$node->get('field_status_notes')->isEmpty()) {
      $statusNotes = $node->get('field_status_notes')->referencedEntities();
      if (!empty($statusNotes)) {
        $latestNote = end($statusNotes);
        if ($latestNote instanceof Paragraph && $latestNote->hasField('field_status_note')) {
          return $latestNote->get('field_status_note')->value ?? '';
        }
      }
    }
    return '';
  }

  /**
   * Retrieves extended attributes for a node.
   *
   * @param object $node
   *   The node object.
   * @param string $langcode
   *   The language code for translations.
   *
   * @return array
   *   An associative array of extended attributes.
   */
  private function getExtendedAttributes(object $node, string $langcode): array {
    static $extendedAttributesCache = [];
    $cacheKey = $node->id() . '_' . $langcode;

    // Return from cache if available.
    if (isset($extendedAttributesCache[$cacheKey])) {
      return $extendedAttributesCache[$cacheKey];
    }

    $extendedAttributes = [
      'nid' => $node->id(),
    ];

    if ($node->hasField('field_source') && !$node->get('field_source')->isEmpty()) {
      $source = (string) $node->get('field_source')->value;
      $extendedAttributes['source'] = $source;
      if ($source === 'staff') {
        $extendedAttributes['channel'] = 'staff';
      }
    }

    // Preload taxonomy terms we'll need to avoid individual loads.
    $termIds = [];
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $termIds[] = $node->get('field_category')->target_id;
    }
    if ($node->hasField('field_status') && !$node->get('field_status')->isEmpty()) {
      $termIds[] = $node->get('field_status')->target_id;
    }

    // Also collect status note term IDs.
    $statusNoteTermIds = [];
    if ($node->hasField('field_status_notes') && !$node->get('field_status_notes')->isEmpty()) {
      foreach ($node->get('field_status_notes') as $note) {
        if ($note->entity && $note->entity->hasField('field_status_term') && !$note->entity->get('field_status_term')->isEmpty()) {
          $statusNoteTermIds[] = $note->entity->get('field_status_term')->target_id;
        }
      }
    }

    // Combine all term IDs and load them at once.
    $allTermIds = array_merge($termIds, $statusNoteTermIds);
    if (!empty($allTermIds)) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_unique($allTermIds));
    }
    else {
      $terms = [];
    }

    // Process category information.
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $categoryId = $node->get('field_category')->target_id;
      if (isset($terms[$categoryId])) {
        $term = $terms[$categoryId];
        if ($term->hasTranslation($langcode)) {
          $term = $term->getTranslation($langcode);
        }
        $extendedAttributes['category_hex'] = ($term->hasField('field_category_hex') && !$term->get('field_category_hex')->isEmpty())
          ? $term->get('field_category_hex')->color
          : '';
        $extendedAttributes['category_icon'] = ($term->hasField('field_category_icon') && !$term->get('field_category_icon')->isEmpty())
          ? $term->get('field_category_icon')->value ?? ''
          : '';
      }
      else {
        $extendedAttributes['category_hex'] = '';
        $extendedAttributes['category_icon'] = '';
      }
    }

    // Process status information.
    if ($node->hasField('field_status') && !$node->get('field_status')->isEmpty()) {
      $statusId = $node->get('field_status')->target_id;
      if (isset($terms[$statusId])) {
        $term = $terms[$statusId];
        if ($term->hasTranslation($langcode)) {
          $term = $term->getTranslation($langcode);
        }
        $extendedAttributes['status_descriptive_name'] = $term->getName() ?? '';
        $extendedAttributes['status_hex'] = ($term->hasField('field_status_hex') && !$term->get('field_status_hex')->isEmpty())
          ? $term->get('field_status_hex')->color
          : '';
      }
      else {
        $extendedAttributes['status_descriptive_name'] = '';
        $extendedAttributes['status_hex'] = '';
      }
    }

    // Process status notes with preloaded terms.
    if ($node->hasField('field_status_notes') && !$node->get('field_status_notes')->isEmpty()) {
      $statusNotes = [];
      $logCount = -1;

      // Get default initial status term ID (jurisdiction-aware).
      // Category terms are always stored on the root jurisdiction (ensured by
      // presave), so field_jurisdiction here is already the root ID.
      // getInitialStatusTid() also resolves to root as a safety net.
      $jurisdictionId = NULL;
      if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
        $categoryTerm = $node->get('field_category')->entity;
        if ($categoryTerm && $categoryTerm->hasField('field_jurisdiction') && !$categoryTerm->get('field_jurisdiction')->isEmpty()) {
          $jurisdictionId = (int) $categoryTerm->get('field_jurisdiction')->target_id;
        }
      }
      $initialStatusId = $this->getInitialStatusTid($jurisdictionId);

      foreach ($node->get('field_status_notes') as $note) {
        $logCount++;
        $noteEntity = $note->entity;
        if (!$noteEntity) {
          continue;
        }

        $statusTermId = ($noteEntity->hasField('field_status_term') && !$noteEntity->get('field_status_term')->isEmpty())
          ? $noteEntity->get('field_status_term')->getValue()[0]['target_id']
          : $initialStatusId;

        // Get the term from our preloaded collection.
        $statusTerm = $terms[$statusTermId] ?? NULL;
        if ($statusTerm && $statusTerm->hasTranslation($langcode)) {
          $statusTerm = $statusTerm->getTranslation($langcode);
        }

        $statusNotes[$logCount] = [
          'status_note' => $noteEntity->get('field_status_note')->value ?? '',
          'status' => $this->mapStatusToOpenClosedValue($statusTermId),
          'updated_datetime' => $this->formatDateTime($noteEntity->get('created')->value),
          'status_descriptive_name' => $statusTerm ? $statusTerm->getName() : '',
          'status_hex' => ($statusTerm && $statusTerm->hasField('field_status_hex') && !$statusTerm->get('field_status_hex')->isEmpty())
            ? $statusTerm->get('field_status_hex')->color
            : '',
          'status_icon' => ($statusTerm && $statusTerm->hasField('field_status_icon') && !$statusTerm->get('field_status_icon')->isEmpty())
            ? $statusTerm->get('field_status_icon')->value ?? ''
            : '',
        ];
      }

      $extendedAttributes['status_notes'] = $statusNotes;
    }

    // Add media alt text information for accessibility.
    $mediaAltTexts = $this->getMediaAltTexts($node);
    if (!empty($mediaAltTexts)) {
      $extendedAttributes['media_alt_text'] = $mediaAltTexts;
    }

    // Add published status for nodes
    // This flag allows frontend to show an unpublished indicator icon.
    $extendedAttributes['published'] = $node->isPublished();

    // Facility id for facility-mode reports (string, set by FacilityManager
    // on submit). Consumed by the dashboard list column and edit form.
    if ($node->hasField('field_facility') && !$node->get('field_facility')->isEmpty()) {
      $extendedAttributes['field_facility'] = (string) $node->get('field_facility')->value;
    }

    // Cache the result for future use.
    $extendedAttributesCache[$cacheKey] = $extendedAttributes;

    // Limit cache size to avoid memory issues.
    if (count($extendedAttributesCache) > 50) {
      array_shift($extendedAttributesCache);
    }

    return $extendedAttributes;
  }

  /**
   * Retrieves the values of specified fields from a node.
   *
   * @param object $node
   *   The node object.
   * @param string $fieldNames
   *   A comma-separated list of field names.
   *
   * @return array
   *   An associative array of field values.
   */
  private function getFieldValues(object $node, string $fieldNames): array {
    $fieldValues = [];
    $fieldNames = explode(',', $fieldNames);

    foreach ($fieldNames as $fieldName) {
      if ($node->hasField($fieldName)) {
        $field = $node->get($fieldName);
        $fieldAccess = $field->access('view', NULL, TRUE);

        if ($fieldAccess->isAllowed()) {
          if (method_exists($field, 'referencedEntities')) {
            $entities = $field->referencedEntities();
            // Skip empty entity references entirely.
            if (empty($entities)) {
              continue;
            }
            // Never serialise referenced entities with toArray() here:
            // manager field allowlists may include operational references
            // (organisation, internal status) whose target entities carry
            // private config fields. Keep the GeoReport API contract compact
            // and field-permission friendly.
            $value = $this->serializeReferencedEntitiesCompact($entities);
            if ($value === []) {
              continue;
            }
            // Normalize single-value arrays.
            if (count($value) === 1) {
              $value = reset($value);
            }
          }
          else {
            $value = $field->getValue();
            // Convert integer field values from string to int.
            $fieldDefinition = $field->getFieldDefinition();
            $fieldType = $fieldDefinition->getType();
            if ($fieldType === 'integer' || $fieldType === 'list_integer') {
              foreach ($value as &$item) {
                if (isset($item['value']) && is_numeric($item['value'])) {
                  $item['value'] = (int) $item['value'];
                }
              }
            }
          }
          $fieldValues[$fieldName] = $value;
        }
      }
      // Media-entity fields: field lives on referenced media, not on node.
      elseif ($fieldName === 'field_ai_hazard_category') {
        if ($node->hasField('field_request_media') && !$node->get('field_request_media')->isEmpty()) {
          foreach ($node->get('field_request_media')->referencedEntities() as $media) {
            if ($media->hasField('field_ai_hazard_category') && !$media->get('field_ai_hazard_category')->isEmpty()) {
              $fieldValues[$fieldName] = $media->get('field_ai_hazard_category')->getValue();
              break;
            }
          }
        }
      }
    }

    return $fieldValues;
  }

  /**
   * Retrieves the values of all fields from a node.
   *
   * Produces a non-lossy snapshot of every non-empty field the current user
   * may view. Entity-reference fields are resolved to a compact
   * {target_id, label} shape so the payload stays small (notably
   * field_jurisdiction, whose group entity would otherwise drag the full
   * field_nuxt_config and field_boundary GeoJSON into every row). Scalar and
   * text fields keep their full item array, with the same string->int
   * conversion for integer / list_integer fields that getFieldValues() applies.
   *
   * @param object $node
   *   The node object.
   *
   * @return array
   *   An associative array of field values keyed by field name.
   */
  private function getAllFieldValues(object $node): array {
    $fieldValues = [];

    foreach ($node->getFields() as $fieldName => $field) {
      $fieldAccess = $field->access('view', NULL, TRUE);

      if (!$fieldAccess->isAllowed()) {
        continue;
      }
      // Skip empty fields: clean API responses omit absent data.
      if ($field->isEmpty()) {
        continue;
      }

      if (method_exists($field, 'referencedEntities')) {
        $entities = $field->referencedEntities();
        // Skip entity references that resolve to nothing.
        if (empty($entities)) {
          continue;
        }
        // Compact shape only: never $entity->toArray() here, it would bloat
        // the payload (e.g. field_jurisdiction -> full group config).
        // Referenced entity access is checked inside the helper so direct
        // GeoReport serialisation honours taxonomy/group access hooks too.
        $value = $this->serializeReferencedEntitiesCompact($entities);
        if ($value === []) {
          continue;
        }
        // Normalize single-value references to a single assoc array.
        if (count($value) === 1) {
          $value = reset($value);
        }
      }
      else {
        $value = $field->getValue();
        // Convert integer field values from string to int.
        $fieldType = $field->getFieldDefinition()->getType();
        if ($fieldType === 'integer' || $fieldType === 'list_integer') {
          foreach ($value as &$item) {
            if (isset($item['value']) && is_numeric($item['value'])) {
              $item['value'] = (int) $item['value'];
            }
          }
        }
      }

      $fieldValues[$fieldName] = $value;
    }

    return $fieldValues;
  }

  /**
   * Serialises referenced entities to the only shape GeoReport exports need.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   Referenced entities.
   *
   * @return array<int, array{target_id: mixed, label: string}>
   *   Compact references safe for API export.
   */
  private function serializeReferencedEntitiesCompact(array $entities): array {
    $references = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof EntityInterface || !$entity->access('view', $this->currentUser)) {
        continue;
      }

      $references[] = [
        'target_id' => $entity->id(),
        'label' => $entity->label(),
      ];
    }

    return $references;
  }

  /**
   * Returns a scalar field value only when field access allows viewing it.
   */
  private function viewableFieldValue(ContentEntityInterface $entity, string $fieldName): ?string {
    if (!$entity->hasField($fieldName)) {
      return NULL;
    }

    $field = $entity->get($fieldName);
    if ($field->isEmpty() || !$field->access('view', $this->currentUser)) {
      return NULL;
    }

    $value = $field->value ?? NULL;
    return $value === NULL ? NULL : (string) $value;
  }

  /**
   * Formats a timestamp as a date/time string.
   *
   * @param int $timestamp
   *   The timestamp to be formatted.
   *
   * @return string
   *   The formatted date/time string.
   */
  private function formatDateTime(int $timestamp): string {
    return date('c', $timestamp);
  }

  /**
   * Retrieves a safe value from an array, handling HTML escaping and stripping slashes.
   *
   * @param array $data
   *   The array to retrieve the value from.
   * @param string $key
   *   The key of the value to retrieve.
   *
   * @return string|null
   *   The safe value, or null if the key is not present in the array.
   */
  private function getSafeValue(array $data, string $key): ?string {
    return isset($data[$key]) ? Html::escape(stripslashes($data[$key])) : NULL;
  }

  /**
   * Resolves the public facility id submitted through the create endpoint.
   */
  private function resolveSubmittedFacilityId(array $requestData): ?string {
    $raw = $requestData['field_facility'] ?? $requestData['facility_id'] ?? NULL;
    if (!is_scalar($raw)) {
      return NULL;
    }

    $facilityId = trim(Html::escape(stripslashes((string) $raw)));
    return $facilityId !== '' ? $facilityId : NULL;
  }

  /**
   * Handles media URLs in the request data.
   *
   * @param array $requestData
   *   The request data array.
   *
   * @return array
   *   An array of media URLs.
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   If an image cannot be retrieved via URL.
   */
  private function handleMediaUrls(array $requestData): array {
    $mediaUrls = [];

    if (isset($requestData['media_url'])) {
      $urls = explode(',', $requestData['media_url']);
      // Guard against an incompletely-installed media stack: on a fresh
      // install where markaspot_media's config/install did not land, the
      // media `field_media_image` storage definition is absent and calling
      // ->getSetting() on the missing entry raises a fatal \Error that the
      // POST handler can only surface as a redacted 502. Degrade gracefully
      // by skipping image handling instead — the report itself still saves.
      $mediaStorageDefinitions = $this->entityFieldManager->getFieldStorageDefinitions('media');
      if (!isset($mediaStorageDefinitions['field_media_image'])) {
        $this->logger?->warning(
          'media_url received but the media field_media_image storage is missing; skipping image import. Run drush updb / re-import markaspot_media config to restore media support.'
        );
        return $mediaUrls;
      }
      $storageSetting = $mediaStorageDefinitions['field_media_image']->getSetting('uri_scheme');
      $wrapperScheme = $this->getWrapperScheme($storageSetting);
      $fieldConfig = $this->configFactory->get('field.field.media.request_image.field_media_image');
      $fieldSettings = $fieldConfig->get('settings');
      $fileDirectory = $this->token->replace($fieldSettings['file_directory'] ?? '');
      $fileDirectory = trim($fileDirectory, '/');
      // Create the directory if it doesn't exist.
      $directoryPath = $wrapperScheme . ($fileDirectory ? $fileDirectory . '/' : '');
      $this->fileSystem->prepareDirectory($directoryPath, FileSystemInterface::CREATE_DIRECTORY);

      foreach ($urls as $url) {
        $destination = $directoryPath . basename($url);

        if (strstr($url, 'http')) {
          try {
            $data = (string) $this->httpClient->get(trim($url))->getBody();
            $filePath = $this->fileSystem->saveData($data, $destination, FileExists::Rename);

            if ($filePath) {
              $file = File::create(['uri' => $filePath]);
              $file->save();

              if ($this->moduleHandler->moduleExists('markaspot_media')) {
                $media = $this->createMediaEntity('request_image', $file);
                $mediaUrls[] = [
                  'target_id' => $media->id(),
                  'alt' => 'Open311 File',
                ];
              }
              else {
                $mediaUrls[] = [
                  'target_id' => $file->id(),
                  'alt' => 'Open311 File',
                  'uri' => $file->getFileUri(),
                ];
              }
            }
            else {
              throw new \Exception('Failed to save file', 400);
            }
          }
          catch (TransferException $exception) {
            $this->messenger->addError($this->t('Failed to fetch file due to error "%error"', ['%error' => $exception->getMessage()]));
          }
          catch (FileException | InvalidStreamWrapperException $e) {
            $this->messenger->addError($this->t('Failed to save file due to error "%error"', ['%error' => $e->getMessage()]));
            throw new \Exception('Image could not be retrieved via URL', 400);
          }
        }
      }
    }

    return $mediaUrls;
  }

  /**
   * Creates a media entity with the provided file.
   *
   * @param string $bundle
   *   The media bundle.
   * @param \Drupal\file\Entity\File $file
   *   The file object.
   *
   * @return \Drupal\media\MediaInterface
   *   The created media entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If there is an error saving the media entity.
   */
  private function createMediaEntity(string $bundle, File $file): MediaInterface {
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $bundle,
      'uid' => $this->currentUser->id(),
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => 'Open311 File',
        'uri' => $file->getFileUri(),
      ],
    ]);

    $media->setName('media:' . $bundle . ':' . $media->uuid())
      ->setPublished(TRUE)
      ->save();

    return $media;
  }

  /**
   * Determines the appropriate wrapper scheme based on the storage setting.
   *
   * @param string $storageSetting
   *   The storage setting (e.g., 'private', 's3fs', 'public').
   *
   * @return string
   *   The wrapper scheme.
   */
  private function getWrapperScheme(string $storageSetting): string {
    switch ($storageSetting) {
      case 'private':
        return 'private://';

      case 's3fs':
        return 's3fs://';

      default:
        return 'public://';
    }
  }

  /**
   * Handles extended attributes in the request data.
   *
   * @param array $extendedAttributes
   *   The extended attributes array.
   *
   * @return array
   *   An array of field values from the extended attributes.
   */
  private function handleExtendedAttributes(array $extendedAttributes): array {
    $fieldValues = [];

    foreach ($extendedAttributes as $fieldName => $value) {
      if (!empty($fieldName)) {
        $fieldValues[$fieldName] = $value;
      }
    }

    return $fieldValues;
  }

  /**
   * Updates the published status of media entities.
   *
   * @param array $mediaUpdates
   *   Array of media items with mid and published status.
   *   Can also use 'delta' instead of 'mid' to reference media by position.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $node
   *   Optional node entity to look up media by delta position.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If there is an error saving the media entity.
   */
  public function updateMediaPublishedStatus(array $mediaUpdates, $node = NULL): void {
    foreach ($mediaUpdates as $delta => $mediaUpdate) {
      // Validate array structure.
      if (!is_array($mediaUpdate)) {
        continue;
      }

      // Skip if published status is not provided.
      if (!isset($mediaUpdate['published'])) {
        continue;
      }

      $published = (bool) $mediaUpdate['published'];
      $mid = NULL;

      // Determine media ID - either explicit mid, or lookup by delta.
      if (isset($mediaUpdate['mid']) && is_numeric($mediaUpdate['mid'])) {
        $mid = (int) $mediaUpdate['mid'];
      }
      elseif (isset($mediaUpdate['delta']) && is_numeric($mediaUpdate['delta']) && $node) {
        // Look up media by delta position.
        $delta = (int) $mediaUpdate['delta'];
        if ($node->hasField('field_request_media') && !$node->get('field_request_media')->isEmpty()) {
          $mediaItems = $node->get('field_request_media')->getValue();
          if (isset($mediaItems[$delta]['target_id'])) {
            $mid = (int) $mediaItems[$delta]['target_id'];
          }
        }
      }
      elseif (is_numeric($delta) && $node && !isset($mediaUpdate['mid'])) {
        // If no mid specified, use the array key as delta.
        if ($node->hasField('field_request_media') && !$node->get('field_request_media')->isEmpty()) {
          $mediaItems = $node->get('field_request_media')->getValue();
          if (isset($mediaItems[$delta]['target_id'])) {
            $mid = (int) $mediaItems[$delta]['target_id'];
          }
        }
      }

      // Skip if we couldn't determine a valid media ID.
      if (!$mid || $mid === 'legacy') {
        continue;
      }

      // Load and update the media entity.
      $media = $this->entityTypeManager->getStorage('media')->load($mid);
      if (!$media) {
        continue;
      }

      // Check if current user has permission to update this media entity.
      if (!$media->access('update')) {
        continue;
      }

      $currentStatus = $media->isPublished();
      // Only update if status is changing.
      if ($currentStatus !== $published) {
        if ($published) {
          $media->setPublished();
        }
        else {
          $media->setUnpublished();
        }
        $media->save();
      }
    }
  }

  /**
   * Parses an address string into structured components.
   *
   * This function takes a free-form address string and attempts to parse it into
   * structured address components according to the GeoReport v2 standard. It
   * processes the address from most specific (e.g., street address) to most
   * general (e.g., country) geographic units.
   *
   * @param string $addressString
   *   The input address string to be parsed.
   *
   * @return array
   *   An associative array of parsed address components:
   *   - address_line1: Street address or most specific part of the address.
   *   - address_line2: Additional address information if available.
   *   - neighborhood: Neighborhood or district information.
   *   - locality: City, town, or village.
   *   - county: County or region.
   *   - postal_code: Postal or ZIP code.
   *   - state: State, province, or administrative area.
   *   - country: Country name.
   */
  private function addressParser(string $addressString): array {
    $addressString = html_entity_decode($addressString, ENT_QUOTES, 'UTF-8');
    $addressString = trim($addressString);

    // Initialize the result array.
    $result = [
      'address_line1' => '',
      'address_line2' => '',
      'neighborhood' => '',
      'locality' => '',
      'county' => '',
      'postal_code' => '',
      'state' => '',
      'country' => '',
    ];

    // Split the address string by commas.
    $parts = preg_split('/,\s*/', $addressString);

    // Extract postal code (supports formats: 50667, 1067 PV, 1067PV, SW1A 1AA).
    $extractPostalCode = function ($str) {
      // Dutch: 1234 AB or 1234AB.
      if (preg_match('/\b(\d{4}\s?[A-Z]{2})\b/i', $str, $matches)) {
        return $matches[1];
      }
      // UK: SW1A 1AA, EC1A 1BB.
      if (preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2})\b/i', $str, $matches)) {
        return $matches[1];
      }
      // Generic numeric: 4-7 digits, optionally followed by letters.
      if (preg_match('/\b(\d{4,7})\b/', $str, $matches)) {
        return $matches[1];
      }
      return NULL;
    };

    $numParts = count($parts);
    for ($i = 0; $i < $numParts; $i++) {
      $part = trim($parts[$i]);

      // Add a Check for postal code.
      $postalCode = $extractPostalCode($part);
      if ($postalCode) {
        $result['postal_code'] = $postalCode;
        $part = trim(str_replace($postalCode, '', $part));
        if (empty($part)) {
          continue;
        }
      }

      // Assign parts based on position and content.
      if ($i == 0) {
        // First part is likely the most specific (address number or cross streets).
        $result['address_line1'] = $part;
      }
      elseif ($i == 1) {
        // Second part: if a postal code was extracted from this part, the
        // remaining text is the city/locality, not address_line2.
        if ($postalCode && !empty($part)) {
          $result['locality'] = $part;
        }
        elseif (empty($result['address_line2'])) {
          $result['address_line2'] = $part;
        }
        else {
          $result['address_line1'] .= ', ' . $part;
        }
      }
      elseif ($i == $numParts - 1) {
        // Last part is likely country.
        $result['country'] = $part;
      }
      elseif ($i == $numParts - 2) {
        // Second to last might be state/province.
        $result['state'] = $part;
      }
      else {
        // Middle parts could be neighborhood, locality, or county.
        if (empty($result['neighborhood'])) {
          $result['neighborhood'] = $part;
        }
        elseif (empty($result['locality'])) {
          $result['locality'] = $part;
        }
        elseif (empty($result['county'])) {
          $result['county'] = $part;
        }
        else {
          // If all else is filled, append to address_line2.
          $result['address_line2'] .= ', ' . $part;
        }
      }
    }

    // Clean up results.
    foreach ($result as $key => $value) {
      $result[$key] = trim($value);
    }

    return $result;
  }

  /**
   * Resolves the country code for an address from the jurisdiction config.
   *
   * Looks up client.countryCode in the jurisdiction's field_nuxt_config.
   * Falls back to the site's default country from system.date config.
   *
   * @param int|string|null $jurisdictionId
   *   The jurisdiction group ID, or NULL.
   *
   * @return string
   *   ISO 3166-1 alpha-2 country code (e.g. "DE", "NL").
   */
  private function resolveJurisdictionCountry($jurisdictionId): string {
    if ($jurisdictionId && is_numeric($jurisdictionId)) {
      $group = $this->entityTypeManager->getStorage('group')->load((int) $jurisdictionId);
      if ($group && $group->hasField('field_nuxt_config') && !$group->get('field_nuxt_config')->isEmpty()) {
        $config = json_decode($group->get('field_nuxt_config')->value, TRUE);
        if (is_array($config)) {
          $code = $config['client']['countryCode'] ?? '';
          if (!empty($code)) {
            return strtoupper($code);
          }
        }
      }
    }
    return $this->configFactory->get('system.date')->get('country.default') ?: 'DE';
  }

}
