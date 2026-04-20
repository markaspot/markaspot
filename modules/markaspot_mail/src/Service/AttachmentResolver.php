<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_mail\Mail\MailAttachment;
use Psr\Log\LoggerInterface;

/**
 * Resolves file-/image-reference fields on an entity to MailAttachments.
 *
 * Builders that want to ship files alongside their mail (escalation,
 * moderation, ECA auto-attach) inject this service and call resolve()
 * with the source entity and the field names to scan. The service gates
 * each referenced file against five policies — master switch, stream
 * scheme (private:// gated by $includePrivate), MIME whitelist,
 * per-file byte cap, running total-size budget — and returns the
 * surviving MailAttachment list.
 *
 * The gates are read from markaspot_mail.settings:attachments.* so
 * operators can tighten budgets per site without touching code. Rejected
 * files log for DSGVO-audit context: MIME- and per-file-cap rejections
 * at notice (operator should investigate), budget exhaustion at info
 * with an aggregate count (routine on large reports).
 *
 * Private-scheme policy: by default private:// files are rejected to
 * keep accidental third-party leaks from happening. Staff-recipient
 * builders — where the recipient has an existing view-access path to
 * the file (dashboard / backend) — pass $includePrivate = TRUE. This
 * matters in practice: service_request's primary photo field
 * (field_request_image) is `uri_scheme: private`, so a default-deny
 * would silently defeat the entire attachment feature for escalation
 * and moderation mails. The explicit opt-in keeps that decision
 * auditable per builder.
 *
 * Deliberately a service, not a trait: Config + Logger dependencies
 * preclude the pure-function trait pattern used by the two existing
 * Mail/*Trait.php helpers. Consistent with MailBrandingService and
 * MailHtmlRenderer, which use the same DI registration.
 *
 * Out of scope here: Media entities (field_request_media). A media
 * bundle wraps a file entity via source_field, so a future iteration
 * can add a second loop that resolves media → file and reuses the
 * same gate pipeline.
 */
class AttachmentResolver {

  /**
   * Fallback caps if config is missing.
   *
   * Second line of defense if an install skipped the settings seed
   * (e.g. partial restore). MUST stay in sync with the defaults in
   * config/install/markaspot_mail.settings.yml.
   */
  private const DEFAULT_PER_FILE_BYTES = 5242880;
  private const DEFAULT_TOTAL_BYTES = 20971520;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Scans fields on $entity and returns the surviving attachments.
   *
   * Fields that don't exist, are not entity_reference to file entities,
   * or are empty are silently skipped. When the total-size budget is
   * exhausted the scan stops — first-come-first-serve order lets callers
   * influence priority by the order they list field names. The stop is
   * deterministic (no reshuffling for fit) so attachment order is not
   * a function of byte size, which matters for test reproducibility
   * and audit logs.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity — typically a service_request node, but any content
   *   entity with file/image reference fields works.
   * @param list<string> $fieldNames
   *   Machine names of fields to scan, in priority order.
   * @param bool $includePrivate
   *   When TRUE, private:// stream URIs pass the scheme gate. Only
   *   Staff-recipient builders should set this, because private://
   *   files bypass Drupal's access-control URL layer once embedded in
   *   an outgoing mail. Default FALSE fails closed against accidental
   *   public routing (e.g. a citizen-confirmation builder mis-wired to
   *   attach files).
   *
   * @return list<\Drupal\markaspot_mail\Mail\MailAttachment>
   */
  public function resolve(
    ContentEntityInterface $entity,
    array $fieldNames,
    bool $includePrivate = FALSE,
  ): array {
    $settings = $this->configFactory
      ->get('markaspot_mail.settings')
      ->get('attachments') ?? [];

    if (!($settings['enabled'] ?? FALSE)) {
      return [];
    }

    $perFileCap = (int) ($settings['max_per_file_bytes'] ?? self::DEFAULT_PER_FILE_BYTES);
    $totalCap = (int) ($settings['max_total_bytes'] ?? self::DEFAULT_TOTAL_BYTES);
    $allowedMimeList = $settings['allowed_mime'] ?? [];
    // Empty list is INTENTIONALLY treated as "MIME gate disabled". Operators
    // who want to block all attachments should flip 'enabled' to false;
    // pruning the whitelist to [] is not a block-all backstop.
    $allowedMime = $allowedMimeList === []
      ? NULL
      : array_fill_keys($allowedMimeList, TRUE);

    // Collect all referenced files first so a budget abort can report
    // how many further files it dropped in one aggregate log line.
    $candidates = $this->collectCandidates($entity, $fieldNames);

    $out = [];
    $used = 0;

    foreach ($candidates as $index => $file) {
      $uri = (string) $file->getFileUri();

      if (!$includePrivate && str_starts_with($uri, 'private://')) {
        $this->logger->notice(
          'Mail attachment rejected: private:// scheme not allowed for this builder (file @fid, @name). Caller must opt in via $includePrivate.',
          [
            '@fid' => (string) $file->id(),
            '@name' => $this->safeFilename($file),
          ]
        );
        continue;
      }

      $mime = (string) $file->getMimeType();
      if ($allowedMime !== NULL && !isset($allowedMime[$mime])) {
        $this->logger->notice(
          'Mail attachment rejected: MIME @mime not in whitelist (file @fid, @name).',
          [
            '@mime' => $mime !== '' ? $mime : '(empty)',
            '@fid' => (string) $file->id(),
            '@name' => $this->safeFilename($file),
          ]
        );
        continue;
      }

      $size = $file->getSize();
      // Treat unknown-size files as worst-case (cap) so the budget
      // arithmetic stays safe; the file itself is still included. Trade-off:
      // one NULL-size file can consume a big chunk of budget even if the
      // actual payload is tiny.
      $sizeForBudget = $size !== NULL ? (int) $size : $perFileCap;

      if ($sizeForBudget > $perFileCap) {
        $this->logger->notice(
          'Mail attachment rejected: per-file cap exceeded (file @fid, @bytes bytes, cap @cap).',
          [
            '@fid' => (string) $file->id(),
            '@bytes' => (string) $sizeForBudget,
            '@cap' => (string) $perFileCap,
          ]
        );
        continue;
      }

      // Inclusive check: a file that exactly fills the remaining budget
      // is still attached ($used + $sizeForBudget == $totalCap passes).
      if ($used + $sizeForBudget > $totalCap) {
        $remaining = count($candidates) - $index;
        $this->logger->info(
          'Mail attachment budget exhausted: stopped at file @fid, @dropped further files dropped (used @used of @cap bytes).',
          [
            '@fid' => (string) $file->id(),
            '@dropped' => (string) $remaining,
            '@used' => (string) $used,
            '@cap' => (string) $totalCap,
          ]
        );
        return $out;
      }

      $used += $sizeForBudget;
      $out[] = new MailAttachment(
        filename: (string) $file->getFilename(),
        filemime: $mime !== '' ? $mime : 'application/octet-stream',
        filepath: $uri,
        filesize: $size !== NULL ? (int) $size : NULL,
      );
    }

    return $out;
  }

  /**
   * Flattens fields into a single candidate list preserving scan order.
   *
   * Centralizing the scan lets the budget branch report an accurate
   * dropped-file count without recomputing the remaining cursor across
   * nested loops. Skips fields that don't exist, aren't entity_reference
   * to file entities, or are empty. Non-FileInterface referenced entities
   * (e.g. users if a builder mis-passes a user field) and non-permanent
   * files are dropped silently — those are not policy rejections, they
   * are "not an attachment candidate" at the type level.
   *
   * @return list<\Drupal\file\FileInterface>
   */
  private function collectCandidates(ContentEntityInterface $entity, array $fieldNames): array {
    $out = [];
    foreach ($fieldNames as $fieldName) {
      if (!$entity->hasField($fieldName)) {
        continue;
      }
      $field = $entity->get($fieldName);
      if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
        continue;
      }
      foreach ($field->referencedEntities() as $file) {
        if (!$file instanceof FileInterface) {
          continue;
        }
        if (!$file->isPermanent()) {
          continue;
        }
        $out[] = $file;
      }
    }
    return $out;
  }

  /**
   * Strips CR/LF/NUL bytes from a filename before it enters a logger context.
   *
   * Drupal's upload pipeline usually sanitizes filenames at storage time,
   * but modules overriding the rename callback or setting the filename
   * programmatically may bypass that. File-/syslog-based loggers interpolate
   * context values directly; an injected newline produces a fake log line
   * (CWE-117). Cheap defense-in-depth.
   */
  private function safeFilename(FileInterface $file): string {
    return str_replace(["\r", "\n", "\0"], '', basename((string) $file->getFilename()));
  }

}
