<?php

namespace Drupal\markaspot_open311\Plugin\rest\resource;

use Drupal\taxonomy\Entity\Term;

/**
 * Class GeoreportProcessor parsing.
 *
 * @package Drupal\markaspot_open311\Plugin\rest\resource
 */
class GeoreportProcessor {

  /**
   * Load Open311 config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * GeoreportProcessor constructor.
   */
  public function __construct() {
    $this->config = \Drupal::configFactory()
      ->getEditable('markaspot_open311.settings');
  }

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
   * Process errors with http status codes.
   *
   * @param string $message
   *   The error message.
   * @param int $code
   *   The http status/error code.
   *
   * @throws \Exception
   *   Throwing an exception which is reformatted by event subscriber.
   */
  public function processsServicesError($message, $code) {
    throw new \Exception($message, $code);
  }

  /**
   * Get Taxononmy Term Fields.
   *
   * @param int $tid
   *    The Term id.
   * @param string $field_name
   *    The field name.
   *
   * @return mixed
   *    returns the term.
   */
  public function getTerm($tid, $field_name) {
    // var_dump(Term::load(4)->get('name')->value);
    // http://drupalapi.de/api/drupal/drupal%21core%21modules%21taxonomy%21taxonomy.module/function/taxonomy_term_load/drupal-8
    if (isset($tid) && Term::load($tid) != '') {
      return Term::load($tid)->get($field_name)->value;
    }
  }

  /**
   * Map the node object as georeport request.
   *
   * @param object $node
   *    The node object.
   * @param string $extended_role
   *    The extended role parameter allows rendering additional fields.
   * @param bool $uuid
   *    Using node-uuid or node-nid.
   *
   * @return array
   *    Return the $request array.
   */
  public function nodeMapRequest($node, $extended_role, $uuid) {
    if ($uuid !== FALSE) {
      $id = $node->uuid->value;
    }
    else {
      $id = $node->nid->value;
    }
    $request = array(
      'servicerequest_id' => $id,
      'title' => $node->title->value,
      'description' => $node->body->value,
      'lat' => floatval($node->field_geolocation->lat),
      'long' => floatval($node->field_geolocation->lng),
      'address_string' => $this->formatAddress($node->field_address),
      'service_name' => $this->getTerm($node->field_category->target_id, 'name'),
      'requested_datetime' => date('c', $node->created->value),
      'updated_datetime' => date('c', $node->changed->value),
    );
    // Media Url:
    if (isset($node->field_image->fid)) {
      $image_uri = file_create_url($node->field_image->entity->getFileUri());
      $request['media_url'] = $image_uri;
    }

    // Checking latest paragraph entity item for publish the official status.
    if (isset($node->field_status_notes)) {
      // Access the paragraph entity.
      foreach ($node->field_status_notes as $note) {
        // All properties as always: = $note->entity.
        // See below for accessing a detailed history of the service request.
        $request['status_note'] = $note->entity->field_status_note->value;
        $request['status'] = $this->taxMapStatus($note->entity->field_status_term->target_id);
      }
    }
    $service_code = $this->getTerm($node->field_category->target_id, 'field_service_code');
    $request['service_code'] = isset($service_code) ? $service_code : NULL;

    if (isset($extended_role)) {
      if (\Drupal::moduleHandler()->moduleExists('service_request')) {

        $request['extended_attributes']['markaspot'] = [];

        $nid = array('nid' => $node->nid->value);
        $category = array(
          'category_hex' => Term::load($node->field_category->target_id)->field_category_hex->color,
          'category_icon' => Term::load($node->field_category->target_id)->field_category_icon->value,
        );

        if (isset($node->field_status_notes)) {
          foreach ($node->field_status_notes as $note) {
            $status['status_hex'] = Term::load($note->entity->field_status_term->target_id)->field_status_hex->color;
            $status['status_icon'] = Term::load($note->entity->field_status_term->target_id)->field_status_icon->value;
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
      $request['extended_attributes']['author'] = $node->author;
      $request['extended_attributes']['e-mail'] = $node->field_e_mail->value;
    }
    return $request;
  }

  /**
   * Prepare Node properties.
   *
   * @param array $request_data
   *    Georeport Request data via form urlencoded.
   *
   * @return array
   *    values to be saved via entity api.
   */
  public function requestMapNode($request_data) {

    if (isset($request_data['service_request_id'])) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(array('uuid' => $request_data['service_request_id']));
      foreach ($nodes as $node) {
        $uuid = $node->uuid->value;
      }
      if (isset($uuid)) {
        $request_data['requested_datetime'] = date('c', $node->created->value);
      }
      else {
        // After initital import
        // toDo: Make this configurable depending on enabled method (update),
        // $this->processsServicesError(t('Property service_request_id provided, but corresponding id not found for update'), 400);.
      }
    }

    $values['node'] = $node;

    $values['type'] = 'service_request';
    if (isset($uuid)) {
      $values['uuid'] = $request_data['service_request_id'];
    }
    $values['title'] = isset($request_data['service_code']) ? $request_data['service_code'] : '';
    $values['body'] = isset($request_data['description']) ? $request_data['description'] : '';

    // Don't need this for development.
    $values['field_e_mail'] = isset($request_data['email']) ? $request_data['email'] : '';

    $values['field_geolocation']['lat'] = isset($request_data['lat']) ? $request_data['lat'] : '';
    $values['field_geolocation']['lng'] = isset($request_data['long']) ? $request_data['long'] : '';

    if (array_key_exists('address_string', $request_data) ||  array_key_exists('address', $request_data)) {

      $address = $this->addressParser($request_data['address_string']) ? $request_data['address_string'] : $request_data['address'];

      // $values['field_address']['country_code'] = 'DE';.
      $values['field_address']['address_line1'] = $address['street'];
      $values['field_address']['postal_code'] = $address['zip'];
      $values['field_address']['locality'] = $address['city'];

    }

    // Get Category by service_code.
    $values['created'] = isset($request_data['requested_datetime']) ? strtotime($request_data['requested_datetime']) : time();

    // This wont work with entity->save().
    $values['changed'] = isset($request_data['updated_datetime']) ? strtotime($request_data['updated_datetime']) : $values['created'];

    $values['field_category']['target_id'] = $this->serviceMapTax($request_data['service_code']);

    // File Handling:
    if (isset($request_data['media_url']) && strstr($request_data['media_url'], "http")) {
      $managed = TRUE;
      $file = system_retrieve_file($request_data['media_url'], 'public://', $managed, FILE_EXISTS_RENAME);

      $field_keys['image'] = 'field_request_image';

      if ($file !== FALSE) {
        $values[$field_keys['image']] = array(
          'target_id' => $file->id(),
          'alt' => 'Open311 File',
        );
      }

    }

    return $values;
  }

  /**
   * Parse an address_string to an array.
   *
   * Todo: make this reusable for any country.
   *
   * @param string $address_string
   *   The address as string.
   *
   * @return array
   *   Return address
   */
  private function addressParser($address_string) {

    $address_array = explode(',', $address_string);

    $zip_city = explode(' ', trim($address_array[1]));

    $address['street'] = $address_array[0];
    $address['zip'] = trim($zip_city[0]);
    $address['city'] = trim($zip_city[1]);

    return $address;

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
  public function getTaxonomyTree($vocabulary = "tags", $parent = 0, $max_depth = NULL) {
    // Load terms.
    $tree = \Drupal::service('entity_type.manager')
      ->getStorage("taxonomy_term")
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
    $service_category = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->load($tid);

    $service['service_code'] = $service_category->field_service_code->value;
    $service['service_name'] = $service_category->name;
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
   * Mapping requested service_code to drupal taxonomy.
   *
   * @param string $service_code
   *   Open311 Service code (can be Code0001)
   *
   * @return int
   *   The TaxonomyId
   */
  public function serviceMapTax($service_code) {

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(array('field_service_code' => $service_code));
    $term = reset($terms);
    if ($term != FALSE) {
      $tid = $term->tid->value;
      return $tid;
    }
    else {
      $this->processsServicesError('Servicecode not found', 404);
    }
    return FALSE;
  }

  /**
   * Mapping requested status to drupal taxonomy.
   *
   * @param string $status_sub
   *   Custom Service status (can be foreign translated term name).
   *
   * @return int
   *   The tid
   */
  public function statusMapTax($status_sub) {

    $terms = taxonomy_term_load_multiple_by_name($status_sub);
    $term = reset($terms);
    if ($term != FALSE) {
      $tid = $term->tid->value;
      return $tid;
    }
    else {
      $this->processsServicesError('Status not found', 404);
      return FALSE;
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
  public function taxMapStatus($taxonomy_id) {
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
   * Format address_string property.
   *
   * @param object $address
   *   The address field.
   *
   * @return string
   *   The GeoReport address_string property
   */
  public function formatAddress($address) {
    // todo: Format this with conditions and international,
    // make it configurable?
    $address_string = $address->postal_code . ' ' . $address->locality . ', ' . $address->address_line1 . ' ' . $address->address_line2;
    return trim($address_string);
  }

}
