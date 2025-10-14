<?php

namespace Drupal\markaspot_service_provider\Form;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for service providers to respond to service requests.
 */
class ServiceProviderResponseForm extends FormBase
{

    use StringTranslationTrait;

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'markaspot_service_provider_response_form';
    }

    /**
     * Check if UUID is valid.
     */
    public function isValid($uuid)
    {
        $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]);
        return !empty($nodes) ? reset($nodes) : false;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state, $uuid = null)
    {
        $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]);
        $node = !empty($nodes) ? reset($nodes) : null;

        if (!$this->isValid($uuid)) {
            throw new NotFoundHttpException();
        }

        // Check for existing completions.
        $existing_completions = $node->hasField('field_service_provider_notes')
        ? $node->get('field_service_provider_notes')->getValue()
        : [];

        $reassignment_allowed = false;
        if ($node->hasField('field_reassign_sp') && !$node->get('field_reassign_sp')->isEmpty()) {
            $reassignment_allowed = $node->get('field_reassign_sp')->value;
        }

        $form['completion_notes'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Completion Notes for') . ' ' . $node->title->value,
        '#description' => $this->t('Please describe the work that has been completed for this service request.'),
        '#required' => true,
        '#disabled' => !empty($existing_completions) && !$reassignment_allowed,
        ];

        if (!empty($existing_completions)) {
            $form['existing_notes'] = [
            '#type' => 'details',
            '#title' => $this->t('Previous Completions'),
            '#open' => true,
            ];

            foreach ($existing_completions as $index => $completion) {
                $form['existing_notes']['completion_' . $index] = [
                '#type' => 'markup',
                '#markup' => '<div class="service-provider-completion">' . $completion['value'] . '</div>',
                ];
            }

            if (!$reassignment_allowed) {
                \Drupal::messenger()->addWarning($this->t('This service request has already been completed. Further completions require administrator approval.'));
            }
        }

        $form['uuid'] = [
        '#type' => 'hidden',
        '#value' => $uuid,
        ];

        $form['set_status'] = [
        '#title' => $this->t('Mark as completed'),
        '#type' => 'checkbox',
        '#default_value' => $form_state->getValue('set_status') ?? 1,
        '#description' => $this->t('Update the service request status to completed.'),
        '#disabled' => !empty($existing_completions) && !$reassignment_allowed,
        ];

        $form['actions']['#type'] = 'actions';
        $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Submit Completion'),
        '#button_type' => 'primary',
        '#disabled' => !empty($existing_completions) && !$reassignment_allowed,
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        if (strlen($form_state->getValue('completion_notes')) < 10) {
            $form_state->setErrorByName('completion_notes', $this->t('Please provide detailed completion notes (at least 10 characters).'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        // Service submission is typically handled via API
        // This provides a simple form-based fallback
        \Drupal::messenger()->addStatus($this->t('Thank you for completing this service request.'));
    }

}
