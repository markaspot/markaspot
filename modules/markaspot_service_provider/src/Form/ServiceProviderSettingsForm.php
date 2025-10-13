<?php

namespace Drupal\markaspot_service_provider\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Configure service provider settings for this site.
 */
class ServiceProviderSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ServiceProviderSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_service_provider_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markaspot_service_provider.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_service_provider.settings');

    $form['service_provider'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Service Provider Settings'),
      '#collapsible' => TRUE,
      '#description' => $this->t('Configure how service providers interact with service requests.'),
      '#group' => 'settings',
    ];

    // Completion status configuration.
    $form['service_provider']['completion_status_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Completion Status'),
      '#description' => $this->t('The status to set when a service provider marks a request as completed.'),
      '#options' => $this->getStatusTermOptions(),
      '#default_value' => $config->get('completion_status_tid'),
      '#required' => FALSE,
    ];

    // Status note configuration.
    $form['service_provider']['status_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Completion Status Note'),
      '#description' => $this->t('Optional status note to add when a service provider completes a request. Supports tokens.'),
      '#default_value' => $config->get('status_note'),
      '#rows' => 3,
    ];

    // Reassignment configuration.
    $form['service_provider']['allow_multiple_completions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow Multiple Completions'),
      '#description' => $this->t('If enabled, service providers can add multiple completion entries to a single request (useful for multi-step work or reassignments).'),
      '#default_value' => $config->get('allow_multiple_completions') ?? TRUE,
    ];

    // Email notification settings.
    $form['service_provider']['notification'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Notification Settings'),
      '#open' => TRUE,
    ];

    $form['service_provider']['notification']['mail_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email Subject'),
      '#description' => $this->t('Subject line for service provider notification emails. Supports tokens.'),
      '#default_value' => $config->get('mail_subject') ?: 'Service Request Assignment: [node:title]',
      '#maxlength' => 255,
    ];

    $form['service_provider']['notification']['mailtext'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email Body'),
      '#description' => $this->t('Email body for service provider notifications. Supports tokens.'),
      '#default_value' => $config->get('mailtext') ?: 'A new service request has been assigned to you.',
      '#rows' => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('markaspot_service_provider.settings')
      ->set('completion_status_tid', $form_state->getValue('completion_status_tid'))
      ->set('status_note', $form_state->getValue('status_note'))
      ->set('allow_multiple_completions', $form_state->getValue('allow_multiple_completions'))
      ->set('mail_subject', $form_state->getValue('mail_subject'))
      ->set('mailtext', $form_state->getValue('mailtext'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Helper function to get status term options for select widget.
   *
   * @return array
   *   Select options for form
   */
  protected function getStatusTermOptions() {
    $options = ['' => $this->t('- None -')];

    try {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $terms = $term_storage->loadTree('service_status');

      foreach ($terms as $term) {
        $options[$term->tid] = $term->name;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('markaspot_service_provider')->error('Failed to load status terms: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $options;
  }

}
