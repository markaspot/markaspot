<?php

namespace Drupal\markaspot_open311\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_open311\Exception\GeoreportException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class GeoreportProcessorService parsing.
 *
 * @package Drupal\markaspot_open311\Plugin\rest\resource
 */
class GeoreportProcessorService implements GeoreportProcessorServiceInterface {

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
   * GeoreportProcessorService constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config object.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGenerator $fileUrlGenerator
   *   File url generator object.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ConfigFactoryInterface $configFactory, AccountProxyInterface $current_user, TimeInterface $time, RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager, FileUrlGenerator $fileUrlGenerator, ModuleHandlerInterface $module_handler) {
    $this->config = $configFactory->get('markaspot_open311.settings');
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   *
   * Public static function create() {
   * return new static(
   * $container->get('config.factory'),
   * $container->get('current_user'),
   * $container->get('datetime.time'),
   * $container->get('request_stack'),
   * $container->get('entity_type.manager'),
   * );
   * }
   */

  /**
   * Get discovery from congiguration.
   *
   * @return object
   *   Return Open or Closed Status according to specification.
   */
  public function getDiscovery() {
    return $this->config->get('discovery');
  }

  /**
   * Prepare Node properties.
   *
   * @param array $request_data
   *   Georeport Request data via form urlencoded.
   * @param string $op
   *   Operation.
   *
   * @return array
   *   values to be saved via entity api.
   *
   * @throws \Drupal\markaspot_open311\Exception\GeoreportException
   *   Georeport Exception with error code and error text.
   */
  public function requestMapNode(array $request_data, string $op) {

    $values['type'] = 'service_request';
    if (isset($request_id)) {
      // $values['request_id'] = $request_data['service_request_id'];
    }
    $values['title'] = isset($request_data['service_code']) ? Html::escape(stripslashes($request_data['service_code'])) : NULL;
    $values['body'] = isset($request_data['description']) ? Html::escape(stripslashes($request_data['description'])) : NULL;

    // Don't need this for development.
    $values['field_e_mail'] = isset($request_data['email']) ? Html::escape(stripslashes($request_data['email'])) : NULL;
    $values['field_first_name'] = isset($request_data['first_name']) ? Html::escape(stripslashes($request_data['first_name'])) : NULL;
    $values['field_last_name'] = isset($request_data['last_name']) ? Html::escape(stripslashes($request_data['last_name'])) : NULL;
    $values['field_phone'] = isset($request_data['phone']) ? Html::escape(stripslashes($request_data['phone'])) : NULL;
    $values['field_geolocation']['lat'] = $request_data['lat'] ?? NULL;
    $values['field_geolocation']['lng'] = $request_data['long'] ?? NULL;
    if (!isset($values['field_geolocation']['lat'])) {
      $values['field_geolocation'] = NULL;
    }
    if (array_key_exists('address_string', $request_data) || array_key_exists('address', $request_data)) {

      $address = $this->addressParser($request_data['address_string']) ? Html::escape(stripslashes($request_data['address_string'])) : $request_data['address'];

      $values['field_address']['address_line1'] = $address['street'];
      $values['field_address']['postal_code'] = $address['zip'];
      $values['field_address']['locality'] = $address['city'];
    }

    // Get Category by service_code.
    $values['created'] = isset($request_data['requested_datetime']) && $op == 'update' ? strtotime($request_data['requested_datetime']) : '';

    // This won't work with entity->save().
    $values['changed'] = time();

    $category_tid = isset($request_data['service_code']) ? $this->serviceMapTax($request_data['service_code']) : NULL;
    $values['field_category'] = $category_tid;
    if ($values['field_category'] == NULL && $op !== 'update') {
      throw new GeoreportException('Service-Code empty or not valid', 400);
    }
    if (isset($request_data['service_code']) && $values['field_category'] == NULL && $op == 'update') {
      throw new GeoreportException('Service Code not valid', 400);
    }
    // File Handling:
    if (isset($request_data['media_url'])) {
      $media_urls = explode(',', $request_data['media_url']);
      foreach ($media_urls as $url) {
        if (strstr($url, "http")) {
          $managed = TRUE;
          $file = system_retrieve_file($url, 'public://', $managed, FileSystemInterface::EXISTS_RENAME);
          if ($file !== FALSE) {
            if ($this->moduleHandler->moduleExists('markaspot_media')) {
              $field_keys['image'] = 'field_request_media';
              $media = $this->entityTypeManager->getStorage('media')->create([
                'bundle' => 'request_image',
                'uid' => $this->currentUser->id(),
                'field_media_image' => [
                  'target_id' => $file->id(),
                  'alt' => 'Open311 File',
                ],
              ]);
              $media->setName('media:request_image:' . $media->uuid())
                ->setPublished(TRUE)
                ->save();
              // $field_keys['image'][] = 'field_request_media';
              $values['field_request_media'][] = [
                'target_id' => $media->id(),
                'alt' => 'Open311 File',
              ];
            }
            else {
              $field_keys['image'] = 'field_request_image';
              $values[$field_keys['image']] = [
                'target_id' => $file->id(),
                'alt' => 'Open311 File',
              ];
            }
          }
          else {
            throw new GeoreportException('Image could not be retrieved via URL', 400);
          }

        }
      }
    }

    if (array_key_exists('extended_attributes', $request_data)) {
      // Check for additional attribute for use of revision log message.
      if (isset($request_data['extended_attributes']['revision_log_message'])) {
        $values['revision_log_message'] = $request_data['extended_attributes']['revision_log_message'];
      }
      // Check for all drupal fields.
      foreach ($request_data['extended_attributes']['drupal'] as $field_name => $value) {
        if (isset($field_name)) {
          $values[$field_name] = $value;
        }
      }
    }
    return array_filter($values, function ($value) {
      return ($value !== NULL && $value !== FALSE && $value !== '');
    });
  }

  /**
   * Parse an address_string to an array.
   *
   * @todo make this reusable for any country.
   *
   * @param string $address_string
   *   The address as string.
   *
   * @return array
   *   Return address
   */
  private function addressParser(string $address_string): array {

    $address_array = explode(',', $address_string);

    $zip_city = explode(' ', trim($address_array[1]));

    $address['street'] = $address_array[0];
    $address['zip'] = trim($zip_city[0]);
    $address['city'] = trim($zip_city[1]);

    return $address;

  }

  /**
   * Mapping requested service_code to drupal taxonomy.
   *
   * @param string $service_code
   *   Open311 Service code (can be Code0001)
   *
   * @return int
   *   The TaxonomyId
   */
  public function serviceMapTax($service_code) {
    $service_codes = explode(',', $service_code);
    if (count($service_codes) > 1) {
      throw new GeoreportException('Service Code has to be unique', 400);
    }
    foreach ($service_codes as $service_code) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_service_code' => trim($service_code)]);
      $term = reset($terms);
      return !empty($term) ? $term->id() : NULL;
    }

    $term = reset($terms);
    return !empty($term) ? $term->id() : new NotFoundHttpException('Servicecode not found');

  }

  /**
   * Mapping requested status to drupal taxonomy.
   *
   * @param string $statuses
   *   Open311 Service code (can be Code0001)
   *
   * @return int
   *   The TaxonomyId
   */
  public function fieldStatusMapTax($statuses) {
    $statuses = explode(',', $statuses);
    foreach ($statuses as $status) {
      $terms[] = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties(['name' => $status]);
    }

    foreach ($terms as $term) {
      $ids[] = array_keys($term);
    }
    if ($ids != FALSE) {
      return $ids;
    }
    else {
      new NotFoundHttpException('Status not found');
    }
    return FALSE;
  }

  /**
   * Returns renderable array of taxonomy terms from Categories vocabulary.
   *
   * @param string $vocabulary
   *   The taxonomy vocabulary.
   * @param int $parent
   *   The ID of the parent taxonomy term.
   * @param int $max_depth
   *   The max depth up to which to look up children.
   *
   * @return array
   *   Return the drupal taxonomy term as a georeport service.
   */
  public function getTaxonomyTree($vocabulary = "tags", $parent = 0, $max_depth = NULL): array {
    // Load terms.
    $tree = $this->entityTypeManager->getStorage("taxonomy_term")
      ->loadTree($vocabulary, $parent, $max_depth, $load_entities = FALSE);

    // Make sure there are terms to work with.
    if (empty($tree)) {
      return [];
    }

    foreach ($tree as $term) {
      // var_dump($term);
      $services[] = $this->taxMapService($term->tid);
    }

    return $services;
  }

  /**
   * Mapping taxonomies to services.
   *
   * @param object $tid
   *   The taxonomy term id.
   *
   * @return object
   *   $service: The service object
   */
  public function taxMapService($tid) {

    // Load all field for this taxonomy term:
    $service_category = $this->entityTypeManager->getStorage('taxonomy_term')
      ->load($tid);

    $service['service_code'] = $service_category->field_service_code->value;
    $service['service_name'] = $service_category->name->value;
    $service['metadata'] = "false";
    $service['type'] = 'realtime';
    $service['description'] = $service_category->description->value;
    if (isset($service_category->field_keywords)) {
      $service['keywords'] = $service_category->field_keywords->value;
    }
    else {
      $service['keywords'] = "";
    }
    foreach ($service_category as $key => $value) {
      $service['extended_attributes'][$key] = $value;
    }
    return $service;
  }

  /**
   * Query the database for nodes.
   *
   * @param object $query
   *   The db query.
   * @param object $user
   *   Drupal User object.
   * @param array $parameters
   *   Array of query parameters.
   *
   * @return array
   *   Array of service_requests.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getResults(object $query, object $user, array $parameters): array {
    $nids = $query->execute();
    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadMultiple($nids);
    // $debug = $query->__toString();
    // Extensions.
    $extended_role = 'anonymous';

    if (isset($parameters['extensions'])) {
      if ($user->hasPermission('access open311 extension')) {
        $extended_role = 'user';
      }
      if ($user->hasPermission('access open311 advanced properties')) {
        $extended_role = 'manager';
      }
    }

    // Building requests array.
    foreach ($nodes as $node) {
      $service_requests[] = $this->nodeMapRequest($node, $extended_role, $parameters);
    }
    if (!empty($service_requests)) {
      return $service_requests;
    }
    else {
      throw new NotFoundHttpException('No service requests found');
    }
  }

  /**
   * Map the node object as georeport request.
   *
   * @param object $node
   *   The node object.
   * @param string $extended_role
   *   The extended role parameter allows rendering additional fields.
   * @param array $parameters
   *   The query parameters.
   *
   * @return array
   *   Return the $request array.
   */
  public function nodeMapRequest(object $node, string $extended_role, array $parameters) {

    $id = $node->request_id->value;

    $request = [
      'service_request_id' => $id,
      'title' => $node->title->value,
      'description' => $node->body->value,
      'lat' => floatval($node->field_geolocation->lat),
      'long' => floatval($node->field_geolocation->lng),
      'address_string' => $this->formatAddress($node->field_address),
      'service_name' => $this->getTerm($node->field_category->target_id, 'name'),
      'requested_datetime' => date('c', $node->created->value),
      'updated_datetime' => date('c', $node->changed->value),
      'status' => $this->taxMapStatus($node->field_status->target_id),
    ];
    // Media Url:
    // Media Url:
    if (isset($node->field_request_image)) {
      $image = $node->field_request_image->entity;
    }
    if (isset($node->field_request_media)) {
      foreach ($node->get('field_request_media')->getValue() as $media) {
        $media = $this->entityTypeManager->getStorage('media')->load($media['target_id']);
        if (isset($media->field_media_image->entity)) {
          $image_uris[] = ($media->isPublished()) ? $this->fileUrlGenerator->generateAbsoluteString($media->field_media_image->entity->getFileUri()) : '';
        }
      }
    }

    if (isset($image)) {
      $image_uri = $this->fileUrlGenerator->generateAbsoluteString($image->getFileUri());
    }
    elseif (isset($media->field_media_image->entity)) {
      $image_uri = implode(',', $image_uris);
    }
    $request['media_url'] = (isset($image_uri)) ? $image_uri : '';

    // Checking latest paragraph entity item for publish the official status.
    if (isset($node->field_status_notes)) {
      // Access the paragraph entity.
      foreach ($node->field_status_notes as $note) {
        // All properties as always: = $note->entity.
        // See below for accessing a detailed history of the service request.
        $request['status_note'] = $note->entity->field_status_note->value;
      }
    }
    if (isset($node->field_category)) {
      $service_code = $this->getTerm($node->field_category->target_id, 'field_service_code');
      $request['service_code'] = $service_code ?? NULL;
    }
    if ($extended_role == 'manager') {

      $request['email'] = $node->field_e_mail->value;

      if (isset($node->field_phone)) {
        $request['phone'] = $node->field_phone->value;
      }
      if (isset($node->field_given_name)) {
        $request['first_name'] = $node->field_given_name->value;
      }
      if (isset($node->field_family_name)) {
        $request['last_name'] = $node->field_family_name->value;
      }
      if (isset($node->field_first_name)) {
        $request['first_name'] = $node->field_first_name->value;
      }
      if (isset($node->field_last_name)) {
        $request['last_name'] = $node->field_last_name->value;
      }
    }
    if (isset($extended_role) && isset($parameters['extensions'])) {
      if ($this->moduleHandler->moduleExists('service_request')) {
        $request['extended_attributes']['markaspot'] = [];

        $nid = ['nid' => $node->nid->value];
        if (isset($node->field_category)) {
          $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($node->field_category->target_id);
          $category = [
            'category_hex' => $term->field_category_hex->color,
            'category_icon' => $term->field_category_icon->value,
          ];
        }

        if (isset($node->field_status_notes)) {
          foreach ($node->field_status_notes as $note) {
            if (isset($note->entity->field_status_term->target_id)) {
              $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($note->entity->field_status_term->target_id);

              $status['status_descriptive_name'] = $term->name->value;
              $status['status_hex'] = $term->field_status_hex->color;
              $status['status_icon'] = $term->field_status_icon->value;
            }
          }

          // Access the paragraph entity.
          $logCount = -1;
          foreach ($node->field_status_notes as $note) {
            $logCount++;
            // All properties as always: = $note->entity.
            $log['status_notes'][$logCount]['status_note'] = $note->entity->field_status_note->value;
            $log['status_notes'][$logCount]['status'] = $this->taxMapStatus($note->entity->field_status_term->target_id);
            $log['status_notes'][$logCount]['updated_datetime'] = date('c', $note->entity->created->value);
          }
        }
        $status = (isset($status)) ? $status : [];
        $log = (isset($log)) ? $log : [];
        $request['extended_attributes']['markaspot'] = array_merge($nid, $category, $status, $log);
      }
    }

    if ($extended_role == 'manager') {

      $request['extended_attributes']['author'] = $node->uid->entity->label();
      $request['extended_attributes']['e-mail'] = $node->field_e_mail->value;

      if (isset($parameters['fields'])) {
        if ($node instanceof FieldableEntityInterface) {
          $request['extended_attributes']['drupal'] = [];
          $fieldNames = explode(',', $parameters['fields']);
          foreach ($fieldNames as $field_name) {
            /** @var \Drupal\Core\Field\FieldItemListInterface $field */
            $field = $node->{$field_name};
            // Check if fieldname by parameter is an actual field.
            if (isset($field)) {
              // Check if perm. are granted for this field (e.g. internal notes)
              $field_access = $field->access('view', NULL, TRUE);
              if (!$field_access->isAllowed()) {
                $field = NULL;
              }
            }
            if (method_exists($node->get($field_name), 'referencedEntities')) {
              $entities = $node->get($field_name)->referencedEntities();
              foreach ($entities as $entity) {
                $request['extended_attributes']['drupal'][$field_name] = $entity;
              }
            }
            else {
              $request['extended_attributes']['drupal'][$field_name] = $node->{$field_name};

            }
          }
        }
      }

      if (isset($parameters['full'])) {
        if ($node instanceof FieldableEntityInterface) {
          $request['extended_attributes']['drupal'] = [];
          foreach ($node as $field_name => $field) {
            /** @var \Drupal\Core\Field\FieldItemListInterface $field */
            $field_access = $field->access('view', NULL, TRUE);
            if (!$field_access->isAllowed()) {
              $field = NULL;
            }
            $request['extended_attributes']['node'][$field_name] = $field;
          }
        }
      }
    }
    return $request;
  }

  /**
   * Format address_string property.
   *
   * @param object $address
   *   The address field.
   *
   * @return string
   *   The GeoReport address_string property
   */
  public function formatAddress($address) {
    // @todo Format this with conditions and international,
    // make it configurable?
    $address_string = $address->postal_code . ' ' . $address->locality . ', ' . $address->address_line1 . ' ' . $address->address_line2;
    return trim($address_string);
  }

  /**
   * Get Taxononmy Term Fields.
   *
   * @param int $tid
   *   The Term id.
   * @param string $field_name
   *   The field name.
   *
   * @return mixed
   *   returns the term.
   */
  public function getTerm($tid, string $field_name) {
    // var_dump(Term::load(4)->get('name')->value);
    // http://drupalapi.de/api/drupal/drupal%21core%21modules%21taxonomy%21taxonomy.module/function/taxonomy_term_load/drupal-8
    if (isset($tid) && $this->entityTypeManager->getStorage('taxonomy_term')->load($tid) != '') {
      return $this->entityTypeManager->getStorage('taxonomy_term')->load($tid)->get($field_name)->value;
    }
  }

  /**
   * Mapping taxonomy to status. GeoReport v2 has only open and closed status.
   *
   * @param int $taxonomy_id
   *   The Drupal Taxonomy ID.
   *
   * @return string
   *   Return Open or Closed Status according to specification.
   */
  public function taxMapStatus($taxonomy_id): string {
    // Mapping Status to Open311 Status (open/closed)
    $status_open = array_values($this->config->get('status_open'));
    if (in_array($taxonomy_id, $status_open)) {
      $status = 'open';
    }
    else {
      $status = 'closed';
    }

    return $status;
  }

  /**
   * Mapping requested status to drupal taxonomy.
   *
   * @param string $status
   *   Open or Closed.
   *
   * @return array
   *   The tids array
   */
  public function statusMapTax(string $status) {
    // Get all terms according to status.
    if ($status == 'open') {
      $tids = array_values($this->config->get('status_open'));
    }
    else {
      $tids = array_values($this->config->get('status_closed'));
    }
    return $tids;
  }

  /**
   * Create initial status note.
   *
   * @param array $paragraphData
   *   The Paragraph to be created.
   *
   * @return Paragraph
   *   Paragraph Object
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Storage exception.
   */
  public function createParagraph(array $paragraphData):Paragraph {
    $paragraph = Paragraph::create(['type' => 'status']);
    $paragraph->set('field_status_term', $paragraphData[0]);
    $paragraph->set('field_status_note', $paragraphData[1]);

    $paragraph->isNew();
    $paragraph->save();
    return $paragraph;
  }

}
