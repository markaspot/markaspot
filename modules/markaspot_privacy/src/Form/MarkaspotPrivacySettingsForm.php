<?php

namespace Drupal\markaspot_privacy\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotPrivacySettingsForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_privacy_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_privacy.settings');
    $form['markaspot_privacy'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Type of GDPR checkbox'),
      '#collapsible' => TRUE,
      '#description' => $this->t('The setting allows to choose between saving user confimation to field or rely on form input only.'),
      '#group' => 'settings',
    ];

    $form['markaspot_privacy']['field_save'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Safe user input with service request.'),
      '#default_value' => $config->get('field_save'),
      '#description' => $this->t('Use Drupal fields for saving checked value to database.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_privacy.settings')
      ->set('field_save', $values['field_save'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_privacy.settings',
    ];
  }

}
