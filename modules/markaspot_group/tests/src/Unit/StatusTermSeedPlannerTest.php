<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\markaspot_group\Service\StatusTermSeedPlanner;
use Drupal\Tests\UnitTestCase;

/**
 * Tests pure status seeding plans for both root relationships.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\StatusTermSeedPlanner
 */
class StatusTermSeedPlannerTest extends UnitTestCase {

  /**
   * Tests in-tree seeding plans references without term creation.
   *
   * @covers ::planSelection
   */
  public function testPlanSelectionUsesSourceTermIds(): void {
    $planner = new StatusTermSeedPlanner();

    $this->assertSame([
      [
        'source_tid' => 3,
        'name' => 'Open',
        'action' => 'select',
        'target_tid' => 3,
        'reason' => '',
      ],
      [
        'source_tid' => 4,
        'name' => 'Closed',
        'action' => 'select',
        'target_tid' => 4,
        'reason' => '',
      ],
    ], $planner->planSelection([
      ['source_tid' => 3, 'name' => 'Open'],
      ['source_tid' => 4, 'name' => 'Closed'],
    ]));
  }

  /**
   * Tests cross-root create and case-insensitive collision planning.
   *
   * @covers ::planCopies
   */
  public function testPlanCopiesCreatesMissingAndReusesCollisions(): void {
    $planner = new StatusTermSeedPlanner();

    $this->assertSame([
      [
        'source_tid' => 3,
        'name' => 'Open',
        'action' => 'create',
        'target_tid' => NULL,
        'match_source_tid' => NULL,
        'reason' => '',
      ],
      [
        'source_tid' => 4,
        'name' => 'Closed',
        'action' => 'skip',
        'target_tid' => 44,
        'match_source_tid' => NULL,
        'reason' => 'Name already exists on target.',
      ],
    ], $planner->planCopies([
      ['source_tid' => 3, 'name' => 'Open'],
      ['source_tid' => 4, 'name' => 'Closed'],
    ], [
      ['target_tid' => 44, 'name' => 'cLoSeD'],
    ]));
  }

  /**
   * Tests that repeated source names stay idempotent within one run.
   *
   * @covers ::planCopies
   */
  public function testPlanCopiesSkipsRepeatedSourceName(): void {
    $planner = new StatusTermSeedPlanner();

    $plan = $planner->planCopies([
      ['source_tid' => 7, 'name' => 'In Progress'],
      ['source_tid' => 8, 'name' => 'in progress'],
    ], []);

    $this->assertSame('create', $plan[0]['action']);
    $this->assertSame('skip', $plan[1]['action']);
    $this->assertNull($plan[1]['target_tid']);
    $this->assertSame(7, $plan[1]['match_source_tid']);
  }

  /**
   * Tests that replacement planning reports target-only selections.
   *
   * @covers ::planDeselections
   */
  public function testPlanDeselectionsReportsRemovedTargetTerms(): void {
    $planner = new StatusTermSeedPlanner();

    $this->assertSame([
      [
        'target_tid' => 9,
        'name' => 'Archived',
        'action' => 'deselect',
        'reason' => 'Not present in the source effective status set.',
      ],
    ], $planner->planDeselections([
      ['target_tid' => 3, 'name' => 'Open'],
      ['target_tid' => 9, 'name' => 'Archived'],
    ], [3, 4]));
  }

}
