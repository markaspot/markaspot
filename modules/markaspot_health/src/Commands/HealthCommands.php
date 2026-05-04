<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_health\HealthCheckPluginManager;
use Drupal\markaspot_health\HealthCheckResult;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Mark-a-Spot health checks.
 */
class HealthCommands extends DrushCommands {

  /**
   * Constructs HealthCommands.
   */
  public function __construct(
    protected HealthCheckPluginManager $pluginManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected UpdateHookRegistry $updateHookRegistry,
  ) {
    parent::__construct();
  }

  /**
   * Runs all Mark-a-Spot health checks.
   *
   * @param array $options
   *   Command options.
   *
   * @option severity
   *   Filter by severity: error, warning, or info. Defaults to all.
   * @option format
   *   Output format: table or json. Defaults to table.
   * @option exit-non-zero
   *   When set, exit code equals the number of failed error-severity checks.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields|null
   *   Structured rows for table output, NULL when format is json.
   */
  #[CLI\Command(name: 'markaspot:health', aliases: ['mas:health'])]
  #[CLI\Option(name: 'severity', description: 'Filter by severity: error, warning, info.')]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Option(name: 'exit-non-zero', description: 'Exit non-zero when error-severity checks fail.')]
  #[CLI\Usage(name: 'drush markaspot:health', description: 'Run all checks and print a table.')]
  #[CLI\Usage(name: 'drush markaspot:health --severity=error --format=json', description: 'Print JSON for error-severity failures only.')]
  public function health(
    array $options = [
      'severity' => NULL,
      'format' => 'table',
      'exit-non-zero' => FALSE,
    ],
  ): ?RowsOfFields {
    $results = $this->pluginManager->runAll();

    $severityFilter = $options['severity'] ?? NULL;
    if ($severityFilter !== NULL && $severityFilter !== '') {
      $results = array_values(array_filter(
        $results,
        static fn(HealthCheckResult $r): bool => $r->severity === $severityFilter,
      ));
    }

    $errorCount = 0;
    foreach ($results as $result) {
      if (!$result->passed && $result->severity === 'error') {
        $errorCount++;
      }
    }

    $format = $options['format'] ?? 'table';
    if ($format === 'json') {
      $this->output()->writeln((string) json_encode(
        array_map(
          static fn(HealthCheckResult $r): array => $r->toArray(),
          $results,
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
      ));
      $this->maybeFail($options, $errorCount);
      return NULL;
    }

    $rows = [];
    foreach ($results as $result) {
      $rows[] = [
        'id' => $result->id(),
        'label' => $result->label,
        'status' => $result->passed ? 'PASS' : 'FAIL',
        'severity' => $result->severity,
        'count' => $result->count,
        'message' => $result->message,
        'fix' => $result->passed ? '' : ($result->fixHint ?? ''),
      ];
    }

    $this->maybeFail($options, $errorCount);
    return new RowsOfFields($rows);
  }

  /**
   * Repairs schema entries that block normal database updates.
   *
   * Modules with system.schema 0 or no entry are treated by Drupal as coming
   * from an unsupported previous major version. This command only moves such
   * already-enabled modules to Drupal's minimum supported schema baseline.
   * Subsequent hook_update_N implementations still run via updatedb.
   *
   * @param array $options
   *   Command options.
   *
   * @option apply
   *   Write repaired schema baselines. Defaults to dry-run.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Rows describing missing schema entries and target versions.
   */
  #[CLI\Command(name: 'markaspot:health:repair-schema', aliases: ['mas:repair-schema'])]
  #[CLI\Option(name: 'apply', description: 'Write repaired schema baselines. Defaults to dry-run.')]
  #[CLI\FieldLabels(labels: [
    'module' => 'Module',
    'current' => 'Current',
    'target' => 'Target',
    'action' => 'Action',
  ])]
  #[CLI\Usage(name: 'drush markaspot:health:repair-schema', description: 'Dry-run schema baseline repair for already-enabled modules.')]
  #[CLI\Usage(name: 'drush markaspot:health:repair-schema --apply', description: 'Seed the Drupal core minimum schema baseline, then run drush updatedb -y.')]
  public function repairModuleSchema(
    array $options = [
      'apply' => FALSE,
    ],
  ): RowsOfFields {
    $apply = !empty($options['apply']);
    $modules = (array) $this->configFactory->get('core.extension')->get('module');
    $target = \Drupal::CORE_MINIMUM_SCHEMA_VERSION;

    $rows = [];
    foreach (array_keys($modules) as $module) {
      $current = $this->updateHookRegistry->getInstalledVersion($module);
      if ($current > 0) {
        continue;
      }

      if ($apply) {
        $this->updateHookRegistry->setInstalledVersion($module, $target);
      }

      $rows[] = [
        'module' => $module,
        'current' => $current,
        'target' => $target,
        'action' => $apply ? 'set-baseline' : 'dry-run',
      ];
    }

    if ($rows === []) {
      $this->logger()->success('No missing or zero module schema entries found.');
    }
    elseif ($apply) {
      $this->logger()->notice('Schema baselines repaired. Run drush updatedb -y next.');
    }
    else {
      $this->logger()->warning('Dry-run only. Re-run with --apply, then run drush updatedb -y.');
    }

    return new RowsOfFields($rows);
  }

  /**
   * Throws a command failure when --exit-non-zero is set and errors exist.
   *
   * Drush 12 derives the exit code from a thrown exception, so this is the
   * canonical way to signal failure from a command method.
   *
   * @param array<string, mixed> $options
   *   Command options.
   * @param int $errorCount
   *   Number of failed error-severity checks.
   */
  protected function maybeFail(array $options, int $errorCount): void {
    if (!empty($options['exit-non-zero']) && $errorCount > 0) {
      throw new \RuntimeException(sprintf(
        '%d error-severity health check(s) failed.',
        $errorCount,
      ));
    }
  }

  /**
   * Adds an administrator user as admin-role member of every jur and org group.
   *
   * Drupal Group module's UI does not have a bulk "add me to all groups"
   * action. Operators who land on the health-check page after a `cim` or
   * after a tenant created a new group regularly hit the case where they
   * have site-level admin access but no group-level membership. This
   * command bulk-adds the chosen user as the admin group-role member of
   * every jur and org group they are not already a member of.
   *
   * @param array $options
   *   Command options.
   *
   * @option user
   *   Username to onboard. Defaults to user 1 (root admin).
   * @option dry-run
   *   Print what would be added without saving.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Rows describing the per-group action taken.
   */
  #[CLI\Command(name: 'markaspot:health:onboard-admin', aliases: ['mas:onboard-admin'])]
  #[CLI\Option(name: 'user', description: 'Username to onboard. REQUIRED: must hold the site-level administrator role.')]
  #[CLI\Option(name: 'dry-run', description: 'Print actions without saving.')]
  #[CLI\FieldLabels(labels: [
    'group_id' => 'Group',
    'group_type' => 'Type',
    'group_label' => 'Label',
    'action' => 'Action',
    'group_role' => 'Group role',
  ])]
  public function onboardAdmin(
    array $options = [
      'user' => self::REQ,
      'dry-run' => FALSE,
    ],
  ): RowsOfFields {
    $username = $options['user'] ?? NULL;
    if ($username === NULL || $username === '') {
      throw new \RuntimeException('--user is required. Example: drush mas:onboard-admin --user=admin');
    }
    $user = $this->resolveUser($username);
    if (!in_array('administrator', $user->getRoles(), TRUE)) {
      throw new \RuntimeException(sprintf(
        'User "%s" does not hold the site-level administrator role. Refusing to onboard a non-admin user.',
        $user->getAccountName(),
      ));
    }
    $dryRun = !empty($options['dry-run']);
    if (!$dryRun) {
      // Drush respects -y / --yes globally and skips the prompt when set.
      $confirmed = $this->io()->confirm(
        sprintf('Add %s as admin of every jur/org group? This cannot be undone automatically.', $user->getAccountName()),
        FALSE,
      );
      if (!$confirmed) {
        $this->logger()->notice('Aborted by operator.');
        return new RowsOfFields([]);
      }
    }

    $groupStorage = $this->entityTypeManager->getStorage('group');
    // accessCheck(FALSE) is intentional: Drush CLI defaults to anonymous,
    // which would return zero groups and silently no-op. The command is
    // explicitly privileged; do not "fix" this by enabling access checks.
    $ids = $groupStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', array_values(array_unique([$this->jurisdictionGroupType(), 'org'])), 'IN')
      ->execute();
    if ($ids === []) {
      $this->logger()->notice('No jur or org groups found.');
      return new RowsOfFields([]);
    }

    $rows = [];
    foreach ($groupStorage->loadMultiple($ids) as $group) {
      if (!$group instanceof GroupInterface) {
        continue;
      }
      $type = $group->bundle();
      $adminRole = $this->resolveAdminGroupRole($type);
      $member = $group->getMember($user);
      if ($member) {
        $rows[] = [
          'group_id' => (string) $group->id(),
          'group_type' => $type,
          'group_label' => (string) $group->label(),
          'action' => 'already member',
          'group_role' => '',
        ];
        continue;
      }
      if ($adminRole === NULL) {
        $rows[] = [
          'group_id' => (string) $group->id(),
          'group_type' => $type,
          'group_label' => (string) $group->label(),
          'action' => 'no admin role',
          'group_role' => '',
        ];
        continue;
      }
      if ($dryRun) {
        $rows[] = [
          'group_id' => (string) $group->id(),
          'group_type' => $type,
          'group_label' => (string) $group->label(),
          'action' => 'would add',
          'group_role' => $adminRole,
        ];
        continue;
      }
      try {
        $group->addMember($user, ['group_roles' => [$adminRole]]);
        $this->logger()->notice('Onboarded @user (uid @uid) as @role on group @gid (@label).', [
          '@user' => $user->getAccountName(),
          '@uid' => $user->id(),
          '@role' => $adminRole,
          '@gid' => $group->id(),
          '@label' => $group->label(),
        ]);
        $action = 'added';
      }
      catch (\Throwable $e) {
        $this->logger()->error('Failed to onboard @user on group @gid: @msg', [
          '@user' => $user->getAccountName(),
          '@gid' => $group->id(),
          '@msg' => $e->getMessage(),
        ]);
        $action = 'ERROR: ' . $e->getMessage();
      }
      $rows[] = [
        'group_id' => (string) $group->id(),
        'group_type' => $type,
        'group_label' => (string) $group->label(),
        'action' => $action,
        'group_role' => $adminRole,
      ];
    }

    return new RowsOfFields($rows);
  }

  /**
   * Resolves the type-specific admin group_role machine name.
   *
   * Looks up the group_role with admin = TRUE for the given group_type
   * via Entity API instead of relying on a string-concat convention. A
   * future custom group type without a flagged admin role returns NULL,
   * which the caller surfaces as a "no admin role" output row.
   */
  protected function resolveAdminGroupRole(string $groupType): ?string {
    $matches = $this->entityTypeManager
      ->getStorage('group_role')
      ->loadByProperties([
        'group_type' => $groupType,
        'admin' => TRUE,
      ]);
    if ($matches === []) {
      return NULL;
    }
    return (string) array_key_first($matches);
  }

  /**
   * Resolves the user argument or falls back to user 1.
   */
  protected function resolveUser(?string $username): UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    if ($username === NULL || $username === '') {
      $user = $storage->load(1);
      if (!$user instanceof UserInterface) {
        throw new \RuntimeException('User 1 not found.');
      }
      return $user;
    }
    $matches = $storage->loadByProperties(['name' => $username]);
    $user = reset($matches) ?: NULL;
    if (!$user instanceof UserInterface) {
      throw new \RuntimeException(sprintf('User "%s" not found.', $username));
    }
    return $user;
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  private function jurisdictionGroupType(): string {
    $configured = $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
