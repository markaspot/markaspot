<?php

namespace Drupal\markaspot_privacy\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Class GDPRForm.
 */
class GDPRForm extends FormBase {


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'default_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uuid = NULL) {
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Please confirm the deletion of this content.'),
      '#description' => $this->t('All content including the user-data will be deleted.'),
      '#default_value' => 1,
      "#required" => TRUE,
    ];

    $form['uuid'] = [
      '#type' => 'hidden',
      '#value' => $uuid,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Display result.
    foreach ($form_state->getValues() as $key => $value) {
      $uuid = $form_state->getValue('uuid');
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]);

    if (empty($node)) {
      drupal_set_message($this->t("Sorry, we can't find the content requested. Maybe this has been deleted already."), 'error');
    }
    else {

      // we only have one node as loaded by uuid.
      $node = reset($node);
      $node->setPublished(FALSE);
      $node->set('field_e_mail', "anonymous@example.off");
      $node->save();

      $title = $node->title->value;

      drupal_set_message($this->t('The service request "@title" has been removed from the system.', ['@title' => $title]),'info');


      \Drupal::logger('markaspot_privacy')->notice('User deleted %title.',
        ['%title' => $title]);

      $form_state->setRedirect('<front>');

    }

  }

}
