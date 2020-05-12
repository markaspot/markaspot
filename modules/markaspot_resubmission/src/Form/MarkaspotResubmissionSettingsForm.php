<?php

namespace Drupal\markaspot_resubmission\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure resubmission settings for this site.
 */
class MarkaspotResubmissionSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_resubmission_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_resubmission.settings');
    $form['markaspot_resubmission'] = array(
      '#type' => 'fieldset',
      '#title' => t('Resubmission Settings'),
      '#collapsible' => TRUE,
      '#description' => t('This setting allow you to choose between several resubmission settings.'),
      '#group' => 'settings',
    );

    $form['markaspot_resubmission']['common']['tax_status'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => 'service_status',
      '#description' => t('Match the request status to a Drupal vocabulary (machine_name) of your choice.'),
    );

    $form['markaspot_resubmission']['status_resubmissive'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => self::getTaxonomyTermOptions(
        $this->config('markaspot_resubmission.settings')->get('tax_status')),
      '#default_value' => $config->get('status_resubmissive'),
      '#title' => t('Please choose the status for resubmissable reports.'),
    );

    $catOptions = $this->getTaxonomyTermOptions('service_category');
    $form['markaspot_resubmission']['days'] = [
      '#tree' => TRUE,
      '#type' => 'details',
      '#title' => t('Resubmission period settings per category'),
      '#description' => t('You can change the period in which archivable content is sent to the archiving status.'),
      '#open' => TRUE, // Controls the HTML5 'open' attribute. Defaults to FALSE.
    ];

    $form['markaspot_resubmission']['mailtext'] = array(
      '#type' => 'text',
      '#token_types' => array('site'),
      '#title' => $this->t('Mailtext'),
      '#default_value' => 'Mailtext',
    );

    foreach ($catOptions as $tid => $category_name) {
      $form['markaspot_resubmission']['days'][$tid] = [
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
    $this->config('markaspot_resubmission.settings')
      ->set('tax_status', $values['tax_status'])
      ->set('status_resubmissive', $values['status_resubmissive'])
      ->set('days', $values['days'])
      ->set('mailtext', $values['mailtext'])
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
      'markaspot_resubmission.settings',
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
