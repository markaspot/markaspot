<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests effective service status resolution.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\StatusTermScope
 */
class StatusTermScopeTest extends UnitTestCase {

  /**
   * Tests that an explicit jurisdiction selection wins over the root pool.
   *
   * @covers ::loadByProperties
   */
  public function testSelectionWinsOverTreePool(): void {
    $first = $this->createTerm(101, 7);
    $selected = $this->createTerm(102, 7);
    $scope = $this->buildScopedResolver(
      12,
      7,
      [$first, $selected],
      [102],
    );

    $this->assertSame(
      [1 => $selected],
      $scope->loadByProperties(['vid' => 'service_status'], 12),
    );
  }

  /**
   * Tests that an empty selection inherits every root-owned status.
   *
   * @covers ::loadByProperties
   */
  public function testEmptySelectionUsesTreePool(): void {
    $terms = [
      101 => $this->createTerm(101, 7),
      102 => $this->createTerm(102, 7),
    ];
    $scope = $this->buildScopedResolver(7, 7, $terms, []);

    $this->assertSame(
      $terms,
      $scope->loadByProperties(['vid' => 'service_status'], 7),
    );
  }

  /**
   * Tests that a child ID is resolved to its root before term loading.
   *
   * @covers ::loadByProperties
   */
  public function testChildJurisdictionLoadsRootOwnedTerms(): void {
    $term = $this->createTerm(101, 7);
    $scope = $this->buildScopedResolver(12, 7, [$term], []);

    $this->assertSame(
      [$term],
      $scope->loadByProperties(['vid' => 'service_status'], 12),
    );
  }

  /**
   * Tests that unscoped single-tenant lookups remain unchanged.
   *
   * @covers ::loadByProperties
   * @covers ::canScope
   */
  public function testSingleTenantLookupPassesThroughUnchanged(): void {
    $term = $this->createMock(TermInterface::class);
    $taxonomyStorage = $this->createMock(EntityStorageInterface::class);
    $taxonomyStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['vid' => 'service_status', 'status' => 1])
      ->willReturn([$term]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($taxonomyStorage);

    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->expects($this->once())
      ->method('getFieldStorageDefinitions')
      ->with('taxonomy_term')
      ->willReturn([]);

    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchyResolver->expects($this->never())
      ->method('getRootJurisdictionId');

    $scope = new StatusTermScope(
      $entityTypeManager,
      $fieldManager,
      $hierarchyResolver,
    );

    $this->assertSame(
      [$term],
      $scope->loadByProperties(
        ['vid' => 'service_status', 'status' => 1],
        12,
      ),
    );
  }

  /**
   * Tests that a missing jurisdiction keeps the legacy lookup unchanged.
   *
   * @covers ::loadByProperties
   * @covers ::canScope
   */
  public function testLookupWithoutJurisdictionPassesThroughUnchanged(): void {
    $taxonomyStorage = $this->createMock(EntityStorageInterface::class);
    $taxonomyStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['vid' => 'service_status'])
      ->willReturn([]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($taxonomyStorage);

    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->expects($this->never())
      ->method('getFieldStorageDefinitions');
    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchyResolver->expects($this->never())
      ->method('getRootJurisdictionId');

    $scope = new StatusTermScope(
      $entityTypeManager,
      $fieldManager,
      $hierarchyResolver,
    );

    $this->assertSame(
      [],
      $scope->loadByProperties(['vid' => 'service_status'], NULL),
    );
  }

  /**
   * Tests that selection references from another root are dropped.
   *
   * @covers ::loadByProperties
   */
  public function testForeignSelectedTermsAreDroppedDefensively(): void {
    $owned = $this->createTerm(101, 7);
    $foreign = $this->createTerm(202, 9);
    $scope = $this->buildScopedResolver(
      12,
      7,
      [
        101 => $owned,
        202 => $foreign,
      ],
      [101, 202],
    );

    $this->assertSame(
      [101 => $owned],
      $scope->loadByProperties(['vid' => 'service_status'], 12),
    );
  }

  /**
   * Builds a resolver with a root-owned taxonomy pool and group selection.
   *
   * @param int $requestedId
   *   Requested jurisdiction group ID.
   * @param int $rootId
   *   Resolved root jurisdiction group ID.
   * @param \Drupal\taxonomy\TermInterface[] $terms
   *   Terms returned by taxonomy storage.
   * @param int[] $selection
   *   Explicit selected term IDs, or an empty list for inheritance.
   *
   * @return \Drupal\markaspot_group\Service\StatusTermScope
   *   Configured effective status resolver.
   */
  protected function buildScopedResolver(
    int $requestedId,
    int $rootId,
    array $terms,
    array $selection,
  ): StatusTermScope {
    $taxonomyStorage = $this->createMock(EntityStorageInterface::class);
    $taxonomyStorage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'vid' => 'service_status',
        'field_jurisdiction' => $rootId,
      ])
      ->willReturn($terms);

    $selectionField = $this->createMock(FieldItemListInterface::class);
    $selectionField->method('isEmpty')->willReturn($selection === []);
    $selectionField->method('getValue')->willReturn(array_map(
      static fn(int $termId): array => ['target_id' => $termId],
      $selection,
    ));

    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')
      ->with('field_service_statuses')
      ->willReturn(TRUE);
    $group->method('get')
      ->with('field_service_statuses')
      ->willReturn($selectionField);

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->expects($this->once())
      ->method('load')
      ->with($requestedId)
      ->willReturn($group);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static fn(string $entityTypeId): EntityStorageInterface => match ($entityTypeId) {
        'taxonomy_term' => $taxonomyStorage,
        'group' => $groupStorage,
      });

    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->expects($this->once())
      ->method('getFieldStorageDefinitions')
      ->with('taxonomy_term')
      ->willReturn([
        'field_jurisdiction' => $this->createMock(FieldStorageDefinitionInterface::class),
      ]);

    $hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchyResolver->expects($this->once())
      ->method('getRootJurisdictionId')
      ->with($requestedId)
      ->willReturn($rootId);

    return new StatusTermScope(
      $entityTypeManager,
      $fieldManager,
      $hierarchyResolver,
    );
  }

  /**
   * Creates a service status term with one jurisdiction owner.
   *
   * @param int $termId
   *   Taxonomy term ID.
   * @param int $jurisdictionId
   *   Owning root jurisdiction group ID.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   Mocked service status term.
   */
  protected function createTerm(int $termId, int $jurisdictionId): TermInterface {
    $jurisdictionField = $this->createMock(FieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('getValue')
      ->willReturn([['target_id' => $jurisdictionId]]);

    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($termId);
    $term->method('bundle')->willReturn('service_status');
    $term->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    return $term;
  }

}
