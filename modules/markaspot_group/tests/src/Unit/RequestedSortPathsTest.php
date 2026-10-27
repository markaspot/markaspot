<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Reads JSON:API sort paths for the private field filter guard.
 *
 * @group markaspot_group
 */
final class RequestedSortPathsTest extends UnitTestCase {

  /**
   * Both sort forms yield their paths; malformed input fails closed.
   */
  public function testSortForms(): void {
    $this->assertSame([], _markaspot_group_requested_sort_paths(''));
    $this->assertSame(['created', 'title'], _markaspot_group_requested_sort_paths('-created, title'));
    $this->assertSame(['category_order'], _markaspot_group_requested_sort_paths([
      'primary' => ['path' => 'category_order', 'direction' => 'DESC', 'langcode' => 'de'],
    ]));
    $this->assertSame(['organisation_order', 'nid'], _markaspot_group_requested_sort_paths([
      ['path' => 'organisation_order'],
      ['path' => '-nid'],
    ]));
    $this->assertNull(_markaspot_group_requested_sort_paths([['direction' => 'ASC']]));
    $this->assertNull(_markaspot_group_requested_sort_paths(['category_order']));
    $this->assertNull(_markaspot_group_requested_sort_paths(42));
  }

}
