<?php

namespace Drupal\markaspot_static_json\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Configure static_json settings for this site.
 */
class MarkaspotStaticJsonSettingsForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_static_json_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_static_json.settings');

    $form['markaspot_static_json'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Static JSON Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure the Static JSON process which is used to create low server load requests.json endpoint in the files directory'),
      '#group' => 'settings',
    ];

    $form['markaspot_static_json']['limit'] = [
      '#type' => 'number',
      '#default_value' => $config->get('limit') ? $config->get('limit') : 10,
      '#title' => $this->t('Limit'),
      "#length" => 3,
      '#description' => $this->t('You can configure a limit < 200'),

    ];
    $form['markaspot_static_json']['reset'] = [
      '#type' => 'checkbox',
      '#default_value' => $config->get('reset') ? $config->get('reset') : 0,
      '#title' => $this->t('Reset Cron calling Open311 requests at page 0'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    // var_dump($values);
    $this->config('markaspot_static_json.settings')
      ->set('limit', $values['limit'])
      ->set('reset', $values['reset'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_static_json.settings',
    ];
  }

}
