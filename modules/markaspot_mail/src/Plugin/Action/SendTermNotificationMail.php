<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Sends the notification selected by a request's current status term.
 */
#[Action(
  id: 'markaspot_mail_send_term_notification',
  label: new TranslatableMarkup('Send status notification from term (Mark-a-Spot)'),
  type: 'system',
)]
final class SendTermNotificationMail extends NotificationMailActionBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['recipient'] = $this->recipientConfigurationElement();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof NodeInterface || !$entity->hasField('field_status')) {
      return;
    }

    $status_field = $entity->get('field_status');
    if (!$status_field instanceof EntityReferenceFieldItemListInterface || $status_field->isEmpty()) {
      return;
    }

    $status_terms = $status_field->referencedEntities();
    $status_term = reset($status_terms);
    if (!$status_term instanceof TermInterface || !$status_term->hasField('field_notification_key')) {
      return;
    }

    $notification_field = $status_term->get('field_notification_key');
    if ($notification_field->isEmpty()) {
      return;
    }

    $notification_key = trim((string) ($notification_field->getValue()[0]['value'] ?? ''));
    if ($notification_key === '') {
      return;
    }

    $this->sendNotification($entity, $notification_key);
  }

}
