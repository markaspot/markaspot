<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_index\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\markaspot_group\Drush\Commands\OrganisationRelationSyncCommands;
use Drupal\markaspot_group\Service\OrganisationRelationDiff;
use Drupal\markaspot_group\Service\OrganisationRelationSync;
use Drupal\markaspot_request_index\RequestIndexVerifier;
use Drupal\markaspot_request_index\RequestIndexWriter;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests that mas:org-relations:sync leaves the request index consistent.
 *
 * The index projects field_organisation, the authority, so repairing the
 * relations must neither change a row nor leave one behind the field.
 *
 * @group markaspot_request_index
 */
#[RunTestsInSeparateProcesses]
final class RequestIndexOrganisationRelationSyncKernelTest extends RequestIndexKernelTestBase {

  /**
   * Relation drift is repaired; rows stay identical and match the projector.
   */
  public function testSyncLeavesIndexConsistent(): void {
    $a = (int) $this->organisationA->id();
    $b = (int) $this->organisationB->id();
    $this->createRequest();
    // Field B, relation moved to A behind the entity API.
    $moved = $this->createRequest(['title' => 'Fallen branch', 'field_organisation' => [$b]]);
    $this->database()->update('group_relationship_field_data')
      ->fields(['gid' => $a])
      ->condition('entity_id', $moved->id())
      ->condition('gid', $b)
      ->execute();
    // Field A, relation lost.
    $lost = $this->createRequest(['title' => 'Pothole near the bus stop']);
    $this->deleteOrganisationRelations((int) $lost->id());
    // Field B, relations A and B.
    $extra = $this->createRequest(['title' => 'Blocked drain', 'field_organisation' => [$b]]);
    $this->addRelationRow((int) $extra->id(), $a);
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();

    $verifier = $this->verifier();
    $this->assertSame(3, $verifier->drift()['drifted']);
    $rowsBefore = $this->indexRows();
    $this->assertSame(['checked' => 4, 'differences' => []], $verifier->diff(0));

    $command = OrganisationRelationSyncCommands::create($this->container);
    $output = new BufferedOutput();
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output);
    $exit = $command->sync([
      'apply' => TRUE,
      'log' => 'temporary://request-index-org-relation-sync.csv',
      'batch-size' => 2,
      'top' => 0,
    ]);
    $this->assertSame(OrganisationRelationSyncCommands::EXIT_SUCCESS, $exit, $output->fetch());

    $this->assertSame(0, $verifier->drift()['drifted']);
    $this->assertSame(['requests' => 4, 'indexed' => 4, 'missing' => 0, 'orphans' => 0], $verifier->parity());
    $this->assertSame(['checked' => 4, 'differences' => []], $verifier->diff(0));
    $this->assertSame($rowsBefore, $this->indexRows(), 'The sync does not rewrite index rows.');
    $this->assertSame([$b], $this->children('org', (int) $moved->id()));
    $this->assertSame([$a], $this->children('org', (int) $lost->id()));
    $this->assertSame([$b], $this->children('org', (int) $extra->id()));
  }

  /**
   * Verify reports the drift the sync repairs, duplicates included.
   *
   * A non-default translation row of a relation is not drift for either.
   */
  public function testDriftDefinitionMatchesTheSync(): void {
    $a = (int) $this->organisationA->id();
    $this->createRequest();
    $duplicate = $this->createRequest(['title' => 'Blocked drain']);
    $this->addRelationRow((int) $duplicate->id(), $a);
    $translated = $this->createRequest(['title' => 'Fallen branch']);
    $this->addTranslationRow((int) $translated->id(), (int) $this->organisationB->id());
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();

    $drift = $this->verifier()->drift();
    $this->assertSame(1, $drift['drifted']);
    $this->assertSame(['published: duplicate relations' => 1], $drift['patterns']);
    $this->assertSame([sprintf('field=%d relation=%d,%d', $a, $a, $a) => 1], $drift['combinations']);

    $sync = $this->container->get('class_resolver')->getInstanceFromDefinition(OrganisationRelationSync::class);
    $this->assertInstanceOf(OrganisationRelationSync::class, $sync);
    $scanned = array_map(static fn(OrganisationRelationDiff $diff): int => $diff->nid, iterator_to_array($sync->scan(), FALSE));
    $this->assertSame([(int) $duplicate->id()], $scanned);
  }

  /**
   * Returns all index rows and organisation child rows.
   *
   * @return array{rows: list<array<string, mixed>>, organisations: list<array<string, mixed>>}
   *   Rows in node ID order.
   */
  private function indexRows(): array {
    return [
      'rows' => $this->database()->select(RequestIndexWriter::TABLE, 'i')
        ->fields('i')->orderBy('i.nid')->execute()->fetchAll(FetchAs::Associative),
      'organisations' => $this->database()->select(RequestIndexWriter::CHILD_TABLES['organisations'], 'o')
        ->fields('o')->orderBy('o.nid')->orderBy('o.gid')->execute()->fetchAll(FetchAs::Associative),
    ];
  }

  /**
   * Deletes the organisation relations of a request, bypassing hooks.
   */
  private function deleteOrganisationRelations(int $nid): void {
    $ids = $this->database()->select('group_relationship_field_data', 'd')
      ->fields('d', ['id'])
      ->condition('d.entity_id', $nid)
      ->condition('d.group_type', 'org')
      ->execute()
      ->fetchCol();
    $this->assertNotEmpty($ids);
    $this->database()->delete('group_relationship_field_data')->condition('id', $ids, 'IN')->execute();
    $this->database()->delete('group_relationship')->condition('id', $ids, 'IN')->execute();
  }

  /**
   * Adds an organisation relation row for a request, bypassing hooks.
   */
  private function addRelationRow(int $nid, int $gid): void {
    $row = $this->database()->select('group_relationship_field_data', 'd')
      ->fields('d')
      ->condition('d.entity_id', $nid)
      ->condition('d.group_type', 'org')
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    $row['id'] = (int) $this->database()->insert('group_relationship')->fields([
      'type' => $row['type'],
      'uuid' => $this->container->get('uuid')->generate(),
      'langcode' => $row['langcode'],
    ])->execute();
    $row['gid'] = $gid;
    $this->database()->insert('group_relationship_field_data')->fields($row)->execute();
  }

  /**
   * Adds a non-default translation row to the organisation relation.
   */
  private function addTranslationRow(int $nid, int $gid): void {
    $row = $this->database()->select('group_relationship_field_data', 'd')
      ->fields('d')
      ->condition('d.entity_id', $nid)
      ->condition('d.group_type', 'org')
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    $row['langcode'] = $row['langcode'] === 'de' ? 'fr' : 'de';
    $row['default_langcode'] = 0;
    $row['gid'] = $gid;
    $this->database()->insert('group_relationship_field_data')->fields($row)->execute();
  }

  /**
   * Returns the verifier.
   */
  private function verifier(): RequestIndexVerifier {
    $verifier = $this->container->get('markaspot_request_index.verifier');
    $this->assertInstanceOf(RequestIndexVerifier::class, $verifier);
    return $verifier;
  }

}
