<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markaspot_mail\Service\EcaMailMigrator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command migrating legacy ECA email actions to markaspot_mail.
 *
 * Thin wrapper around EcaMailMigrator: all analysis and rewrite logic lives
 * there so it can be unit tested without a bootstrapped Drupal. This class
 * only parses/validates CLI options and formats EcaMailMigrator's array
 * output as drush tables.
 */
class MailTextsMigrateCommands extends DrushCommands {

  public function __construct(
    protected EcaMailMigrator $migrator,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * Migrates legacy ECA status/confirmation mails onto markaspot_mail.texts.
   *
   * Default mode is a dry-run analysis: every active eca.eca.* config is
   * scanned for action_send_email_action actions, and for each one a
   * suggested markaspot_mail.texts key is printed (report_confirmation for
   * insert-triggered models, status_open/status_closed/
   * status_not_responsible for update-triggered models, resolved from the
   * tenant's own status taxonomy term name gating that branch). Nothing is
   * written in this mode.
   *
   * Pass --apply to perform the migration: the tenant's own subject/message
   * wording is moved into markaspot_mail.texts (never the shipped
   * defaults), the jurisdiction-footer/link/signature paragraphs specific
   * to the old free-text format are stripped (the branded template renders
   * them structurally instead), and the action_send_email_action entry is
   * replaced by markaspot_mail_send_notification pointing at the resolved
   * key. Events, conditions, gateways and every other action in the model
   * are left byte-identical. A pre-write YAML backup of each touched
   * eca.eca.* config is written to
   * private://markaspot_mail_migrate_backup/<timestamp>/, or temporary://
   * when no private filesystem is configured.
   *
   * Actions that resolve to UNRESOLVED are never migrated by --apply alone
   * — supply --map to give them an explicit notification_key, or leave
   * them for a follow-up run once you've reviewed the dry-run output.
   *
   * @param array $options
   *   Command options.
   *
   * @option apply
   *   Perform the migration. Without this flag the command only analyzes.
   * @option map
   *   Comma-separated ActivityID=notification_key overrides, e.g.
   *   "Activity_1w5ikso=status_closed,Activity_1u8e4au=status_open". Only
   *   used together with --apply. Required to migrate UNRESOLVED actions;
   *   also lets you correct a wrong RESOLVED suggestion before applying.
   * @option format
   *   Output format: table or json. Defaults to table.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields|null
   *   Structured rows for table output, NULL when format is json.
   */
  #[CLI\Command(name: 'markaspot:mail-texts-migrate', aliases: ['mas:mail-texts-migrate'])]
  #[CLI\Option(name: 'apply', description: 'Perform the migration. Without this flag the command only analyzes.')]
  #[CLI\Option(name: 'map', description: 'Comma-separated ActivityID=notification_key overrides.')]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\FieldLabels(labels: [
    'config_name' => 'Config',
    'model_label' => 'Model',
    'activity_id' => 'Activity ID',
    'activity_label' => 'Activity',
    'trigger_event' => 'Trigger',
    'branch_condition' => 'Branch condition',
    'subject_preview' => 'Subject',
    'suggested_key' => 'Suggested key',
    'status' => 'Status',
    'reason' => 'Reason',
    'notification_key' => 'Notification key',
    'text_applied' => 'Text applied',
    'discarded_variant_of' => 'Discarded variant of',
    'backup_path' => 'Backup',
  ])]
  #[CLI\Usage(name: 'drush markaspot:mail-texts-migrate', description: 'Dry-run: analyze every active eca.eca.* config and print suggested notification keys.')]
  #[CLI\Usage(name: 'drush markaspot:mail-texts-migrate --apply', description: 'Apply the migration for every RESOLVED/ALREADY_MIGRATED action.')]
  #[CLI\Usage(name: 'drush markaspot:mail-texts-migrate --apply --map="Activity_1w5ikso=status_closed"', description: 'Apply, overriding one UNRESOLVED (or wrongly suggested) activity.')]
  public function mailTextsMigrate(
    array $options = [
      'apply' => FALSE,
      'map' => '',
      'format' => 'table',
    ],
  ): ?RowsOfFields {
    if (!$this->moduleHandler->moduleExists('eca')) {
      $this->logger()->warning(dt('ECA module is not enabled; nothing to migrate.'));
      return new RowsOfFields([]);
    }

    $findings = $this->migrator->analyze();

    if (empty($options['apply'])) {
      return $this->formatRows($this->dryRunRows($findings), $options);
    }

    $mapOverrides = $this->parseMap((string) ($options['map'] ?? ''));
    $this->validateMapOverrides($mapOverrides);

    $result = $this->migrator->apply($findings, $mapOverrides);
    return $this->formatRows($this->applyRows($result), $options);
  }

  /**
   * Reduces analyze() findings to their display columns.
   *
   * @param list<array<string, mixed>> $findings
   *   Findings as returned by EcaMailMigrator::analyze().
   *
   * @return list<array<string, string>>
   *   Display rows.
   */
  protected function dryRunRows(array $findings): array {
    $rows = [];
    foreach ($findings as $finding) {
      $status = (string) $finding['status'];
      if (!empty($finding['shared_key_conflict'])) {
        $status .= ' (shared key)';
      }
      $rows[] = [
        'config_name' => (string) $finding['config_name'],
        'model_label' => (string) $finding['model_label'],
        'activity_id' => (string) $finding['activity_id'],
        'activity_label' => (string) $finding['activity_label'],
        'trigger_event' => (string) $finding['trigger_event'],
        'branch_condition' => (string) $finding['branch_condition'],
        'subject_preview' => (string) $finding['subject_preview'],
        'suggested_key' => (string) ($finding['suggested_key'] ?? ''),
        'status' => $status,
        'reason' => (string) $finding['reason'],
      ];
    }
    return $rows;
  }

  /**
   * Reduces apply() results to their display columns.
   *
   * @param list<array<string, mixed>> $result
   *   Result rows as returned by EcaMailMigrator::apply().
   *
   * @return list<array<string, string>>
   *   Display rows.
   */
  protected function applyRows(array $result): array {
    $rows = [];
    foreach ($result as $row) {
      $rows[] = [
        'config_name' => (string) $row['config_name'],
        'activity_id' => (string) $row['activity_id'],
        'notification_key' => (string) $row['notification_key'],
        'status' => (string) $row['status'],
        'text_applied' => !empty($row['text_applied']) ? 'yes' : 'no',
        'discarded_variant_of' => (string) ($row['discarded_variant_of'] ?? ''),
        'backup_path' => (string) ($row['backup_path'] ?? ''),
      ];
    }
    return $rows;
  }

  /**
   * Parses the --map option into an ActivityID => notification_key array.
   *
   * @return array<string, string>
   *   The parsed overrides.
   */
  protected function parseMap(string $map): array {
    $map = trim($map);
    if ($map === '') {
      return [];
    }
    $overrides = [];
    foreach (explode(',', $map) as $pair) {
      $parts = explode('=', $pair, 2);
      if (count($parts) !== 2) {
        throw new \RuntimeException(sprintf('--map entry "%s" is not in ActivityID=notification_key form.', trim($pair)));
      }
      [$activityId, $key] = array_map('trim', $parts);
      if ($activityId === '' || $key === '') {
        throw new \RuntimeException(sprintf('--map entry "%s" is not in ActivityID=notification_key form.', trim($pair)));
      }
      $overrides[$activityId] = $key;
    }
    return $overrides;
  }

  /**
   * Fails fast on a --map value outside the four known notification keys.
   *
   * @param array<string, string> $mapOverrides
   *   Activity ID => notification_key overrides, as parsed by parseMap().
   */
  protected function validateMapOverrides(array $mapOverrides): void {
    foreach ($mapOverrides as $activityId => $key) {
      if (!in_array($key, EcaMailMigrator::KNOWN_KEYS, TRUE)) {
        throw new \RuntimeException(sprintf(
          '--map: unknown notification_key "%s" for activity "%s". Known keys: %s.',
          $key,
          $activityId,
          implode(', ', EcaMailMigrator::KNOWN_KEYS),
        ));
      }
    }
  }

  /**
   * Formats rows as table (RowsOfFields) or prints JSON directly.
   *
   * @param list<array<string, string>> $rows
   *   Display rows.
   * @param array<string, mixed> $options
   *   Command options; only 'format' is read.
   */
  protected function formatRows(array $rows, array $options): ?RowsOfFields {
    if (($options['format'] ?? 'table') === 'json') {
      $this->output()->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return NULL;
    }
    return new RowsOfFields($rows);
  }

}
