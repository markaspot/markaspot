<?php

namespace Drupal\markaspot_validation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure georeport settings for this site.
 */
class MarkaspotValidationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_validation_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_validation.settings');
    $form['markaspot_validation'] = array(
      '#type' => 'fieldset',
      '#title' => t('Validation Types'),
      '#collapsible' => TRUE,
      '#description' => t('This setting allow you too choose a map tile operator of your choose. Be aware that you have to apply the same for the Geolocation Field settings</a>, too.'),
      '#group' => 'settings',
    );

    $form['markaspot_validation']['wkt'] = array(
      '#type' => 'textarea',
      '#title' => t('Polygon in WKT Format'),
      '#default_value' => $config->get('wkt'),
      '#description' => t('Place your polygon wkt here. You can <a href="@wkt-editor">create and edit</a> the vectors online. Leave this empty, if you don\'t need polygon validation.', ['@wkt-editor' => 'https://arthur-e.github.io/Wicket/sandbox-gmaps3.html']),
    );
    $form['markaspot_validation']['duplicate_check'] = array(
      '#type' => 'checkbox',
      '#title' => t('Duplicate Request check enabled'),
      '#default_value' => $config->get('duplicate_check'),
      '#description' => t('Check if new requests get a duplicate check.'),
    );

    $form['markaspot_validation']['radius'] = array(
      '#type' => 'textfield',
      '#title' => t('Duplicate Request check Radius'),
      '#default_value' => $config->get('radius'),
      '#description' => t('Validate if new requests are possible duplicates within this radius.'),
    );

    $form['markaspot_validation']['unit'] = array(
      '#type' => 'radios',
      '#title' => t('Duplicate Radius Unit'),
      '#default_value' => $config->get('unit'),
      '#options' => array(
        'meters' => t('Meters'),
        'yards' => t('Yards'),
      ),
      '#description' => t('Validate if new requests are possible duplicates within this radius.'),
    );

    $form['markaspot_validation']['hint'] = array(
      '#type' => 'checkbox',
      '#title' => t('Validate duplicates only as a hint'),
      '#default_value' => $config->get('hint'),
      '#description' => t('Users can ignore this validation note by resubmitting the report form.'),
    );
    $form['markaspot_validation']['treshold'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#title' => t('Iterations treshold'),
      '#default_value' => $config->get('treshold'),
      '#description' => t('Increase this number if you think that validation notes are too few.'),
    );
    $form['markaspot_validation']['days'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#title' => t('Duplicate reach back in days'),
      '#default_value' => $config->get('days'),
      '#description' => t('How many days to reach back for similar requests'),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    $wkt = trim($form_state->getValue('wkt'));

    if (!empty($valid)) {
      $form_state->set('wkt', $wkt);

    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('markaspot_validation.settings')
      ->set('wkt', $values['wkt'])
      ->set('duplicate_check', $values['duplicate_check'])
      ->set('radius', $values['radius'])
      ->set('unit', $values['unit'])
      ->set('days', $values['days'])
      ->set('hint', $values['hint'])
      ->set('treshold', $values['treshold'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'markaspot_validation.settings',
    ];
  }

}
