<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\TenantPagesAssignedCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests optional editorial page warnings without a legal-readiness claim.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\TenantPagesAssignedCheck
 */
class TenantPagesAssignedCheckTest extends UnitTestCase {

  /**
   * @covers ::run
   */
  public function testMissingPagesNeverBecomeVisibilityDependentErrors(): void {
    $plugin = $this->buildPlugin(0);
    $result = $plugin->run(['jurisdiction' => 1]);
    $this->assertFalse($result->passed);
    $this->assertSame('warning', $result->severity);
    $this->assertSame(1, $result->count);
    $this->assertStringContainsString('Legal content was not assessed', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testExistingPagesDoNotProveLegalContent(): void {
    $result = $this->buildPlugin(1)->run();
    $this->assertTrue($result->passed);
    $this->assertStringContainsString('Legal content was not assessed', $result->message);
  }

  /**
   * Mocks only page lookups; visibility must never influence this check.
   */
  private function buildPlugin(int $pageCount): TenantPagesAssignedCheck {
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->willReturn(TRUE);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(1);
    $group->method('label')->willReturn('Public municipality');
    $group->expects($this->never())->method('hasField');
    $group->expects($this->never())->method('get');
    $plugin = $this->getMockBuilder(TenantPagesAssignedCheck::class)
      ->setConstructorArgs([
        [],
        'tenant_pages_assigned',
        ['id' => 'tenant_pages_assigned', 'label' => 'Information pages', 'severity' => 'warning'],
        $entities,
        $this->createMock(Connection::class),
      ])
      ->onlyMethods(['loadJurisdictions', 'countPagesForJurisdiction'])
      ->getMock();
    $plugin->method('loadJurisdictions')->willReturn([$group]);
    $plugin->method('countPagesForJurisdiction')->with(1)->willReturn($pageCount);
    return $plugin;
  }

}
