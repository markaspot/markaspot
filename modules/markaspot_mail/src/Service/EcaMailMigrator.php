<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Core\Config\Config;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\markaspot_mail\Mail\SplitParagraphsTrait;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Migrates legacy ECA action_send_email_action actions onto markaspot_mail.
 *
 * Every tenant that predates markaspot_mail's Stage 5 (markaspot_mail.texts
 * + the markaspot_mail_send_notification ECA action) still runs its
 * confirmation / status-update mails as raw action_send_email_action
 * entries inside eca.eca.process_confirm_report, process_tunr6d6 and
 * process_ugsohtl (or tenant-specific renames of the same BPMN template).
 * Those entries carry the tenant's actual wording, including localized
 * copy (bleckede) and tenant-specific status taxonomy term IDs (dorsten:
 * 15/30/37/14 vs. the shipped defaults' 4/5/30).
 *
 * This service is the read (analyze()) and write (apply()) half of the
 * `markaspot:mail-texts-migrate` drush command: analyze() is a pure
 * read-only scan that never touches config, apply() performs the actual
 * config rewrite (with a pre-write YAML backup) and is idempotent against
 * actions already migrated.
 *
 * The heuristic mapping from an ECA branch to a markaspot_mail.texts key
 * is deliberately conservative: insert-triggered models always suggest
 * report_confirmation (a tenant only ever has one "the report was just
 * filed" mail); update-triggered models resolve the gating field_status
 * .target_id condition's expected_value against the tenant's own
 * taxonomy_term (so tid 5 on one tenant and tid 15 on another both
 * resolve correctly), matching the term's label against known keyword
 * buckets, falling back to the same keyword match against the action's
 * subject when the term name itself is inconclusive (dorsten's tid=14
 * "Kein Befall" branch, whose subject "... Prüfung abgeschlossen" is the
 * only signal that it is actually a closed-status mail). Branches that
 * still can't be resolved come back UNRESOLVED and are never auto-applied
 * — only an explicit --map override migrates them.
 */
final class EcaMailMigrator {

  use SplitParagraphsTrait;

  private const ECA_CONFIG_PREFIX = 'eca.eca.';

  private const TEXTS_CONFIG_NAME = 'markaspot_mail.texts';

  private const LEGACY_PLUGIN = 'action_send_email_action';

  private const NOTIFICATION_PLUGIN = 'markaspot_mail_send_notification';

  private const BACKUP_DIRECTORY = 'public://markaspot_mail_migrate_backup';

  /**
   * The four notification keys markaspot_mail.texts ships today.
   *
   * Apply() refuses --map overrides pointing at any other key: the config
   * schema (markaspot_mail.texts.slot_set) only defines these four
   * top-level keys, so writing an unlisted key would produce config that
   * fails schema validation.
   */
  public const KNOWN_KEYS = [
    'report_confirmation',
    'status_open',
    'status_closed',
    'status_not_responsible',
  ];

  /**
   * Keyword buckets for the update-model status heuristic.
   *
   * Checked in this order: closed and "not responsible" are more specific
   * than the very generic "open/in progress/update" bucket, so they win
   * ties.
   */
  private const CLOSED_KEYWORDS = ['erledigt', 'geschlossen', 'closed', 'solved', 'abgeschlossen', 'resolved'];

  private const NOT_RESPONSIBLE_KEYWORDS = ['zuständig', 'zustaendig', 'responsible'];

  private const OPEN_KEYWORDS = ['offen', 'open', 'bearbeitung', 'progress', 'update'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Scans every active eca.eca.* config for action_send_email_action actions.
   *
   * Read-only: never touches config, safe to call any number of times.
   * Inactive configs (status: false) are skipped, matching the "aktive
   * eca.eca.*-Configs" scope of the migration — a disabled workflow has no
   * live wording to migrate.
   *
   * @return list<array<string, mixed>>
   *   One finding per action_send_email_action (or already-migrated
   *   markaspot_mail_send_notification) action, each with the display
   *   columns (config_name, model_id, model_label, activity_id,
   *   activity_label, trigger_event, branch_condition, subject_preview,
   *   suggested_key, status, reason, shared_key_conflict) plus the
   *   underscore-prefixed fields apply() needs to perform the rewrite
   *   (_plugin, _subject_raw, _message_raw, _recipient_raw,
   *   _successors_raw).
   */
  public function analyze(): array {
    $findings = [];
    foreach ($this->configFactory->listAll(self::ECA_CONFIG_PREFIX) as $configName) {
      $raw = $this->configFactory->get($configName)->getRawData();
      if (($raw['status'] ?? FALSE) !== TRUE) {
        continue;
      }
      $findings = array_merge($findings, $this->analyzeModel($configName, $raw));
    }
    return $this->annotateSharedKeyConflicts($findings);
  }

  /**
   * Applies the migration for a set of findings.
   *
   * @param list<array<string, mixed>> $findings
   *   Findings as returned by analyze() (or a filtered subset of it).
   * @param array<string, string> $mapOverrides
   *   Activity ID => notification_key overrides. Every value MUST be one
   *   of self::KNOWN_KEYS; the caller (the drush command) validates this
   *   before calling apply() so a typo fails fast instead of silently
   *   skipping.
   *
   * @return list<array<string, mixed>>
   *   One result row per finding: config_name, activity_id,
   *   notification_key, status (migrated|skipped_unresolved|
   *   already_migrated), text_applied (bool), discarded_variant_of
   *   (activity ID or NULL), backup_path (or NULL for
   *   skipped/already_migrated rows).
   */
  public function apply(array $findings, array $mapOverrides): array {
    $byConfig = [];
    foreach ($findings as $finding) {
      $byConfig[$finding['config_name']][] = $finding;
    }

    $report = [];
    $usedKeysThisRun = [];
    $textsConfig = $this->configFactory->getEditable(self::TEXTS_CONFIG_NAME);
    $textsChanged = FALSE;
    $timestamp = (string) $this->time->getCurrentTime();

    foreach ($byConfig as $configName => $configFindings) {
      $ecaConfig = $this->configFactory->getEditable($configName);
      $raw = $ecaConfig->getRawData();
      $configChanged = FALSE;
      $backupPath = NULL;

      foreach ($configFindings as $finding) {
        $activityId = (string) $finding['activity_id'];

        if ($finding['_plugin'] === self::NOTIFICATION_PLUGIN) {
          $report[] = $this->applyResultRow($configName, $activityId, (string) $finding['suggested_key'], 'already_migrated', FALSE, NULL, NULL);
          continue;
        }

        $key = $mapOverrides[$activityId] ?? $finding['suggested_key'];
        if ($key === NULL || $key === '') {
          $report[] = $this->applyResultRow($configName, $activityId, '', 'skipped_unresolved', FALSE, NULL, NULL);
          continue;
        }

        if ($backupPath === NULL) {
          $backupPath = $this->writeBackup($configName, $raw, $timestamp);
        }

        $textWinner = !isset($usedKeysThisRun[$key]);
        if ($textWinner) {
          $this->applyTextsForKey($textsConfig, $key, $finding);
          $usedKeysThisRun[$key] = $activityId;
          $textsChanged = TRUE;
        }

        $raw['actions'][$activityId] = [
          'label' => $finding['activity_label'],
          'plugin' => self::NOTIFICATION_PLUGIN,
          'configuration' => [
            'notification_key' => $key,
            'recipient' => $finding['_recipient_raw'],
          ],
          'successors' => $finding['_successors_raw'],
        ];
        $configChanged = TRUE;

        $report[] = $this->applyResultRow(
          $configName,
          $activityId,
          $key,
          'migrated',
          $textWinner,
          $textWinner ? NULL : $usedKeysThisRun[$key],
          $backupPath,
        );
      }

      if ($configChanged) {
        $ecaConfig->setData($raw)->save();
      }
    }

    if ($textsChanged) {
      $textsConfig->save();
    }

    return $report;
  }

  /**
   * Builds one apply() result row.
   *
   * @return array<string, mixed>
   *   The result row.
   */
  private function applyResultRow(
    string $configName,
    string $activityId,
    string $notificationKey,
    string $status,
    bool $textApplied,
    ?string $discardedVariantOf,
    ?string $backupPath,
  ): array {
    return [
      'config_name' => $configName,
      'activity_id' => $activityId,
      'notification_key' => $notificationKey,
      'status' => $status,
      'text_applied' => $textApplied,
      'discarded_variant_of' => $discardedVariantOf,
      'backup_path' => $backupPath,
    ];
  }

  /**
   * Writes subject/intro/body_blocks/cta_label for one notification key.
   *
   * Headline and preheader are deliberately left empty: NotificationText
   * Builder renders correctly without them, and there is no legacy source
   * field to invent them from.
   */
  private function applyTextsForKey(Config $textsConfig, string $key, array $finding): void {
    $subject = trim((string) $finding['_subject_raw']);
    $transformed = $this->transformMessage((string) $finding['_message_raw']);

    $textsConfig->set($key, [
      'subject' => $subject,
      'headline' => '',
      'intro' => $transformed['intro'],
      'body_blocks' => $transformed['body_blocks'],
      'cta_label' => $transformed['cta_label'],
      'preheader' => '',
    ]);
  }

  /**
   * Exports an eca.eca.* config's current raw data as a YAML backup file.
   *
   * Written once per config per apply() run, before the first action in
   * it is rewritten, so an operator can diff or restore the pre-migration
   * model. All configs touched in one apply() invocation share the same
   * $timestamp directory.
   *
   * @return string
   *   The public:// URI of the written backup file.
   *
   * @throws \RuntimeException
   *   When the backup directory cannot be created or the file cannot be
   *   written — apply() must not silently proceed to rewrite config
   *   without a backup on disk.
   */
  private function writeBackup(string $configName, array $raw, string $timestamp): string {
    $directory = self::BACKUP_DIRECTORY . '/' . $timestamp;
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException(sprintf('EcaMailMigrator: could not create backup directory "%s".', $directory));
    }

    $uri = $directory . '/' . $configName . '.yml';
    $written = $this->fileSystem->saveData(Yaml::encode($raw), $uri, FileExists::Replace);
    if ($written === FALSE) {
      throw new \RuntimeException(sprintf('EcaMailMigrator: could not write backup file "%s".', $uri));
    }
    return $uri;
  }

  /**
   * Analyzes a single decoded eca.eca.* config for email actions.
   *
   * @param string $configName
   *   The config object name, e.g. 'eca.eca.process_tunr6d6'.
   * @param array<string, mixed> $eca
   *   The raw (getRawData()) config array.
   *
   * @return list<array<string, mixed>>
   *   Findings for every action_send_email_action / already-migrated
   *   action in this config.
   */
  private function analyzeModel(string $configName, array $eca): array {
    $modelId = (string) ($eca['id'] ?? $configName);
    $modelLabel = (string) ($eca['label'] ?? $modelId);
    $triggerEvent = $this->triggerEventType($eca);

    $findings = [];
    foreach ((array) ($eca['actions'] ?? []) as $activityId => $action) {
      if (!is_array($action) || !is_string($activityId)) {
        continue;
      }
      $plugin = (string) ($action['plugin'] ?? '');
      if ($plugin !== self::LEGACY_PLUGIN && $plugin !== self::NOTIFICATION_PLUGIN) {
        continue;
      }
      $findings[] = $this->buildFinding($configName, $modelId, $modelLabel, $triggerEvent, $eca, $activityId, $action, $plugin);
    }
    return $findings;
  }

  /**
   * Builds a single finding row for one action.
   *
   * @return array<string, mixed>
   *   The finding row.
   */
  private function buildFinding(
    string $configName,
    string $modelId,
    string $modelLabel,
    string $triggerEvent,
    array $eca,
    string $activityId,
    array $action,
    string $plugin,
  ): array {
    $configuration = (array) ($action['configuration'] ?? []);
    $activityLabel = (string) ($action['label'] ?? $activityId);
    $conditionId = $this->findGatingConditionId($eca, $activityId);
    $branchCondition = $this->describeCondition($eca, $conditionId);

    if ($plugin === self::NOTIFICATION_PLUGIN) {
      return [
        'config_name' => $configName,
        'model_id' => $modelId,
        'model_label' => $modelLabel,
        'activity_id' => $activityId,
        'activity_label' => $activityLabel,
        'trigger_event' => $triggerEvent,
        'branch_condition' => $branchCondition,
        'subject_preview' => '(already migrated — see notification_key)',
        'suggested_key' => (string) ($configuration['notification_key'] ?? ''),
        'status' => 'ALREADY_MIGRATED',
        'reason' => '',
        'shared_key_conflict' => FALSE,
        '_plugin' => $plugin,
        '_subject_raw' => '',
        '_message_raw' => '',
        '_recipient_raw' => (string) ($configuration['recipient'] ?? ''),
        '_successors_raw' => (array) ($action['successors'] ?? []),
      ];
    }

    $subject = (string) ($configuration['subject'] ?? '');
    $suggestedKey = NULL;
    $reason = '';

    if ($triggerEvent === 'insert') {
      $suggestedKey = 'report_confirmation';
    }
    else {
      $tid = $this->gatingStatusTid($eca, $conditionId);
      if ($tid === NULL) {
        $reason = 'update model, but the gating condition is not a field_status.target_id comparison — cannot infer a status key.';
      }
      else {
        $termName = $this->resolveStatusTermName($tid);
        if ($termName !== NULL) {
          $branchCondition .= sprintf(' (term: "%s")', $termName);
        }
        $suggestedKey = self::matchKeywords($termName ?? '') ?? self::matchKeywords($subject);

        // $reason always starts from the term-lookup outcome, then gets
        // amended with the resolution outcome below, so a RESOLVED row
        // that only succeeded through the subject fallback still surfaces
        // the missing term (informational, not blocking) instead of
        // silently discarding that fact.
        $reason = $termName === NULL ? sprintf('status term tid=%d not found in this tenant\'s taxonomy.', $tid) : '';
        if ($suggestedKey === NULL) {
          $reason = trim($reason . ' The subject line also matched no known keyword bucket.');
        }
        elseif ($termName === NULL) {
          $reason = trim($reason . ' Resolved via the subject line instead.');
        }
      }
    }

    return [
      'config_name' => $configName,
      'model_id' => $modelId,
      'model_label' => $modelLabel,
      'activity_id' => $activityId,
      'activity_label' => $activityLabel,
      'trigger_event' => $triggerEvent,
      'branch_condition' => $branchCondition,
      'subject_preview' => mb_strimwidth($subject, 0, 60, '…'),
      'suggested_key' => $suggestedKey,
      'status' => $suggestedKey === NULL ? 'UNRESOLVED' : 'RESOLVED',
      'reason' => $reason,
      'shared_key_conflict' => FALSE,
      '_plugin' => $plugin,
      '_subject_raw' => $subject,
      '_message_raw' => (string) ($configuration['message'] ?? ''),
      '_recipient_raw' => (string) ($configuration['recipient'] ?? ''),
      '_successors_raw' => (array) ($action['successors'] ?? []),
    ];
  }

  /**
   * Flags findings that share a suggested_key with differently-worded siblings.
   *
   * Only insert-triggered models can produce this: process_ugsohtl-style
   * flows branch on field_category, not field_status, so every branch
   * still resolves to report_confirmation under heuristic (a) even though
   * the "open" and "closed" welcome variants carry genuinely different
   * copy. apply()'s first-wins rule silently discards the losing variants
   * (LHM Du/Sie is the case this is designed for), which is correct there
   * but would silently drop real content divergence here — so analyze()
   * surfaces the conflict up front instead of only after the fact.
   *
   * @param list<array<string, mixed>> $findings
   *   Findings as produced by analyzeModel().
   *
   * @return list<array<string, mixed>>
   *   The same findings, with shared_key_conflict and reason updated for
   *   any conflicting group.
   */
  private function annotateSharedKeyConflicts(array $findings): array {
    $bySuggestedKey = [];
    foreach ($findings as $index => $finding) {
      if ($finding['suggested_key'] === NULL || $finding['suggested_key'] === '') {
        continue;
      }
      $bySuggestedKey[$finding['suggested_key']][] = $index;
    }

    foreach ($bySuggestedKey as $indices) {
      if (count($indices) < 2) {
        continue;
      }
      // Compare subject AND message: process_ugsohtl's shipped defaults are
      // the case this guards against, and its three branches share one
      // identical subject line ("Your request [node:request_id] status
      // update") while their message bodies genuinely differ — subject
      // alone would have missed the divergence entirely.
      $wordings = array_unique(array_map(
        static fn (int $i): string => $findings[$i]['_subject_raw'] . "\x00" . $findings[$i]['_message_raw'],
        $indices,
      ));
      if (count($wordings) < 2) {
        // Same key, identical subject and message: no real divergence (or
        // both already migrated), nothing to flag.
        continue;
      }
      foreach ($indices as $i) {
        $findings[$i]['shared_key_conflict'] = TRUE;
        $findings[$i]['reason'] = trim($findings[$i]['reason'] . ' Shares notification_key with ' . (count($indices) - 1) . ' other action(s) that have different wording — only the first-processed action\'s text wins; review with --map before --apply if that is not intended.');
      }
    }

    return $findings;
  }

  /**
   * Determines whether a model's root event is insert- or update-triggered.
   */
  private function triggerEventType(array $eca): string {
    foreach ((array) ($eca['events'] ?? []) as $event) {
      $plugin = (string) ($event['plugin'] ?? '');
      if (str_contains($plugin, ':insert')) {
        return 'insert';
      }
      if (str_contains($plugin, ':update')) {
        return 'update';
      }
    }
    return 'unknown';
  }

  /**
   * Finds the successor edge's condition ID gating direct entry into an action.
   *
   * Walks every event's and gateway's "successors" list looking for the
   * single edge whose target id is $activityId. BPMN models are trees, so
   * an activity has exactly one incoming edge; if none is found (or the
   * edge carries an empty condition, i.e. unconditional), NULL is returned.
   */
  private function findGatingConditionId(array $eca, string $activityId): ?string {
    $nodes = array_merge((array) ($eca['events'] ?? []), (array) ($eca['gateways'] ?? []));
    foreach ($nodes as $node) {
      foreach ((array) ($node['successors'] ?? []) as $successor) {
        if (($successor['id'] ?? NULL) === $activityId) {
          $condition = (string) ($successor['condition'] ?? '');
          return $condition !== '' ? $condition : NULL;
        }
      }
    }
    return NULL;
  }

  /**
   * Human-readable one-line summary of a condition, for the report table.
   */
  private function describeCondition(array $eca, ?string $conditionId): string {
    if ($conditionId === NULL) {
      return '(unconditional)';
    }
    $condition = $eca['conditions'][$conditionId] ?? NULL;
    if (!is_array($condition)) {
      return sprintf('(condition "%s" not found)', $conditionId);
    }
    $plugin = (string) ($condition['plugin'] ?? '');
    $config = (array) ($condition['configuration'] ?? []);
    return match ($plugin) {
      'eca_entity_field_value' => sprintf('%s %s %s', $config['field_name'] ?? '?', $config['operator'] ?? '==', $config['expected_value'] ?? '?'),
      'eca_entity_field_value_changed' => sprintf('%s changed', $config['field_name'] ?? '?'),
      'eca_scalar' => sprintf('%s %s %s', $config['left'] ?? '?', $config['operator'] ?? '?', $config['right'] ?? '?'),
      default => sprintf('%s (%s)', $plugin !== '' ? $plugin : 'unknown', $conditionId),
    };
  }

  /**
   * Extracts the field_status.target_id expected tid gating an activity.
   *
   * Returns NULL for anything that is not a plain eca_entity_field_value
   * comparison against field_status.target_id — e.g. process_ugsohtl's
   * category-based eca_scalar gates, or an unconditional edge.
   */
  private function gatingStatusTid(array $eca, ?string $conditionId): ?int {
    if ($conditionId === NULL) {
      return NULL;
    }
    $condition = $eca['conditions'][$conditionId] ?? NULL;
    if (!is_array($condition) || ($condition['plugin'] ?? '') !== 'eca_entity_field_value') {
      return NULL;
    }
    $config = (array) ($condition['configuration'] ?? []);
    if (($config['field_name'] ?? '') !== 'field_status.target_id') {
      return NULL;
    }
    $tid = filter_var($config['expected_value'] ?? NULL, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $tid !== FALSE ? $tid : NULL;
  }

  /**
   * Loads a status taxonomy term's label by ID, tolerant of missing terms.
   */
  private function resolveStatusTermName(int $tid): ?string {
    try {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    }
    catch (\Throwable $e) {
      $this->logger->warning(
        'EcaMailMigrator: failed to load taxonomy term @tid: @message',
        ['@tid' => $tid, '@message' => $e->getMessage()],
      );
      return NULL;
    }
    return $term instanceof TermInterface ? (string) $term->label() : NULL;
  }

  /**
   * Matches a text against the closed / not-responsible / open keyword buckets.
   *
   * Order matters: "closed" and "not responsible" keywords are checked
   * before the very generic "open/in progress/update" bucket so a term or
   * subject naming both a status word and something else does not fall
   * through to the least specific match.
   */
  public static function matchKeywords(string $text): ?string {
    $lower = mb_strtolower($text);
    if ($lower === '') {
      return NULL;
    }
    foreach (self::CLOSED_KEYWORDS as $keyword) {
      if (str_contains($lower, $keyword)) {
        return 'status_closed';
      }
    }
    foreach (self::NOT_RESPONSIBLE_KEYWORDS as $keyword) {
      if (str_contains($lower, $keyword)) {
        return 'status_not_responsible';
      }
    }
    foreach (self::OPEN_KEYWORDS as $keyword) {
      if (str_contains($lower, $keyword)) {
        return 'status_open';
      }
    }
    return NULL;
  }

  /**
   * Splits a legacy ECA message into intro + body_blocks, minus dead weight.
   *
   * Reuses SplitParagraphsTrait::splitParagraphs() (the same blank-line
   * split + mail-safe Xss::filter + single-newline-to-<br> normalization
   * EcaActionEmailBuilder uses for un-migrated ECA copy) so migrated and
   * still-legacy mails treat paragraph boundaries identically. Three
   * paragraph categories are then dropped because a Notification
   * TextBuilder-rendered mail already produces their effect structurally:
   * jurisdiction footer/address/reply-to blocks (MailBrandingService
   * renders them), the "view your report" link paragraph (the builder
   * derives its own CTA from branding + request_id — the paragraph is
   * replaced by cta_label, not kept as body text) and the legacy
   * "automatically generated, do not reply" signature line (implicit in
   * the branded template).
   *
   * @return array{intro: string, body_blocks: list<string>, cta_label: string}
   *   The transformed slots.
   */
  public function transformMessage(string $rawMessage): array {
    $paragraphs = $this->splitParagraphs($rawMessage);
    $kept = [];
    $ctaLabel = '';
    $isGerman = self::looksGerman($rawMessage);

    foreach ($paragraphs as $paragraph) {
      $text = trim((string) $paragraph);
      if ($text === '') {
        continue;
      }
      if (self::isFooterParagraph($text)) {
        continue;
      }
      if (self::isLinkParagraph($text)) {
        if ($ctaLabel === '') {
          $ctaLabel = $isGerman ? 'Meldung ansehen' : 'View your report';
        }
        continue;
      }
      if (self::isSignatureParagraph($text)) {
        continue;
      }
      $kept[] = $text;
    }

    $intro = array_shift($kept) ?? '';
    return [
      'intro' => $intro,
      'body_blocks' => array_values($kept),
      'cta_label' => $ctaLabel,
    ];
  }

  /**
   * True for paragraphs that duplicate the branded footer/Reply-To.
   *
   * MailBrandingService renders field_email_footer and
   * field_jurisdiction_e_mail (as Reply-To) automatically for
   * jurisdiction-mode mails; keeping them in body_blocks would print the
   * jurisdiction address and contact mail twice.
   */
  private static function isFooterParagraph(string $text): bool {
    return (bool) preg_match('/field_jurisdiction(?:_address|_e_mail)\b|field_email_footer/i', $text);
  }

  /**
   * True for paragraphs whose only purpose is the "view your report" link.
   *
   * Matches the two generic Drupal tokens ([site:url], [node:url] and its
   * :path/:absolute variants) as well as markaspot_token's custom
   * [node:markaspot_frontend_url] — dorsten's real ECA copy uses the
   * latter exclusively, never [site:url]/[node:url]. All three are
   * superseded by NotificationTextBuilder's own CTA (branding
   * frontend_base_url + jurisdiction slug + request_id), so the paragraph
   * carrying them is dropped rather than kept as inert dead-link text.
   *
   * Deliberately narrow: a bare "https://…" URL (e.g. the Bonn business-
   * hours link in the shipped process_tunr6d6 defaults) is NOT a link
   * paragraph here — that is genuine tenant content unrelated to the
   * report-view CTA and must survive the migration untouched.
   */
  private static function isLinkParagraph(string $text): bool {
    return (bool) preg_match('/\[site:url\]|\[node:url(?::[^\]]*)?\]|\[node:markaspot_frontend_url\]/i', $text);
  }

  /**
   * True for the legacy "this is an automatically generated email" line.
   */
  private static function isSignatureParagraph(string $text): bool {
    return (bool) preg_match('/^--|automat\w*\s+(?:generat\w*|generier\w*)|automated\s+email/iu', trim($text));
  }

  /**
   * Simple DE/EN detection for the invented cta_label string.
   *
   * Per the migration brief: no token/config signal reliably carries the
   * mail's language at this stage (langcode on the eca.eca.* config entity
   * is the authoring language of the config entity itself, not
   * necessarily the citizen-facing mail language), so this falls back to
   * scanning the legacy body for common German report-mail nouns.
   */
  private static function looksGerman(string $text): bool {
    return (bool) preg_match('/\bIhre\b|\bMeldung\b/u', $text);
  }

}
