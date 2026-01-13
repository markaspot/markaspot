<?php

namespace Drupal\markaspot_open311\Service;

use Drupal\group\Entity\GroupMembership;
use Drupal\media\MediaInterface;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\Exception\FileException;
use GuzzleHttp\Exception\TransferException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\Component\Utility\Html;
use Drupal\Component\Datetime\Time;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\paragraphs\Entity\Paragraph;
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
    if (array_key_exists('lat', $requestData) && array_key_exists('long', $requestData)) {
      $values['field_geolocation'] = [
        'lat' => $requestData['lat'],
        'lng' => $requestData['long'],
      ];
    }
    // Handle Media URL file creation.
    if (array_key_exists('media_url', $requestData)) {
      $values['field_request_media'] = $this->handleMediaUrls($requestData);
    }
    // $values['created'] = isset($request_data['requested_datetime']) && $operation == 'update' ? strtotime($request_data['requested_datetime']) : '';
    $addressString = $requestData['address_string'] ?? ($requestData['address'] ?? NULL);
    if ($addressString) {
      $address = $this->addressParser(Html::escape(stripslashes($addressString)));
      if (!empty($address)) {
        $values['field_address']['address_line1'] = $address['address_line1'];
        $values['field_address']['address_line2'] = $address['address_line2'];
        $values['field_address']['postal_code'] = $address['postal_code'];
        $values['field_address']['locality'] = $address['locality'];
        // Maybe we add this later.
        // $values['field_address']['administrative_area'] = $address['state'];
        // $values['field_address']['country_code'] = $address['country'];.
      }
    }

    if (array_key_exists('service_code', $requestData)) {
      $category_tid = $this->mapServiceCodeToTaxonomy($requestData['service_code']);
      $values['field_category'] = $category_tid;
      if ($values['field_category'] == NULL && $operation !== 'update') {
        throw new GeoreportException('Service-Code empty or not valid', 400);
      }
      if ($values['field_category'] == NULL && $operation == 'update') {
        throw new GeoreportException('Service Code not valid', 400);
      }
    }

    if (array_key_exists('extended_attributes', $requestData)) {
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

      $values += $this->handleExtendedAttributes($extendedDrupal);

      // Handle media published status updates (original path)
      if (isset($requestData['extended_attributes']['media'])) {
        $values['_media_updates'] = $requestData['extended_attributes']['media'];
      }
    }

    return array_filter($values, function ($value) {
      return ($value !== NULL && $value !== FALSE && $value !== '');
    });
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
   *
   * @return int|null
   *   The taxonomy term ID, or null if not found.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the service code is not found in the taxonomy.
   */
  public function mapServiceCodeToTaxonomy(string $serviceCode): ?int {
    $serviceCodes = explode(',', $serviceCode);
    foreach ($serviceCodes as $code) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_service_code' => trim($code)]);
      $term = reset($terms);
      if (!empty($term)) {
        return $term->id();
      }
    }

    throw new NotFoundHttpException('Service code not found');
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
   * @param string $langcode
   *   The language code for the vocabulary.
   * @param int $parent
   *   The ID of the parent taxonomy term (default: 0).
   * @param int|null $maxDepth
   *   The maximum depth for the taxonomy tree (default: null).
   *
   * @return array
   *   An array of service definitions.
   */
  public function getTaxonomyTree(string $vocabulary = 'tags', string $langcode = 'en', int $parent = 0, ?int $maxDepth = NULL): array {
    $tree = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabulary, 'status' => 1]);

    if (empty($tree)) {
      return [];
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

    $service = [
      'service_code' => $term->field_service_code->value,
      'service_name' => $term->getName(),
      'metadata' => 'false',
      'type' => 'realtime',
      'description' => $term->getDescription(),
      'keywords' => $term->field_keywords->value ?? '',
    ];

    foreach ($term->getFields() as $key => $value) {
      $service['extended_attributes'][$key] = $value->value;
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
   *
   * @return array
   *   An array of service request definitions, or structured response with
   *   metadata when meta=true.
   */
  public function getResults(object $query, object $user, array $parameters): array {
    // Check if meta parameter requests wrapped response with metadata.
    // Note: extensions=true alone returns plain array for backwards compatibility.
    $includeMetadata = !empty($parameters['meta']) &&
                       (strtolower($parameters['meta']) === 'true' || $parameters['meta'] === '1');

    // Get total count before applying range, if metadata is requested.
    $totalCount = 0;
    $limit = 0;
    $offset = 0;

    if ($includeMetadata) {
      // Extract limit/offset from parameters (they were set before query creation).
      $limit = isset($parameters['limit']) ? (int) $parameters['limit'] : 100;

      // Support both 'page' (1-based) and 'offset' (0-based) parameters.
      if (isset($parameters['page']) && $parameters['page'] > 0) {
        $page = (int) $parameters['page'];
        $offset = ($page - 1) * $limit;
      }
      elseif (isset($parameters['offset']) && $parameters['offset'] >= 0) {
        $offset = (int) $parameters['offset'];
      }

      // Clone query to get total count without range.
      $countQuery = clone $query;
      $countQuery->range(NULL, NULL);
      $totalCount = (int) $countQuery->count()->execute();
    }

    $nids = $query->execute();

    if (empty($nids)) {
      // Return empty structure based on metadata flag.
      if ($includeMetadata) {
        return [
          'requests' => [],
          'meta' => [
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
          ],
        ];
      }
      return [];
    }

    // Load nodes - for privileged users we need to bypass entity access.
    $storage = $this->entityTypeManager->getStorage('node');
    $bypass_access = $user->hasPermission('bypass node access') || $user->id() == 1;

    if ($bypass_access) {
      // Switch to root user account to bypass all access checks during node loading.
      $account_switcher = \Drupal::service('account_switcher');
      $root_user = User::load(1);
      $account_switcher->switchTo($root_user);

      // Load nodes as root user (bypasses access control)
      $nodes = $storage->loadMultiple($nids);

      // Switch back to original user.
      $account_switcher->switchBack();
    }
    else {
      // For regular users, load with normal access checks.
      $nodes = $storage->loadMultiple($nids);
      // Additional filtering for authenticated users' own unpublished content.
      if (!$user->isAnonymous()) {
        foreach ($nids as $nid) {
          if (!isset($nodes[$nid])) {
            // Temporarily switch to check if this is user's own content.
            $account_switcher = \Drupal::service('account_switcher');
            $account_switcher->switchTo(User::load(1));
            $node = $storage->load($nid);
            $account_switcher->switchBack();

            if ($node && !$node->isPublished() && $node->getOwnerId() == $user->id()) {
              $nodes[$nid] = $node;
            }
          }
        }
      }
    }

    // Use the proper role determination method.
    $extendedRole = $this->determineExtendedRole($user);

    // Preload all taxonomy terms needed by these nodes.
    $this->preloadTaxonomyTerms($nodes);

    $serviceRequests = [];
    foreach ($nodes as $node) {
      $serviceRequests[] = $this->mapNodeToServiceRequest($node, $extendedRole, $parameters);
    }

    // Return structured response with metadata if extensions enabled.
    if ($includeMetadata) {
      return [
        'requests' => $serviceRequests,
        'meta' => [
          'total' => $totalCount,
          'limit' => $limit,
          'offset' => $offset,
        ],
      ];
    }

    return $serviceRequests;
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

    // User 1 and users with bypass node access can see everything.
    if ($user->hasPermission('bypass node access') || $user->id() == 1) {
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
    $jurisdiction_gid = $this->resolveJurisdictionId($parameters);
    if ($jurisdiction_gid) {
      $node_ids = $this->getNodeIdsInGroup($jurisdiction_gid);
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
    if ($org_group_id) {
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
   * Supports both numeric group ID ('gid') and URL slug ('jurisdiction').
   *
   * @param array $parameters
   *   Query parameters.
   *
   * @return int|null
   *   The group ID or NULL if not specified/found.
   */
  protected function resolveJurisdictionId(array $parameters): ?int {
    // Check for direct group ID.
    if (!empty($parameters['gid']) && is_numeric($parameters['gid'])) {
      return (int) $parameters['gid'];
    }

    // Check for jurisdiction slug.
    if (!empty($parameters['jurisdiction'])) {
      $slug = $parameters['jurisdiction'];

      // If numeric, treat as group ID.
      if (is_numeric($slug)) {
        return (int) $slug;
      }

      // Validate slug format (alphanumeric, hyphens, underscores, max 64 chars).
      if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $slug)) {
        return NULL;
      }

      // Load jurisdiction group type from config (supports legacy 'jurisdiction' naming).
      $config = $this->configFactory->get('markaspot_open311.settings');
      $jur_type = $config->get('jurisdiction_group_type') ?? 'jur';

      // Lookup by slug.
      $groups = $this->entityTypeManager->getStorage('group')->loadByProperties([
        'type' => $jur_type,
        'field_slug' => $slug,
      ]);
      $group = reset($groups);
      if ($group) {
        return (int) $group->id();
      }
    }

    return NULL;
  }

  /**
   * Resolves organisation group_id parameter to a group ID.
   *
   * Supports numeric group ID via 'group_id' parameter.
   * This is used to filter service requests by a specific organisation/department.
   *
   * @param array $parameters
   *   Query parameters.
   *
   * @return int|null
   *   The organisation group ID or NULL if not specified.
   *   Returns -1 if the group_id was specified but the group doesn't exist.
   */
  protected function resolveOrganisationGroupId(array $parameters): ?int {
    // Check for group_id parameter.
    if (!empty($parameters['group_id']) && is_numeric($parameters['group_id'])) {
      $group_id = (int) $parameters['group_id'];

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

    return NULL;
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
    $connection = \Drupal::database();
    $node_ids = $connection->select('group_relationship_field_data', 'gr')
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
   * Checks if a user can edit a node.
   *
   * This method avoids using $node->access('update') which triggers
   * Group module's buggy node access handler with missing group_roles field.
   *
   * @param object $node
   *   The node object.
   * @param \Drupal\Core\Session\AccountProxyInterface $user
   *   The user to check access for.
   *
   * @return bool
   *   TRUE if the user can edit the node, FALSE otherwise.
   */
  protected function checkNodeEditability(object $node, $user): bool {
    // Admin and users with bypass permission can edit anything.
    if ($user->hasPermission('bypass node access') || $user->id() == 1) {
      return TRUE;
    }

    // Anonymous users cannot edit.
    if ($user->isAnonymous()) {
      return FALSE;
    }

    // Check if user owns the node.
    if ($node->getOwnerId() == $user->id()) {
      return TRUE;
    }

    // Check if user has general 'edit any service_request content' permission.
    if ($user->hasPermission('edit any service_request content')) {
      return TRUE;
    }

    // If Group module is enabled, check group membership permissions.
    if ($this->moduleHandler->moduleExists('group')) {
      return $this->checkGroupEditPermission($node, $user);
    }

    return FALSE;
  }

  /**
   * Checks if user has edit permission via Group module membership.
   *
   * @param object $node
   *   The node object.
   * @param \Drupal\Core\Session\AccountProxyInterface $user
   *   The user to check.
   *
   * @return bool
   *   TRUE if user can edit via group membership.
   */
  protected function checkGroupEditPermission(object $node, $user): bool {
    return $this->checkGroupPermission($node, $user, 'update');
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
    $langcode = $parameters['langcode'] ?? 'en';
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

    // Add organisation (department) if available.
    if ($node->hasField('field_organisation') && !$node->get('field_organisation')->isEmpty()) {
      $organisationEntity = $node->get('field_organisation')->entity;
      if ($organisationEntity) {
        $request['organisation'] = [
          'id' => (string) $organisationEntity->id(),
          'uuid' => $organisationEntity->uuid(),
          'label' => $organisationEntity->label(),
          'name' => $organisationEntity->label(),
        ];
      }
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

    // Add manager-only fields.
    if ($extendedRole === 'manager') {
      if ($node->hasField('field_e_mail') && !$node->get('field_e_mail')->isEmpty()) {
        $request['email'] = $node->get('field_e_mail')->value ?? '';
        $request['extended_attributes']['e-mail'] = $node->get('field_e_mail')->value ?? '';
      }

      if ($node->hasField('field_phone') && !$node->get('field_phone')->isEmpty()) {
        $request['phone'] = $node->get('field_phone')->value ?? '';
      }

      if ($node->hasField('field_first_name') && !$node->get('field_first_name')->isEmpty()) {
        $request['first_name'] = $node->get('field_first_name')->value ?? '';
      }

      if ($node->hasField('field_last_name') && !$node->get('field_last_name')->isEmpty()) {
        $request['last_name'] = $node->get('field_last_name')->value ?? '';
      }

      if ($node->hasField('uid') && !$node->get('uid')->isEmpty() && $node->get('uid')->entity) {
        $request['extended_attributes']['author'] = $node->get('uid')->entity->label();
      }
    }

    // Add extended attributes if extensions parameter is set.
    if ($extendedRole !== 'anonymous' && isset($parameters['extensions'])) {
      $request['extended_attributes']['markaspot'] = $this->getExtendedAttributes($node, $parameters['langcode'] ?? 'en');

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
      if (isset($parameters['full'])) {
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
   * @param \Drupal\Core\Field\FieldItemListInterface $address
   *   The address field value.
   *
   * @return string
   *   The formatted address string.
   */
  public function formatAddress(FieldItemListInterface $address): string {
    $addressString = $address->postal_code . ' ' . $address->locality . ', ' . $address->address_line1 . ' ' . $address->address_line2;
    return trim($addressString);
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

    $statusOpen = array_values($this->configFactory->get('markaspot_open311.settings')->get('status_open'));
    return in_array($taxonomyId, $statusOpen) ? 'open' : 'closed';
  }

  /**
   * Maps a status value ("open" or "closed") to an array of taxonomy term IDs.
   *
   * @param string $status
   *   The status value ("open" or "closed").
   *
   * @return array
   *   An array of taxonomy term IDs.
   */
  public function mapStatusToTaxonomyIds(string $status): array {
    $config = $this->configFactory->get('markaspot_open311.settings');
    return array_values($config->get($status === 'open' ? 'status_open' : 'status_closed'));
  }

  /**
   * Creates an initial status note paragraph entity.
   *
   * @param array $paragraphData
   *   An array containing the taxonomy term ID and status note text.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph
   *   The created paragraph entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If there is an error saving the paragraph entity.
   */
  public function createStatusNoteParagraph(array $paragraphData): Paragraph {
    $paragraph = Paragraph::create(['type' => 'status']);
    $paragraph->set('field_status_term', $paragraphData[0]);
    // Set with user's default format.
    $paragraph->set('field_status_note', [
      'value' => $paragraphData[1],
      'format' => filter_default_format(),
    ]);
    $paragraph->save();
    return $paragraph;
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

      // Get default initial status term ID.
      $initialStatusId = $this->configFactory->get('markaspot_open311.settings')->get('status_open_start')[0] ?? NULL;

      foreach ($node->get('field_status_notes') as $note) {
        $logCount++;
        $noteEntity = $note->entity;
        if (!$noteEntity) {
          continue;
        }

        $statusTermId = $noteEntity->field_status_term->getValue()[0]['target_id'] ?? $initialStatusId;

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

    // Add hazard level from AI vision analysis.
    if ($node->hasField('field_hazard_level') && !$node->get('field_hazard_level')->isEmpty()) {
      $extendedAttributes['hazard_level'] = (int) $node->get('field_hazard_level')->value;
    }
    else {
      $extendedAttributes['hazard_level'] = 0;
    }

    // Add published status for nodes
    // This flag allows frontend to show an unpublished indicator icon.
    $extendedAttributes['published'] = $node->isPublished();

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
            $value = $field->referencedEntities();
            // Normalize single-value arrays.
            if (is_array($value) && count($value) === 1) {
              $value = reset($value);
            }
          }
          else {
            $value = $field->getValue();
          }
          $fieldValues[$fieldName] = $value;
        }
      }
    }

    return $fieldValues;
  }

  /**
   * Retrieves the values of all fields from a node.
   *
   * @param object $node
   *   The node object.
   *
   * @return array
   *   An associative array of field values.
   */
  private function getAllFieldValues(object $node): array {
    $fieldValues = [];

    foreach ($node->getFields() as $fieldName => $field) {
      $fieldAccess = $field->access('view', NULL, TRUE);

      if ($fieldAccess->isAllowed()) {
        $fieldValues[$fieldName] = $field->value;
      }
    }

    return $fieldValues;
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
      $storageSetting = $this->entityFieldManager->getFieldStorageDefinitions('media')['field_media_image']->getSetting('uri_scheme');
      $wrapperScheme = $this->getWrapperScheme($storageSetting);
      $fieldConfig = $this->configFactory->get('field.field.media.request_image.field_media_image');
      $fieldSettings = $fieldConfig->get('settings');
      $fileDirectory = $this->token->replace($fieldSettings['file_directory'] ?? '');
      $fileDirectory = trim($fileDirectory, '/');
      // Create the directory if it doesn't exist.
      $directoryPath = $wrapperScheme . ($fileDirectory ? $fileDirectory . '/' : '');
      \Drupal::service('file_system')->prepareDirectory($directoryPath, FileSystemInterface::CREATE_DIRECTORY);

      foreach ($urls as $url) {
        // Destroy the client after use.
        unset($client);

        $destination = $directoryPath . basename($url);

        if (strstr($url, 'http')) {
          try {
            $data = (string) \Drupal::httpClient()->get(trim($url))->getBody();
            $filePath = \Drupal::service('file_system')->saveData($data, $destination, FileSystemInterface::EXISTS_RENAME);

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
            \Drupal::messenger()->addError(t('Failed to fetch file due to error "%error"', ['%error' => $exception->getMessage()]));
          }
          catch (FileException | InvalidStreamWrapperException $e) {
            \Drupal::messenger()->addError(t('Failed to save file due to error "%error"', ['%error' => $e->getMessage()]));
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

    // Extract postal code.
    $extractPostalCode = function ($str) {
      if (preg_match('/\b(\d{4,7}([A-Z]{1,2})?)\b/i', $str, $matches)) {
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
        // Second part could be street name or continue address.
        if (empty($result['address_line2'])) {
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

}
