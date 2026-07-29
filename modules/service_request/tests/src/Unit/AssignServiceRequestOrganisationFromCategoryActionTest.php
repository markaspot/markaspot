<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\service_request\Plugin\Action\AssignServiceRequestOrganisationFromCategory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Plugin/Action/AssignServiceRequestOrganisationFromCategory.php';

/**
 * Tests category-based organisation routing through jurisdiction ancestors.
 */
#[CoversClass(AssignServiceRequestOrganisationFromCategory::class)]
#[Group('service_request')]
class AssignServiceRequestOrganisationFromCategoryActionTest extends UnitTestCase {

  /**
   * Tests the most specific jurisdiction wins.
   */
  public function testOwnJurisdictionMatchWins(): void {
    $queried_jurisdictions = [];
    $action = $this->buildAction(
      [[42]],
      [6],
      $queried_jurisdictions,
    );

    $this->assertSame(42, $this->derive($action, 9, 1));
    $this->assertSame([9], $queried_jurisdictions);
  }

  /**
   * Tests routing continues to the nearest parent.
   */
  public function testParentJurisdictionMatchIsUsed(): void {
    $queried_jurisdictions = [];
    $action = $this->buildAction(
      [[], [43]],
      [6],
      $queried_jurisdictions,
    );

    $this->assertSame(43, $this->derive($action, 9, 1));
    $this->assertSame([9, 6], $queried_jurisdictions);
  }

  /**
   * Tests no match at any hierarchy level returns NULL.
   */
  public function testNoHierarchyMatchReturnsNull(): void {
    $queried_jurisdictions = [];
    $action = $this->buildAction(
      [[], []],
      [6],
      $queried_jurisdictions,
    );

    $this->assertNull($this->derive($action, 9, 1));
    $this->assertSame([9, 6], $queried_jurisdictions);
  }

  /**
   * Tests ambiguous matches are logged and never guessed.
   */
  public function testAmbiguousMatchIsLoggedAndStopsRouting(): void {
    $queried_jurisdictions = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Category @tid maps to multiple organisation groups in jurisdiction @jurisdiction. Skipping automatic assignment.',
        [
          '@tid' => 1,
          '@jurisdiction' => 9,
        ],
      );
    $action = $this->buildAction(
      [[42, 43], [44]],
      [6],
      $queried_jurisdictions,
      $logger,
    );

    $this->assertNull($this->derive($action, 9, 1));
    $this->assertSame([9], $queried_jurisdictions);
  }

  /**
   * Builds the action with deterministic query results.
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
  private function buildAction(
    array $query_results,
    array $ancestor_ids,
    array &$queried_jurisdictions,
    ?LoggerInterface $logger = NULL,
  ): AssignServiceRequestOrganisationFromCategory {
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
        'field_service_categories' => TRUE,
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

    return new AssignServiceRequestOrganisationFromCategory(
      [],
      'service_request_assign_organisation_from_category',
      ['id' => 'service_request_assign_organisation_from_category'],
      $entity_type_manager,
      $field_manager,
      $logger ?? $this->createMock(LoggerInterface::class),
      $this->createMock(AccountInterface::class),
      $resolver,
    );
  }

  /**
   * Invokes the protected jurisdiction routing method.
   */
  private function derive(
    AssignServiceRequestOrganisationFromCategory $action,
    int $jurisdiction_id,
    int $category_id,
  ): ?int {
    $method = new \ReflectionMethod(
      $action,
      'deriveJurisdictionOrganisationGroupId',
    );
    return $method->invoke($action, $category_id, $jurisdiction_id, ['org']);
  }

}
