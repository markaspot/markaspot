<?php

namespace Drupal\markaspot_resubmission\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Resubmission Reminder entity.
 *
 * @ContentEntityType(
 *   id = "resubmission_reminder",
 *   label = @Translation("Resubmission Reminder"),
 *   base_table = "resubmission_reminder",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 * )
 */
class ResubmissionReminder extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['nid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Service Request'))
      ->setDescription(t('The service request node this reminder is for.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE);

    $fields['sent_timestamp'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Sent Timestamp'))
      ->setDescription(t('When the reminder was sent.'))
      ->setRequired(TRUE);

    $fields['recipient_email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Recipient Email'))
      ->setDescription(t('The email address the reminder was sent to.'))
      ->setRequired(TRUE);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('The status of the reminder (sent, failed, etc).'))
      ->setSettings([
        'max_length' => 50,
        'default_value' => 'sent',
      ])
      ->setRequired(TRUE);

    $fields['reminder_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Reminder Count'))
      ->setDescription(t('The sequence number of this reminder (1st, 2nd, etc).'))
      ->setDefaultValue(1)
      ->setRequired(TRUE);

    $fields['node_status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Node Status at Time of Reminder'))
      ->setDescription(t('The status of the service request when reminder was sent.'))
      ->setSettings([
        'max_length' => 255,
      ]);

    $fields['error_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Error Message'))
      ->setDescription(t('Error message if the reminder failed to send.'));

    return $fields;
  }

  /**
   * Get the node ID this reminder is for.
   *
   * @return int
   *   The node ID.
   */
  public function getNodeId() {
    return $this->get('nid')->target_id;
  }

  /**
   * Get the sent timestamp.
   *
   * @return int
   *   The timestamp.
   */
  public function getSentTimestamp() {
    return $this->get('sent_timestamp')->value;
  }

  /**
   * Get the recipient email.
   *
   * @return string
   *   The email address.
   */
  public function getRecipientEmail() {
    return $this->get('recipient_email')->value;
  }

  /**
   * Get the reminder count.
   *
   * @return int
   *   The reminder count.
   */
  public function getReminderCount() {
    return $this->get('reminder_count')->value;
  }

  /**
   * Get the status.
   *
   * @return string
   *   The status.
   */
  public function getStatus() {
    return $this->get('status')->value;
  }

}
