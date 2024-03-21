<?php

namespace Drupal\markaspot_open311\Service;

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
class GeoreportProcessorService implements GeoreportProcessorServiceInterface
{
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
    Token $token
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
   * @return object
   *   Returns the discovery configuration.
   */
  public function getDiscovery(): object
  {
    return $this->configFactory->get('markaspot_open311.settings')->get('discovery');
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
  public function prepareNodeProperties(array $requestData, string $operation): array
  {
    $values = [
      'type' => 'service_request',
      'changed' => $this->time->getCurrentTime(),
    ];

    $values = [
      'type' => 'service_request',
      'field_first_name' => $this->getSafeValue($requestData, 'first_name'),
      'field_last_name' => $this->getSafeValue($requestData, 'last_name'),
      'field_phone' => $this->getSafeValue($requestData, 'phone'),
    ];

    $values['title'] = isset($requestData['service_code']) ? Html::escape(stripslashes($requestData['service_code'])) : NULL;

    if (array_key_exists('email', $requestData)) {
      // Assuming getSafeValue sanitizes the input.
      $sanitizedValue = $this->getSafeValue($requestData, 'email');
      $values['field_e_mail'] = [
        'value' => $sanitizedValue
      ];
    }


    // creating a tmp title to be created later via request_id
    // $values['title'] = $operation === 'create'  ? Html::escape(stripslashes($requestData['service_code'])) : NULL;


    if (array_key_exists('description', $requestData)) {
      // Assuming getSafeValue sanitizes the input.
      $sanitizedValue = $this->getSafeValue($requestData, 'description');
      $values['body'] = [
        'value' => $sanitizedValue,
        'format' => 'plain_text', // Set the format explicitly
      ];
    }
    if (array_key_exists('lat', $requestData) && array_key_exists('long', $requestData)) {
      $values['field_geolocation'] = [
        'lat' => $requestData['lat'],
        'lng' => $requestData['long'],
      ];
    }
    // Handle Media URL file creation
    $values['field_request_media'] = $this->handleMediaUrls($requestData);

    # $values['created'] = isset($request_data['requested_datetime']) && $operation == 'update' ? strtotime($request_data['requested_datetime']) : '';


    $addressString = $requestData['address_string'] ?? ($requestData['address'] ?? null);
    if ($addressString) {
      $address = $this->addressParser($addressString) ? Html::escape(stripslashes($addressString)) : [];
      if (!empty($address)) {
        $values['field_address']['address_line1'] = $address['street'];
        $values['field_address']['postal_code'] = $address['zip'];
        $values['field_address']['locality'] = $address['city'];
      }
    }

    if (array_key_exists('service_code', $requestData)) {
      $category_tid = $this->mapServiceCodeToTaxonomy($requestData['service_code']);
      $values['field_category'] = $category_tid;
      if ($values['field_category'] == null && $operation !== 'update') {
        throw new GeoreportException('Service-Code empty or not valid', 400);
      }
      if ($values['field_category'] == null && $operation == 'update') {
        throw new GeoreportException('Service Code not valid', 400);
      }
    }

    if (array_key_exists('extended_attributes', $requestData)) {
      $values['revision_log_message'] = $requestData['extended_attributes']['revision_log_message'] ?? null;
      $values += $this->handleExtendedAttributes($requestData['extended_attributes']['drupal'] ?? []);
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
  private function parseAddress(string $addressString): array
  {
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
  public function mapServiceCodeToTaxonomy(string $serviceCode): ?int
  {
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
  public function mapStatusToTaxonomy(string $statuses): array
  {
    $statusValues = explode(',', $statuses);
    $termIds = [];

    foreach ($statusValues as $status) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $status]);
      if (!empty($terms)) {
        $termIds = array_merge($termIds, array_keys($terms));
      } else {
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
  public function getTaxonomyTree(string $vocabulary = 'tags', string $langcode = 'en', int $parent = 0, ?int $maxDepth = null): array
  {
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
  public function mapTaxonomyToService(int $tid, string $langcode): array
  {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);

    // Load the translation if available
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
   *   An array of service request definitions.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If no service requests are found.
   */
  public function getResults(object $query, object $user, array $parameters): array
  {
    $nids = $query->execute();
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $extendedRole = $user->hasPermission('access open311 extension') ? 'user' : 'anonymous';
    if ($user->hasPermission('access open311 advanced properties')) {
      $extendedRole = 'manager';
    }

    $serviceRequests = [];
    foreach ($nodes as $node) {
      $serviceRequests[] = $this->mapNodeToServiceRequest($node, $extendedRole, $parameters);
    }

    if (!empty($serviceRequests)) {
      return $serviceRequests;
    }

    throw new NotFoundHttpException('No service requests found');
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
  public function mapNodeToServiceRequest(object $node, string $extendedRole, array $parameters): array
  {
    $id = $node->get('request_id')->value;
    $request = [
      'service_request_id' => $id,
      'title' => $node->getTitle(),
      'description' => $node->get('body')->value,
      'lat' => (float) $node->get('field_geolocation')->lat,
      'long' => (float) $node->get('field_geolocation')->lng,
      'address_string' => $this->formatAddress($node->get('field_address')),
      'service_name' => $this->getTaxonomyTermField($node->get('field_category')->target_id, 'name'),
      'requested_datetime' => $this->formatDateTime($node->get('created')->value),
      'updated_datetime' => $this->formatDateTime($node->get('changed')->value),
      'status' => $this->mapStatusToOpenClosedValue($node->get('field_status')->target_id),
    ];

    // Handle media URLs
    $request['media_url'] = $this->getMediaUrls($node);

    // Handle status notes
    $request['status_note'] = $this->getStatusNote($node);

    // Handle service code
    $request['service_code'] = $this->getTaxonomyTermField($node->get('field_category')->target_id, 'field_service_code');

    if ($extendedRole === 'manager') {
      $request['email'] = $node->get('field_e_mail')->value;
      $request['phone'] = $node->get('field_phone')->value ?? null;
      $request['first_name'] = $node->get('field_first_name')->value ?? $node->get('field_first_name')->value ?? null;
      $request['last_name'] = $node->get('field_last_name')->value ?? $node->get('field_last_name')->value ?? null;
    }

    if ($extendedRole !== '' && isset($parameters['extensions'])) {
      $request['extended_attributes']['markaspot'] = $this->getExtendedAttributes($node, $parameters['langcode'] ?? 'en');
    }

    if ($extendedRole === 'manager') {
      $request['extended_attributes']['author'] = $node->get('uid')->entity->label();
      $request['extended_attributes']['e-mail'] = $node->get('field_e_mail')->value;

      if (isset($parameters['fields'])) {
        $request['extended_attributes']['drupal'] = $this->getFieldValues($node, $parameters['fields']);
      }

      if (isset($parameters['full'])) {
        $request['extended_attributes']['drupal'] = $this->getAllFieldValues($node);
      }
    }

    return $request;
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
  public function formatAddress(\Drupal\Core\Field\FieldItemListInterface $address): string
  {
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
  public function getTaxonomyTermField(int $tid, string $fieldName): mixed
  {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    return $term ? $term->get($fieldName)->value : null;
  }

  /**
   * Maps a taxonomy term ID to an "open" or "closed" status value.
   *
   * @param int $taxonomyId
   *   The taxonomy term ID.
   *
   * @return string
   *   The status value ("open" or "closed").
   */
  public function mapStatusToOpenClosedValue(int $taxonomyId): string
  {
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
  public function mapStatusToTaxonomyIds(string $status): array
  {
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
  public function createStatusNoteParagraph(array $paragraphData): Paragraph
  {
    $paragraph = Paragraph::create(['type' => 'status']);
    $paragraph->set('field_status_term', $paragraphData[0]);
    $paragraph->set('field_status_note', $paragraphData[1]);
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
  private function getMediaUrls(object $node): string
  {
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
      $fieldValues = $node->get('field_status_notes')->getValue();
      $latestNoteValue = end($fieldValues);
      if (isset($latestNoteValue['entity'])) {
        $latestNote = $latestNoteValue['entity'];
        if ($latestNote instanceof Paragraph && $latestNote->hasField('field_status_note')) {
          return $latestNote->get('field_status_note')->value;
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
  private function getExtendedAttributes(object $node, string $langcode): array
  {
    $extendedAttributes = [
      'nid' => $node->id(),
    ];

    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($node->get('field_category')->target_id);
      if ($term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }

      $extendedAttributes['category_hex'] = $term->get('field_category_hex')->color;
      $extendedAttributes['category_icon'] = $term->get('field_category_icon')->value;
    }

    if ($node->hasField('field_status_notes') && !$node->get('field_status_notes')->isEmpty()) {
      $statusNotes = [];
      $logCount = -1;

      foreach ($node->get('field_status_notes') as $note) {
        $logCount++;
        $noteEntity = $note->entity;
        $statusTerm = $this->entityTypeManager->getStorage('taxonomy_term')->load($noteEntity->get('field_status_term')->target_id);

        if ($statusTerm->hasTranslation($langcode)) {
          $statusTerm = $statusTerm->getTranslation($langcode);
        }

        $statusNotes[$logCount] = [
          'status_note' => $noteEntity->get('field_status_note')->value,
          'status' => $this->mapStatusToOpenClosedValue($statusTerm->id()),
          'updated_datetime' => $this->formatDateTime($noteEntity->get('created')->value),
          'status_descriptive_name' => $statusTerm->getName(),
          'status_hex' => $statusTerm->get('field_status_hex')->color,
          'status_icon' => $statusTerm->get('field_status_icon')->value,
        ];
      }

      $extendedAttributes['status_notes'] = $statusNotes;
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
  private function getFieldValues(object $node, string $fieldNames): array
  {
    $fieldValues = [];
    $fieldNames = explode(',', $fieldNames);

    foreach ($fieldNames as $fieldName) {
      if ($node->hasField($fieldName)) {
        $field = $node->get($fieldName);
        $fieldAccess = $field->access('view', null, true);

        if ($fieldAccess->isAllowed()) {
          if (method_exists($field, 'referencedEntities')) {
            $fieldValues[$fieldName] = $field->referencedEntities();
          } else {
            $fieldValues[$fieldName] = $field->value;
          }
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
  private function getAllFieldValues(object $node): array
  {
    $fieldValues = [];

    foreach ($node->getFields() as $fieldName => $field) {
      $fieldAccess = $field->access('view', null, true);

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
  private function formatDateTime(int $timestamp): string
  {
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
  private function getSafeValue(array $data, string $key): ?string
  {
    return isset($data[$key]) ? Html::escape(stripslashes($data[$key])) : null;
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
      // Create the directory if it doesn't exist
      $directoryPath = $wrapperScheme . ($fileDirectory ? $fileDirectory . '/' : '');
      \Drupal::service('file_system')->prepareDirectory($directoryPath, FileSystemInterface::CREATE_DIRECTORY);


      foreach ($urls as $url) {
        unset($client); // Destroy the client after use

        $destination = $directoryPath . basename($url);

        if (strstr($url, 'http')) {
          try {
            $data = (string) \Drupal::httpClient()->get($url)->getBody();
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
          catch (\GuzzleHttp\Exception\TransferException $exception) {
            \Drupal::messenger()->addError(t('Failed to fetch file due to error "%error"', ['%error' => $exception->getMessage()]));
          }
          catch (\Drupal\Core\File\Exception\FileException | \Drupal\Core\File\Exception\InvalidStreamWrapperException $e) {
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
  private function createMediaEntity(string $bundle, \Drupal\file\Entity\File $file): \Drupal\media\MediaInterface
  {
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
      ->setPublished(true)
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
}
