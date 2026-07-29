<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\RequestFieldValueTrait;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Mail\SplitParagraphsTrait;
use Drupal\markaspot_mail\Service\AttachmentResolver;
use Psr\Log\LoggerInterface;

/**
 * Builder for Drupal-core EmailAction mails driven by ECA workflows.
 *
 * Markaspot_group ships four ECA processes (process_confirm_report,
 * process_apply_group, process_tunr6d6 status flow, process_ugsohtl
 * welcome flow) that all fire the core action_send_email_action plugin.
 * That plugin routes every mail through module=system, key=action_send_email,
 * with the admin-configured subject + message already token-replaced by
 * system_mail() before hook_mail_alter runs.
 *
 * This builder takes the already-finalized subject + body from the
 * MailContext, resolves the jurisdiction from the acted-upon entity
 * (shipped in $ctx->params['context']['node'] by EmailAction::execute()),
 * and wraps the verbatim ECA copy in a card_transactional body so the
 * recipient sees the same wording inside branded HTML. We do not attempt
 * to re-author or re-paragraphize the admin's copy: the ECA editor is
 * the source of truth for wording, we only own the chrome.
 *
 * No CTA extraction: ECA bodies rarely carry a canonical single CTA, and
 * guessing from URL patterns in the plaintext is fragile. If operators
 * want a button they can migrate the workflow to a concrete builder in
 * a later stage.
 *
 * The builder returns NULL when $params['context'] is missing or has no
 * subject, which keeps the dispatcher from turning accidental
 * system:action_send_email sends (non-ECA, no entity) into broken cards.
 */
final class EcaActionEmailBuilder implements MailBuilderInterface {

  use ResolveJurisdictionFromNodeTrait;
  use RequestFieldValueTrait;
  use SplitParagraphsTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly AttachmentResolver $attachmentResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_ACTION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'system' && $key === 'action_send_email';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $context = $ctx->params['context'] ?? NULL;
    if (!is_array($context) || empty($context['subject'])) {
      // Not an ECA-driven EmailAction — the mail arrived through another
      // code path that routes via system:action_send_email without the
      // $params['context'] envelope. Pass through unbranded.
      return NULL;
    }

    $subject = trim($ctx->subject);
    if ($subject === '') {
      $this->logger->notice('ECA action mail has empty subject after system_mail; skipping branded render.');
      return NULL;
    }

    $body = $this->extractBody($ctx);
    if ($body === '') {
      $this->logger->notice('ECA action mail has empty body after system_mail; skipping branded render.');
      return NULL;
    }

    $paragraphs = $this->splitParagraphs($body);
    $intro = array_shift($paragraphs) ?? '';
    $bodyBlocks = $paragraphs;

    $entity = $context['node'] ?? $context['entity'] ?? NULL;
    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromEntity(
      $entity instanceof ContentEntityInterface ? $entity : NULL
    );

    // Auto-attach citizen uploads whenever the recipient is NOT the
    // reporter. Confirmation and status-update mails from ECA routes
    // go back to the person who filed the report — they already have
    // their own files. Org / head-organisation / jurisdiction-staff
    // recipients, on the other hand, need the files to act on the
    // ticket, and we would otherwise require every BPMN workflow to
    // opt in manually. Bundle-gated to node/service_request so
    // accidental ECA workflows against user/comment entities can't
    // trigger resolver calls with irrelevant field names.
    $attachments = [];
    if ($entity instanceof ContentEntityInterface
      && $entity->getEntityTypeId() === 'node'
      && $entity->bundle() === 'service_request'
      && !$this->recipientIsReporter($ctx->to, $entity)) {
      $attachments = $this->attachmentResolver->resolve(
        $entity,
        ['field_request_image', 'field_request_media', 'field_attachment'],
        includePrivate: TRUE,
      );
    }

    $content = [
      'preheader' => mb_strimwidth(strip_tags($body), 0, 100, '…'),
      'intro' => $intro,
      'body_blocks' => $bodyBlocks,
    ];
    $facilityFeatures = $this->resolveFacilityFeatures(
      $entity instanceof ContentEntityInterface ? $entity : NULL,
      $ctx->langcode
    );
    if ($facilityFeatures !== []) {
      $content['features_block'] = $facilityFeatures;
    }

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: $content,
      mode: $mode,
      jurisdictionId: $jurisdictionId,
      attachments: $attachments,
    );
  }

  /**
   * True when any recipient matches the entity's reporter email.
   *
   * If a citizen's own mail is in the To/Cc list we skip attachments
   * entirely — Drupal's mail pipeline has no per-recipient attachment
   * control, so mixed lists (staff + citizen) fail closed rather than
   * leak citizen-visible-but-own files back to them in a decorated mail.
   * That matches the user's intent: "Bürger brauchen nicht das eigene
   * File zur Bestätigung."
   *
   * Parsing handles three common $to shapes produced by Drupal's mail
   * pipeline: (1) a bare address string, (2) a comma-separated list of
   * addresses, (3) RFC 5322 display-name form "Max Mustermann"
   * <max@example.com> produced by ECA tokens that expand to
   * [node:author:name] <[node:field_e_mail]>.
   *
   * Intentionally NOT normalized: plus-aliases (max+tag@… vs max@…) and
   * IDN/Punycode. Plus-aliasing is MTA-local and not resolvable at the
   * application layer; IDN normalization would require introducing a
   * dependency we don't otherwise need. Both are acceptable residuals
   * for the Mark-a-Spot deployment model (direct form input, minimal
   * intermediate normalization between report submission and mail send).
   */
  private function recipientIsReporter(mixed $to, ContentEntityInterface $entity): bool {
    if (!$entity->hasField('field_e_mail')) {
      return FALSE;
    }
    $field = $entity->get('field_e_mail');
    if ($field->isEmpty()) {
      return FALSE;
    }
    // getString() returns a single string (joined for multi-value; for
    // email single-value fields identical to ->first()->value). Using
    // the declared interface method keeps the recipient-check unit-
    // testable without mocking FieldItemBase::__get magic access.
    $reporterEmail = strtolower(trim($field->getString()));
    if ($reporterEmail === '') {
      return FALSE;
    }
    $recipients = is_array($to) ? $to : explode(',', (string) $to);
    foreach ($recipients as $recipient) {
      $normalized = strtolower(trim((string) $recipient));
      // RFC 5322 display-name: the real angle-addr is ALWAYS the last
      // token on the mailbox line (`display-name SP angle-addr`). A
      // naive first-match regex (/<([^>]+)>/) lets a reporter hide
      // their own address inside the display-name part
      // (e.g. `"Max <spoof@evil.com>" <reporter@example.org>`), which
      // would let the gate either false-positive or false-negative
      // depending on which address was injected. The anchor `\s*$`
      // pins the match to the trailing angle-addr, and
      // FILTER_VALIDATE_EMAIL post-validates so malformed content
      // between brackets falls through to the plain-string comparison.
      if (preg_match('/<([^<>]+)>\s*$/', $normalized, $matches)) {
        $candidate = strtolower(trim($matches[1]));
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== FALSE) {
          $normalized = $candidate;
        }
      }
      if ($normalized === $reporterEmail) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds facility context rows for service request ECA mails.
   *
   * The organisation assignment is deliberately not consulted here. Facility
   * context is useful for citizen and staff mails even when field_organisation
   * is still empty or intentionally unmanaged for the selected facility.
   *
   * @return array<int, array<string, string>>
   *   Feature rows for the transactional mail card.
   */
  private function resolveFacilityFeatures(?ContentEntityInterface $entity, string $langcode): array {
    if (
      $entity === NULL ||
      $entity->getEntityTypeId() !== 'node' ||
      $entity->bundle() !== 'service_request' ||
      !$entity->hasField('field_facility') ||
      $entity->get('field_facility')->isEmpty()
    ) {
      return [];
    }

    $features = [];
    $facilityId = $this->resolveFieldString($entity, 'field_facility');
    if ($facilityId !== '') {
      $features[] = [
        (string) $this->t('Facility', [], ['langcode' => $langcode]) => $facilityId,
      ];
    }

    $address = $this->resolveAddressText($entity);
    if ($address !== '') {
      $features[] = [
        (string) $this->t('Location', [], ['langcode' => $langcode]) => $address,
      ];
    }

    return $features;
  }

  /**
   * Resolves a scalar node field value.
   */
  private function resolveFieldString(ContentEntityInterface $entity, string $fieldName): string {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim($entity->get($fieldName)->getString());
  }

  /**
   * Flattens $ctx->body into a single plain-text string.
   *
   * System_mail() for action_send_email appends its body as a single
   * element via $message['body'][] = $body; we coerce every entry to
   * string and join on a double newline so upstream hooks that pushed
   * multiple entries land on paragraph boundaries.
   */
  private function extractBody(MailContext $ctx): string {
    $parts = [];
    foreach ($ctx->body as $entry) {
      $parts[] = (string) $entry;
    }
    return trim(implode("\n\n", $parts));
  }

  /**
   * Resolves (mode, jurisdictionId) from the acted-upon entity.
   *
   * Called with either $context['node'] or $context['entity'] (both keys
   * exist in the wild; core EmailAction sets 'node', while some ECA
   * subclasses/contrib extensions rename). When the entity has a
   * field_jurisdiction reference pointing at a jur group we switch to
   * jurisdiction mode; otherwise we stay platform.
   *
   * @return array{0: string, 1: int|null}
   *   Two-element array: [mode, jurisdictionId].
   */
  private function resolveJurisdictionFromEntity(?ContentEntityInterface $entity): array {
    if ($entity === NULL || !$entity->hasField('field_jurisdiction')) {
      return ['platform', NULL];
    }
    $field = $entity->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return ['platform', NULL];
    }
    $referenced = $field->referencedEntities();
    $target = $referenced[0] ?? NULL;
    if (!$target instanceof ContentEntityInterface || $target->getEntityTypeId() !== 'group') {
      return ['platform', NULL];
    }
    if (!$this->isResolvedJurisdictionGroup($target)) {
      return ['platform', NULL];
    }
    return ['jurisdiction', (int) $target->id()];
  }

}
