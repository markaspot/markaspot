<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Drush\Commands\OrganisationRelationSyncCommands;
use Drupal\markaspot_group\Service\OrganisationRelationSync;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests mas:org-relations:sync: field_organisation wins, nothing else moves.
 *
 * All groups, titles and addresses are fictional.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class OrganisationRelationSyncKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'node',
    'taxonomy',
    'file',
    'entity_reference_revisions',
    'paragraphs',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'service_request',
  ];

  /**
   * Root jurisdiction of the tenant under test.
   */
  private Group $county;

  /**
   * District below the root.
   */
  private Group $district;

  /**
   * Root jurisdiction of another tenant.
   */
  private Group $otherCounty;

  /**
   * Organisation in the root jurisdiction.
   */
  private Group $roadWorks;

  /**
   * Second organisation in the root jurisdiction.
   */
  private Group $parks;

  /**
   * Organisation of the other tenant.
   */
  private Group $otherOrganisation;

  /**
   * Fixture requests keyed by role.
   *
   * @var array<string, \Drupal\node\Entity\Node>
   */
  private array $requests = [];

  /**
   * Entity hooks seen while probing, as "hook:entity_type".
   *
   * @var list<string>
   */
  private array $hookLog = [];

  /**
   * Whether the entity hook probes record.
   */
  private bool $probing = FALSE;

  /**
   * {@inheritdoc}
   *
   * Real database locks instead of the null backend, so a lock that another
   * process holds is visible to the sync.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('lock', DatabaseLockBackend::class)
      ->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['user', 'node', 'paragraph', 'group', 'group_relationship', 'group_config_wrapper'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $admin = User::create(['uid' => 1, 'name' => 'admin', 'status' => 1, 'mail' => 'admin@example.test']);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['jur', 'org'] as $groupType) {
      if (!$types->load($groupType . '-group_node-service_request')) {
        $types->createFromPlugin(GroupType::load($groupType), 'group_node:service_request')->save();
      }
    }

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->createField('group', 'org', 'field_service_categories', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->createField('group', 'org', 'field_head_organisation_e_mail', 'email');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group'], -1);
    $this->createField('node', 'service_request', 'field_organisation', 'entity_reference', ['target_type' => 'group'], -1, 'custom');
    // Read by service_request's presave hook.
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->county = $this->createGroup('jur', 'Fictional county');
    $this->district = $this->createGroup('jur', 'Fictional district', ['field_parent_jurisdiction' => $this->county->id()]);
    $this->otherCounty = $this->createGroup('jur', 'Other county');
    $this->roadWorks = $this->createGroup('org', 'Road works unit', [
      'field_jurisdiction' => $this->county->id(),
      'field_head_organisation_e_mail' => 'roads@example.test',
    ]);
    $this->parks = $this->createGroup('org', 'Parks unit', [
      'field_jurisdiction' => $this->county->id(),
      'field_head_organisation_e_mail' => 'parks@example.test',
    ]);
    $this->otherOrganisation = $this->createGroup('org', 'Other county depot', [
      'field_jurisdiction' => $this->otherCounty->id(),
      'field_head_organisation_e_mail' => 'depot@example.test',
    ]);

    $roads = (int) $this->roadWorks->id();
    $parks = (int) $this->parks->id();

    $this->requests['consistent'] = $this->createRequest('Loose paving slab', [$roads]);

    // The common drift: the field moved on, the relation stayed behind.
    $this->requests['moved'] = $this->createRequest('Fallen branch', [$roads]);
    $this->setFieldOrganisations($this->requests['moved'], [$parks]);

    $this->requests['missing'] = $this->createRequest('Pothole near the bus stop', [$roads]);
    $this->deleteRelations($this->requests['missing'], $roads);

    $this->requests['stale'] = $this->createRequest('Broken bench', [$parks]);
    $this->setFieldOrganisations($this->requests['stale'], []);

    // A foreign and a non-existent organisation in the field are reported,
    // never related.
    $this->requests['invalid'] = $this->createRequest('Graffiti on a wall', [$roads]);
    $this->setFieldOrganisations($this->requests['invalid'], [$roads, (int) $this->otherOrganisation->id(), 999999]);

    $this->requests['duplicate'] = $this->createRequest('Blocked drain', [$roads]);
    $this->duplicateRelation($this->requests['duplicate'], $roads);

    // Jurisdiction drift is out of scope and must stay exactly as it is.
    $this->requests['jurisdiction_drift'] = $this->createRequest('Flickering street lamp', [$roads]);
    $this->setFieldOrganisations($this->requests['jurisdiction_drift'], [$parks]);
    $this->deleteRelations($this->requests['jurisdiction_drift'], (int) $this->district->id());

    $this->requests['other_tenant'] = $this->createRequest('Overflowing bin', [(int) $this->otherOrganisation->id()], $this->otherCounty);
    $this->setFieldOrganisations($this->requests['other_tenant'], []);

    $this->clearCollectedMails();
  }

  /**
   * The dry run reports every pattern and writes nothing.
   */
  public function testDryRunChangesNothing(): void {
    $before = $this->relationshipRows();
    $this->probing = TRUE;
    [$exit, $output] = $this->runSync(['log' => 'temporary://org-sync-dry-run.csv']);
    $this->probing = FALSE;

    $this->assertSame(OrganisationRelationSyncCommands::EXIT_SUCCESS, $exit, $output);
    $this->assertSame($before, $this->relationshipRows());
    $this->assertSame([], $this->hookLog);
    $this->assertSame([], $this->collectedMails());

    $roads = (int) $this->roadWorks->id();
    $parks = (int) $this->parks->id();
    $other = (int) $this->otherOrganisation->id();
    $this->assertStringContainsString("field=$parks relations=$roads -> add $parks, remove $roads", $output);
    $this->assertStringContainsString("field=$roads relations=- -> add $roads", $output);
    $this->assertStringContainsString("field=- relations=$parks -> remove $parks", $output);
    $this->assertStringContainsString("field=- relations=$other -> remove $other", $output);
    $this->assertStringContainsString("relations=$roads,$roads -> remove duplicate $roads", $output);
    $this->assertStringContainsString("skip $other (jurisdiction_mismatch)", $output);
    $this->assertStringContainsString('skip 999999 (missing_group)', $output);
    $this->assertStringContainsString('Dry run: nothing was changed.', $output);

    $log = $this->readLog('temporary://org-sync-dry-run.csv');
    $this->assertSame([
      (int) $this->requests['moved']->id() => 'planned',
      (int) $this->requests['missing']->id() => 'planned',
      (int) $this->requests['stale']->id() => 'planned',
      (int) $this->requests['invalid']->id() => 'skipped',
      (int) $this->requests['duplicate']->id() => 'planned',
      (int) $this->requests['jurisdiction_drift']->id() => 'planned',
      (int) $this->requests['other_tenant']->id() => 'planned',
    ], array_column($log, 'result', 'nid'));
    $moved = $log[(int) $this->requests['moved']->id()];
    $this->assertSame((string) $parks, $moved['add']);
    $this->assertSame((string) $roads, $moved['remove']);
    $this->assertSame("$other:jurisdiction_mismatch;999999:missing_group", $log[(int) $this->requests['invalid']->id()]['skipped']);
  }

  /**
   * Apply makes relations match the field without any side effect.
   */
  public function testApplyMatchesFieldWithoutSideEffects(): void {
    $jurisdictionRows = $this->relationshipRows('jur');
    $nodeState = $this->nodeState();
    $cache = $this->container->get('cache.default');
    $movedNid = (int) $this->requests['moved']->id();
    $cache->set('org_sync_probe', TRUE, -1, ["group_relationship_list:entity:$movedNid"]);

    $this->probing = TRUE;
    [$exit, $output] = $this->runSync(['apply' => TRUE, 'batch-size' => 2, 'log' => 'temporary://org-sync-apply.csv']);
    $this->probing = FALSE;
    $this->assertSame(OrganisationRelationSyncCommands::EXIT_SUCCESS, $exit, $output);

    $roads = (int) $this->roadWorks->id();
    $parks = (int) $this->parks->id();
    $this->assertSame([$roads], $this->organisationRelations('consistent'));
    $this->assertSame([$parks], $this->organisationRelations('moved'));
    $this->assertSame([$roads], $this->organisationRelations('missing'));
    $this->assertSame([], $this->organisationRelations('stale'));
    $this->assertSame([$roads], $this->organisationRelations('invalid'));
    $this->assertSame([$roads], $this->organisationRelations('duplicate'));
    $this->assertSame([$parks], $this->organisationRelations('jurisdiction_drift'));
    $this->assertSame([], $this->organisationRelations('other_tenant'));

    // Jurisdiction relations: same rows, including the missing one.
    $this->assertSame($jurisdictionRows, $this->relationshipRows('jur'));
    // No node save, no revision, no hook, no mail.
    $this->assertSame($nodeState, $this->nodeState());
    $this->assertSame([], $this->hookLog);
    $this->assertSame([], $this->collectedMails());
    // Group's relationship list tags were invalidated.
    $this->assertFalse($cache->get('org_sync_probe'));

    $this->assertStringContainsString('Applied: 3 relations added, 5 removed.', $output);
    $log = $this->readLog('temporary://org-sync-apply.csv');
    $this->assertSame('applied', $log[$movedNid]['result']);
    $this->assertSame('skipped', $log[(int) $this->requests['invalid']->id()]['result']);

    // The written rows are complete relationship entities.
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship');
    $storage->resetCache();
    $relationships = $storage->loadByProperties(['entity_id' => $movedNid, 'gid' => $parks]);
    $this->assertCount(1, $relationships);
    $relationship = reset($relationships);
    $this->assertInstanceOf(GroupRelationshipInterface::class, $relationship);
    $this->assertSame('group_node:service_request', $relationship->getPluginId());
    $this->assertSame('org', $relationship->getGroupTypeId());
    $this->assertSame('org-group_node-service_request', $relationship->bundle());
    $this->assertSame($movedNid, (int) $relationship->getEntity()->id());
    $this->assertSame('Fallen branch', $relationship->label());
    $this->assertTrue($relationship->uuid() !== NULL && $relationship->uuid() !== '');
  }

  /**
   * A second apply finds nothing to write.
   */
  public function testApplyIsIdempotent(): void {
    $this->runSync(['apply' => TRUE, 'log' => 'temporary://org-sync-first.csv']);
    $after = $this->relationshipRows();

    $this->probing = TRUE;
    [$exit, $output] = $this->runSync(['apply' => TRUE, 'log' => 'temporary://org-sync-second.csv']);
    $this->probing = FALSE;

    $this->assertSame(OrganisationRelationSyncCommands::EXIT_SUCCESS, $exit, $output);
    $this->assertSame($after, $this->relationshipRows());
    $this->assertSame([], $this->hookLog);
    $this->assertStringContainsString('Applied: 0 relations added, 0 removed.', $output);
    // Only the unfixable field values remain as drift.
    $this->assertSame(
      [(int) $this->requests['invalid']->id() => 'skipped'],
      array_column($this->readLog('temporary://org-sync-second.csv'), 'result', 'nid'),
    );
  }

  /**
   * Jurisdiction, node, organisation and limit filters narrow the scan.
   */
  public function testFilters(): void {
    $sync = $this->sync();
    $nids = static fn(iterable $diffs): array => array_map(static fn($diff): int => $diff->nid, iterator_to_array($diffs, FALSE));

    $all = $nids($sync->scan());
    $this->assertCount(7, $all);
    $inRoot = $nids($sync->scan(['jurisdiction' => (int) $this->county->id()]));
    $this->assertNotContains((int) $this->requests['other_tenant']->id(), $inRoot);
    $this->assertCount(6, $inRoot);
    $this->assertSame([(int) $this->requests['other_tenant']->id()], $nids($sync->scan(['jurisdiction' => (int) $this->otherCounty->id()])));
    $this->assertSame([(int) $this->requests['stale']->id()], $nids($sync->scan(['nid' => (int) $this->requests['stale']->id()])));
    $this->assertSame([], $nids($sync->scan(['nid' => (int) $this->requests['consistent']->id()])));
    $this->assertSame(
      [
        (int) $this->requests['moved']->id(),
        (int) $this->requests['stale']->id(),
        (int) $this->requests['jurisdiction_drift']->id(),
      ],
      $nids($sync->scan(['organisations' => [(int) $this->parks->id()]])),
    );
    $this->assertSame(array_slice($all, 0, 2), $nids($sync->scan(['limit' => 2])));

    $this->expectException(\InvalidArgumentException::class);
    iterator_to_array($sync->scan(['jurisdiction' => (int) $this->roadWorks->id()]));
  }

  /**
   * In single-organisation mode a multi-organisation field is left alone.
   */
  public function testSingleOrganisationModeBlocksMultipleFieldValues(): void {
    $roads = (int) $this->roadWorks->id();
    $parks = (int) $this->parks->id();
    $request = $this->createRequest('Damaged sign', [$roads]);
    $this->setFieldOrganisations($request, [$roads, $parks]);
    $this->config('markaspot_group.settings')->set('single_organisation_assignment', TRUE)->save();

    $results = $this->sync()->apply([(int) $request->id()]);

    $this->assertSame('blocked', $results[(int) $request->id()]['result']);
    $this->assertSame([$roads], $this->relationIds((int) $request->id()));
  }

  /**
   * Unknown relationship fields make apply refuse instead of writing.
   */
  public function testUnknownRelationshipFieldRefusesApply(): void {
    $this->createField('group_relationship', 'org-group_node-service_request', 'field_note', 'string');
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $before = $this->relationshipRows();

    [$exit, $output] = $this->runSync(['log' => 'temporary://org-sync-refused.csv']);
    $this->assertSame(OrganisationRelationSyncCommands::EXIT_SUCCESS, $exit);
    $this->assertStringContainsString('--apply would be refused', $output);
    $this->assertStringContainsString('field_note', $output);

    try {
      $this->runSync(['apply' => TRUE, 'log' => 'temporary://org-sync-refused-apply.csv']);
      $this->fail('Apply wrote relations despite an unknown relationship field.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('field_note', $e->getMessage());
    }
    $this->assertSame($before, $this->relationshipRows());
  }

  /**
   * A request locked by another process is left alone and named for a rerun.
   */
  public function testLockedRequestIsLeftForRerun(): void {
    $movedNid = (int) $this->requests['moved']->id();
    $lockName = 'markaspot_group:service_request_sync:' . $movedNid;
    $lock = $this->container->get('lock');
    // Creates the semaphore table.
    $lock->acquire('org_sync_probe');
    $lock->release('org_sync_probe');
    $this->container->get('database')->insert('semaphore')
      ->fields(['name' => $lockName, 'value' => 'another-process', 'expire' => microtime(TRUE) + 30])
      ->execute();

    [$exit, $output] = $this->runSync(['apply' => TRUE, 'log' => 'temporary://org-sync-locked.csv']);
    $this->assertSame(OrganisationRelationSyncCommands::EXIT_SUCCESS, $exit, $output);
    $roads = (int) $this->roadWorks->id();
    $this->assertSame([$roads], $this->organisationRelations('moved'), 'The locked request was not touched.');
    $this->assertSame([$roads], $this->organisationRelations('missing'), 'The other requests were repaired.');
    $this->assertSame('locked', $this->readLog('temporary://org-sync-locked.csv')[$movedNid]['result']);
    $this->assertStringContainsString('another process held their lock: 1', $output);

    $this->container->get('database')->delete('semaphore')->condition('name', $lockName)->execute();
    [, $output] = $this->runSync(['apply' => TRUE, 'log' => 'temporary://org-sync-locked-rerun.csv']);
    $this->assertSame([(int) $this->parks->id()], $this->organisationRelations('moved'));
    $this->assertStringNotContainsString('held their lock', $output);
  }

  /**
   * The CSV log is never written where the web server serves it.
   */
  public function testLogStaysOffTheWeb(): void {
    $appRoot = (string) $this->container->getParameter('app.root');
    foreach (['public://org-relations.csv', 'reports/../../org-relations.csv', $appRoot . '/org-relations.csv'] as $log) {
      try {
        $this->runSync(['log' => $log]);
        $this->fail(sprintf('The log was accepted at %s.', $log));
      }
      catch (\RuntimeException $e) {
        $this->assertStringStartsWith('Refusing', $e->getMessage(), $log);
      }
    }
    $this->assertFileDoesNotExist('public://org-relations.csv');
    $this->assertFileDoesNotExist($appRoot . '/org-relations.csv');
  }

  /**
   * Control: the entity API path saves the node and mails the organisation.
   *
   * This is why the sync writes the relation tables directly, and it shows
   * that the hook probe and the mail collector of the other tests would see
   * those side effects.
   */
  public function testEntityApiRelationWouldSaveNodeAndSendMail(): void {
    // The field already lists the organisation, as for every relation the
    // sync adds; the pair was never notified in this process.
    $request = $this->requests['moved'];
    $this->probing = TRUE;
    $this->parks->addRelationship(Node::load($request->id()), 'group_node:service_request');
    $this->probing = FALSE;

    $this->assertContains('entity_update:node', $this->hookLog);
    $this->assertContains('entity_insert:group_relationship', $this->hookLog);
    $mails = $this->collectedMails();
    $this->assertCount(1, $mails);
    $this->assertSame('org_notification', $mails[0]['key']);
    $this->assertSame('parks@example.test', $mails[0]['to']);
  }

  /**
   * Records entity presave calls while probing.
   */
  #[Hook('entity_presave')]
  public function probeEntityPresave(EntityInterface $entity): void {
    $this->probe('entity_presave', $entity);
  }

  /**
   * Records entity insert calls while probing.
   */
  #[Hook('entity_insert')]
  public function probeEntityInsert(EntityInterface $entity): void {
    $this->probe('entity_insert', $entity);
  }

  /**
   * Records entity update calls while probing.
   */
  #[Hook('entity_update')]
  public function probeEntityUpdate(EntityInterface $entity): void {
    $this->probe('entity_update', $entity);
  }

  /**
   * Records entity delete calls while probing.
   */
  #[Hook('entity_delete')]
  public function probeEntityDelete(EntityInterface $entity): void {
    $this->probe('entity_delete', $entity);
  }

  /**
   * Adds an entity hook call to the log while probing.
   */
  private function probe(string $hook, EntityInterface $entity): void {
    if ($this->probing) {
      $this->hookLog[] = $hook . ':' . $entity->getEntityTypeId();
    }
  }

  /**
   * Runs the Drush command and returns exit code and output.
   *
   * @param array<string, mixed> $options
   *   Options; omitted ones take the command defaults.
   *
   * @return array{0: int, 1: string}
   *   Exit code and output.
   */
  private function runSync(array $options): array {
    $command = OrganisationRelationSyncCommands::create($this->container);
    $output = new BufferedOutput();
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output);
    $exit = $command->sync($options + [
      'jurisdiction' => NULL,
      'nid' => NULL,
      'organisation' => NULL,
      'limit' => NULL,
      'apply' => FALSE,
      'log' => NULL,
      'batch-size' => OrganisationRelationSync::BATCH_SIZE,
      'top' => 0,
    ]);
    return [$exit, $output->fetch()];
  }

  /**
   * Returns a fresh sync service.
   */
  private function sync(): OrganisationRelationSync {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(OrganisationRelationSync::class);
  }

  /**
   * Reads the CSV log keyed by node ID.
   *
   * @return array<int, array<string, string>>
   *   Rows keyed by node ID.
   */
  private function readLog(string $uri): array {
    $lines = array_map(static fn(string $line): array => str_getcsv($line, ',', '"', ''), file($uri, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $header = array_shift($lines);
    $rows = [];
    foreach ($lines as $line) {
      $row = array_combine($header, $line);
      $rows[(int) $row['nid']] = $row;
    }
    return $rows;
  }

  /**
   * Returns every relationship row of service requests, optionally by type.
   *
   * @return list<array<string, mixed>>
   *   Rows of the base and data tables, joined, in ID order.
   */
  private function relationshipRows(?string $groupType = NULL): array {
    $query = $this->container->get('database')->select('group_relationship_field_data', 'd');
    $query->join('group_relationship', 'b', 'b.id = d.id');
    $query->fields('d')->fields('b', ['uuid'])
      ->condition('d.plugin_id', 'group_node:service_request')
      ->orderBy('d.id');
    if ($groupType !== NULL) {
      $query->condition('d.group_type', $groupType);
    }
    return $query->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Returns revision ID, changed time and revision count of every request.
   *
   * @return array<int, array{0: string, 1: string, 2: string}>
   *   State keyed by node ID.
   */
  private function nodeState(): array {
    $database = $this->container->get('database');
    $state = [];
    foreach ($this->requests as $request) {
      $nid = (int) $request->id();
      $state[$nid] = [
        (string) $database->query('SELECT vid FROM {node} WHERE nid = :nid', [':nid' => $nid])->fetchField(),
        (string) $database->query('SELECT changed FROM {node_field_data} WHERE nid = :nid', [':nid' => $nid])->fetchField(),
        (string) $database->query('SELECT COUNT(*) FROM {node_revision} WHERE nid = :nid', [':nid' => $nid])->fetchField(),
      ];
    }
    return $state;
  }

  /**
   * Returns the sorted organisation relation group IDs of a fixture request.
   *
   * @return list<int>
   *   Group IDs, duplicates kept.
   */
  private function organisationRelations(string $key): array {
    return $this->relationIds((int) $this->requests[$key]->id());
  }

  /**
   * Returns the sorted organisation relation group IDs of a request.
   *
   * @return list<int>
   *   Group IDs, duplicates kept.
   */
  private function relationIds(int $nid): array {
    $ids = array_map('intval', $this->container->get('database')->select('group_relationship_field_data', 'd')
      ->fields('d', ['gid'])
      ->condition('d.entity_id', $nid)
      ->condition('d.plugin_id', 'group_node:service_request')
      ->condition('d.group_type', 'org')
      ->execute()
      ->fetchCol());
    sort($ids);
    return $ids;
  }

  /**
   * Creates a service request through the entity API.
   *
   * @param string $title
   *   Fictional title.
   * @param list<int> $organisations
   *   Organisation group IDs.
   * @param \Drupal\group\Entity\Group|null $jurisdiction
   *   Jurisdiction, the district by default.
   */
  private function createRequest(string $title, array $organisations, ?Group $jurisdiction = NULL): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => $title,
      'status' => 1,
      'uid' => 1,
      'field_jurisdiction' => [($jurisdiction ?? $this->district)->id()],
      'field_organisation' => $organisations,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Overwrites field_organisation in storage, as a hook-skipping writer does.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The request.
   * @param list<int> $organisations
   *   New organisation group IDs.
   */
  private function setFieldOrganisations(Node $node, array $organisations): void {
    $database = $this->container->get('database');
    foreach (['node__field_organisation', 'node_revision__field_organisation'] as $table) {
      $database->delete($table)->condition('entity_id', $node->id())->execute();
      foreach (array_values($organisations) as $delta => $gid) {
        $database->insert($table)->fields([
          'bundle' => 'service_request',
          'deleted' => 0,
          'entity_id' => $node->id(),
          'revision_id' => $node->getRevisionId(),
          'langcode' => $node->language()->getId(),
          'delta' => $delta,
          'field_organisation_target_id' => $gid,
        ])->execute();
      }
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
  }

  /**
   * Deletes the relations of a request to one group, bypassing hooks.
   */
  private function deleteRelations(Node $node, int $gid): void {
    $database = $this->container->get('database');
    $ids = $database->select('group_relationship_field_data', 'd')
      ->fields('d', ['id'])
      ->condition('d.entity_id', $node->id())
      ->condition('d.gid', $gid)
      ->condition('d.plugin_id', 'group_node:service_request')
      ->execute()
      ->fetchCol();
    $this->assertNotEmpty($ids);
    $database->delete('group_relationship_field_data')->condition('id', $ids, 'IN')->execute();
    $database->delete('group_relationship')->condition('id', $ids, 'IN')->execute();
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache($ids);
  }

  /**
   * Copies the relation of a request to one group, bypassing hooks.
   */
  private function duplicateRelation(Node $node, int $gid): void {
    $database = $this->container->get('database');
    $row = $database->select('group_relationship_field_data', 'd')
      ->fields('d')
      ->condition('d.entity_id', $node->id())
      ->condition('d.gid', $gid)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    $id = (int) $database->insert('group_relationship')->fields([
      'type' => $row['type'],
      'uuid' => $this->container->get('uuid')->generate(),
      'langcode' => $row['langcode'],
    ])->execute();
    $row['id'] = $id;
    $database->insert('group_relationship_field_data')->fields($row)->execute();
  }

  /**
   * Creates and saves a group.
   *
   * @param string $type
   *   Group type.
   * @param string $label
   *   Fictional label.
   * @param array<string, mixed> $values
   *   Field values.
   */
  private function createGroup(string $type, string $label, array $values = []): Group {
    $group = Group::create(['type' => $type, 'label' => $label] + $values);
    $group->save();
    return $group;
  }

  /**
   * Creates a field storage and field.
   *
   * @param string $entityType
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string $name
   *   Field name.
   * @param string $type
   *   Field type.
   * @param array<string, mixed> $settings
   *   Storage settings.
   * @param int $cardinality
   *   Cardinality.
   * @param string $permissionType
   *   The field_permissions type.
   */
  private function createField(string $entityType, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1, string $permissionType = 'public'): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $settings,
        'third_party_settings' => ['field_permissions' => ['permission_type' => $permissionType]],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $name,
    ])->save();
  }

  /**
   * Clears mail collected by the kernel test mail backend.
   */
  private function clearCollectedMails(): void {
    $this->container->get('state')->set('system.test_mail_collector', []);
  }

  /**
   * Returns mail collected by the kernel test mail backend.
   *
   * @return list<array<string, mixed>>
   *   Collected mail messages.
   */
  private function collectedMails(): array {
    return $this->container->get('state')->get('system.test_mail_collector') ?? [];
  }

}
