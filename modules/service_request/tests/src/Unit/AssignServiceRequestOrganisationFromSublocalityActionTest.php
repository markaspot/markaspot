<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\service_request\Plugin\Action\AssignServiceRequestOrganisationFromSublocality;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the sublocality-based organisation assignment action.
 */
#[CoversClass(AssignServiceRequestOrganisationFromSublocality::class)]
#[Group('service_request')]
class AssignServiceRequestOrganisationFromSublocalityActionTest extends UnitTestCase {

  /**
   * Tests the action plugin id and provider are stable.
   */
  public function testActionPluginIdAndProviderAreStable(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString('namespace Drupal\\service_request\\Plugin\\Action;', $source);
    $this->assertStringContainsString('id = "service_request_assign_organisation_from_sublocality"', $source);
  }

  /**
   * Tests the action defaults target the sublocality fields, not category.
   */
  public function testActionDefaultsTargetSublocalityFields(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString("'sublocality_field' => 'field_sublocality'", $source);
    $this->assertStringContainsString("'org_sublocality_field' => 'field_sublocality_terms'", $source);
    $this->assertStringNotContainsString("'category_field'", $source);
  }

  /**
   * Tests the action does not sync or create group relationships itself.
   *
   * Relationship sync is delegated to the chained sync action.
   */
  public function testActionDoesNotSyncRelationships(): void {
    $source = $this->loadActionSource();

    $this->assertStringNotContainsString("'sync_relationship'", $source);
    $this->assertStringNotContainsString('function syncRelationships(', $source);
    $this->assertStringNotContainsString('addRelationship', $source);
    $this->assertStringNotContainsString('group_relationship', $source);
  }

  /**
   * Tests access checks node update and field edit access.
   */
  public function testActionChecksMutationAccess(): void {
    $source = $this->loadActionSource();

    $this->assertStringContainsString("access('update'", $source);
    $this->assertStringContainsString("access('edit'", $source);
    $this->assertStringContainsString('AccessResult::forbidden()', $source);
  }

  /**
   * Tests the most specific jurisdiction wins.
   */
  public function testOwnJurisdictionMatchWins(): void {
    $queried_jurisdictions = [];
    $action = $this->buildScopedAction(
      [[42]],
      [6],
      $queried_jurisdictions,
    );

    $this->assertSame(42, $this->deriveScoped($action, 7, 9));
    $this->assertSame([9], $queried_jurisdictions);
  }

  /**
   * Tests routing continues to the nearest parent.
   */
  public function testParentJurisdictionMatchIsUsed(): void {
    $queried_jurisdictions = [];
    $action = $this->buildScopedAction(
      [[], [43]],
      [6],
      $queried_jurisdictions,
    );

    $this->assertSame(43, $this->deriveScoped($action, 7, 9));
    $this->assertSame([9, 6], $queried_jurisdictions);
  }

  /**
   * Tests no match at any hierarchy level returns NULL.
   */
  public function testNoHierarchyMatchReturnsNull(): void {
    $queried_jurisdictions = [];
    $action = $this->buildScopedAction(
      [[], []],
      [6],
      $queried_jurisdictions,
    );

    $this->assertNull($this->deriveScoped($action, 7, 9));
    $this->assertSame([9, 6], $queried_jurisdictions);
  }

  /**
   * Tests own-jurisdiction ambiguity preserves first-match routing.
   */
  public function testOwnJurisdictionAmbiguityUsesFirstMatchAndLogs(): void {
    $queried_jurisdictions = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Sublocality @tid maps to multiple organisation groups in its own jurisdiction @jurisdiction. Using first match @org_id for compatibility.',
        [
          '@tid' => 7,
          '@jurisdiction' => 9,
          '@org_id' => 42,
        ],
      );
    $action = $this->buildScopedAction(
      [[42, 43], [44]],
      [6],
      $queried_jurisdictions,
      $logger,
    );

    $this->assertSame(42, $this->deriveScoped($action, 7, 9));
    $this->assertSame([9], $queried_jurisdictions);
  }

  /**
   * Tests that exactly one matching organisation group is returned.
   */
  public function testDeriveReturnsGroupIdWhenExactlyOneMatch(): void {
    $action = $this->buildAction($this->mockGroupStorage([42]));
    $node = $this->mockNode(7, 1);

    $this->assertSame(42, $this->derive($action, $node, ['org']));
  }

  /**
   * Tests that ambiguous (many) matches skip assignment.
   */
  public function testDeriveReturnsNullWhenAmbiguousMatch(): void {
    $action = $this->buildAction($this->mockGroupStorage([42, 43]));
    $node = $this->mockNode(7, 1);

    $this->assertNull($this->derive($action, $node, ['org']));
  }

  /**
   * Tests that no match returns null.
   */
  public function testDeriveReturnsNullWhenNoMatch(): void {
    $action = $this->buildAction($this->mockGroupStorage([]));
    $node = $this->mockNode(7, 1);

    $this->assertNull($this->derive($action, $node, ['org']));
  }

  /**
   * Tests that an empty sublocality field short-circuits to null.
   */
  public function testDeriveReturnsNullWhenSublocalityEmpty(): void {
    $action = $this->buildAction($this->mockGroupStorage([42]));
    $node = $this->mockNode(0, 1);

    $this->assertNull($this->derive($action, $node, ['org']));
  }

  /**
   * Builds the action with mocked dependencies.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $group_storage
   *   The mocked group storage.
   */
  private function buildAction(EntityStorageInterface $group_storage): AssignServiceRequestOrganisationFromSublocality {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($group_storage);

    // The org bundle exposes both required group fields.
    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')->willReturn([
      'field_jurisdiction' => TRUE,
      'field_sublocality_terms' => TRUE,
    ]);

    return new AssignServiceRequestOrganisationFromSublocality(
      [],
      'service_request_assign_organisation_from_sublocality',
      ['id' => 'service_request_assign_organisation_from_sublocality'],
      $entity_type_manager,
      $field_manager,
      $this->createMock(LoggerInterface::class),
      $this->createMock(AccountInterface::class),
      NULL
    );
  }

  /**
   * Builds the action with deterministic jurisdiction query results.
   *
   * @param array<int, int[]> $query_results
   *   Query results in execution order.
   * @param int[] $ancestor_ids
   *   Ancestor jurisdiction IDs, nearest first.
   * @param int[] $queried_jurisdictions
   *   Captured jurisdiction query conditions.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger mock.
   */
  private function buildScopedAction(
    array $query_results,
    array $ancestor_ids,
    array &$queried_jurisdictions,
    ?LoggerInterface $logger = NULL,
  ): AssignServiceRequestOrganisationFromSublocality {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')
      ->willReturnCallback(function () use (&$query_results, &$queried_jurisdictions): QueryInterface {
        $result = array_shift($query_results) ?? [];
        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')
          ->willReturnCallback(function (string $field, mixed $value) use ($query, &$queried_jurisdictions): QueryInterface {
            if ($field === 'field_jurisdiction') {
              $queried_jurisdictions[] = (int) $value;
            }
            if ($field === 'status') {
              $this->assertTrue($value);
            }
            return $query;
          });
        $query->method('range')->with(0, 2)->willReturnSelf();
        $query->method('execute')->willReturn($result);
        return $query;
      });

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('group', 'org')
      ->willReturn([
        'field_jurisdiction' => TRUE,
        'field_sublocality_terms' => TRUE,
      ]);

    $resolver = new class($ancestor_ids) {

      /**
       * Constructs the hierarchy resolver stub.
       *
       * @param int[] $ancestorIds
       *   Ancestor jurisdiction IDs.
       */
      public function __construct(
        private readonly array $ancestorIds,
      ) {}

      /**
       * Gets ancestor jurisdiction IDs.
       *
       * @param int $jurisdiction_id
       *   Starting jurisdiction ID.
       *
       * @return int[]
       *   Ancestor jurisdiction IDs.
       */
      public function getAncestorIds(int $jurisdiction_id): array {
        return $this->ancestorIds;
      }

    };

    return new AssignServiceRequestOrganisationFromSublocality(
      [],
      'service_request_assign_organisation_from_sublocality',
      ['id' => 'service_request_assign_organisation_from_sublocality'],
      $entity_type_manager,
      $field_manager,
      $logger ?? $this->createMock(LoggerInterface::class),
      $this->createMock(AccountInterface::class),
      $resolver,
    );
  }

  /**
   * Mocks group storage whose query returns the given group IDs.
   *
   * @param int[] $result_ids
   *   The group IDs the entity query should return.
   */
  private function mockGroupStorage(array $result_ids): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')
      ->willReturnCallback(function (string $field, mixed $value) use ($query): QueryInterface {
        if ($field === 'status') {
          $this->assertTrue($value);
        }
        return $query;
      });
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($result_ids);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    // For an exactly-one match the action re-loads the group to verify the
    // jurisdiction. The loaded group exposes no field_jurisdiction; combined
    // with a node that also lacks one (see mockNode), groupMatchesJurisdiction
    // takes the single-tenant fallback and returns TRUE.
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('hasField')->willReturn(FALSE);
    $storage->method('load')->willReturn($group);

    return $storage;
  }

  /**
   * Mocks a service request node with the given sublocality and jurisdiction.
   *
   * @param int $sublocality_tid
   *   The sublocality term ID (0 means empty field).
   * @param int $jurisdiction_id
   *   The jurisdiction group ID.
   */
  private function mockNode(int $sublocality_tid, int $jurisdiction_id): NodeInterface {
    $node = $this->createMock(NodeInterface::class);

    // FieldItemListInterface exposes target_id via magic __get (inherited from
    // the typed-data list interface), so the mock must stub __get. The action
    // reads it via the null-coalescing operator (target_id ?? 0), which first
    // calls __isset; that must be stubbed too or the read collapses to NULL.
    $sublocality_item = $this->createMock(FieldItemListInterface::class);
    $sublocality_item->method('isEmpty')->willReturn($sublocality_tid <= 0);
    $sublocality_item->method('__isset')->willReturnCallback(static function (string $name) use ($sublocality_tid) {
      return $name === 'target_id' && $sublocality_tid > 0;
    });
    $sublocality_item->method('__get')->willReturnCallback(static function (string $name) use ($sublocality_tid) {
      return $name === 'target_id' && $sublocality_tid > 0 ? $sublocality_tid : NULL;
    });

    // The node does NOT expose field_jurisdiction in this mock, so the
    // single-tenant jurisdiction fallback applies for the loaded group.
    $node->method('hasField')->willReturnCallback(static function (string $field): bool {
      return $field === 'field_sublocality';
    });
    $node->method('get')->willReturnCallback(function (string $field) use ($sublocality_item) {
      if ($field === 'field_sublocality') {
        return $sublocality_item;
      }
      $empty = $this->createMock(FieldItemListInterface::class);
      $empty->method('isEmpty')->willReturn(TRUE);
      return $empty;
    });

    return $node;
  }

  /**
   * Invokes the protected deriveOrganisationGroupId method.
   *
   * @param \Drupal\service_request\Plugin\Action\AssignServiceRequestOrganisationFromSublocality $action
   *   The action under test.
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string[] $bundles
   *   Accepted organisation group bundles.
   */
  private function derive(AssignServiceRequestOrganisationFromSublocality $action, NodeInterface $node, array $bundles): ?int {
    $method = new \ReflectionMethod($action, 'deriveOrganisationGroupId');
    $method->setAccessible(TRUE);
    return $method->invoke($action, $node, $bundles);
  }

  /**
   * Invokes the protected jurisdiction routing method.
   */
  private function deriveScoped(
    AssignServiceRequestOrganisationFromSublocality $action,
    int $sublocality_tid,
    int $jurisdiction_id,
  ): ?int {
    $method = new \ReflectionMethod(
      $action,
      'deriveJurisdictionOrganisationGroupId',
    );
    return $method->invoke(
      $action,
      $sublocality_tid,
      $jurisdiction_id,
      ['org'],
    );
  }

  /**
   * Loads the action source.
   */
  private function loadActionSource(): string {
    $path = dirname(__DIR__, 3) . '/src/Plugin/Action/AssignServiceRequestOrganisationFromSublocality.php';
    $source = file_get_contents($path);
    $this->assertIsString($source);
    return $source;
  }

}
