<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\node\NodeInterface;

/**
 * Defines the Inbound Mail staging entity.
 *
 * A parsed citizen email that has passed the security and spam gates but is
 * not yet a service request. It lives in a triage inbox where a moderator
 * categorizes it (which promotes it to a service_request through the existing
 * Open311 GeoreportProcessor path) or discards it. Reply threading appends to
 * a staged mail or, when the parent was already promoted, to the service
 * request node.
 *
 * Purely additive: it does not touch the service_request, web or Open311
 * paths. The promotion flow only CALLS the existing processor service.
 *
 * Binary attachments are persisted as managed files (attachment_files) while
 * the mail is staged, so a photo survives until promotion turns it into a
 * request_image media entity.
 *
 * @ContentEntityType(
 *   id = "inbound_mail",
 *   label = @Translation("Inbound Mail"),
 *   label_collection = @Translation("Inbound Mail Triage"),
 *   base_table = "inbound_mail",
 *   admin_permission = "triage inbound mail",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "subject",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\markaspot_mail_inbound\InboundMailListBuilder",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "collection" = "/admin/mark-a-spot/inbound-mail",
 *   },
 * )
 */
class InboundMail extends ContentEntityBase {

  /**
   * Staged: awaiting moderator triage.
   */
  public const STATE_STAGED = 'staged';

  /**
   * Promoted: turned into a service request node (see nid).
   */
  public const STATE_PROMOTED = 'promoted';

  /**
   * Discarded: a moderator rejected it as not a report.
   */
  public const STATE_DISCARDED = 'discarded';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['from_address'] = BaseFieldDefinition::create('email')
      ->setLabel(t('From address'))
      ->setDescription(t('The sender email address.'))
      ->setRequired(TRUE);

    $fields['from_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('From name'))
      ->setDescription(t('The decoded sender display name.'))
      ->setSetting('max_length', 255);

    $fields['subject'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Subject'))
      ->setDescription(t('The sanitized email subject.'))
      ->setSetting('max_length', 255);

    $fields['body'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Body'))
      ->setDescription(t('The plain-text body of the email (and any threaded replies).'));

    $fields['message_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Message-ID'))
      ->setDescription(t('The normalized Message-ID of the originating email.'))
      ->setSetting('max_length', 998);

    $fields['thread_message_ids'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Thread Message-IDs'))
      ->setDescription(t('All Message-IDs that thread onto this mail (its own and any replies).'))
      ->setSetting('max_length', 998)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('State'))
      ->setDescription(t('Triage state of the inbound mail.'))
      ->setSetting('allowed_values', [
        self::STATE_STAGED => t('Staged'),
        self::STATE_PROMOTED => t('Promoted'),
        self::STATE_DISCARDED => t('Discarded'),
      ])
      ->setDefaultValue(self::STATE_STAGED)
      ->setRequired(TRUE);

    $fields['mailbox_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Mailbox id'))
      ->setDescription(t('The configured mailbox that received this mail.'))
      ->setSetting('max_length', 255);

    $fields['jurisdiction_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Jurisdiction'))
      ->setDescription(t('The jurisdiction group this mail belongs to.'))
      ->setSetting('target_type', 'group')
      ->setSetting('handler', 'default');

    $fields['nid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Service request'))
      ->setDescription(t('The service request node created when this mail was promoted.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default');

    $fields['attachment_files'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Attachment files'))
      ->setDescription(t('Binary attachments persisted as managed files while staged.'))
      ->setSetting('target_type', 'file')
      ->setSetting('handler', 'default')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Received'))
      ->setDescription(t('When the mail was staged.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('When the mail was last updated.'));

    return $fields;
  }

  /**
   * Returns the sender email address.
   */
  public function getFromAddress(): string {
    return (string) $this->get('from_address')->value;
  }

  /**
   * Returns the sanitized subject.
   */
  public function getSubject(): string {
    return (string) $this->get('subject')->value;
  }

  /**
   * Returns the plain-text body.
   */
  public function getBody(): string {
    return (string) $this->get('body')->value;
  }

  /**
   * Returns the triage state.
   */
  public function getState(): string {
    return (string) $this->get('state')->value;
  }

  /**
   * Sets the triage state.
   */
  public function setState(string $state): self {
    $this->set('state', $state);
    return $this;
  }

  /**
   * Returns the configured mailbox id.
   */
  public function getMailboxId(): string {
    return (string) $this->get('mailbox_id')->value;
  }

  /**
   * Returns the jurisdiction group id, or 0 when none is set.
   */
  public function getJurisdictionId(): int {
    return (int) ($this->get('jurisdiction_id')->target_id ?? 0);
  }

  /**
   * Returns all Message-IDs threaded onto this mail.
   *
   * @return string[]
   *   Non-empty, unique Message-IDs (the mail's own plus all reply ids).
   */
  public function getThreadMessageIds(): array {
    $ids = [];
    foreach ($this->get('thread_message_ids') as $item) {
      $value = trim((string) $item->value);
      if ($value !== '') {
        $ids[] = $value;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Returns the file ids of the persisted attachments.
   *
   * @return int[]
   *   The managed file ids.
   */
  public function getAttachmentFileIds(): array {
    $ids = [];
    foreach ($this->get('attachment_files') as $item) {
      if (!empty($item->target_id)) {
        $ids[] = (int) $item->target_id;
      }
    }
    return $ids;
  }

  /**
   * Returns the promoted service request node, or NULL when not promoted.
   */
  public function getServiceRequest(): ?NodeInterface {
    $node = $this->get('nid')->entity;
    return $node instanceof NodeInterface ? $node : NULL;
  }

}
