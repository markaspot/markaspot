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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('markaspot_ui.settings')
      ->set('headless_mode_protection', $form_state->getValue('headless_mode_protection'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
