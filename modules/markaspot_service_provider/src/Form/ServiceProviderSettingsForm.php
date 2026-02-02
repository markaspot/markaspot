<?php

namespace Drupal\markaspot_service_provider\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Configure service provider settings for this site.
 */
class ServiceProviderSettingsForm extends ConfigFormBase
{

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
    public function __construct(EntityTypeManagerInterface $entity_type_manager)
    {
        $this->entityTypeManager = $entity_type_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('entity_type.manager')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'markaspot_service_provider_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return ['markaspot_service_provider.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('markaspot_service_provider.settings');

        $form['service_provider'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Service Provider Settings'),
        '#collapsible' => true,
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
        '#required' => false,
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
        '#default_value' => $config->get('allow_multiple_completions') ?? true,
        ];

        $form['service_provider']['eca_info'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('<strong>Email Notifications:</strong> Configure email notifications to service providers using ECA (Event-Condition-Action) rules, similar to the markaspot_resubmission module. Use the <code>getServiceProviderEmails()</code> method from the service provider service to retrieve email addresses.') . '</p>',
        ];

        // Cron-based notifications.
        $form['cron_notifications'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Cron-based Notifications'),
        '#collapsible' => true,
        '#description' => $this->t('Configure periodic notifications to service providers about pending requests.'),
        '#group' => 'settings',
        ];

        $form['cron_notifications']['enable_cron_notifications'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Cron Notifications'),
        '#description' => $this->t('If enabled, service providers will receive periodic notifications about pending service requests (requires cron to be running).'),
        '#default_value' => $config->get('enable_cron_notifications') ?? false,
        ];

        $form['cron_notifications']['cron_interval'] = [
        '#type' => 'select',
        '#title' => $this->t('Notification Interval'),
        '#description' => $this->t('How often to check for and send notifications to service providers.'),
        '#options' => [
        '900' => $this->t('Every 15 minutes'),
        '1800' => $this->t('Every 30 minutes'),
        '3600' => $this->t('Every hour'),
        '7200' => $this->t('Every 2 hours'),
        '21600' => $this->t('Every 6 hours'),
        '43200' => $this->t('Every 12 hours'),
        '86400' => $this->t('Once a day'),
        ],
        '#default_value' => $config->get('cron_interval') ?? 3600,
        '#states' => [
        'visible' => [
          ':input[name="enable_cron_notifications"]' => ['checked' => true],
        ],
        ],
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $this->config('markaspot_service_provider.settings')
            ->set('completion_status_tid', $form_state->getValue('completion_status_tid'))
            ->set('status_note', $form_state->getValue('status_note'))
            ->set('allow_multiple_completions', $form_state->getValue('allow_multiple_completions'))
            ->set('enable_cron_notifications', $form_state->getValue('enable_cron_notifications'))
            ->set('cron_interval', $form_state->getValue('cron_interval'))
            ->save();

        parent::submitForm($form, $form_state);
    }

    /**
     * Helper function to get status term options for select widget.
     *
     * @return array
     *   Select options for form
     */
    protected function getStatusTermOptions()
    {
        $options = ['' => $this->t('- None -')];

        try {
            $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
            $terms = $term_storage->loadTree('service_status');

            foreach ($terms as $term) {
                $options[$term->tid] = $term->name;
            }
        }
        catch (\Exception $e) {
            \Drupal::logger('markaspot_service_provider')->error(
                'Failed to load status terms: @error', [
                '@error' => $e->getMessage(),
                ]
            );
        }

        return $options;
    }

}
