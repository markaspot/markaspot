<?php
/**
 * @file
 * Contains \Drupal\markaspot_feedback\Form\MarkaspotFeedbackForm.
 */
namespace Drupal\markaspot_feedback\Form;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;



class MarkaspotFeedbackForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_feedback_form';
  }

  public function isValid($uuid){
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
        '#title' => t('Additional Feedback'),
        '#required' => TRUE,
        '#value' => $node->field_feedback->value,
        "#disabled" => isset($node->field_feedback->value) ?? 0
      ];

      $form['uuid'] = array(
        '#type' => 'hidden',
        '#value' => $uuid,
      );
      $form['set_status'] = array(
        '#title' => "Set Status to open",
        '#type' => 'checkbox',
        '#value' => 1,
      );


      $form['actions']['#type'] = 'actions';
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#button_type' => 'primary',
        '#disabled' => isset($node->field_feedback->value) ?? 0
      ];
      return $form;
    } else {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
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

    $node = \Drupal::service('markaspot_feedback.feedback')->get($form_state->getValue('uuid'));
    $node->field_feedback->value  = $form_state->getValue('feedback') ;
    $new_status_note = \Drupal::service('markaspot_feedback.feedback')->createParagraph();
    $node->field_status_notes[] = $new_status_note;

    $node->save();
  }

}
