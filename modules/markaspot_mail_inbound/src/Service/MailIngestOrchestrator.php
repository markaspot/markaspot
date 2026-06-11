<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Event\InboundReplyReceivedEvent;
use Drupal\markaspot_mail_inbound\IngestResult;
use Drupal\markaspot_mail_inbound\Util\MailTextUtils;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrates one parsed inbound email through the staging pipeline.
 *
 * REBUILD (issue #467): this REPLACES the old InboundRequestCreator::ingest,
 * which auto-created a service request and improvised field values. An email
 * no longer becomes a service request here. The flow is:
 *
 *   parse (done by the worker)
 *   -> sender blocklist / allowlist / flood   (kept, BEFORE threading)
 *   -> spam / not-a-report heuristic
 *   -> threading:
 *        - reply to a STAGED inbound_mail -> append to that mail, re-surface
 *        - reply to a PROMOTED service request -> append internal_remark
 *          (with the H1 sender-match check), unchanged reply-to-SR behavior
 *        - otherwise -> create a new staged inbound_mail (state=staged)
 *   -> dispatch the existing event
 *
 * Promotion to a service request happens later, explicitly, in
 * InboundMailPromoter::promoteToServiceRequest() via the Open311 processor.
 *
 * The security hardening from the first cut is preserved verbatim: sender
 * verification before threading, the flood guard, attachment MIME content
 * sniffing, and never writing raw bodies to logs.
 */
class MailIngestOrchestrator {

  public const FLOOD_NAME = 'markaspot_mail_inbound.sender';

  /**
   * Constructs a MailIngestOrchestrator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailboxResolver $mailboxResolver,
    protected SpamHeuristicFilter $spamFilter,
    protected FloodInterface $flood,
    protected EventDispatcherInterface $eventDispatcher,
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected LoggerChannelInterface $logger,
  ) {
  }

  /**
   * Ingests one parsed message for a given mailbox.
   *
   * @param \Drupal\markaspot_mail_inbound\Dto\InboundMessage $message
   *   The parsed message.
   * @param array<string, mixed> $mailbox
   *   A normalized mailbox definition (see MailboxResolver).
   *
   * @return \Drupal\markaspot_mail_inbound\IngestResult
   *   The outcome.
   */
  public function ingest(InboundMessage $message, array $mailbox): IngestResult {
    $settings = $this->mailboxResolver->getGlobalSettings();

    if ($message->fromAddress === '') {
      $this->logger->warning('Inbound mail without a valid From address discarded (mailbox @id).', ['@id' => $mailbox['id'] ?? '']);
      return IngestResult::invalid('Missing or invalid From address');
    }

    // 1. Dedupe: the exact Message-ID was already staged or promoted.
    if ($message->messageId !== '') {
      $existingMail = $this->findInboundMailByMessageIds([$message->messageId]);
      if ($existingMail !== NULL) {
        $this->logger->info('Skipping duplicate inbound mail @id (mailbox @mb).', [
          '@id' => $existingMail->id(),
          '@mb' => $mailbox['id'] ?? '',
        ]);
        return IngestResult::duplicate((int) $existingMail->id());
      }
    }

    // 2. Sender checks: blocklist, allowlist, flood. These MUST run before
    // threading resolution: otherwise a blocklisted or flooding sender could
    // inject unlimited content by replying to known Message-IDs, bypassing
    // every gate.
    if (MailTextUtils::matchesAddressList($message->fromAddress, $mailbox['sender_blocklist'] ?? [])) {
      $this->logger->notice('Inbound mail from blocklisted sender discarded (mailbox @id).', ['@id' => $mailbox['id'] ?? '']);
      return IngestResult::blocked('Sender is blocklisted');
    }
    $allowlist = $mailbox['sender_allowlist'] ?? [];
    if ($allowlist !== [] && !MailTextUtils::matchesAddressList($message->fromAddress, $allowlist)) {
      $this->logger->notice('Inbound mail from sender outside the allowlist discarded (mailbox @id).', ['@id' => $mailbox['id'] ?? '']);
      return IngestResult::blocked('Sender not on allowlist');
    }
    $floodId = mb_strtolower($message->fromAddress);
    if (!$this->flood->isAllowed(self::FLOOD_NAME, $settings['flood_limit'], $settings['flood_window'], $floodId)) {
      $this->logger->warning('Inbound mail rate limit reached for a sender (mailbox @id).', ['@id' => $mailbox['id'] ?? '']);
      return IngestResult::blocked('Sender flood limit reached');
    }

    // 3. Spam / not-a-report heuristic. Runs after the security gates and
    // before staging so the triage inbox holds only genuine citizen mails.
    // Conservative by design: only unambiguous machine markers match.
    $spamReason = $this->spamFilter->classify($message);
    if ($spamReason !== NULL) {
      return $this->recordDiscardedSpam($message, $mailbox, $settings, $spamReason);
    }

    // 4. Threading.
    $threadIds = $message->getThreadingIds();
    if ($threadIds !== []) {
      // 4a. Reply to an existing STAGED inbound mail: append to that mail and
      // re-surface it in triage.
      $stagedParent = $this->findInboundMailByMessageIds($threadIds);
      if ($stagedParent !== NULL && $stagedParent->getState() === InboundMail::STATE_STAGED) {
        if ($this->replySenderMatchesStagedMail($stagedParent, $message)) {
          $this->appendReplyToStagedMail($stagedParent, $message, $settings);
          // A reply consumes a flood slot like a new mail: otherwise a sender
          // could append unlimited content by replying to a known thread.
          $this->flood->register(self::FLOOD_NAME, $settings['flood_window'], $floodId);
          $this->logger->notice('Inbound mail threaded as reply onto staged mail @id (mailbox @mb).', [
            '@id' => $stagedParent->id(),
            '@mb' => $mailbox['id'] ?? '',
          ]);
          return IngestResult::replyToMail((int) $stagedParent->id());
        }
        $this->logger->warning('Inbound mail references staged mail @id but the sender does not match; staging a new mail (mailbox @mb).', [
          '@id' => $stagedParent->id(),
          '@mb' => $mailbox['id'] ?? '',
        ]);
      }

      // 4b. Reply to a PROMOTED service request: keep the existing reply-to-SR
      // behavior with the H1 sender-match check (the node carries
      // field_email_message_id once promoted).
      $node = $this->findNodeByMessageIds($threadIds);
      if ($node !== NULL && $this->replySenderMatchesReporter($node, $message)) {
        $this->appendReplyRemark($node, $message, $settings);
        // A reply to a promoted request also consumes a flood slot, so the
        // rate limit applies uniformly to new mails and both reply paths.
        $this->flood->register(self::FLOOD_NAME, $settings['flood_window'], $floodId);
        $this->eventDispatcher->dispatch(
          new InboundReplyReceivedEvent($node, $message),
          InboundReplyReceivedEvent::EVENT_NAME
        );
        $this->logger->notice('Inbound mail threaded as reply to promoted request node @nid (mailbox @mb).', [
          '@nid' => $node->id(),
          '@mb' => $mailbox['id'] ?? '',
        ]);
        return IngestResult::replyToNode((int) $node->id());
      }
      if ($node !== NULL) {
        $this->logger->warning('Inbound mail references promoted request node @nid but the sender does not match the reporter; staging a new mail (mailbox @mb).', [
          '@nid' => $node->id(),
          '@mb' => $mailbox['id'] ?? '',
        ]);
      }
    }

    // 5. Stage as a new inbound_mail. NO service_request is created here.
    $mail = $this->stageMail($message, $mailbox, $settings, InboundMail::STATE_STAGED);

    $this->flood->register(self::FLOOD_NAME, $settings['flood_window'], $floodId);

    $this->logger->notice('Staged inbound mail @id (mailbox @mb).', [
      '@id' => $mail->id(),
      '@mb' => $mailbox['id'] ?? '',
    ]);

    return IngestResult::staged((int) $mail->id());
  }

  /**
   * Records a spam / not-a-report mail as a discarded inbound_mail.
   *
   * Kept as an auditable record rather than silently dropped, so an operator
   * can review what the heuristic filtered out. No attachments are persisted
   * for discarded mail and no flood slot is consumed (it never reached
   * staging).
   */
  protected function recordDiscardedSpam(InboundMessage $message, array $mailbox, array $settings, string $reason): IngestResult {
    $this->logger->notice('Inbound mail classified as non-report and discarded (mailbox @mb): @reason', [
      '@mb' => $mailbox['id'] ?? '',
      '@reason' => $reason,
    ]);
    $mail = $this->stageMail($message, $mailbox, $settings, InboundMail::STATE_DISCARDED, FALSE);
    return IngestResult::discarded((int) $mail->id());
  }

  /**
   * Creates and saves a new inbound_mail entity from a parsed message.
   *
   * @param \Drupal\markaspot_mail_inbound\Dto\InboundMessage $message
   *   The parsed message.
   * @param array<string, mixed> $mailbox
   *   The mailbox definition.
   * @param array<string, mixed> $settings
   *   The global settings.
   * @param string $state
   *   The initial state (staged or discarded).
   * @param bool $persistAttachments
   *   Whether to persist attachments as managed files. Discarded mail keeps no
   *   binary payload.
   *
   * @return \Drupal\markaspot_mail_inbound\Entity\InboundMail
   *   The saved entity.
   */
  protected function stageMail(InboundMessage $message, array $mailbox, array $settings, string $state, bool $persistAttachments = TRUE): InboundMail {
    $subject = MailTextUtils::sanitizeSubject($message->subject);
    $body = $this->extractBody($message, $settings);

    $threadIds = array_values(array_unique(array_filter(array_merge(
      [$message->messageId],
      $message->getThreadingIds()
    ))));

    $values = [
      'from_address' => $message->fromAddress,
      'from_name' => mb_substr($message->fromName, 0, 255),
      'subject' => $subject,
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
      'message_id' => mb_substr($message->messageId, 0, 998),
      'thread_message_ids' => array_map(static fn(string $id): string => mb_substr($id, 0, 998), $threadIds),
      'state' => $state,
      'mailbox_id' => (string) ($mailbox['id'] ?? ''),
    ];

    $jurisdictionGid = (int) ($mailbox['jurisdiction_gid'] ?? 0);
    if ($jurisdictionGid > 0) {
      $values['jurisdiction_id'] = ['target_id' => $jurisdictionGid];
    }

    if ($persistAttachments) {
      $fileIds = $this->persistAttachmentFiles($message, $settings);
      if ($fileIds !== []) {
        $values['attachment_files'] = array_map(static fn(int $fid): array => ['target_id' => $fid], $fileIds);
      }
    }

    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail */
    $mail = $this->entityTypeManager->getStorage('inbound_mail')->create($values);
    $mail->save();
    return $mail;
  }

  /**
   * Extracts the plain-text body, length-capped.
   */
  protected function extractBody(InboundMessage $message, array $settings): string {
    $body = $message->textBody !== ''
      ? MailTextUtils::normalizeWhitespace($message->textBody)
      : MailTextUtils::htmlToText($message->htmlBody);
    if (mb_strlen($body) > $settings['max_body_length']) {
      $body = mb_substr($body, 0, $settings['max_body_length']);
    }
    return $body;
  }

  /**
   * Persists inbound attachments as managed files.
   *
   * Hard rules, unchanged from the first cut:
   * - The declared MIME type is ignored; the type is sniffed from content.
   * - Only configured MIME types pass, with count and size caps.
   * - The client file name is never used for storage; a generated name with
   *   an extension derived from the sniffed type is used instead.
   *
   * SECURITY (review HIGH 1): a STAGED attachment must NOT be publicly
   * reachable before a moderator promotes the mail. The media image field's
   * uri scheme is public://, so the eventual request_image scheme is public.
   * Staged files therefore land in a SEPARATE, access-restricted staging
   * location (private:// when configured, otherwise a public subdir hardened
   * with an .htaccess deny rule and a logged requirements warning), under a
   * NON-predictable filename (a uuid component, not a guessable timestamp).
   * On promotion InboundMailPromoter moves the file into the normal media
   * scheme so a promoted-report image behaves exactly like a web upload.
   *
   * The managed files are referenced by the inbound_mail's attachment_files
   * field while staged; promotion later turns them into request_image media.
   *
   * @return int[]
   *   The created managed file ids.
   */
  protected function persistAttachmentFiles(InboundMessage $message, array $settings): array {
    if ($message->attachments === []) {
      return [];
    }

    $directory = $this->resolveStagingDirectory();
    if ($directory === NULL) {
      return [];
    }

    $maxBytes = $settings['max_attachment_size_mb'] * 1024 * 1024;
    $allowedTypes = $settings['allowed_mime_types'];
    $extensionMap = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/heic' => 'heic',
      'image/heif' => 'heif',
      'image/gif' => 'gif',
    ];

    $fileIds = [];
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    foreach ($message->attachments as $attachment) {
      if (count($fileIds) >= $settings['max_attachments']) {
        $this->logger->notice('Inbound mail attachment limit (@max) reached; remaining attachments dropped.', ['@max' => $settings['max_attachments']]);
        break;
      }
      if ($attachment->size > $maxBytes) {
        $this->logger->notice('Inbound mail attachment dropped: @size bytes exceeds the configured limit.', ['@size' => $attachment->size]);
        continue;
      }
      // Content sniffing: never trust the declared Content-Type header.
      $sniffed = mb_strtolower((string) $finfo->buffer($attachment->content));
      if (!in_array($sniffed, $allowedTypes, TRUE) || !isset($extensionMap[$sniffed])) {
        $this->logger->notice('Inbound mail attachment dropped: sniffed type @type is not allowed.', ['@type' => $sniffed]);
        continue;
      }

      // Non-predictable filename: a random component, not a timestamp a
      // visitor could enumerate while the file is still in staging.
      $destination = $directory . 'mail-' . bin2hex(random_bytes(16)) . '.' . $extensionMap[$sniffed];
      try {
        $filePath = $this->fileSystem->saveData($attachment->content, $destination, FileExists::Rename);
        /** @var \Drupal\file\FileInterface $file */
        $file = $this->entityTypeManager->getStorage('file')->create(['uri' => $filePath]);
        // PERMANENT, not temporary: attachment_files is a plain entity
        // reference (no file-usage registration), so a temporary file would be
        // garbage-collected by file_cron after temporary_maximum_age (default
        // 6h) while the mail waits in triage — silently destroying citizen
        // photos. The lifecycle is managed explicitly instead: promotion hands
        // the file to request_image media (which holds usage), discard deletes
        // it, and hook_uninstall deletes the files of still-staged mails.
        $file->setPermanent();
        $file->save();
        $fileIds[] = (int) $file->id();
      }
      catch (\Throwable $e) {
        $this->logger->error('Failed to store inbound mail attachment: @message', ['@message' => $e->getMessage()]);
      }
    }

    return $fileIds;
  }

  /**
   * Resolves the access-restricted staging directory for attachment files.
   *
   * Staged citizen photos must never be reachable before moderation. The
   * private filesystem is preferred. When private:// is not configured the
   * directory falls back to a dedicated public subdir hardened with an
   * .htaccess deny rule, and a runtime requirements warning surfaces (see
   * markaspot_mail_inbound_requirements()).
   *
   * @return string|null
   *   The prepared staging directory URI, or NULL when it is not writable.
   */
  protected function resolveStagingDirectory(): ?string {
    $hasPrivate = $this->streamWrapperManager->isValidScheme('private');
    $directory = $hasPrivate
      ? 'private://mail-inbound-staged/'
      : 'public://mail-inbound-staged/';

    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->logger->error('Inbound mail staging directory @dir is not writable.', ['@dir' => $directory]);
      return NULL;
    }

    if (!$hasPrivate) {
      // Best-effort web-server deny so staged photos are not downloadable at a
      // public URL while awaiting moderation. The requirements warning tells
      // operators to configure the private filesystem.
      $htaccess = $directory . '.htaccess';
      if (!file_exists($this->fileSystem->realpath($htaccess) ?: $htaccess)) {
        try {
          $this->fileSystem->saveData(
            "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            $htaccess,
            FileExists::Replace
          );
        }
        catch (\Throwable $e) {
          $this->logger->warning('Could not write the .htaccess deny rule for the public mail staging directory: @message', ['@message' => $e->getMessage()]);
        }
      }
      $this->logger->warning('Inbound mail attachments are staged in the PUBLIC filesystem because private:// is not configured. Staged citizen photos are only protected by an .htaccess deny rule. Configure the private filesystem for proper pre-moderation access control.');
    }

    return $directory;
  }

  /**
   * Appends a reply onto a staged inbound mail and re-surfaces it.
   *
   * Updates the staged mail's body with the reply text and records the reply's
   * Message-ID on thread_message_ids so a subsequent message in the same
   * thread keeps matching this mail. Saving touches changed, re-surfacing it in
   * triage.
   *
   * Idempotent: a reply Message-ID already present in thread_message_ids is not
   * appended a second time (a cron re-run before the IMAP Seen flag persisted).
   */
  protected function appendReplyToStagedMail(InboundMail $mail, InboundMessage $message, array $settings): void {
    $existing = $mail->getThreadMessageIds();
    $replyId = trim($message->messageId);
    if ($replyId !== '' && in_array($replyId, $existing, TRUE)) {
      $this->logger->info('Reply @id already recorded on staged mail @mail; skipping duplicate append.', [
        '@id' => $replyId,
        '@mail' => $mail->id(),
      ]);
      return;
    }

    $replyBody = $this->extractBody($message, $settings);
    $combined = $mail->getBody();
    // Plain-text separator stored on the staged mail body. Not translated:
    // this is internal staging content, and the orchestrator is a plain
    // service (no StringTranslationTrait).
    $combined .= "\n\n---\nReply from " . $message->fromAddress . ":\n" . $replyBody;
    if (mb_strlen($combined) > $settings['max_body_length']) {
      $combined = mb_substr($combined, 0, $settings['max_body_length']);
    }

    $threadIds = $existing;
    foreach (array_merge([$message->messageId], $message->getThreadingIds()) as $id) {
      $id = trim($id);
      if ($id !== '' && !in_array($id, $threadIds, TRUE)) {
        $threadIds[] = mb_substr($id, 0, 998);
      }
    }

    $mail->set('body', ['value' => $combined, 'format' => 'plain_text']);
    $mail->set('thread_message_ids', $threadIds);
    $mail->save();
  }

  /**
   * Verifies a reply genuinely comes from the staged mail's sender.
   *
   * Mirrors replySenderMatchesReporter() for the staged case: a Message-ID
   * alone is not proof of authorship.
   */
  protected function replySenderMatchesStagedMail(InboundMail $mail, InboundMessage $message): bool {
    $sender = mb_strtolower(trim($mail->getFromAddress()));
    if ($sender === '') {
      return FALSE;
    }
    return $sender === mb_strtolower(trim($message->fromAddress));
  }

  /**
   * Verifies that a reply genuinely comes from the original reporter.
   *
   * Unchanged from the first cut (H1): compares the reply's From address
   * case-insensitively against the promoted node's stored field_e_mail. Only a
   * match may append staff-visible content; a Message-ID alone is not proof of
   * authorship. When the parent has no field_e_mail the reporter is unknown,
   * so we cannot vouch for the sender and treat it as a mismatch.
   */
  protected function replySenderMatchesReporter(NodeInterface $node, InboundMessage $message): bool {
    if (!$node->hasField('field_e_mail')) {
      return FALSE;
    }
    $reporter = mb_strtolower(trim((string) $node->get('field_e_mail')->value));
    if ($reporter === '') {
      return FALSE;
    }
    return $reporter === mb_strtolower(trim($message->fromAddress));
  }

  /**
   * Appends a reply as internal remark paragraph to the promoted request.
   *
   * Unchanged behavior from the first cut. Idempotent via a hidden, stable
   * marker derived from the reply's own Message-ID.
   */
  protected function appendReplyRemark(NodeInterface $node, InboundMessage $message, array $settings): bool {
    if (!$node->hasField('field_internal_remark')) {
      $this->logger->notice('Node @nid has no field_internal_remark; reply recorded via event only.', ['@nid' => $node->id()]);
      return FALSE;
    }
    try {
      $bundleInfo = $this->entityTypeManager->getStorage('paragraphs_type')->load('internal_remark');
      if ($bundleInfo === NULL) {
        $this->logger->notice('Paragraph bundle internal_remark missing; reply to node @nid recorded via event only.', ['@nid' => $node->id()]);
        return FALSE;
      }

      $marker = $this->replyRemarkMarker($message->messageId);
      if ($marker !== '' && $this->nodeHasReplyMarker($node, $marker)) {
        $this->logger->info('Reply @marker already recorded on node @nid; skipping duplicate append.', [
          '@marker' => $marker,
          '@nid' => $node->id(),
        ]);
        return TRUE;
      }

      $body = $this->extractBody($message, $settings);
      $remark = 'Email reply from ' . $message->fromAddress . ":\n\n" . $body;
      if ($marker !== '') {
        $remark .= "\n\n" . $marker;
      }

      /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
        'type' => 'internal_remark',
        'langcode' => $node->language()->getId(),
      ]);
      $paragraph->set('field_internal_remark_text', [
        'value' => $remark,
        'format' => 'plain_text',
      ]);
      if ($paragraph->hasField('field_author')) {
        // Anonymous: the remark originates from the citizen, not from staff.
        $paragraph->set('field_author', 0);
      }
      $paragraph->save();

      $current = $node->get('field_internal_remark')->getValue();
      $current[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
      $node->set('field_internal_remark', $current);
      $node->save();
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to append reply remark to node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Builds the hidden idempotency marker for a reply Message-ID.
   *
   * The Message-ID is HASHED before embedding (review LOW 8): a crafted
   * Message-ID containing the marker's own closing bracket or control
   * characters could otherwise distort the str_contains() duplicate check that
   * reads the marker back. A hex sha256 has a fixed, safe charset, so the
   * marker is always well-formed and matching stays consistent.
   */
  protected function replyRemarkMarker(string $messageId): string {
    $messageId = trim($messageId);
    if ($messageId === '') {
      return '';
    }
    return '[mail-inbound:reply-id:' . hash('sha256', $messageId) . ']';
  }

  /**
   * Checks whether any existing remark on the node carries the marker.
   */
  protected function nodeHasReplyMarker(NodeInterface $node, string $marker): bool {
    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');
    foreach ($node->get('field_internal_remark')->getValue() as $item) {
      if (empty($item['target_id'])) {
        continue;
      }
      /** @var \Drupal\paragraphs\ParagraphInterface|null $paragraph */
      $paragraph = $paragraphStorage->load($item['target_id']);
      if ($paragraph === NULL || !$paragraph->hasField('field_internal_remark_text')) {
        continue;
      }
      if (str_contains((string) $paragraph->get('field_internal_remark_text')->value, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds a staged or promoted inbound_mail whose stored ids match.
   *
   * @param string[] $messageIds
   *   Candidate message ids (already normalized, no angle brackets).
   *
   * @return \Drupal\markaspot_mail_inbound\Entity\InboundMail|null
   *   The matching inbound mail or NULL.
   */
  protected function findInboundMailByMessageIds(array $messageIds): ?InboundMail {
    $messageIds = array_values(array_filter($messageIds, static fn(string $id): bool => $id !== ''));
    if ($messageIds === []) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('inbound_mail');
    // Match either the canonical message_id or any threaded id.
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition(
      $query->orConditionGroup()
        ->condition('message_id', $messageIds, 'IN')
        ->condition('thread_message_ids', $messageIds, 'IN')
    );
    $ids = $query
      ->range(0, 1)
      ->sort('changed', 'DESC')
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail|null $mail */
    $mail = $storage->load((int) reset($ids));
    return $mail;
  }

  /**
   * Finds a promoted service request node whose stored Message-ID matches.
   *
   * @param string[] $messageIds
   *   Candidate message ids (already normalized, no angle brackets).
   *
   * @return \Drupal\node\NodeInterface|null
   *   The matching node or NULL.
   */
  protected function findNodeByMessageIds(array $messageIds): ?NodeInterface {
    $messageIds = array_values(array_filter($messageIds, static fn(string $id): bool => $id !== ''));
    if ($messageIds === []) {
      return NULL;
    }
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'service_request')
      ->condition('field_email_message_id', $messageIds, 'IN')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if ($nids === []) {
      return NULL;
    }
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load(reset($nids));
    return $node;
  }

}
