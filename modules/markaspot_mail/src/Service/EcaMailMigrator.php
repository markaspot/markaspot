<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

use Drupal\Core\Config\Config;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
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
 * actions already migrated. The ECA action itself is always rewired onto
 * markaspot_mail_send_notification, but the markaspot_mail.texts write
 * for its key is three-way guarded (see reconcileTextsForKey()) so a
 * re-run — or a later --map override landing on an already-migrated key
 * — never clobbers wording an admin has since edited through the notification
 * texts form.
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

  private const PRIVATE_BACKUP_DIRECTORY = 'private://markaspot_mail_migrate_backup';

  private const TEMPORARY_BACKUP_DIRECTORY = 'temporary://markaspot_mail_migrate_backup';

  private const SHIPPED_TEXTS_FILE = 'config/install/markaspot_mail.texts.yml';

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

  /**
   * Lazily-loaded, cached decode of config/install/markaspot_mail.texts.yml.
   *
   * @var array<string, array<string, mixed>>|null
   */
  private ?array $shippedDefaultsCache = NULL;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly LoggerInterface $logger,
    private readonly ?StreamWrapperManagerInterface $streamWrapperManager = NULL,
  ) {}

  /**
   * Scans selected eca.eca.* config for action_send_email_action actions.
   *
   * Read-only: never touches config, safe to call any number of times. The
   * command defaults remain limited to enabled workflows, while update hooks
   * can include a disabled model so enabling it later cannot restore a legacy
   * mail path.
   *
   * @param list<string>|null $configNames
   *   Config names to scan, or NULL to scan every eca.eca.* config.
   * @param bool $includeInactive
   *   Whether disabled workflows should be analyzed.
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
  public function analyze(?array $configNames = NULL, bool $includeInactive = FALSE): array {
    $findings = [];
    $configNames ??= $this->configFactory->listAll(self::ECA_CONFIG_PREFIX);
    foreach ($configNames as $configName) {
      $raw = $this->configFactory->get($configName)->getRawData();
      if (!$includeInactive && ($raw['status'] ?? FALSE) !== TRUE) {
        continue;
      }
      $findings = array_merge($findings, $this->analyzeModel($configName, $raw));
    }
    return $this->annotateSharedKeyConflicts($findings);
  }

  /**
   * Applies the migration for a set of findings.
   *
   * Text writes are guarded three ways per notification_key, so a re-run
   * (or a --map override landing on an already-migrated key) never
   * clobbers an admin's post-migration edit: (1) the live config for the
   * key still matches the shipped install default — a first migration
   * replacing neutral boilerplate with tenant wording, write it; (2) the
   * live config already matches what this run would write — idempotent,
   * no-op; (3) the live config differs from both — an admin (or an
   * earlier migration run) has customized it, so the write is skipped
   * entirely and the row is flagged texts_preserved with a reason. In all
   * three cases the ECA action itself is still rewired onto
   * markaspot_mail_send_notification; only the text write is guarded.
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
   *   already_migrated), text_applied (bool), texts_preserved (bool),
   *   texts_preserved_reason (string or NULL), discarded_variant_of
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
      $modelActions = [];

      foreach ($configFindings as $finding) {
        $activityId = (string) $finding['activity_id'];

        if ($finding['_plugin'] === self::NOTIFICATION_PLUGIN) {
          $report[] = $this->applyResultRow($configName, $activityId, (string) $finding['suggested_key'], 'already_migrated', FALSE, NULL, NULL, FALSE, NULL);
          if ((string) $finding['suggested_key'] !== '') {
            $modelActions[$activityId] = [
              'object' => ($finding['_object_raw'] ?? '') !== '' ? $finding['_object_raw'] : 'entity',
              'notification_key' => (string) $finding['suggested_key'],
              'recipient' => (string) $finding['_recipient_raw'],
            ];
          }
          continue;
        }

        $key = $mapOverrides[$activityId] ?? $finding['suggested_key'];
        if ($key === NULL || $key === '') {
          $report[] = $this->applyResultRow($configName, $activityId, '', 'skipped_unresolved', FALSE, NULL, NULL, FALSE, NULL);
          continue;
        }

        if ($backupPath === NULL) {
          $backupPath = $this->writeBackup($configName, $raw, $timestamp);
        }

        $isFirstForKey = !isset($usedKeysThisRun[$key]);
        $textApplied = FALSE;
        $textsPreserved = FALSE;
        $textsPreservedReason = NULL;

        if ($isFirstForKey) {
          $usedKeysThisRun[$key] = $activityId;
          [$textApplied, $textsPreserved, $textsPreservedReason, $written] = $this->reconcileTextsForKey($textsConfig, $key, $finding);
          if ($written) {
            $textsChanged = TRUE;
          }
        }

        $raw['actions'][$activityId] = [
          'label' => $finding['activity_label'],
          'plugin' => self::NOTIFICATION_PLUGIN,
          'configuration' => [
            // ECA resolves the acted-upon entity from this token name (see
            // EcaObject::getEntities()). Without it the action receives NULL
            // under real ECA execution and silently skips every send — unit
            // tests pass the node directly and never catch that.
            'object' => ($finding['_object_raw'] ?? '') !== '' ? $finding['_object_raw'] : 'entity',
            'notification_key' => $key,
            'recipient' => $finding['_recipient_raw'],
          ],
          'successors' => $finding['_successors_raw'],
        ];
        $modelActions[$activityId] = $raw['actions'][$activityId]['configuration'];
        $configChanged = TRUE;

        $report[] = $this->applyResultRow(
          $configName,
          $activityId,
          $key,
          'migrated',
          $textApplied,
          $isFirstForKey ? NULL : $usedKeysThisRun[$key],
          $backupPath,
          $textsPreserved,
          $textsPreservedReason,
        );
      }

      [$modelSynced, $modelBackupPath] = $this->reconcileBpmnModel(
        $raw,
        $modelActions,
        $timestamp,
      );
      if ($modelSynced) {
        $configChanged = TRUE;
        foreach ($report as &$row) {
          if ($row['config_name'] === $configName
            && isset($modelActions[$row['activity_id']])) {
            $row['model_synced'] = TRUE;
            $row['backup_path'] ??= $modelBackupPath;
          }
        }
        unset($row);
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
   * Applies the three-tier text-overwrite guard for one notification_key.
   *
   * Only called once per key per apply() run (the first action claiming
   * that key); every later action targeting the same key is reported as
   * a discarded_variant_of duplicate by the caller and never reaches
   * here, regardless of which tier this call resolved to.
   *
   * @return array{0: bool, 1: bool, 2: string|null, 3: bool}
   *   [textApplied, textsPreserved, textsPreservedReason, wroteConfig].
   */
  private function reconcileTextsForKey(Config $textsConfig, string $key, array $finding): array {
    $wouldWrite = $this->buildTextsPayload($finding);
    $current = $this->normalizeSlots((array) $textsConfig->get($key));
    $shippedDefault = $this->normalizeSlots($this->getShippedDefaultSlots($key));

    if ($current === $shippedDefault) {
      // Tier 1: still the neutral shipped boilerplate, safe to replace
      // with the tenant's own wording.
      $textsConfig->set($key, $wouldWrite);
      return [TRUE, FALSE, NULL, TRUE];
    }

    if ($current === $wouldWrite) {
      // Tier 2: already exactly what this run would write (a previous
      // apply() run, most likely) — idempotent no-op.
      return [TRUE, FALSE, NULL, FALSE];
    }

    // Tier 3: live config has been customized (an admin edit, or a prior
    // migration run with different source wording) and matches neither
    // the shipped default nor this action's own text. The ECA action is
    // still rewired by the caller; only the text write is skipped so the
    // customization survives.
    return [FALSE, TRUE, 'existing wording differs from shipped default, keeping it', FALSE];
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
    bool $textsPreserved,
    ?string $textsPreservedReason,
  ): array {
    return [
      'config_name' => $configName,
      'activity_id' => $activityId,
      'notification_key' => $notificationKey,
      'status' => $status,
      'text_applied' => $textApplied,
      'discarded_variant_of' => $discardedVariantOf,
      'backup_path' => $backupPath,
      'model_synced' => FALSE,
      'texts_preserved' => $textsPreserved,
      'texts_preserved_reason' => $textsPreservedReason,
    ];
  }

  /**
   * Rewrites the authoritative BPMN source for migrated runtime actions.
   *
   * @param array<string, mixed> $ecaRaw
   *   Runtime ECA configuration, updated by reference with the data hash.
   * @param array<string, array<string, mixed>> $actions
   *   Activity IDs and their notification action configuration.
   * @param string $timestamp
   *   Backup directory timestamp.
   *
   * @return array{0: bool, 1: string|null}
   *   Whether the BPMN source changed and its backup path.
   */
  private function reconcileBpmnModel(array &$ecaRaw, array $actions, string $timestamp): array {
    if ($actions === []) {
      return [FALSE, NULL];
    }

    $modelerId = (string) ($ecaRaw['third_party_settings']['modeler_api']['modeler_id'] ?? '');
    $modelId = (string) ($ecaRaw['id'] ?? '');
    if ($modelerId !== 'bpmn_io' || $modelId === '') {
      return [FALSE, NULL];
    }

    $configName = 'modeler_api.data_model.eca_' . $modelerId . '_' . $modelId;
    $modelConfig = $this->configFactory->getEditable($configName);
    $modelRaw = $modelConfig->getRawData();
    $xml = $modelRaw['data'] ?? NULL;
    if (!is_string($xml) || $xml === '') {
      return [FALSE, NULL];
    }

    $updatedXml = self::migrateBpmnActions($xml, $actions);
    if ($updatedXml === NULL || $updatedXml === $xml) {
      return [FALSE, NULL];
    }

    $backupPath = $this->writeBackup($configName, $modelRaw, $timestamp);
    $modelRaw['data'] = $updatedXml;
    $modelConfig->setData($modelRaw)->save();
    $ecaRaw['third_party_settings']['modeler_api']['data'] = 'hash:' . md5($updatedXml);

    return [TRUE, $backupPath];
  }

  /**
   * Converts selected legacy BPMN tasks to notification-mail actions.
   *
   * @param string $xml
   *   BPMN XML source.
   * @param array<string, array<string, mixed>> $actions
   *   Activity IDs and action configuration.
   *
   * @return string|null
   *   Updated XML, or NULL when the model cannot be parsed safely.
   */
  private static function migrateBpmnActions(string $xml, array $actions): ?string {
    $previous = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument();
    $document->preserveWhiteSpace = FALSE;
    $document->formatOutput = TRUE;
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
      return NULL;
    }

    $bpmnNamespace = 'http://www.omg.org/spec/BPMN/20100524/MODEL';
    $camundaNamespace = 'http://camunda.org/schema/1.0/bpmn';
    $changed = FALSE;
    foreach ($document->getElementsByTagNameNS($bpmnNamespace, 'task') as $task) {
      if (!$task instanceof \DOMElement) {
        continue;
      }
      $activityId = $task->getAttribute('id');
      if (!isset($actions[$activityId])) {
        continue;
      }

      $configuration = $actions[$activityId];
      $task->setAttributeNS(
        $camundaNamespace,
        'camunda:modelerTemplate',
        'org.drupal.action.' . self::NOTIFICATION_PLUGIN,
      );

      $extension = NULL;
      foreach ($task->childNodes as $child) {
        if ($child instanceof \DOMElement
          && $child->namespaceURI === $bpmnNamespace
          && $child->localName === 'extensionElements') {
          $extension = $child;
          break;
        }
      }
      if (!$extension instanceof \DOMElement) {
        $extension = $document->createElementNS($bpmnNamespace, 'bpmn2:extensionElements');
        $task->insertBefore($extension, $task->firstChild);
      }

      $properties = NULL;
      foreach ($extension->childNodes as $child) {
        if ($child instanceof \DOMElement
          && $child->namespaceURI === $camundaNamespace
          && $child->localName === 'properties') {
          $properties = $child;
          break;
        }
      }
      if (!$properties instanceof \DOMElement) {
        $properties = $document->createElementNS($camundaNamespace, 'camunda:properties');
        $extension->insertBefore($properties, $extension->firstChild);
      }

      $pluginProperty = NULL;
      foreach ($properties->childNodes as $child) {
        if ($child instanceof \DOMElement
          && $child->namespaceURI === $camundaNamespace
          && $child->localName === 'property'
          && $child->getAttribute('name') === 'pluginid') {
          $pluginProperty = $child;
          break;
        }
      }
      if (!$pluginProperty instanceof \DOMElement) {
        $pluginProperty = $document->createElementNS($camundaNamespace, 'camunda:property');
        $pluginProperty->setAttribute('name', 'pluginid');
        $properties->appendChild($pluginProperty);
      }
      $pluginProperty->setAttribute('value', self::NOTIFICATION_PLUGIN);

      $remove = [];
      foreach ($extension->childNodes as $child) {
        if ($child instanceof \DOMElement
          && $child->namespaceURI === $camundaNamespace
          && $child->localName === 'field') {
          $remove[] = $child;
        }
      }
      foreach ($remove as $field) {
        $extension->removeChild($field);
      }

      foreach (['object', 'notification_key', 'recipient'] as $name) {
        $field = $document->createElementNS($camundaNamespace, 'camunda:field');
        $field->setAttribute('name', $name);
        $value = $document->createElementNS($camundaNamespace, 'camunda:string');
        $value->appendChild($document->createTextNode((string) ($configuration[$name] ?? '')));
        $field->appendChild($value);
        $extension->appendChild($field);
      }
      $changed = TRUE;
    }

    return $changed ? $document->saveXML() ?: NULL : $xml;
  }

  /**
   * Builds the subject/intro/body_blocks/cta_label payload for one action.
   *
   * Pure: does not touch config. Headline and preheader are deliberately
   * left empty: NotificationTextBuilder renders correctly without them,
   * and there is no legacy source field to invent them from.
   *
   * @return array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string}
   *   The six-slot payload this action's wording would write.
   */
  private function buildTextsPayload(array $finding): array {
    $subject = trim((string) $finding['_subject_raw']);
    $transformed = $this->transformMessage((string) $finding['_message_raw']);

    return [
      'subject' => $subject,
      'headline' => '',
      'intro' => $transformed['intro'],
      'body_blocks' => $transformed['body_blocks'],
      'cta_label' => $transformed['cta_label'],
      'preheader' => '',
    ];
  }

  /**
   * Normalizes a six-slot texts array for exact-equality comparison.
   *
   * Coerces missing keys to their empty value so a live config entry, a
   * shipped-default entry and a freshly-built payload compare equal
   * whenever their actual content matches, regardless of which keys each
   * source happened to have present.
   *
   * @param array<string, mixed> $slots
   *   A slot_set-shaped array (subject/headline/intro/body_blocks/
   *   cta_label/preheader), possibly with missing keys.
   *
   * @return array{subject: string, headline: string, intro: string, body_blocks: list<string>, cta_label: string, preheader: string}
   *   The same slots with every key present and type-coerced.
   */
  private function normalizeSlots(array $slots): array {
    return [
      'subject' => (string) ($slots['subject'] ?? ''),
      'headline' => (string) ($slots['headline'] ?? ''),
      'intro' => (string) ($slots['intro'] ?? ''),
      'body_blocks' => array_values(array_map('strval', (array) ($slots['body_blocks'] ?? []))),
      'cta_label' => (string) ($slots['cta_label'] ?? ''),
      'preheader' => (string) ($slots['preheader'] ?? ''),
    ];
  }

  /**
   * Reads one notification_key's slots from the shipped install default.
   *
   * Reads config/install/markaspot_mail.texts.yml directly off disk
   * (never the active config store — that is what apply() is about to
   * overwrite) so a freshly-installed tenant that never touched the
   * admin form is recognized as "still neutral, safe to replace", while
   * a tenant that edited even one slot after installing is not.
   *
   * @return array<string, mixed>
   *   The shipped slots for $key, or an empty array if the key or file is
   *   missing (fails safe towards tier 3 "preserve", never towards
   *   tier 1 "overwrite").
   */
  private function getShippedDefaultSlots(string $key): array {
    if ($this->shippedDefaultsCache === NULL) {
      $path = $this->moduleExtensionList->getPath('markaspot_mail') . '/' . self::SHIPPED_TEXTS_FILE;
      $contents = @file_get_contents($path);
      $decoded = $contents !== FALSE ? Yaml::decode($contents) : NULL;
      $this->shippedDefaultsCache = is_array($decoded) ? $decoded : [];
    }
    $slots = $this->shippedDefaultsCache[$key] ?? [];
    return is_array($slots) ? $slots : [];
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
   *   The private:// URI, or a temporary:// URI when private storage is absent.
   *
   * @throws \RuntimeException
   *   When the backup directory cannot be created or the file cannot be
   *   written — apply() must not silently proceed to rewrite config
   *   without a backup on disk.
   */
  private function writeBackup(string $configName, array $raw, string $timestamp): string {
    $baseDirectory = $this->streamWrapperManager?->isValidScheme('private') === TRUE
      ? self::PRIVATE_BACKUP_DIRECTORY
      : self::TEMPORARY_BACKUP_DIRECTORY;
    if ($baseDirectory === self::TEMPORARY_BACKUP_DIRECTORY) {
      $this->logger->warning('Private file storage is unavailable. ECA migration backups are temporary and should be copied before container cleanup.');
    }
    $directory = $baseDirectory . '/' . $timestamp;
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
        '_object_raw' => (string) ($configuration['object'] ?? ''),
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
      '_object_raw' => (string) ($configuration['object'] ?? ''),
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
