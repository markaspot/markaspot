<?php

namespace Drupal\markaspot_request_id\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotRequestIdSettingsForm extends ConfigFormBase {

  use StringTranslationTrait;

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

    $form['markaspot_request_id'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('RequestID Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure the ID and Title Settings'),
      '#group' => 'settings',
    ];

    $form['markaspot_request_id']['rollover'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serial ID Rollover'),
      '#description' => $this->t('Check if serial id shall rollover every year'),
      '#default_value' => $config->get('rollover'),
    ];

    // $year_end = mktime(23, 59, 59, 12, 31, date('Y') - 1);
    // $previous_year = date('Y') - 1;
    $form['markaspot_request_id']['start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Next Rollover Date'),
      '#description' => $this->t('Force Rollover earlier than this date'),
    ];

    $form['markaspot_request_id']['format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PHP Date Format'),
      '#default_value' => $config->get('format') ?? 'Y',
      '#description' => $this->t('The format of the outputted date string, creating IDs like #1-2018'),
    ];

    $form['markaspot_request_id']['delimiter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delimiter'),
      '#default_value' => $config->get('delimiter') ?? '-',
      '#description' => $this->t('The delimiter between serial id and date pattern like #1-2018'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('markaspot_request_id.settings')
      ->set('format', $values['format'])
      ->set('rollover', $values['rollover'])
      ->set('delimiter', $values['delimiter'])
      ->save();
    if (!empty($values['start'])) {
      $this->config('markaspot_request_id.settings')
        ->set('start', $values['start']->__toString())
        ->save();
    }
    else {
      $this->config('markaspot_request_id.settings')
        ->set('start', NULL)
        ->save();
    }

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
