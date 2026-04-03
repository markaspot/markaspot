<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Mark-a-Spot content moderation settings.
 *
 * Allows administrators to set the flag threshold for auto-hiding content
 * and configure which flag reasons trigger immediate DSA notifications.
 */
class ModerationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_moderation_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['markaspot_moderation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('markaspot_moderation.settings');

    $form['flag_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Flag threshold'),
      '#description' => $this->t('Number of active flags required to automatically hide (unpublish) a service request.'),
      '#default_value' => $config->get('flag_threshold') ?? 3,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['immediate_notify_reasons'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Immediate notification reasons'),
      '#description' => $this->t('Flag reasons that trigger an immediate email notification to the jurisdiction admin and site admin (DSA Article 16 compliance).'),
      '#options' => [
        'spam' => $this->t('Spam'),
        'offensive' => $this->t('Offensive content'),
        'personal' => $this->t('Personal data exposure'),
        'location' => $this->t('Incorrect location'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => $config->get('immediate_notify_reasons') ?? [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $immediateReasons = array_values(array_filter(
      $form_state->getValue('immediate_notify_reasons')
    ));

    $this->config('markaspot_moderation.settings')
      ->set('flag_threshold', (int) $form_state->getValue('flag_threshold'))
      ->set('immediate_notify_reasons', $immediateReasons)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
