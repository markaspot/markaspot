<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Event\InboundRequestCreatedEvent;
use Drupal\markaspot_mail_inbound\Util\MailTextUtils;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Promotes a staged inbound mail into a service request, or discards it.
 *
 * Promotion routes the mail through the EXISTING Open311 GeoreportProcessor
 * path (the same prepareNodeProperties / createNode contract the REST resource
 * uses) rather than improvising field values.
 *
 * When the moderator's chosen category term carries a field_service_code, the
 * code is fed to the processor (which maps service_code -> field_category).
 * When the term has NO service code (a valid jurisdiction-scoped category that
 * was never assigned an Open311 code), service_code is omitted and
 * field_category is set directly on the created node after the processor call.
 * Both paths produce a node with the correct field_category value; the
 * platform title regenerates via the markaspot_request_id presave hook on save
 * in both cases.
 *
 * Additive and non-breaking: this only CALLS the processor; it never modifies
 * it or the shared presave hooks. The processor and geocoder are injected as
 * OPTIONAL services and their use is guarded.
 */
class InboundMailPromoter {

  use StringTranslationTrait;

  /**
   * Constructs an InboundMailPromoter.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected EventDispatcherInterface $eventDispatcher,
    protected LoggerChannelInterface $logger,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected Token $token,
    protected InternalRemarkWriter $remarkWriter,
    protected ?object $georeportProcessor = NULL,
    protected ?object $geocoder = NULL,
  ) {
  }

  /**
   * Promotes a staged inbound mail into a service request node.
   *
   * @param \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail
   *   The staged inbound mail.
   * @param int $categoryTid
   *   The service_category term id the moderator chose. Must exist in the
   *   service_category vocabulary and belong to the mail's jurisdiction. When
   *   the term carries a field_service_code the code is passed to the Open311
   *   processor (which resolves it back to the term for field_category). When
   *   the term has no code, field_category is set directly on the node after
   *   the processor call.
   * @param string|null $addressHint
   *   Optional NER-extracted address from the AI suggestion path. When
   *   provided, this address is tried for geocoding BEFORE the trivial-regex
   *   scan of the body. Default behavior (regex scan) is unchanged when NULL.
   *
   * @return \Drupal\node\NodeInterface
   *   The created, saved service request node.
   *
   * @throws \RuntimeException
   *   When the Open311 processor is unavailable or the mail is not staged.
   */
  public function promoteToServiceRequest(InboundMail $mail, int $categoryTid, ?string $addressHint = NULL): NodeInterface {
    if ($this->georeportProcessor === NULL) {
      throw new \RuntimeException('Cannot promote inbound mail: the markaspot_open311 processor is not available.');
    }
    if ($mail->getState() !== InboundMail::STATE_STAGED) {
      throw new \RuntimeException(sprintf('Cannot promote inbound mail %s: it is %s, not staged.', $mail->id(), $mail->getState()));
    }

    $jurisdictionGid = $mail->getJurisdictionId();

    // Resolve the service code for the chosen category (may be NULL for
    // categories that were never assigned an Open311 code). The code is used
    // as the processor's mapping key when present; when absent, field_category
    // is set directly on the node after the processor call (see below).
    $serviceCode = $this->resolveServiceCode($categoryTid);

    // Build the requestData payload the processor consumes.
    //
    // Description precedence (product decision, 2026-06-11): when the AI
    // suggestion service stored a suggested_description (a neutral, PII-free
    // summary), it is used as the public report body. Otherwise the fallback
    // is the original citizen mail text (subject + first log segment, before
    // any appended conversation entries). The subject is prepended in both
    // cases via buildDescription(). The title is system-generated by
    // markaspot_request_id, so we never set it. field_gdpr is TRUE: emailing
    // the published intake address is the consent act.
    $suggestedDescription = $mail->getSuggestedDescription();
    $descriptionBody = $suggestedDescription ?? MailTextUtils::extractOriginalMessage($mail->getBody());
    $requestData = [
      'email' => $mail->getFromAddress(),
      'description' => $this->buildDescription($mail->getSubject(), $descriptionBody),
      'field_gdpr' => TRUE,
    ];
    // When the category has a service_code, pass it to the processor: it maps
    // the code back to the jurisdiction-scoped term and writes field_category
    // for us. When it has no code, the processor cannot do the mapping; we set
    // field_category directly on the node below AFTER the processor call.
    if ($serviceCode !== NULL) {
      $requestData['service_code'] = $serviceCode;
    }
    if ($jurisdictionGid > 0) {
      $requestData['jurisdiction_id'] = $jurisdictionGid;
    }

    // GEOCODING: try the AI-suggested address first (when provided), then fall
    // back to the trivial regex scan of the body. The address hint comes from
    // the AI text-classification path (NER-extracted); it is more reliable than
    // the regex but equally optional — a miss still leaves the report
    // ungeolocated, as always.
    $coordinates = $this->tryGeocode($mail, $addressHint);
    if ($coordinates !== NULL) {
      $requestData['lat'] = $coordinates['lat'];
      $requestData['long'] = $coordinates['lng'];
    }

    // Mirror the Open311 createNode() path: prepareNodeProperties -> create ->
    // set jurisdiction -> initial status + note -> save (NO validate()).
    $values = $this->georeportProcessor->prepareNodeProperties($requestData, 'create');
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create($values);

    // Codeless category: the processor could not map a service_code to a term,
    // so field_category was not set by prepareNodeProperties. Set it directly
    // here using the tid the moderator chose. This is the same field the
    // processor writes on the coded path; the platform title regenerates via
    // markaspot_request_id's presave hook in both cases.
    if ($serviceCode === NULL && $categoryTid > 0 && $node->hasField('field_category')) {
      $node->set('field_category', ['target_id' => $categoryTid]);
    }

    // Email reports always enter the moderation queue unpublished.
    $node->setUnpublished();

    // EXPLICITLY ungeolocated when the mail carried no usable location: the
    // shared processor seeds a default coordinate when the request has none
    // (right for the web map picker, wrong for email — the rebuild design
    // lists the silent default as a first-cut bug). Clearing the field only
    // on OUR node keeps the shared path untouched (non-regression pillar)
    // and makes the missing location visible: the dashboard API reports
    // ungeolocated=true, the auto-reply asks the citizen, and moderation
    // completes the report.
    if ($coordinates === NULL && $node->hasField('field_geolocation')) {
      $node->set('field_geolocation', []);
    }

    // Pre-set the jurisdiction so markaspot_group's hook keeps it (it only
    // derives field_jurisdiction when empty) and creates the relationship.
    if ($jurisdictionGid > 0 && $node->hasField('field_jurisdiction')) {
      $node->set('field_jurisdiction', ['target_id' => $jurisdictionGid]);
    }

    // field_source / field_email_message_id mark the email channel (these
    // fields are field_permissions-gated and shipped by this module).
    if ($node->hasField('field_source')) {
      $node->set('field_source', 'email');
    }
    if ($node->hasField('field_email_message_id') && $mail->get('message_id')->value) {
      $node->set('field_email_message_id', mb_substr((string) $mail->get('message_id')->value, 0, 998));
    }

    // Initial jurisdiction-aware status + status note paragraph, mirroring
    // GeoreportRequestIndexResource::createNode().
    $this->applyInitialStatus($node, $jurisdictionGid);

    // Attach any persisted attachments as request_image media. The builder
    // checks for field_request_media BEFORE creating any media entity
    // (Phase 1 LOW carry-over): without the field no media is minted, so no
    // orphaned media entity can be left behind.
    $attachments = $this->buildAttachmentMedia($mail, $node);
    if ($attachments['items'] !== []) {
      $node->set('field_request_media', $attachments['items']);
    }
    $attachedFileIds = $attachments['fids'];

    // The group relationship is created by markaspot_group_node_insert, which
    // fires on this save() and relates the node to its ROOT jurisdiction via
    // getRootJurisdictionId() (review MEDIUM 3). The earlier safety net here
    // related the node to the raw mailbox gid as well, which produced TWO
    // relationships for a child-jurisdiction mailbox where the canonical
    // web/Open311 path yields exactly one. It is removed so promotion mirrors
    // the web path. markaspot_group is a hard transitive dependency
    // (markaspot_open311 -> markaspot_group), so the hook is always present.
    $node->save();

    // The staged body is the FULL conversation log (original message plus
    // every citizen reply appended while staged and every staff/auto reply).
    // The description above quotes only the original message; when the log
    // holds MORE than that, preserve the complete log as ONE internal remark
    // so staff keep the pre-promotion dialogue without it leaking into the
    // citizen-visible description (product decision, 2026-06-11).
    $this->recordConversationRemark($mail, $node);

    // Record the promotion on the mail. References to files the node actually
    // carries are released: those belong to its request_image media now (which
    // holds the file usage), and a stale reference would re-delete them at
    // discard-after-promote edge cases and module uninstall. A file that did
    // NOT make it onto the node (media create failed, field absent) keeps its
    // reference, so it stays reachable for discard / uninstall cleanup instead
    // of being silently orphaned.
    $remaining = array_values(array_diff($mail->getAttachmentFileIds(), $attachedFileIds));
    $mail->set('nid', ['target_id' => $node->id()]);
    $mail->set('attachment_files', array_map(static fn(int $fid): array => ['target_id' => $fid], $remaining));
    $mail->setState(InboundMail::STATE_PROMOTED);
    $mail->save();

    // Dispatch the existing created event (auto-reply, staff notify, etc.).
    $this->eventDispatcher->dispatch(
      new InboundRequestCreatedEvent($node, $this->mailToMessage($mail)),
      InboundRequestCreatedEvent::EVENT_NAME
    );

    $this->logger->notice('Promoted inbound mail @mail to service request @nid.', [
      '@mail' => $mail->id(),
      '@nid' => $node->id(),
    ]);

    return $node;
  }

  /**
   * Discards a staged inbound mail and deletes its attachment files.
   *
   * @param \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail
   *   The inbound mail to discard.
   */
  public function discard(InboundMail $mail): void {
    $this->deleteAttachmentFiles($mail);
    $mail->setState(InboundMail::STATE_DISCARDED);
    $mail->save();
    $this->logger->notice('Discarded inbound mail @mail.', ['@mail' => $mail->id()]);
  }

  /**
   * Records the full mail body as an internal remark on the promoted node.
   *
   * UNCONDITIONAL (product decision, 2026-06-11): the public description may
   * be an AI-generated paraphrase (suggested_description path) or the original
   * mail text (fallback path). In both cases the citizen's exact wording MUST
   * remain available to staff; storing it as an internal remark achieves this
   * without surfacing PII in the citizen-visible description. Even a clean
   * one-message mail gets a remark so staff can always read what the citizen
   * actually wrote, regardless of which description path was taken.
   *
   * Reuses the SAME guarded paragraph mechanism as post-promotion replies
   * (InternalRemarkWriter), so a missing paragraph stack degrades to a logged
   * no-op and never blocks the promotion.
   */
  protected function recordConversationRemark(InboundMail $mail, NodeInterface $node): void {
    $log = trim($mail->getBody());
    if ($log === '') {
      return;
    }
    // Untranslated like the log entries themselves: internal staff-facing
    // content. The label wording is a product decision (2026-06-11).
    $this->remarkWriter->append($node, "E-Mail-Konversation aus dem Posteingang (vor Übernahme):\n\n" . $log);
  }

  /**
   * Builds the node description from the subject and body.
   *
   * The subject is always prepended (the title is system-generated
   * "#<id> <category>" by markaspot_request_id's presave hook, so we never
   * set it). The caller passes one of two body sources depending on what is
   * available:
   *
   *   1. AI suggested_description (preferred): PII-free, neutral problem
   *      description generated by the AI, WITHOUT salutation, sign-off or
   *      citizen names. Suitable for public display.
   *   2. Fallback: MailTextUtils::extractOriginalMessage() — the original
   *      citizen mail text before any conversation entries. This is today's
   *      behavior when no AI suggestion is available.
   *
   * The citizen's exact wording is ALWAYS preserved as an internal remark
   * regardless of which body source is used (see recordConversationRemark).
   *
   * @param string $subject
   *   The mail subject.
   * @param string $body
   *   The body text — either the AI-generated description or the original
   *   citizen message.
   *
   * @return string
   *   The combined description.
   */
  protected function buildDescription(string $subject, string $body): string {
    $subject = trim($subject);
    $body = trim($body);
    if ($subject === '') {
      return $body;
    }
    if ($body === '') {
      return $subject;
    }
    return $subject . "\n\n" . $body;
  }

  /**
   * Resolves the service_code string for a category term, if any.
   *
   * When present, the code is passed to the processor which maps it back to a
   * jurisdiction-scoped term (the coded path). When absent (NULL), the caller
   * sets field_category directly on the created node (the codeless path).
   *
   * @param int $categoryTid
   *   The service_category term id.
   *
   * @return string|null
   *   The service code, or NULL when the term has no field_service_code.
   */
  protected function resolveServiceCode(int $categoryTid): ?string {
    if ($categoryTid <= 0) {
      return NULL;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($categoryTid);
    if ($term === NULL || !$term->hasField('field_service_code') || $term->get('field_service_code')->isEmpty()) {
      return NULL;
    }
    $code = trim((string) $term->get('field_service_code')->value);
    return $code !== '' ? $code : NULL;
  }

  /**
   * Attempts to geocode an address for the mail.
   *
   * Resolution order:
   *   1. $addressHint — the AI-extracted address from text classification.
   *      More precise than a regex scan; tried first when provided.
   *   2. extractTrivialAddress() on the body — the existing simple German
   *      street regex (unchanged, still the common path for manual promotion).
   *
   * Returns coordinates only when the geocoder is available AND a candidate
   * address resolves. A miss leaves the report ungeolocated (the common case).
   *
   * @param \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail
   *   The mail being promoted.
   * @param string|null $addressHint
   *   Optional AI-extracted address to try before the regex scan.
   *
   * @return array{lat: float, lng: float}|null
   *   Coordinates, or NULL when ungeolocated.
   */
  protected function tryGeocode(InboundMail $mail, ?string $addressHint = NULL): ?array {
    if ($this->geocoder === NULL) {
      return NULL;
    }

    // Try the AI hint first (additive; default path is unchanged when NULL).
    if ($addressHint !== NULL && $addressHint !== '') {
      $result = $this->geocodeCandidate($addressHint);
      if ($result !== NULL) {
        return $result;
      }
      // Hint did not resolve; fall through to the regex scan.
    }

    // Deliberately the FULL conversation log, not just the original message:
    // the missing-location auto-reply asks the citizen for an address, and
    // the answer arrives as an appended reply entry.
    $candidate = $this->extractTrivialAddress($mail->getBody());
    if ($candidate === NULL) {
      return NULL;
    }
    return $this->geocodeCandidate($candidate);
  }

  /**
   * Geocodes a single address candidate string.
   *
   * @param string $candidate
   *   The address to geocode.
   *
   * @return array{lat: float, lng: float}|null
   *   Coordinates, or NULL on failure.
   */
  protected function geocodeCandidate(string $candidate): ?array {
    try {
      $result = $this->geocoder->getCoordinatesFromAddress($candidate);
    }
    catch (\Throwable $e) {
      // Log the exception TYPE only: geocoder exception messages can echo the
      // queried string, and the candidate here is an address derived from a
      // citizen's mail body (PII does not belong in watchdog).
      $this->logger->warning('Geocoding a promoted inbound mail address failed (@type).', ['@type' => get_class($e)]);
      return NULL;
    }
    if (!is_array($result) || !isset($result['lat'], $result['lng'])) {
      return NULL;
    }
    return ['lat' => (float) $result['lat'], 'lng' => (float) $result['lng']];
  }

  /**
   * Extracts a trivial street + postcode address from free text.
   *
   * Deliberately simple (NO NER): the load-bearing token is a "<street>
   * <number>" with a German street suffix. A 5-digit postcode + locality is
   * only ADDED to that match, never used on its own (review LOW 7): a bare
   * "12345 Spam" five-digit run plus capitalized word ("Ticket 12345 Spam",
   * an order number, a phone fragment) would otherwise geocode to a wrong
   * place. Without a confident street token the report stays ungeolocated and
   * is completed in moderation.
   *
   * @param string $body
   *   The plain-text body.
   *
   * @return string|null
   *   A geocodable address string, or NULL.
   */
  protected function extractTrivialAddress(string $body): ?string {
    if (trim($body) === '') {
      return NULL;
    }

    // A street name (a single compound word ending in a German street suffix,
    // e.g. "Hauptstrasse", "Lindenweg", "Marktplatz") followed by a house
    // number. German street names are typically one token, so the name part is
    // matched as a single word; this keeps the match tight (no "the light in
    // the Hauptstrasse" over-capture) without venturing into NER. The street is
    // REQUIRED: a postcode is never treated as an address on its own.
    if (!preg_match('/\b([A-ZÄÖÜ][A-Za-zÄÖÜäöüß.\-]*(?:stra(?:ss|ß)e|str\.?|weg|platz|allee|gasse|ring|damm))\s+(\d{1,4}[a-z]?)\b/u', $body, $m)) {
      return NULL;
    }
    $street = trim($m[1] . ' ' . $m[2]);

    // 5-digit postcode + locality, ADDED to the confident street token only.
    // The locality char class excludes "." so a trailing sentence period
    // ("... 50667 Koeln.") is not swept into the match.
    if (preg_match('/\b(\d{5})\s+([A-ZÄÖÜ][A-Za-zÄÖÜäöüß\-]+(?:\s+[A-ZÄÖÜ][A-Za-zÄÖÜäöüß\-]+)?)/u', $body, $m)) {
      return $street . ', ' . trim($m[1] . ' ' . $m[2]);
    }
    return $street;
  }

  /**
   * Applies the jurisdiction-aware initial status and status note paragraph.
   *
   * Mirrors GeoreportRequestIndexResource::createNode(). Degrades gracefully
   * when no status terms exist yet.
   */
  protected function applyInitialStatus(NodeInterface $node, int $jurisdictionGid): void {
    try {
      $initialStatusTid = $this->georeportProcessor->getInitialStatusTid($jurisdictionGid > 0 ? $jurisdictionGid : NULL);
      if ($initialStatusTid === NULL) {
        return;
      }
      if ($node->hasField('field_status')) {
        $node->set('field_status', [['target_id' => $initialStatusTid]]);
      }
      if ($node->hasField('field_status_notes')) {
        $langcode = $node->language()->getId();
        $paragraph = $this->georeportProcessor->createStatusNoteParagraph(
          ['status_term_id' => $initialStatusTid],
          $langcode
        );
        // Behavioral parity with GeoreportRequestIndexResource::createNode():
        // when the status term carries no description (and no boilerplate),
        // createStatusNoteParagraph leaves field_status_note empty. Backfill
        // the same default note string the web path uses (review MEDIUM 4).
        if ($paragraph->hasField('field_status_note') && $paragraph->get('field_status_note')->isEmpty()) {
          $paragraph->set('field_status_note', [
            'value' => $this->t('The service request has been created.', [], ['langcode' => $langcode]),
            'format' => 'plain_text',
          ]);
          $paragraph->save();
        }
        $node->set('field_status_notes', [
          [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ],
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not apply initial status to promoted inbound mail: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Turns the staged attachment files into request_image media references.
   *
   * Reuses the MIME-sniff guarantees already applied at staging (only sniffed,
   * allowed image files were persisted) and mirrors
   * GeoreportProcessorService::createMediaEntity().
   *
   * SECURITY (review HIGH 1): staged files live in an access-restricted
   * staging location (private://mail-inbound-staged/ or a denied public
   * subdir). On promotion each file is MOVED into the normal request_image
   * media scheme (derived from the media image field's uri_scheme + the
   * request_image field's file_directory) so a promoted-report image is stored
   * and served exactly like a web-uploaded one.
   *
   * The target node is checked for field_request_media FIRST (Phase 1 LOW
   * carry-over): media entities are only created when the node can actually
   * carry them, so a missing field never leaves orphaned media behind. The
   * staged files then simply keep their references on the mail.
   *
   * @return array{items: array<int, array<string, mixed>>, fids: int[]}
   *   "items": field_request_media item values (media targets, or raw file
   *   targets when the media stack is absent). "fids": the ids of the files
   *   those items carry — only THESE references may be released on the mail
   *   afterwards; a file whose media creation failed is not in the list.
   */
  protected function buildAttachmentMedia(InboundMail $mail, NodeInterface $node): array {
    if (!$node->hasField('field_request_media')) {
      return ['items' => [], 'fids' => []];
    }
    $fileIds = $mail->getAttachmentFileIds();
    if ($fileIds === []) {
      return ['items' => [], 'fids' => []];
    }
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $hasMedia = $this->moduleHandler->moduleExists('media');
    $mediaDirectory = $this->resolveMediaDirectory();

    $values = [];
    $attachedFids = [];
    foreach ($fileIds as $fid) {
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $fileStorage->load($fid);
      if ($file === NULL) {
        continue;
      }
      $this->moveFileToMediaScheme($file, $mediaDirectory);
      if ($hasMedia) {
        try {
          /** @var \Drupal\media\MediaInterface $media */
          $media = $this->entityTypeManager->getStorage('media')->create([
            'bundle' => 'request_image',
            'uid' => 0,
            'field_media_image' => [
              'target_id' => $file->id(),
              'alt' => 'Email attachment',
              'uri' => $file->getFileUri(),
            ],
          ]);
          $media->setName('media:request_image:' . $media->uuid())
            ->setPublished(TRUE)
            ->save();
          $values[] = [
            'target_id' => $media->id(),
            'alt' => 'Email attachment',
          ];
          $attachedFids[] = (int) $file->id();
        }
        catch (\Throwable $e) {
          $this->logger->error('Failed to create request_image media from inbound mail attachment: @message', ['@message' => $e->getMessage()]);
        }
      }
      else {
        $values[] = [
          'target_id' => $file->id(),
          'alt' => 'Email attachment',
          'uri' => $file->getFileUri(),
        ];
        $attachedFids[] = (int) $file->id();
      }
    }
    return ['items' => $values, 'fids' => $attachedFids];
  }

  /**
   * Resolves the target directory for promoted media, mirroring the web path.
   *
   * Uses the media image field's uri_scheme and the request_image field's
   * file_directory, exactly like GeoreportProcessorService::handleMediaUrls().
   *
   * @return string|null
   *   The prepared media directory URI, or NULL when it cannot be prepared.
   */
  protected function resolveMediaDirectory(): ?string {
    $uriScheme = 'public';
    try {
      $mediaStorageDefinitions = $this->entityFieldManager->getFieldStorageDefinitions('media');
      if (isset($mediaStorageDefinitions['field_media_image'])) {
        $uriScheme = (string) $mediaStorageDefinitions['field_media_image']->getSetting('uri_scheme');
      }
    }
    catch (\Throwable) {
      // Media entity type not installed; fall back to public.
    }
    $scheme = match ($uriScheme) {
      'private' => 'private://',
      's3fs' => 's3fs://',
      default => 'public://',
    };
    $fieldSettings = $this->configFactory
      ->get('field.field.media.request_image.field_media_image')
      ->get('settings');
    $fileDirectory = trim($this->token->replace((string) ($fieldSettings['file_directory'] ?? '')), '/');
    $directory = $scheme . ($fileDirectory !== '' ? $fileDirectory . '/' : '');
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->logger->error('Promoted inbound mail media directory @dir is not writable; keeping the staged location.', ['@dir' => $directory]);
      return NULL;
    }
    return $directory;
  }

  /**
   * Moves a staged attachment file into the normal media scheme.
   *
   * No-op when the file already lives in the target directory or the media
   * directory could not be prepared (the file then stays where it is so the
   * report still has its image, logged for follow-up).
   */
  protected function moveFileToMediaScheme(FileInterface $file, ?string $mediaDirectory): void {
    if ($mediaDirectory === NULL) {
      return;
    }
    $currentUri = (string) $file->getFileUri();
    if ($currentUri === '') {
      return;
    }
    // Compare the file's DIRECTORY to the target exactly. A prefix check
    // (str_starts_with) treated public://mail-inbound-staged/x.jpg as already
    // inside a root public:// media directory (empty file_directory), so on
    // the no-private-fs path the promoted file stayed in the denied staging
    // subdir and would 403 on Apache once the report is published.
    $slash = strrpos($currentUri, '/');
    $currentDirectory = $slash === FALSE ? '' : substr($currentUri, 0, $slash + 1);
    if ($currentDirectory === $mediaDirectory) {
      return;
    }
    $destination = $mediaDirectory . basename($currentUri);
    try {
      $this->fileSystem->move($currentUri, $destination, FileExists::Rename);
      // file_move() would also handle the entity, but the file is already a
      // managed entity here; update its uri and re-save to keep usage intact.
      $file->setFileUri($destination);
      $file->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not move staged inbound mail attachment @uri into the media scheme; keeping it in staging: @message', [
        '@uri' => $currentUri,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Deletes the managed files referenced by a discarded mail.
   */
  protected function deleteAttachmentFiles(InboundMail $mail): void {
    $fileIds = $mail->getAttachmentFileIds();
    if ($fileIds === []) {
      return;
    }
    try {
      $fileStorage = $this->entityTypeManager->getStorage('file');
      $files = $fileStorage->loadMultiple($fileIds);
      if ($files !== []) {
        $fileStorage->delete($files);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to delete attachment files for discarded inbound mail @mail: @message', [
        '@mail' => $mail->id(),
        '@message' => $e->getMessage(),
      ]);
    }
    $mail->set('attachment_files', []);
  }

  /**
   * Reconstructs a minimal InboundMessage from a stored mail for the event.
   *
   * The created event carries an InboundMessage; promotion happens long after
   * the original parse, so we rebuild a light DTO from the persisted fields.
   */
  protected function mailToMessage(InboundMail $mail): InboundMessage {
    return new InboundMessage(
      fromAddress: $mail->getFromAddress(),
      fromName: (string) $mail->get('from_name')->value,
      toAddresses: [],
      subject: $mail->getSubject(),
      textBody: $mail->getBody(),
      htmlBody: '',
      messageId: (string) $mail->get('message_id')->value,
      inReplyTo: '',
      references: $mail->getThreadMessageIds(),
      date: (int) $mail->get('created')->value ?: NULL,
      attachments: [],
    );
  }

}
