<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Plugin\views\filter\GroupLabelFilter;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Plugin/views/filter/GroupLabelFilter.php';

/**
 * Tests tenant scope checks for labelled Group filter options.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Plugin\views\filter\GroupLabelFilter
 */
final class GroupLabelFilterTest extends UnitTestCase {

  /**
   * Tests jurisdiction options are limited to the resolved subtree.
   *
   * @covers ::isGroupInScope
   */
  public function testJurisdictionScope(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(12);

    $this->assertTrue($this->isGroupInScope($group, 'jur', [12, 13], [10]));
    $this->assertFalse($this->isGroupInScope($group, 'jur', [13], [10]));
  }

  /**
   * Tests organisation options are limited to managed tenant roots.
   *
   * @covers ::isGroupInScope
   */
  public function testOrganisationScope(): void {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('getValue')->willReturn([['target_id' => 10]]);

    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
    $group->method('get')->with('field_jurisdiction')->willReturn($field);

    $this->assertTrue($this->isGroupInScope($group, 'org', [12], [10]));
    $this->assertFalse($this->isGroupInScope($group, 'org', [12], [20]));
  }

  /**
   * Invokes the private tenant scope predicate.
   */
  private function isGroupInScope(GroupInterface $group, string $groupType, array $allowedJurisdictions, array $allowedRoots): bool {
    $reflection = new \ReflectionClass(GroupLabelFilter::class);
    $filter = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('isGroupInScope');

    return $method->invoke($filter, $group, $groupType, $allowedJurisdictions, $allowedRoots);
  }

}
