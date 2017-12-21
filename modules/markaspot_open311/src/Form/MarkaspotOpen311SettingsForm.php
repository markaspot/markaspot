<?php

namespace Drupal\markaspot_open311\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotOpen311SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_open311_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_open311.settings');

    $form['markaspot_open311']['common'] = array(
      '#type' => 'fieldset',
      '#title' => t('Open311 Settings and Service Discovery'),
      '#collapsible' => TRUE,
      '#description' => t('Configure the Open311 Server Settings (status, publishing settings and Georeport v2 Service Discovery). See http://wiki.open311.org/Service_Discovery. This service discovery specification is meant to be read-only and can be provided either dynamically or using a manually edited static file.'),
      '#group' => 'settings',
    );

    $form['markaspot_open311']['common']['bundle'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_request',
      '#description' => t('Match the service request to a Drupal content-type (machine_name) of your choice'),
    );

    $form['markaspot_open311']['common']['tax_category'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_category',
      '#description' => t('Match the request category to a Drupal vocabulary (machine_name) of your choice'),
    );

    $form['markaspot_open311']['common']['tax_status'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_status',
      '#description' => t('Match the request status to a Drupal vocabulary (machine_name) of your choice'),
    );

    $form['markaspot_open311']['common']['node_options_status'] = array(
      '#type' => 'radios',
      '#default_value' => $config->get('node_options_status'),
      '#options' => array(0 => t('Unpublished'), 1 => t('Published')),
      '#title' => t('Choose the publish status of incoming reports'),
    );

    $form['markaspot_open311']['common']['status_open_start'] = array(
      '#type' => 'select',
      '#multiple' => FALSE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open_start'),
      '#title' => t('Choose the status that gets applied when creating reports by third party apps'),
    );

    $form['markaspot_open311']['common']['status_open'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open'),
      '#title' => t('Please choose the status for open reports'),
    );

    $form['markaspot_open311']['common']['status_closed'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_closed'),
      '#title' => t('Please choose the status for closed reports'),
    );

    $form['markaspot_open311']['common']['status_open'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_open311.settings')->get('tax_status')),
      '#default_value' => $config->get('status_open'),
      '#title' => t('Please choose the status for open reports'),
    );

    $form['markaspot_open311']['common']['nid-limit'] = array(
      '#type' => 'textfield',
      '#title' => t('Limit settings'),
      '#default_value' => $config->get('nid-limit'),
      '#description' => t('Set the maximum number of requests by nids.'),
    );

    $form['markaspot_open311']['discovery'] = array(
      '#type' => 'fieldset',
      '#title' => t('Open311 Service Discovery'),
      '#collapsible' => TRUE,
      '#description' => t('Configure the Open311 Server Settings (status, publishing settings and Georeport v2 Service Discovery). See http://wiki.open311.org/Service_Discovery. This service discovery specification is meant to be read-only and can be provided either dynamically or using a manually edited static file.'),
      '#group' => 'settings',
    );
    $form['markaspot_open311']['discovery']['changeset'] = array(
      '#type' => 'textfield',
      '#default_value' => date('c', time()),
      '#title' => t('Changeset'),
      '#description' => t('Sortable field that specifies the last time this document was updated'),
    );

    $form['markaspot_open311']['discovery']['key_service'] = array(
      '#type' => 'textarea',
      '#default_value' => $config->get('discovery.key_service'),
      '#title' => t('Human readable information on how to get an API key'),
    );
    $form['markaspot_open311']['discovery']['contact'] = array(
      '#type' => 'textarea',
      '#default_value' => $config->get('discovery.contact'),
      '#title' => t('Open311 Contact Details'),
    );

    foreach ($config->get('discovery.endpoints') as $key => $value) {

      foreach ($value['formats'] as $key => $format) {
        $options[$format] = $format;
      }

      $form['markaspot_open311']['discovery']['endpoints'][$key]['type'] = array(
        '#type' => 'textfield',
        '#default_value' => $value['type'],
        '#title' => t('Type'),
      );
      $form['markaspot_open311']['discovery']['endpoints'][$key]['formats'] = array(
        '#type' => 'checkboxes',
        '#options' => $options,
        '#title' => t('Formats'),
        '#description' => t('Data structure of supported MIME types.'),
      );
      $form['markaspot_open311']['discovery']['endpoints'][$key]['specification'] = array(
        '#type' => 'textfield',
        '#default_value' => $value['specification'],
        '#title' => t('Specification'),
        '#description' => t('The token of the service specification that is supported. This token will be defined by each spec. In general the format is a URL that identifies the specification and version number much like an XMLNS declaration. (eg http://wiki.open311.org/GeoReport_v2)'),
      );
      $form['markaspot_open311']['discovery']['endpoints'][$key]['changeset'] = array(
        '#type' => 'textfield',
        '#default_value' => date('c', time()),
        '#title' => t('Changeset'),
        '#description' => t('Sortable field that specifies the last time this document was updated'),
      );

    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Helper function to get taxonomy term options for select widget.
   *
   * @parameter string $machine_name
   *   Taxonomy machine name.
   *
   * @return array
   *   Select options for form
   */
  public function getTaxonomyTermOptions($machine_name) {
    $options = array();

    // $vid = taxonomy_vocabulary_machine_name_load($machine_name)->vid;
    $vid = $machine_name;
    $options_source = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vid);

    foreach ($options_source as $item) {
      $key = $item->tid;
      $value = $item->name;
      $options[$key] = $value;
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    // var_dump($values);
    $this->config('markaspot_open311.settings')

      ->set('status_open', $values['status_open'])
      ->set('status_closed', $values['status_closed'])
      ->set('status_open_start', $values['status_open_start'])
      ->set('node_options_status', $values['node_options_status'])
      ->set('changeset', $values['changeset'])
      ->set('key_service', $values['key_service'])
      ->set('type', $values['type'])
      ->set('contact', $values['contact'])
      ->set('bundle', $values['bundle'])
      ->set('tax_category', $values['tax_category'])
      ->set('tax_status', $values['tax_status'])
      ->set('nid-limit', $values['nid-limit'])
      ->set('discovery.endpoints', array(array(
        'changeset' => $values['changeset'],
        'specification' => $values['specification'],
        'type' => $values['type'],
        'formats' => array_keys($values['formats']),
      ),
      )
      )
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_open311.settings',
    ];
  }

}
