<?php

namespace Drupal\markaspot_demo\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Mark-a-Spot Demo settings.
 */
class DemoSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markaspot_demo_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markaspot_demo.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markaspot_demo.settings');

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' .
        '<strong>WARNING:</strong> This module is for demo/development purposes only. ' .
        'It allows authentication with a fixed code (123456) which is a security risk. ' .
        '<strong>NEVER enable this module in production!</strong>' .
        '</div>',
    ];

    $form['demo_code_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Demo Code'),
      '#markup' => '<code style="font-size: 1.5em; padding: 10px; background: #f5f5f5; border-radius: 4px;">123456</code>',
      '#description' => $this->t('This fixed code works for all configured demo users.'),
    ];

    $demo_emails = $config->get('demo_emails') ?? [];

    $form['demo_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Demo User Emails'),
      '#default_value' => implode("\n", $demo_emails),
      '#description' => $this->t('Enter one email address per line. These users can authenticate with the demo code 123456.<br><strong>Leave empty</strong> to auto-detect users with administrator, moderator, or api_user roles.'),
      '#rows' => 10,
    ];

    // Show auto-detected users if no custom emails are configured.
    if (empty($demo_emails)) {
      $auto_detected = $this->getAutoDetectedUsers();
      if (!empty($auto_detected)) {
        $form['auto_detected'] = [
          '#type' => 'details',
          '#title' => $this->t('Auto-detected Demo Users'),
          '#open' => TRUE,
        ];
        $form['auto_detected']['list'] = [
          '#theme' => 'item_list',
          '#items' => array_map(function ($item) {
            return $item['email'] . ' (' . implode(', ', $item['roles']) . ')';
          }, $auto_detected),
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $emails_text = $form_state->getValue('demo_emails');
    $emails = array_filter(array_map('trim', explode("\n", $emails_text)));

    $this->config('markaspot_demo.settings')
      ->set('demo_emails', $emails)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get auto-detected demo users.
   *
   * @return array
   *   Array of user info with email and roles.
   */
  protected function getAutoDetectedUsers(): array {
    $users_info = [];
    $demo_roles = ['administrator', 'moderator', 'api_user'];

    try {
      $user_storage = \Drupal::entityTypeManager()->getStorage('user');

      foreach ($demo_roles as $role) {
        $users = $user_storage->loadByProperties(['roles' => $role, 'status' => 1]);
        foreach ($users as $user) {
          $mail = $user->getEmail();
          if ($mail) {
            if (!isset($users_info[$mail])) {
              $users_info[$mail] = [
                'email' => $mail,
                'roles' => [],
              ];
            }
            $users_info[$mail]['roles'][] = $role;
          }
        }
      }
    }
    catch (\Exception $e) {
      // Ignore errors.
    }

    return array_values($users_info);
  }

}
