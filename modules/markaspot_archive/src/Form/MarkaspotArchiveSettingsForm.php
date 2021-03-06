<?php

namespace Drupal\markaspot_archive\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure archive settings for this site.
 */
class MarkaspotArchiveSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_archive_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_archive.settings');
    $form['markaspot_archive'] = array(
      '#type' => 'fieldset',
      '#title' => t('Archive Settings'),
      '#collapsible' => TRUE,
      '#description' => t('This setting allow you to choose between several archive settings.'),
      '#group' => 'settings',
    );

    $form['markaspot_archive']['common']['tax_status'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_status',
      '#description' => t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    );

    $form['markaspot_archive']['status_archivable'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_archive.settings')->get('tax_status')),
      '#default_value' => $config->get('status_archivable'),
      '#title' => t('Please choose the status for archivable reports.'),
    );

    $form['markaspot_archive']['status_archived'] = array(
      '#type' => 'select',
      '#multiple' => FALSE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_archive.settings')->get('tax_status')),
      '#default_value' => $config->get('status_archived'),
      '#title' => t('Please choose the status for archived reports.'),
    );

    $form['markaspot_archive']['unpublish'] = array(
      '#type' => 'checkbox',
      '#title' => t('Unpublish'),
      '#description' => t('Unpublish Service Requests on archiving.'),
      '#default_value' => $config->get('unpublish'),
    );

    $form['markaspot_archive']['anonymize'] = array(
      '#type' => 'checkbox',
      '#title' => t('Anonymize personal data'),
      '#description' => t('All data of the field entities below will get anonymized.'),
      '#default_value' => $config->get('anonymize'),
    );

    $form['markaspot_archive']['anonymize_fields'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getFields(),
      '#default_value' => $config->get('anonymize_fields'),
      '#title' => t('Please choose the fields that will get overwritten on archiving.'),
    );



    $catOptions = $this->getTaxonomyTermOptions('service_category');
    $form['markaspot_archive']['days'] = [
      '#tree' => TRUE,
      '#type' => 'details',
      '#title' => t('Archive period settings per category'),
      '#description' => t('You can change the period in which archivable content is sent to the archiving status.'),
      '#open' => TRUE, // Controls the HTML5 'open' attribute. Defaults to FALSE.
    ];


    foreach ($catOptions as $tid => $category_name) {
      $form['markaspot_archive']['days'][$tid] = [
        '#type' => 'number',
        '#min' => 1,
        '#max' => 1000,
        '#step' => 1,
        '#title' => t('Days for <i>@category_name</i>', ['@category_name' => $category_name]),
        '#default_value' => $config->get('days.'. $tid),
        '#description' => t('How many days to reach back for archiving?'),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_archive.settings')
      ->set('tax_status', $values['tax_status'])
      ->set('status_archivable', $values['status_archivable'])
      ->set('status_archived', $values['status_archived'])
      ->set('unpublish', $values['unpublish'])
      ->set('anonymize', $values['anonymize'])
      ->set('anonymize_fields', $values['anonymize_fields'])
      ->set('days', $values['days'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  public function getFields() {
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'service_request');
    return array_keys($definitions);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_archive.settings',
    ];
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

}
