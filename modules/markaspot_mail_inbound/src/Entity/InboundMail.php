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
 *     "access" = "Drupal\markaspot_mail_inbound\InboundMailAccessControlHandler",
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
   * Suggestion status: no AI suggestion requested or applicable.
   */
  public const SUGGESTION_NONE = 'none';

  /**
   * Suggestion status: queued for AI processing, not yet attempted.
   */
  public const SUGGESTION_PENDING = 'pending';

  /**
   * Suggestion status: AI suggestion completed successfully.
   */
  public const SUGGESTION_DONE = 'done';

  /**
   * Suggestion status: AI suggestion attempted but an error occurred.
   */
  public const SUGGESTION_FAILED = 'failed';

  /**
   * Suggestion status: skipped because a gate blocked the attempt.
   *
   * Gates: module setting, feature flag, token limit, service unavailability.
   */
  public const SUGGESTION_SKIPPED = 'skipped';

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

    $fields['suggested_category'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Suggested category'))
      ->setDescription(t('The AI-suggested service category term, or NULL when no suggestion is available yet.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default');

    $fields['suggestion_confidence'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Suggestion confidence'))
      ->setDescription(t('Confidence score (0–1) for the AI category suggestion. NULL for vision-path suggestions where no numeric confidence is available.'));

    $fields['suggestion_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Suggestion status'))
      ->setDescription(t('Lifecycle of the AI categorization attempt for this mail.'))
      ->setSetting('allowed_values', [
        self::SUGGESTION_NONE => t('None'),
        self::SUGGESTION_PENDING => t('Pending'),
        self::SUGGESTION_DONE => t('Done'),
        self::SUGGESTION_FAILED => t('Failed'),
        self::SUGGESTION_SKIPPED => t('Skipped'),
      ])
      ->setDefaultValue(self::SUGGESTION_NONE);

    $fields['suggested_address'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Suggested address'))
      ->setDescription(t('NER-extracted address from the AI text-classification path; passed to the promoter for geocoding.'))
      ->setSetting('max_length', 500);

    $fields['suggested_description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Suggested description'))
      ->setDescription(t('AI-generated neutral problem description for the public report, without salutation, sign-off, names or other PII. Used as the promoted node body when non-empty; falls back to the original mail text.'));

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

  /**
   * Returns the AI-suggested category term id, or NULL when absent.
   */
  public function getSuggestedCategoryTid(): ?int {
    $target_id = $this->get('suggested_category')->target_id ?? NULL;
    return $target_id !== NULL ? (int) $target_id : NULL;
  }

  /**
   * Sets the suggested category term id.
   */
  public function setSuggestedCategoryTid(?int $tid): self {
    $this->set('suggested_category', $tid !== NULL ? ['target_id' => $tid] : NULL);
    return $this;
  }

  /**
   * Returns the suggestion confidence (0–1), or NULL when not available.
   */
  public function getSuggestionConfidence(): ?float {
    $value = $this->get('suggestion_confidence')->value;
    return $value !== NULL ? (float) $value : NULL;
  }

  /**
   * Sets the suggestion confidence.
   */
  public function setSuggestionConfidence(?float $confidence): self {
    $this->set('suggestion_confidence', $confidence);
    return $this;
  }

  /**
   * Returns the suggestion status string.
   */
  public function getSuggestionStatus(): string {
    return (string) ($this->get('suggestion_status')->value ?? self::SUGGESTION_NONE);
  }

  /**
   * Sets the suggestion status.
   */
  public function setSuggestionStatus(string $status): self {
    $this->set('suggestion_status', $status);
    return $this;
  }

  /**
   * Returns the NER-extracted address from AI text classification, or NULL.
   */
  public function getSuggestedAddress(): ?string {
    $value = $this->get('suggested_address')->value;
    return ($value !== NULL && $value !== '') ? (string) $value : NULL;
  }

  /**
   * Sets the NER-extracted suggested address.
   */
  public function setSuggestedAddress(?string $address): self {
    $this->set('suggested_address', $address);
    return $this;
  }

  /**
   * Returns the AI-generated problem description, or NULL when not available.
   *
   * When non-empty, this is the description used for the public report body on
   * promotion; it is free of salutation, sign-off, names and other PII. The
   * citizen's original wording is always preserved as an internal remark.
   */
  public function getSuggestedDescription(): ?string {
    $value = $this->get('suggested_description')->value;
    return ($value !== NULL && $value !== '') ? (string) $value : NULL;
  }

  /**
   * Sets the AI-generated problem description.
   */
  public function setSuggestedDescription(?string $description): self {
    $this->set('suggested_description', $description);
    return $this;
  }

}
