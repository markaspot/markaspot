<?php

namespace Drupal\markaspot_ui\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Mark-a-Spot UI settings.
 */
class MarkaspotUiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markaspot_ui_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['markaspot_ui.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('markaspot_ui.settings');

    $form['headless_mode_protection'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable headless mode protection'),
      '#description' => $this->t(
        '<strong>For headless/decoupled setups:</strong> When enabled, anonymous users accessing Drupal UI paths (admin, node pages, etc.) will be redirected to /user/login. This is ideal when using Mark-a-Spot with a separate frontend (like Nuxt) where all content display happens through the frontend application.<br><br><strong>For traditional Drupal themes:</strong> Leave this disabled if you are building a traditional Drupal theme where content is displayed directly through Drupal\'s routing system.<br><br><strong>Technical details:</strong> This feature protects paths like /admin, /node, /taxonomy, etc. while preserving JSON:API access (/jsonapi/*) and authentication endpoints. All authenticated users can access all paths regardless of this setting.'
      ),
      '#default_value' => $config->get('headless_mode_protection') ?? FALSE,
    ];

    $form['login_redirect'] = [
      '#type' => 'details',
      '#title' => $this->t('Login redirect settings'),
      '#open' => TRUE,
    ];

    $form['login_redirect']['login_redirect_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic redirect after login'),
      '#description' => $this->t('When enabled, users will be automatically redirected to a specific path after successful login. Does not affect password reset flow.'),
      '#default_value' => $config->get('login_redirect_enabled') ?? FALSE,
    ];

    $form['login_redirect']['login_redirect_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect path'),
      '#description' => $this->t('The path to redirect to after login (e.g., /admin/content/management). Leave empty to use the default.'),
      '#default_value' => $config->get('login_redirect_path') ?? '/admin/content/management',
      '#states' => [
        'visible' => [
          ':input[name="login_redirect_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('About headless mode protection'),
      '#open' => FALSE,
    ];

    $form['info']['content'] = [
      '#markup' => $this->t('
        <h3>What paths are protected?</h3>
        <ul>
          <li><code>/admin</code> - All administration pages</li>
          <li><code>/node</code> - All node pages (view, edit, add, delete)</li>
          <li><code>/user/register</code> - User registration</li>
          <li><code>/comment</code> - Comment pages</li>
          <li><code>/group</code> - Group pages</li>
          <li><code>/media</code> - Media management</li>
          <li><code>/taxonomy</code> - Taxonomy management</li>
        </ul>
        <h3>What paths are NOT affected?</h3>
        <ul>
          <li><code>/jsonapi/*</code> - JSON:API endpoints (required for headless frontend)</li>
          <li><code>/user/login</code> - Login page</li>
          <li><code>/user/password</code> - Password reset</li>
          <li><code>/user/logout</code> - Logout</li>
          <li>Any custom routes you define</li>
        </ul>
        <h3>Default behavior</h3>
        <p>This feature is <strong>disabled by default</strong> to maintain backward compatibility with existing Mark-a-Spot installations using traditional Drupal themes.</p>
      '),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate redirect path if enabled.
    if ($form_state->getValue('login_redirect_enabled')) {
      $path = $form_state->getValue('login_redirect_path');

      if (empty($path)) {
        $form_state->setErrorByName('login_redirect_path', $this->t('Redirect path is required when login redirect is enabled.'));
        return;
      }

      // Validate it's an internal path.
      try {
        $url = \Drupal\Core\Url::fromUserInput($path);
        if ($url->isExternal()) {
          $form_state->setErrorByName('login_redirect_path', $this->t('Redirect path must be an internal path starting with /.'));
        }
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('login_redirect_path', $this->t('Invalid redirect path: @error', ['@error' => $e->getMessage()]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('markaspot_ui.settings')
      ->set('headless_mode_protection', $form_state->getValue('headless_mode_protection'))
      ->set('login_redirect_enabled', $form_state->getValue('login_redirect_enabled'))
      ->set('login_redirect_path', $form_state->getValue('login_redirect_path'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
