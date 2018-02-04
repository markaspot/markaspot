<?php

namespace Drupal\markaspot_request_id\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotRequestIdSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_request_id_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_request_id.settings');

    $form['markaspot_request_id'] = array(
      '#type' => 'fieldset',
      '#title' => t('RequestID Settings'),
      '#collapsible' => TRUE,
      '#description' => t('Configure the ID and Title Settings'),
      '#group' => 'settings',
    );

    $form['markaspot_request_id']['format'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('PHP Date Format'),
      '#default_value' => $config->get('format'),
      '#description' => t('The format of the outputted date string, creating IDs like #1-2018'),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('markaspot_request_id.settings')
      ->set('format', $values['format'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_request_id.settings',
    ];
  }

}
