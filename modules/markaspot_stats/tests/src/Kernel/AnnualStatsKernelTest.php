<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_stats\Kernel;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_archive\ArchiveService;
use Drupal\markaspot_group\Service\FormOnlyReportQueryScope;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_stats\Controller\AnnualStatsController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Proves annual counts survive real entity changes and revision anonymization.
 *
 * @group markaspot_stats
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class AnnualStatsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'taxonomy', 'language',
    'markaspot_archive',
  ];

  /**
   * The report archived during the test.
   */
  private Node $report;
  /**
   * The archive status term ID.
   */
  private int $archiveTid;
  /**
   * Unreadable jurisdiction IDs.
   *
   * @var int[]
   */
  private array $unreadable = [];
  /**
   * The SQL scope supplied by form-only policy.
   */
  private string $restriction = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node']);
    $this->config('system.date')->set('timezone.default', 'Europe/Berlin')->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Report', 'new_revision' => TRUE])->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    foreach (['Closed', 'Archive'] as $name) {
      $term = Term::create(['vid' => 'service_status', 'name' => $name]);
      $term->save();
      $this->archiveTid = (int) $term->id();
    }
    foreach (['field_e_mail' => 'email', 'field_status' => 'entity_reference'] as $name => $type) {
      $storage = ['entity_type' => 'node', 'field_name' => $name, 'type' => $type];
      if ($type === 'entity_reference') {
        $storage['settings'] = ['target_type' => 'taxonomy_term'];
      }
      FieldStorageConfig::create($storage)->save();
      FieldConfig::create(['entity_type' => 'node', 'bundle' => 'service_request', 'field_name' => $name])->save();
    }
    // Only the relationship columns consumed by the aggregate are needed.
    $this->container->get('database')->schema()->createTable('group_relationship_field_data', [
      'fields' => [
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'gid' => ['type' => 'int', 'not null' => TRUE],
        'plugin_id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
      ],
    ]);
    $this->report = $this->createReport('2023-12-31T23:30:00Z', [1, 1, 2]);
    $this->report->addTranslation('de', ['title' => 'Übersetzte Meldung'])->save();
    $this->createReport('2023-12-31T22:30:00Z', [1], FALSE);
    $this->createReport('2024-06-01T12:00:00Z', [4, 1]);
    $this->createReport('2024-07-01T12:00:00Z', [4]);
    $this->createReport('2024-06-01T12:00:00Z', [99]);
  }

  /**
   * Preserves counts across publication, archive and revision changes.
   */
  public function testArchiveAndHistoricAnonymizationPreserveIncomingYearCounts(): void {
    $before = $this->statistics();
    $this->assertSame([
      ['year' => 2024, 'count' => 3], ['year' => 2023, 'count' => 1],
    ], $before['years']);
    $this->assertSame(4, $before['total']);
    $this->assertSame('Europe/Berlin', $before['timezone']);

    $oldVid = $this->report->getRevisionId();
    $archive = new ArchiveService(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      new LoggerChannel('markaspot_archive'),
      $this->container->get('database'),
    );
    $values = $archive->anonymize($this->report, ['field_e_mail']);
    $this->report->setNewRevision(TRUE);
    $this->report->save();
    $archive->anonymizeRevisions($this->report, $values);
    $this->report->set('field_status', $this->archiveTid);
    $this->report->setUnpublished();
    $this->report->setChangedTime(strtotime('2026-10-25T12:00:00Z'));
    $this->report->save();

    $this->assertSame($before, $this->statistics());
    $this->assertNotSame('citizen@example.test', $this->report->get('field_e_mail')->value);
    $old = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($oldVid);
    $this->assertNotSame('citizen@example.test', $old->get('field_e_mail')->value);
  }

  /**
   * Uses the configured timezone and the selected jurisdiction subtree.
   */
  public function testTimezoneAndChildScope(): void {
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
    $this->assertSame([
      ['year' => 2024, 'count' => 2], ['year' => 2023, 'count' => 2],
    ], $this->statistics()['years']);
    $this->assertSame(2, $this->statistics(4)['total']);
  }

  /**
   * Represents years without incoming reports as real zero counts.
   */
  public function testMissingYearsHaveZeroCounts(): void {
    $this->createReport('2026-02-01T12:00:00Z', [1]);
    $this->assertSame([
      ['year' => 2026, 'count' => 1], ['year' => 2025, 'count' => 0],
      ['year' => 2024, 'count' => 3], ['year' => 2023, 'count' => 1],
    ], $this->statistics()['years']);
  }

  /**
   * Counts an implausible future timestamp into next year instead of failing.
   *
   * The created column is unsigned, so the lower clamp to 1970 cannot be
   * exercised with stored data.
   */
  public function testFutureTimestampIsClampedIntoNextYear(): void {
    $this->createReport('2037-06-01T12:00:00Z', [1]);
    $nextYear = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y') + 1;
    $statistics = $this->statistics();
    $years = $statistics['years'];
    $this->assertCount($nextYear - 2023 + 1, $years);
    $this->assertSame(['year' => $nextYear, 'count' => 1], $years[0]);
    $this->assertSame(['year' => 2023, 'count' => 1], end($years));
    $this->assertSame(5, $statistics['total']);
  }

  /**
   * Excludes restricted children even when linked to a public parent.
   */
  public function testRestrictedChildDoesNotLeakThroughItsParentRelationship(): void {
    $this->unreadable = [4];
    $this->assertSame(2, $this->statistics()['total']);
    $this->expectException(AccessDeniedHttpException::class);
    $this->statistics(4);
  }

  /**
   * Honors the form-only SQL restriction.
   */
  public function testFormOnlyRestrictionCannotTurnIntoAnUnscopedCount(): void {
    $this->restriction = ' AND n.nid = 0';
    $this->assertSame([], $this->statistics()['years']);
    $this->assertSame(0, $this->statistics()['total']);
  }

  /**
   * Rejects requests without an explicit jurisdiction.
   */
  public function testRequiresAnExplicitJurisdiction(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->controller()->getStats(new Request());
  }

  /**
   * Rejects unknown jurisdictions instead of returning global totals.
   */
  public function testUnknownJurisdictionDoesNotReturnTheGlobalTotal(): void {
    $this->expectException(AccessDeniedHttpException::class);
    $this->statistics(999);
  }

  /**
   * Creates a synthetic report with jurisdiction relationships.
   */
  private function createReport(string $created, array $groups, bool $published = TRUE): Node {
    $node = Node::create([
      'type' => 'service_request', 'title' => 'Synthetic report',
      'langcode' => 'en', 'created' => strtotime($created), 'status' => $published,
      'field_e_mail' => 'citizen@example.test', 'field_status' => 1,
    ]);
    $node->save();
    foreach ($groups as $gid) {
      $this->container->get('database')->insert('group_relationship_field_data')->fields([
        'entity_id' => $node->id(), 'gid' => $gid, 'plugin_id' => 'group_node:service_request',
      ])->execute();
    }
    return $node;
  }

  /**
   * Returns decoded statistics and verifies the no-store header.
   */
  private function statistics(int $jurisdiction = 1): array {
    $response = $this->controller()->getStats(new Request(['jurisdiction' => (string) $jurisdiction]));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    return json_decode($response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Builds a controller with deterministic workspace policy.
   */
  private function controller(): AnnualStatsController {
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getScopeJurisdictionIds')->willReturnCallback(fn(int $id) => $id === 1 ? [1, 4] : [4]);
    $scope = $this->createMock(FormOnlyReportQueryScope::class);
    $scope->method('getSqlRestrictionForRequest')->willReturn($this->restriction);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('allowsReadFor')->willReturnCallback(fn($viewer, int $id) => !in_array($id, $this->unreadable, TRUE));
    $visibility->method('getUnreadableJurisdictionIds')->willReturn($this->unreadable);
    return new class($this->container->get('database'), $hierarchy, $scope, $this->container->get('config.factory'), $visibility) extends AnnualStatsController {

      /**
       * {@inheritdoc}
       */
      protected function resolveJurisdictionId(string|int|null $value, ?string $group_type = NULL): ?int {
        return in_array((int) $value, [1, 4], TRUE) ? (int) $value : NULL;
      }

    };
  }

}
