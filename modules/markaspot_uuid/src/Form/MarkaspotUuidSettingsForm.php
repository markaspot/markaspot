<?php

namespace Drupal\markaspot_uuid\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotUuidSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_uuid_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_uuid.settings');

    $form['markaspot_uuid'] = array(
      '#type' => 'fieldset',
      '#title' => t('UUID Settings'),
      '#collapsible' => TRUE,
      '#description' => t('Configure the ID and Title Settings (status, publishing settings and Georeport v2 Service Discovery). See http://wiki.open311.org/Service_Discovery. This service discovery specification is meant to be read-only and can be provided either dynamically or using a manually edited static file.'),
      '#group' => 'settings',
    );

    $form['markaspot_uuid']['format'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('PHP Date Format'),
      '#default_value' => $config->get('format'),
      '#description' => t('The format of the outputted date string'),
    );

    $form['markaspot_uuid']['offset'] = array(
      '#type' => 'number',
      '#title' => $this->t('Offset'),
      '#default_value' => $config->get('offset'),
      '#description' => t('Start counting service requests from zero by defining an offset'),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('markaspot_uuid.settings')
      ->set('format', $values['format'])
      ->set('offset', $values['offset'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_uuid.settings',
    ];
  }

}
