<?php

namespace Drupal\markaspot_feedback\Form;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 *
 */
class MarkaspotFeedbackForm extends FormBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_feedback_form';
  }

  /**
   *
   */
  public function isValid($uuid) {
    return \Drupal::service('markaspot_feedback.feedback')->get($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uuid = NULL) {
    $node = \Drupal::service('markaspot_feedback.feedback')->get($uuid);

    if ($this->isValid($uuid)) {
      // $form['feedback']['fieldset']
      $form['feedback'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Additional Feedback for') . ' ' . $node->title->value,
        '#description' => $this->t('Please add some additional feedback to this service-request.'),
        '#required' => TRUE,
        '#default_value' => $node->field_feedback->value ?: '',
        "#disabled" => isset($node->field_feedback->value) ?? FALSE,
      ];

      $form['uuid'] = [
        '#type' => 'hidden',
        '#value' => $uuid,
      ];
      $form['set_status'] = [
        '#title' => "Set Status to open",
        '#type' => 'checkbox',
        '#value' => 1,
        '#disabled' => isset($node->field_feedback->value) ?? 0,
        '#description' => $this->t('In case you want us to look after this again, you can re-open this service-request.'),
      ];

      $form['actions']['#type'] = 'actions';
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#button_type' => 'primary',
        '#disabled' => $node->field_feedback->value ?? 0,
      ];
      return $form;
    }
    else {
      throw new NotFoundHttpException();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    if (strlen($form_state->getValue('feedback')) < 5) {
      $form_state->setErrorByName('feedback', $this->t('Please add some valuable feedback.'));
    }
    if ($form_state->getValue('feedback_validate') == 1) {
      $form_state->setErrorByName('feedback', $this->t('Thank you, we already have an additional feedback for this service_request'));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $service = \Drupal::service('markaspot_feedback.feedback');
    $node = $service->get($form_state->getValue('uuid'));
    $node->field_feedback->value = $form_state->getValue('feedback');
    $node->field_status->target_id = 4;
    $new_status_note = $service->createParagraph();
    $node->field_status_notes[] = $new_status_note;

    $node->save();
  }

}
