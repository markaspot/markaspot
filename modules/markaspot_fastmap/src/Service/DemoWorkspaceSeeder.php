<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\markaspot_fastmap\Demo\DemoSeed;
use Drupal\markaspot_fastmap\Demo\DemoWorkspace;
use Drupal\markaspot_nuxt\Plugin\Validation\Constraint\ProjectPromptConstraint;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates or updates one template demo workspace from a seed.
 *
 * Applying a seed is idempotent: every seeded entity has a deterministic UUID
 * (DemoWorkspace::uuid()), so a second run finds and rewrites the same group,
 * terms, start page, reports and media instead of adding new ones. Reports
 * keep their node and therefore their request ID across runs, which keeps the
 * demo.translations keys stable.
 *
 * The seeder never deletes anything except the status-history paragraphs of
 * the reports it rewrites. Removing visitor content is the reset's job
 * (DemoWorkspaceReset), which is gated by the operator allowlist.
 */
final class DemoWorkspaceSeeder {

  /**
   * State key prefix for the last applied seed, keyed by slug.
   *
   * The reset re-applies this copy when no seed directory is configured, so a
   * cron run does not depend on the seed files being mounted.
   */
  public const STATE_PREFIX = 'markaspot_fastmap.demo_seed.';

  /**
   * Report fields only the dashboard writes; a re-seed restores their default.
   */
  private const DASHBOARD_FIELDS = [
    'field_assignee',
    'field_assigned_team',
    'field_status_internal_term',
    'field_priority',
    'field_internal_remark',
  ];

  /**
   * Media keyvalue marking an editorial publication decision.
   *
   * Media listed here are left alone by the AI publication pipeline
   * (_markaspot_vision_publish_safe_media()), which would otherwise keep a
   * seeded photo unpublished because it never went through image analysis.
   */
  private const MANUAL_MEDIA_PUBLICATION = 'markaspot_open311.media_publication_manual';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly WorkspaceProvisioningService $provisioning,
    private readonly FileSystemInterface $fileSystem,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly LoggerInterface $logger,
    private readonly DemoStaffAccount $staffAccount,
    private readonly ?LegalNoticeGenerator $legalNoticeGenerator = NULL,
  ) {}

  /**
   * Applies a seed and returns a summary.
   *
   * @return array{group_id: int, slug: string, created: bool, reports: array<string, string>}
   *   The workspace ID, whether it was created, and the request ID per
   *   report seed key.
   */
  public function seed(DemoSeed $seed): array {
    $slug = $seed->data['slug'];
    $lockName = 'markaspot_fastmap:demo_seed:' . $slug;
    if (!$this->lock->acquire($lockName, 600.0)) {
      throw new \RuntimeException(sprintf('Another seed run for "%s" is in progress.', $slug));
    }

    try {
      return $this->asOperator(function () use ($seed, $slug): array {
        $transaction = $this->database->startTransaction();
        try {
          $result = $this->apply($seed);
          $this->state->set(self::STATE_PREFIX . $slug, $seed->toStorage());
          unset($transaction);
          return $result;
        }
        catch (\Throwable $e) {
          $transaction->rollBack();
          $this->logger->error('Demo workspace seed for @slug failed: @msg', [
            '@slug' => $slug,
            '@msg' => $e->getMessage(),
          ]);
          throw new \RuntimeException(sprintf('Seeding "%s" failed: %s', $slug, $e->getMessage()), 0, $e);
        }
      });
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Runs a callback as the technical operator account (uid 1).
   *
   * Presave hooks and group reference handling consult the current user.
   * Drush and cron both start anonymous; seeding and resetting a demo are
   * operator actions.
   */
  public function asOperator(callable $callback): mixed {
    $operator = $this->entityTypeManager->getStorage('user')->load(1);
    if (!$operator instanceof UserInterface || !$operator->isActive()) {
      // Never fall back to whatever account happens to run the command.
      throw new \RuntimeException('Demo workspaces need an active technical operator account (uid 1).');
    }
    $this->accountSwitcher->switchTo($operator);
    try {
      return $callback();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Loads the jur group with the given slug.
   */
  public function findWorkspace(string $slug): ?GroupInterface {
    $groups = $this->entityTypeManager->getStorage('group')->loadByProperties([
      'type' => $this->provisioning->jurisdictionGroupType(),
      'field_slug' => $slug,
    ]);
    if (count($groups) > 1) {
      throw new \RuntimeException(sprintf('Slug "%s" is used by more than one workspace.', $slug));
    }
    $group = reset($groups);
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Returns the UUIDs of every entity a seed owns, keyed by entity type.
   *
   * @return array<string, string[]>
   *   UUIDs per entity type ID.
   */
  public function seededUuids(DemoSeed $seed): array {
    $uuid = fn(string $kind, string $key): string => $this->uuid($seed, $kind, $key);
    $nodes = [$uuid('page', 'start')];
    $media = [];
    $files = [];
    foreach ($seed->data['reports'] as $report) {
      $nodes[] = $uuid('report', $report['key']);
      if (isset($report['photo'])) {
        $media[] = $uuid('media', $report['key']);
        $files[] = $uuid('file', $report['key']);
      }
    }
    $terms = [];
    foreach ($seed->data['statuses'] as $status) {
      $terms[] = $uuid('status', $status['key']);
    }
    foreach ($seed->data['categories'] as $category) {
      $terms[] = $uuid('category', $category['key']);
    }
    $groups = [$uuid('group', 'workspace')];
    foreach ($seed->data['organisations'] as $organisation) {
      $groups[] = $uuid('organisation', $organisation['key']);
    }
    return [
      'node' => $nodes,
      'media' => $media,
      'file' => $files,
      'taxonomy_term' => $terms,
      'group' => $groups,
    ];
  }

  /**
   * Applies the seed inside the caller's transaction.
   */
  private function apply(DemoSeed $seed): array {
    $data = $seed->data;
    $this->provisioning->ensureLanguagesExist($data['languages']);

    [$group, $created] = $this->upsertWorkspace($seed);
    $groupId = (int) $group->id();
    $this->upsertStaffAccount($seed, $group);

    $statusIds = [];
    foreach ($data['statuses'] as $weight => $status) {
      $statusIds[$status['key']] = (int) $this->upsertTerm($seed, 'service_status', 'status', $status['key'], $groupId, $status['name'], $weight, [
        'field_status_hex' => ['color' => $status['hex']],
        'field_status_icon' => $status['icon'],
        'field_open311_mapping' => $status['mapping'],
      ])->id();
    }

    $categoryIds = [];
    foreach ($data['categories'] as $index => $category) {
      $categoryIds[$category['key']] = (int) $this->upsertTerm($seed, 'service_category', 'category', $category['key'], $groupId, $category['name'], $index, [
        // Same scheme as provisioning: a 1-based position code.
        'field_service_code' => (string) ($index + 1),
        'field_category_hex' => [
          'color' => $category['hex'] ?? WorkspaceProvisioningService::CATEGORY_COLORS[$index % count(WorkspaceProvisioningService::CATEGORY_COLORS)],
        ],
        'field_category_icon' => $category['icon'] ?? $this->provisioning->guessIcon(array_values($category['name'])),
      ])->id();
    }
    if ($group->hasField('field_service_categories')) {
      $group->set('field_service_categories', array_map(static fn(int $tid): array => ['target_id' => $tid], array_values($categoryIds)));
    }

    $organisationIds = $this->upsertOrganisations($seed, $groupId);
    $this->upsertStartPage($seed, $group, $created);

    $requestIds = [];
    $translations = [];
    foreach ($data['reports'] as $report) {
      $node = $this->upsertReport($seed, $report, $groupId, $statusIds, $categoryIds, $organisationIds);
      $requestId = $node->hasField('request_id') ? (string) $node->get('request_id')->value : '';
      if ($requestId === '') {
        throw new \RuntimeException('Seeded reports have no request ID; markaspot_request_id must be enabled.');
      }
      $requestIds[$report['key']] = $requestId;
      foreach ($data['languages'] as $langcode) {
        $translations[$requestId][$langcode] = [
          'title' => $report['title'][$langcode],
          'description' => $report['description'][$langcode],
          'statusNotes' => array_map(static fn(array $entry): string => $entry['note'][$langcode], $report['history']),
        ];
      }
    }

    // Translations are always regenerated from the live request IDs, so the
    // block heals itself if a seeded report ever had to be re-created.
    $config = DemoWorkspace::nuxtConfig($group);
    $config[DemoWorkspace::CONFIG_KEY]['translations'] = $translations;
    // The first report is the deep link target of the solution pages. Its
    // request ID comes from the live node: IDs count per workspace and year.
    $config[DemoWorkspace::CONFIG_KEY]['showcase_request_id'] = $requestIds[$data['reports'][0]['key']] ?? NULL;
    $group->set('field_nuxt_config', $this->encode($config));
    $group->save();

    if ($data['operator'] !== NULL && $this->legalNoticeGenerator !== NULL) {
      try {
        $this->legalNoticeGenerator->syncGroup($group, $data['languages']);
      }
      catch (\Throwable $e) {
        // The demo works without an Impressum text; the operator block in the
        // settings API still names the operator.
        $this->logger->warning('Legal notice for demo workspace @slug was not generated: @msg', [
          '@slug' => $data['slug'],
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->notice('Demo workspace @slug seeded (group @gid, @count reports).', [
      '@slug' => $data['slug'],
      '@gid' => $groupId,
      '@count' => count($requestIds),
    ]);

    return [
      'group_id' => $groupId,
      'slug' => $data['slug'],
      'created' => $created,
      'reports' => $requestIds,
    ];
  }

  /**
   * Creates or rewrites the workspace group.
   *
   * @return array{0: \Drupal\group\Entity\GroupInterface, 1: bool}
   *   The saved group and whether it was created.
   */
  private function upsertWorkspace(DemoSeed $seed): array {
    $data = $seed->data;
    $slug = $data['slug'];
    $group = $this->findWorkspace($slug);
    $created = $group === NULL;

    if ($group !== NULL && !DemoWorkspace::isDemo($group)) {
      // Never turn a customer workspace into a demo, whatever its slug.
      throw new \RuntimeException(sprintf('Slug "%s" belongs to a workspace without a demo block; refusing to touch it.', $slug));
    }

    $previous = $group !== NULL ? DemoWorkspace::block($group) : NULL;
    $map = $data['map'];

    // Rebuilt from scratch on every run: whatever the dashboard changed in the
    // demo goes back to the seeded configuration.
    $config = $this->provisioning->buildNuxtConfig(
      $data['name'],
      $slug,
      $map['lat'],
      $map['lng'],
      $map['zoom'],
      $data['template'],
      $data['languages'],
      $data['default_language'],
    );
    if ($data['theme'] !== NULL) {
      $config['theme'] = array_merge($config['theme'], $data['theme']);
    }
    if ($data['claim'] !== []) {
      $config['client']['claim'] = $data['claim'];
    }
    // Same convention as provisioning: "report" is the default preset.
    if ($data['wording'] !== 'report') {
      $config['i18n']['wording'] = $data['wording'];
    }
    $config[DemoWorkspace::CONFIG_KEY] = [
      'template' => $data['template'],
      'seedVersion' => $data['seed_version'],
      // Filled in after the reports have request IDs.
      'translations' => $previous['translations'] ?? [],
      'task' => $data['task'],
    ];

    if ($data['boundary']['geometry'] ?? NULL) {
      $boundary = $this->provisioning->buildBoundaryJson($data['boundary']['geometry'], $data['name']);
    }
    else {
      $boundary = $this->encode($this->provisioning->generateCircleBoundary(
        $map['lat'],
        $map['lng'],
        $data['boundary']['radius_km'] ?? 1.0,
      ));
    }

    if ($group === NULL) {
      $group = $this->entityTypeManager->getStorage('group')->create([
        'type' => $this->provisioning->jurisdictionGroupType(),
        'uuid' => $this->uuid($seed, 'group', 'workspace'),
        'label' => $data['name'],
        'uid' => 1,
        'field_slug' => $slug,
      ]);
    }
    $group->set('label', $data['name']);
    $group->set('field_nuxt_config', $this->encode($config));
    $this->setIfField($group, 'field_platform_name', $data['name']);
    // The project context the image analysis gets: what the map collects,
    // valid subjects and exclusions. Without it the model only knows the
    // categories. Same bounds as the onboarding chat.
    $purpose = ProjectPromptConstraint::normalize((string) ($data['ai_system_prompt'] ?? ''));
    $this->setIfField($group, 'field_ai_system_prompt', $purpose === '' ? NULL : $purpose);
    $this->setIfField($group, 'field_boundary', $boundary);
    // Permanent: an expiry date would put the workspace into the trial "demo
    // state", whose GDPR gate unpublishes every new report.
    $this->setIfField($group, 'field_expiry_date', NULL);
    $this->setIfField($group, 'field_tier', 'heart');
    $this->setIfField($group, 'field_visibility', 'public');
    if ($data['operator'] !== NULL) {
      $this->setIfField($group, 'field_jurisdiction_e_mail', $data['operator']['email']);
      $this->setIfField($group, 'field_jurisdiction_address', $data['operator']['address']);
    }
    $group->save();

    if ($created) {
      // Needs the group ID: the signet lands in the group's logo directory.
      $this->provisioning->attachPlaceholderSignet($group, $slug, $data['template']);
      $group->save();
    }
    $this->provisioning->addApiUserMembership($group);

    return [$group, $created];
  }

  /**
   * Creates or rewrites the shared staff account of the workspace.
   *
   * Every run restores the account to its seeded shape: name, mail, a fresh
   * random password nobody knows, active, no global roles, and exactly one
   * membership, the moderator membership in this workspace. Memberships
   * elsewhere are removed. See DemoStaffAccount.
   */
  private function upsertStaffAccount(DemoSeed $seed, GroupInterface $group): void {
    $slug = $seed->data['slug'];
    $roleIds = array_map(static fn(string $role): string => $group->bundle() . '-' . $role, DemoStaffAccount::MEMBERSHIP_ROLES);
    $roles = $this->entityTypeManager->getStorage('group_role')->loadMultiple($roleIds);
    foreach ($roleIds as $roleId) {
      $role = $roles[$roleId] ?? NULL;
      if (!$role instanceof GroupRoleInterface || $role->getScope() !== PermissionScopeInterface::INDIVIDUAL_ID) {
        // The demo works without staff login; the endpoint answers 404.
        $this->logger->error('Demo workspace @slug has no staff account: group role @role is missing or not an individual role.', [
          '@slug' => $slug,
          '@role' => $roleId,
        ]);
        return;
      }
    }

    $globalRoles = DemoStaffAccount::GLOBAL_ROLES === [] ? [] : $this->entityTypeManager->getStorage('user_role')->loadMultiple(DemoStaffAccount::GLOBAL_ROLES);
    if (count($globalRoles) !== count(DemoStaffAccount::GLOBAL_ROLES)) {
      $this->logger->error('Demo workspace @slug has no staff account: a global role of @roles is missing.', [
        '@slug' => $slug,
        '@roles' => implode(', ', DemoStaffAccount::GLOBAL_ROLES),
      ]);
      return;
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $uuid = $this->staffAccount->uuid($slug);
    $name = DemoStaffAccount::accountName($slug);
    $mail = DemoStaffAccount::mail($slug);
    $matches = $userStorage->loadByProperties(['uuid' => $uuid]);
    $account = reset($matches);
    foreach (['name' => $name, 'mail' => $mail] as $property => $value) {
      foreach ($userStorage->loadByProperties([$property => $value]) as $other) {
        if ($other->uuid() !== $uuid) {
          throw new \RuntimeException(sprintf('The %s of the demo staff account "%s" belongs to another account; refusing to touch it.', $property, $name));
        }
      }
    }
    if (!$account instanceof UserInterface) {
      $account = $userStorage->create(['uuid' => $uuid, 'name' => $name]);
      assert($account instanceof UserInterface);
    }
    $account->setUsername($name);
    $account->setEmail($mail);
    $account->set('init', $mail);
    $account->setPassword(bin2hex(random_bytes(32)));
    $account->activate();
    $account->set('roles', DemoStaffAccount::GLOBAL_ROLES);
    $account->set('langcode', $seed->data['default_language']);
    // No preferred language: the dashboard switches to a user's preferred
    // language on every status check, which would pin all visitors to one.
    $account->set('preferred_langcode', NULL);
    $account->set('preferred_admin_langcode', NULL);
    $this->setIfField($account, 'field_all_groups_member', FALSE);
    // Visitors are not workspace operators; the operator terms do not apply.
    if ($account->hasField('field_tos_accepted_at') && $account->get('field_tos_accepted_at')->isEmpty()) {
      $account->set('field_tos_accepted_at', $this->time->getRequestTime());
    }
    $account->save();

    $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');
    $own = NULL;
    foreach ($relationshipStorage->loadByProperties(['plugin_id' => 'group_membership', 'entity_id' => $account->id()]) as $membership) {
      if ((int) $membership->get('gid')->target_id === (int) $group->id() && $own === NULL) {
        $own = $membership;
        continue;
      }
      $membership->delete();
    }
    if ($own === NULL) {
      $group->addMember($account, ['group_roles' => $roleIds]);
    }
    elseif (array_column($own->get('group_roles')->getValue(), 'target_id') !== $roleIds) {
      $own->set('group_roles', $roleIds);
      $own->save();
    }
  }

  /**
   * Creates or rewrites a seeded taxonomy term with its translations.
   */
  private function upsertTerm(DemoSeed $seed, string $vocabulary, string $kind, string $key, int $groupId, array $names, int $weight, array $values): ContentEntityInterface {
    $default = $seed->data['default_language'];
    $uuid = $this->uuid($seed, $kind, $key);
    $term = $this->loadOwned('taxonomy_term', $uuid, $groupId);
    if ($term === NULL) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid' => $vocabulary,
        'uuid' => $uuid,
        'langcode' => $default,
        'name' => $names[$default],
        'field_jurisdiction' => ['target_id' => $groupId],
      ]);
    }
    elseif ($term->bundle() !== $vocabulary) {
      throw new \RuntimeException(sprintf('Seeded term "%s" changed its vocabulary.', $key));
    }
    $term = $term->getUntranslated();
    $term->set('name', $names[$default]);
    $term->set('weight', $weight);
    foreach ($values as $field => $value) {
      $this->setIfField($term, $field, $value);
    }
    $this->syncTranslations($term, $seed, static fn(string $langcode): array => ['name' => $names[$langcode]]);
    $term->save();
    return $term;
  }

  /**
   * Creates or renames the seeded organisations of the workspace.
   *
   * @return array<string, int>
   *   Organisation group IDs keyed by seed key.
   */
  private function upsertOrganisations(DemoSeed $seed, int $groupId): array {
    if ($seed->data['organisations'] === []) {
      return [];
    }
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $orgType = $this->entityTypeManager->getStorage('group_type')->load('org');
    $probe = $orgType ? $groupStorage->create(['type' => 'org']) : NULL;
    if (!$probe instanceof FieldableEntityInterface || !$probe->hasField('field_jurisdiction')) {
      $this->logger->warning('Demo workspace @slug: organisations skipped, this site has no org group type with field_jurisdiction.', [
        '@slug' => $seed->data['slug'],
      ]);
      return [];
    }

    $ids = [];
    foreach ($seed->data['organisations'] as $organisation) {
      $uuid = $this->uuid($seed, 'organisation', $organisation['key']);
      $org = $this->loadOwned('group', $uuid, $groupId);
      if ($org === NULL) {
        $org = $groupStorage->create([
          'type' => 'org',
          'uuid' => $uuid,
          'uid' => 1,
          'field_jurisdiction' => ['target_id' => $groupId],
        ]);
      }
      $org->set('label', $organisation['name']);
      $org->save();
      $ids[$organisation['key']] = (int) $org->id();
    }
    return $ids;
  }

  /**
   * Creates or rewrites the promoted start page, including the mini task.
   */
  private function upsertStartPage(DemoSeed $seed, GroupInterface $group, bool $groupCreated): void {
    $data = $seed->data;
    $default = $data['default_language'];
    $groupId = (int) $group->id();
    $uuid = $this->uuid($seed, 'page', 'start');
    $body = static function (string $langcode) use ($data): string {
      $html = $data['start_page']['body'][$langcode];
      if (isset($data['task'][$langcode])) {
        $html .= '<p><strong>' . Html::escape($data['task'][$langcode]) . '</strong></p>';
      }
      return $html;
    };
    $values = static fn(string $langcode): array => [
      'title' => $data['start_page']['title'][$langcode],
      'body' => ['value' => $body($langcode), 'format' => 'basic_html'],
      'status' => TRUE,
      'promote' => TRUE,
      'sticky' => TRUE,
    ];

    $page = $this->loadOwned('node', $uuid, $groupId);
    $isNew = $page === NULL;
    if ($isNew) {
      $page = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'page',
        'uuid' => $uuid,
        'langcode' => $default,
        'uid' => 1,
        'field_jurisdiction' => ['target_id' => $groupId],
      ]);
    }
    $page = $page->getUntranslated();
    foreach ($values($default) as $field => $value) {
      $page->set($field, $value);
    }
    $this->syncTranslations($page, $seed, $values);
    if ($page instanceof NodeInterface && !$isNew) {
      $page->setNewRevision(FALSE);
    }
    $page->save();

    if ($isNew || $groupCreated) {
      try {
        $group->addRelationship($page, 'group_node:page');
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not relate the demo start page to group @gid: @msg', [
          '@gid' => $groupId,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Creates or rewrites one seeded report with its status history and photo.
   */
  private function upsertReport(DemoSeed $seed, array $report, int $groupId, array $statusIds, array $categoryIds, array $organisationIds): NodeInterface {
    $data = $seed->data;
    $default = $data['default_language'];
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $uuid = $this->uuid($seed, 'report', $report['key']);
    $node = $this->loadOwned('node', $uuid, $groupId);
    if ($node !== NULL && (!$node instanceof NodeInterface || $node->bundle() !== 'service_request')) {
      throw new \RuntimeException(sprintf('Seeded report "%s" is not a service request any more.', $report['key']));
    }

    $timestamps = $this->historyTimestamps($report['history']);

    $finalStatus = $statusIds[end($report['history'])['status']];
    $mediaId = isset($report['photo']) ? $this->upsertPhoto($seed, $report, $groupId) : NULL;

    $values = [
      'title' => $report['title'][$default],
      'body' => ['value' => $report['description'][$default], 'format' => 'plain_text'],
      'field_category' => ['target_id' => $categoryIds[$report['category']]],
      'field_geolocation' => ['lat' => $report['lat'], 'lng' => $report['lng']],
      'created' => $timestamps[0],
      'status' => 1,
      // Seeded reports never notify anyone and carry no reporter data, also
      // after a visitor or demo account edited them in the dashboard.
      'field_notification' => FALSE,
      'field_e_mail' => NULL,
      'field_first_name' => NULL,
      'field_last_name' => NULL,
      'field_phone' => NULL,
      'field_request_media' => $mediaId !== NULL ? [['target_id' => $mediaId]] : [],
      'field_organisation' => isset($report['organisation'], $organisationIds[$report['organisation']])
        ? [['target_id' => $organisationIds[$report['organisation']]]]
        : [],
    ];
    if (isset($report['address'])) {
      $values['field_address'] = $report['address'];
    }

    // Status history is rebuilt below; internal remarks added in the
    // dashboard are dropped. Both old paragraph sets go after the save.
    $oldParagraphIds = [];
    foreach (['field_status_notes', 'field_internal_remark'] as $field) {
      if ($node !== NULL && $node->hasField($field)) {
        $oldParagraphIds = array_merge($oldParagraphIds, array_map('intval', array_column($node->get($field)->getValue(), 'target_id')));
      }
    }

    $notes = [];
    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');
    $siteLangcode = (string) ($this->configFactory->get('system.site')->get('default_langcode') ?: 'en');
    foreach ($report['history'] as $position => $entry) {
      $paragraph = $paragraphStorage->create([
        'type' => 'status',
        'langcode' => $siteLangcode,
        'created' => $timestamps[$position],
      ]);
      $this->setIfField($paragraph, 'field_status_term', ['target_id' => $statusIds[$entry['status']]]);
      $this->setIfField($paragraph, 'field_status_note', ['value' => $entry['note'][$default], 'format' => 'plain_text']);
      $paragraph->save();
      $notes[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
    }

    if ($node === NULL) {
      $node = $nodeStorage->create([
        'type' => 'service_request',
        'uuid' => $uuid,
        'uid' => 0,
        // Reports are language neutral like every report; the per-language
        // texts live in the demo block.
        'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
        'field_jurisdiction' => ['target_id' => $groupId],
      ]);
    }
    else {
      // Hourly resets must not grow the revision history of seeded reports.
      $node->setNewRevision(FALSE);
      // Dashboard-only processing data goes back to the field defaults.
      foreach (self::DASHBOARD_FIELDS as $field) {
        // Not applyDefaultValue(): that only resets the first item and keeps
        // loaded references.
        if ($node->hasField($field)) {
          $node->set($field, $node->getFieldDefinition($field)->getDefaultValue($node));
        }
      }
    }
    foreach ($values as $field => $value) {
      $this->setIfField($node, $field, $value);
    }
    // The last history entry carries the final status, so the status-change
    // hook in service_request finds its note already present and adds none.
    $this->setIfField($node, 'field_status_notes', $notes);
    $this->setIfField($node, 'field_status', ['target_id' => $finalStatus]);
    $node->save();

    // Creation hooks may force the jurisdiction's initial status and the
    // geocoder replaces the address of a new report; restore both once.
    $saved = $nodeStorage->loadUnchanged($node->id());
    if (!$saved instanceof NodeInterface) {
      throw new \RuntimeException(sprintf('Seeded report "%s" vanished after saving.', $report['key']));
    }
    $repair = FALSE;
    if ($saved->hasField('field_status') && (int) $saved->get('field_status')->target_id !== $finalStatus) {
      $saved->set('field_status', ['target_id' => $finalStatus]);
      $repair = TRUE;
    }
    if (isset($report['address']) && $saved->hasField('field_address')) {
      $current = array_intersect_key((array) ($saved->get('field_address')->first()?->getValue() ?? []), $report['address']);
      if ($current != $report['address']) {
        $saved->set('field_address', $report['address']);
        $repair = TRUE;
      }
    }
    if ($repair) {
      $saved->setNewRevision(FALSE);
      $saved->save();
      $saved = $nodeStorage->loadUnchanged($node->id());
    }

    $staleParagraphs = array_diff($oldParagraphIds, array_map('intval', array_column($notes, 'target_id')));
    if ($staleParagraphs !== []) {
      $paragraphStorage->delete($paragraphStorage->loadMultiple($staleParagraphs));
    }

    return $saved instanceof NodeInterface ? $saved : $node;
  }

  /**
   * Places the history entries of a report on the clock.
   *
   * Each entry lands on the day "days_ago" before today, at its time of day
   * in the site's time zone (DemoSeed resolves a stable time for every
   * entry). Nothing lies in the future, and the entries stay in order.
   *
   * @param array<int, array{days_ago: int, time: string}> $history
   *   The normalized report history.
   *
   * @return int[]
   *   One Unix timestamp per entry.
   */
  private function historyTimestamps(array $history): array {
    $now = $this->time->getRequestTime();
    $timezone = new \DateTimeZone((string) ($this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get()));
    $timestamps = [];
    $previous = 0;
    foreach ($history as $entry) {
      [$hours, $minutes] = array_map('intval', explode(':', $entry['time']));
      $timestamp = (new \DateTimeImmutable('@' . ($now - $entry['days_ago'] * 86400)))
        ->setTimezone($timezone)
        ->setTime($hours, $minutes)
        ->getTimestamp();
      $timestamp = max($previous + 60, min($timestamp, $now));
      $timestamps[] = $timestamp;
      $previous = $timestamp;
    }
    return $timestamps;
  }

  /**
   * Creates the photo media of a report, or reuses the seeded one.
   *
   * @return int|null
   *   The media ID, NULL when this site has no request_image media type.
   */
  private function upsertPhoto(DemoSeed $seed, array $report, int $groupId): ?int {
    if (!$this->entityTypeManager->hasDefinition('media')
      || !$this->entityTypeManager->getStorage('media_type')->load('request_image')) {
      $this->logger->warning('Demo workspace @slug: photo of @key skipped, no request_image media type.', [
        '@slug' => $seed->data['slug'],
        '@key' => $report['key'],
      ]);
      return NULL;
    }
    $default = $seed->data['default_language'];
    $photo = $report['photo'];
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $mediaUuid = $this->uuid($seed, 'media', $report['key']);
    $existing = $mediaStorage->loadByProperties(['uuid' => $mediaUuid]);
    $media = reset($existing) ?: NULL;

    $file = $media?->get('field_media_image')->entity;
    if ($media === NULL || !$file || !file_exists($file->getFileUri())) {
      $file = $this->copyPhoto($seed, $report);
      $size = getimagesize($file->getFileUri()) ?: [NULL, NULL];
      $image = [
        'target_id' => $file->id(),
        'alt' => $photo['alt'][$default],
        'width' => $size[0],
        'height' => $size[1],
      ];
      if ($media === NULL) {
        $media = $mediaStorage->create([
          'bundle' => 'request_image',
          'uuid' => $mediaUuid,
          'uid' => 0,
          'field_media_image' => $image,
        ]);
      }
      else {
        $media->set('field_media_image', $image);
      }
    }
    else {
      $media->get('field_media_image')->alt = $photo['alt'][$default];
    }
    $media->set('name', $report['title'][$default]);
    $media->setPublished();
    $media->save();
    $this->keyValueFactory->get(self::MANUAL_MEDIA_PUBLICATION)->set($media->id(), TRUE);

    return (int) $media->id();
  }

  /**
   * Copies a seed photo into the request image storage.
   */
  private function copyPhoto(DemoSeed $seed, array $report): ContentEntityInterface {
    $source = $report['photo']['path'];
    $copy = $this->photoCopyUri($seed, $report);
    if (is_file($source)) {
      $directory = dirname($copy);
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new \RuntimeException(sprintf('Directory %s is not writable.', $directory));
      }
      $uri = $this->fileSystem->copy($source, $copy, FileExists::Replace);
    }
    elseif (file_exists($copy)) {
      // A reset from the stored seed copy: the seed directory is usually not
      // mounted where cron runs, but the first run left this copy behind.
      $uri = $copy;
      $source = $this->fileSystem->realpath($copy) ?: $copy;
    }
    else {
      throw new \RuntimeException(sprintf('Photo of report "%s" is missing at %s and no seeded copy exists.', $report['key'], $source));
    }
    $basename = basename($copy);

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $fileUuid = $this->uuid($seed, 'file', $report['key']);
    $existing = $fileStorage->loadByProperties(['uuid' => $fileUuid]);
    $file = reset($existing) ?: $fileStorage->create(['uuid' => $fileUuid, 'uid' => 0]);
    $file->set('uri', $uri);
    $file->set('filename', $basename);
    $file->set('filemime', (string) (mime_content_type($source) ?: 'application/octet-stream'));
    $file->set('filesize', filesize($source));
    $file->set('status', 1);
    $file->save();
    return $file;
  }

  /**
   * Returns where the seeder keeps its copy of a report photo.
   */
  private function photoCopyUri(DemoSeed $seed, array $report): string {
    $scheme = 'public';
    $definitions = $this->entityFieldManager->getFieldDefinitions('media', 'request_image');
    if (isset($definitions['field_media_image'])) {
      $scheme = (string) ($definitions['field_media_image']->getFieldStorageDefinition()->getSetting('uri_scheme') ?: 'public');
    }
    return $scheme . '://demo-seeds/' . $seed->data['slug'] . '/' . $report['key'] . '-' . basename($report['photo']['file']);
  }

  /**
   * Returns the reports whose photo a seed run could not restore.
   *
   * A photo is restorable when the seeded media still has its file, the seed
   * file is readable, or the seeder's own copy is still there. The reset
   * checks this before deleting anything, so a missing photo never leaves an
   * emptied demo behind.
   *
   * @return string[]
   *   Report seed keys.
   */
  public function unrestorablePhotos(DemoSeed $seed): array {
    if (!$this->entityTypeManager->hasDefinition('media')
      || !$this->entityTypeManager->getStorage('media_type')->load('request_image')) {
      // Photos are skipped on such sites anyway.
      return [];
    }
    $missing = [];
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    foreach ($seed->data['reports'] as $report) {
      if (!isset($report['photo'])) {
        continue;
      }
      $existing = $mediaStorage->loadByProperties(['uuid' => $this->uuid($seed, 'media', $report['key'])]);
      $media = reset($existing);
      $file = $media ? $media->get('field_media_image')->entity : NULL;
      if (($file && file_exists($file->getFileUri()))
        || is_file($report['photo']['path'])
        || file_exists($this->photoCopyUri($seed, $report))) {
        continue;
      }
      $missing[] = $report['key'];
    }
    return $missing;
  }

  /**
   * Adds, updates and removes translations so they match the seed languages.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The untranslated entity.
   * @param \Drupal\markaspot_fastmap\Demo\DemoSeed $seed
   *   The seed.
   * @param callable $values
   *   Returns the field values for a langcode.
   */
  private function syncTranslations(ContentEntityInterface $entity, DemoSeed $seed, callable $values): void {
    $default = $seed->data['default_language'];
    $languages = $seed->data['languages'];
    if (!$entity->isTranslatable()) {
      if (count($languages) > 1) {
        $this->logger->warning('Demo workspace @slug: @type @bundle is not translatable, only @lang is seeded.', [
          '@slug' => $seed->data['slug'],
          '@type' => $entity->getEntityTypeId(),
          '@bundle' => $entity->bundle(),
          '@lang' => $default,
        ]);
      }
      return;
    }
    foreach ($languages as $langcode) {
      if ($langcode === $default) {
        continue;
      }
      $translation = $entity->hasTranslation($langcode)
        ? $entity->getTranslation($langcode)
        : $entity->addTranslation($langcode);
      foreach ($values($langcode) as $field => $value) {
        $translation->set($field, $value);
      }
    }
    foreach (array_keys($entity->getTranslationLanguages(FALSE)) as $langcode) {
      if (!in_array($langcode, $languages, TRUE)) {
        $entity->removeTranslation($langcode);
      }
    }
  }

  /**
   * Loads a seeded entity by UUID and checks it still belongs to the group.
   */
  private function loadOwned(string $entityTypeId, string $uuid, int $groupId): ?ContentEntityInterface {
    $matches = $this->entityTypeManager->getStorage($entityTypeId)->loadByProperties(['uuid' => $uuid]);
    $entity = reset($matches);
    if (!$entity instanceof ContentEntityInterface) {
      return NULL;
    }
    $owner = $entity->hasField('field_jurisdiction') ? (int) $entity->get('field_jurisdiction')->target_id : NULL;
    if ($owner !== $groupId) {
      throw new \RuntimeException(sprintf('Entity %s %s carries a seed UUID but belongs to another workspace.', $entityTypeId, $entity->id()));
    }
    return $entity;
  }

  /**
   * Sets a field value when the entity has the field.
   */
  private function setIfField(FieldableEntityInterface $entity, string $field, mixed $value): void {
    if ($entity->hasField($field)) {
      $entity->set($field, $value);
    }
  }

  /**
   * The seed UUID of an entity of this workspace.
   */
  private function uuid(DemoSeed $seed, string $kind, string $key): string {
    $siteUuid = (string) $this->configFactory->get('system.site')->get('uuid');
    return DemoWorkspace::uuid($siteUuid, $seed->data['slug'], $kind, $key);
  }

  /**
   * Encodes JSON the way provisioning stores field values.
   */
  private function encode(array $value): string {
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

}
