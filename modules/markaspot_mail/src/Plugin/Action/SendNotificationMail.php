<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Sends a Mark-a-Spot notification mail (ECA action).
 *
 * Drop-in replacement for core's action_send_email_action inside ECA
 * workflows: instead of a hardcoded subject/message pair authored in the
 * BPMN editor, the operator picks one of the markaspot_mail.texts keys
 * (report_confirmation, status_open, status_closed, status_not_responsible)
 * and the wording is resolved at send time via NotificationTextBuilder,
 * admin-editable at /admin/config/markaspot/mail-texts with per-locale
 * overrides through core Config Translation.
 *
 * Discovered by ECA the same way action_send_email_action is: as a plain
 * core \Drupal\Core\Action\ConfigurableActionBase plugin, not an
 * eca-namespaced one. ECA's Actions service (Drupal\eca\Service\Actions)
 * lists every plugin.manager.action definition generically, so no
 * ECA-specific base class or registration is required.
 *
 * Mirrors EmailAction::execute() (\Drupal\Core\Action\Plugin\Action\
 * EmailAction) for recipient token resolution: $this->configuration is
 * passed as Token::replace() data after seeding its 'node' key from the
 * acted-upon entity, so [node:field_e_mail:value] and friends resolve.
 * Unlike EmailAction, this plugin never assembles subject/body itself —
 * that is NotificationTextBuilder's job, reached via the standard
 * markaspot_mail hook_mail_alter dispatch after mailManager->mail() calls
 * markaspot_mail_mail() (hook_mail, unbranded fallback) and then
 * hook_mail_alter (branded render).
 */
#[Action(
  id: 'markaspot_mail_send_notification',
  label: new TranslatableMarkup('Send notification email (Mark-a-Spot)'),
  type: 'system',
)]
final class SendNotificationMail extends NotificationMailActionBase {

  /**
   * Human-readable labels for the notification keys shipped in this phase.
   *
   * Purely a UX nicety for the select widget; any future markaspot_mail
   * .texts key not listed here still shows up (using its raw machine
   * name as the label) because getNotificationKeyOptions() enumerates the
   * live config rather than this list.
   */
  private const KNOWN_KEY_LABELS = [
    'report_confirmation' => 'Report confirmation',
    'status_open' => 'Status update: open / in progress',
    'status_closed' => 'Status update: closed',
    'status_not_responsible' => 'Status update: not responsible',
  ];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'notification_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['notification_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Notification'),
      '#options' => $this->getNotificationKeyOptions(),
      '#default_value' => $this->configuration['notification_key'],
      '#required' => TRUE,
      '#description' => $this->t('Wording is admin-editable at Mark-a-Spot notification mail texts, not authored here.'),
    ];
    $form['recipient'] = $this->recipientConfigurationElement();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['notification_key'] = (string) $form_state->getValue('notification_key');
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (empty($this->configuration['node'])) {
      $this->configuration['node'] = $entity;
    }

    if (!$entity instanceof NodeInterface) {
      $this->logger->warning('markaspot_mail_send_notification: acted-upon entity is not a node, skipping.');
      return;
    }

    $notificationKey = trim((string) $this->configuration['notification_key']);
    if ($notificationKey === '') {
      $this->logger->warning('markaspot_mail_send_notification: notification_key is not configured, skipping (node @nid).', ['@nid' => $entity->id()]);
      return;
    }

    $this->sendNotification($entity, $notificationKey);
  }

  /**
   * Builds the notification_key select options from live config.
   *
   * @return array<string, string>
   *   Machine key => label, keyed by every top-level key currently present
   *   in markaspot_mail.texts (excluding the langcode sibling key).
   */
  private function getNotificationKeyOptions(): array {
    $rawKeys = array_keys((array) $this->configFactory->get('markaspot_mail.texts')->get());
    $options = [];
    foreach ($rawKeys as $key) {
      if (!is_string($key) || $key === 'langcode') {
        continue;
      }
      $options[$key] = self::KNOWN_KEY_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
    return $options;
  }

}
