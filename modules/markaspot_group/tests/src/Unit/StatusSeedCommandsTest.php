<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Drush\Commands\StatusSeedCommands;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\markaspot_group\Service\StatusTermSeedPlanner;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests status seed command mode selection and operator output.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Drush\Commands\StatusSeedCommands
 */
class StatusSeedCommandsTest extends UnitTestCase {

  /**
   * Tests in-tree apply replaces only the selection and reports deselection.
   *
   * @covers ::seed
   */
  public function testSameTreeApplyReplacesSelectionWithoutCreatingTerms(
  ): void {
    $sourceTerms = [
      3 => $this->createTerm(3, 'Open'),
      4 => $this->createTerm(4, 'Closed'),
    ];
    $previous = $this->createTerm(9, 'Archived');
    $target = $this->createJurisdiction(12, [9]);
    $target->expects($this->once())
      ->method('set')
      ->with('field_service_statuses', [
        ['target_id' => 3],
        ['target_id' => 4],
      ])
      ->willReturnSelf();
    $target->expects($this->once())->method('save');

    [$command, $taxonomyStorage] = $this->createCommand(
      $target,
      $sourceTerms,
      [9 => $previous],
      7,
      7,
    );
    $taxonomyStorage->expects($this->never())->method('create');

    $rows = iterator_to_array($command->seed(12, [
      'from' => 7,
      'apply' => TRUE,
    ]));

    $this->assertSame(
      ['selected', 'selected', 'deselected'],
      array_column($rows, 'result'),
    );
    $this->assertSame(['3', '4', '9'], array_column($rows, 'target_tid'));
  }

  /**
   * Tests cross-root dry-run plans copies, selection, and deselection.
   *
   * @covers ::seed
   */
  public function testCrossRootDryRunDoesNotCreateTerms(): void {
    $sourceTerms = [
      3 => $this->createTerm(3, 'Open'),
    ];
    $previous = $this->createTerm(9, 'Archived');
    $target = $this->createJurisdiction(12, [9]);
    $target->expects($this->never())->method('set');
    $target->expects($this->never())->method('save');

    [$command, $taxonomyStorage] = $this->createCommand(
      $target,
      $sourceTerms,
      [9 => $previous],
      7,
      20,
      [],
    );
    $taxonomyStorage->expects($this->never())->method('create');

    $rows = iterator_to_array($command->seed(12, [
      'from' => 7,
      'apply' => FALSE,
    ]));

    $this->assertSame(
      ['would-create-and-select', 'would-deselect'],
      array_column($rows, 'result'),
    );
  }

  /**
   * Creates a fully wired command with mocked storage and hierarchy.
   *
   * @param \Drupal\group\Entity\GroupInterface $target
   *   Target jurisdiction.
   * @param \Drupal\taxonomy\TermInterface[] $sourceTerms
   *   Effective source terms.
   * @param \Drupal\taxonomy\TermInterface[] $selectedTerms
   *   Terms currently selected on the target.
   * @param int $sourceRoot
   *   Source root jurisdiction ID.
   * @param int $targetRoot
   *   Target root jurisdiction ID.
   * @param \Drupal\taxonomy\TermInterface[]|null $targetPool
   *   Target root pool for cross-root mode, or NULL for same-tree mode.
   *
   * @return array
   *   Command and taxonomy storage mock.
   */
  protected function createCommand(
    GroupInterface $target,
    array $sourceTerms,
    array $selectedTerms,
    int $sourceRoot,
    int $targetRoot,
    ?array $targetPool = NULL,
  ): array {
    $source = $this->createJurisdiction(7);
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->willReturnCallback(
        static fn(int $groupId): ?GroupInterface => match ($groupId) {
          7 => $source,
          12 => $target,
          default => NULL,
        },
      );

    $taxonomyStorage = $this->createMock(EntityStorageInterface::class);
    $loadExpectation = $selectedTerms === []
      ? $this->never()
      : $this->once();
    $taxonomyStorage->expects($loadExpectation)
      ->method('loadMultiple')
      ->willReturn($selectedTerms);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(
        static fn(string $entityTypeId): EntityStorageInterface => match ($entityTypeId) {
          'group' => $groupStorage,
          'taxonomy_term' => $taxonomyStorage,
        },
      );

    $scope = $this->createMock(StatusTermScope::class);
    $scope->expects($this->once())
      ->method('canScope')
      ->with(7)
      ->willReturn(TRUE);
    $scope->expects($this->once())
      ->method('loadByProperties')
      ->with(['vid' => 'service_status'], 7)
      ->willReturn($sourceTerms);
    if ($targetPool !== NULL) {
      $scope->expects($this->once())
        ->method('loadTreePoolByProperties')
        ->with(['vid' => 'service_status'], 12)
        ->willReturn($targetPool);
    }

    $hierarchyResolver = $this->createMock(
      JurisdictionHierarchyResolverInterface::class,
    );
    $hierarchyResolver->expects($this->exactly(2))
      ->method('getRootJurisdictionId')
      ->willReturnCallback(static fn(int $groupId): int => match ($groupId) {
        7 => $sourceRoot,
        12 => $targetRoot,
      });

    $open311Config = $this->createMock(ImmutableConfig::class);
    $open311Config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($open311Config);

    return [
      new StatusSeedCommands(
        $entityTypeManager,
        $scope,
        new StatusTermSeedPlanner(),
        $configFactory,
        $hierarchyResolver,
      ),
      $taxonomyStorage,
    ];
  }

  /**
   * Creates a jurisdiction group with an explicit selection.
   *
   * @param int $groupId
   *   Group ID.
   * @param int[] $selection
   *   Selected term IDs.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   Mocked jurisdiction group.
   */
  protected function createJurisdiction(
    int $groupId,
    array $selection = [],
  ): GroupInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn(array_map(
      static fn(int $termId): array => ['target_id' => $termId],
      $selection,
    ));

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $groupId);
    $group->method('bundle')->willReturn('jur');
    $group->method('hasField')
      ->with('field_service_statuses')
      ->willReturn(TRUE);
    $group->method('get')
      ->with('field_service_statuses')
      ->willReturn($field);
    return $group;
  }

  /**
   * Creates a source or selected service status term.
   *
   * @param int $termId
   *   Term ID.
   * @param string $label
   *   Default-language label.
   *
   * @return \Drupal\taxonomy\TermInterface|\PHPUnit\Framework\MockObject\MockObject
   *   Mocked service status term.
   */
  protected function createTerm(int $termId, string $label): TermInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($termId);
    $term->method('getUntranslated')->willReturnSelf();
    $term->method('label')->willReturn($label);
    return $term;
  }

}
