<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\markaspot_tenant_import\Service\TenantValidationDefaults;
use Drupal\Tests\UnitTestCase;

/**
 * Tests example geography cleanup without changing operator configuration.
 *
 * @group markaspot_tenant_import
 * @coversDefaultClass \Drupal\markaspot_tenant_import\Service\TenantValidationDefaults
 */
final class TenantValidationDefaultsTest extends UnitTestCase {

  private const SHIPPED = ['wkt' => 'POLYGON((0 0,1 0,1 1,0 0))', 'locality' => ['Example city']];

  /**
   * An empty installation cannot inherit the distribution example boundary.
   */
  public function testUntouchedExampleIsCleared(): void {
    $plan = TenantValidationDefaults::plan(self::SHIPPED, self::SHIPPED, FALSE, FALSE);
    $this->assertSame(['wkt', 'locality'], $plan['clear']);
  }

  /**
   * Real boundaries and deliberate choices made after setup are preserved.
   */
  public function testOperatorConfigurationIsPreserved(): void {
    foreach ([['wkt' => 'custom'], ['wkt' => ''], ['locality' => ['Configured city']]] as $change) {
      $plan = TenantValidationDefaults::plan(array_replace(self::SHIPPED, $change), self::SHIPPED, FALSE, FALSE);
      $this->assertSame([], $plan['clear']);
    }
    $plan = TenantValidationDefaults::plan(self::SHIPPED, self::SHIPPED, TRUE, TRUE);
    $this->assertSame('preserve_initialized', $plan['action']);
  }

  /**
   * Existing reports forbid silently widening the configured activity area.
   */
  public function testPopulatedInstallationRequiresReview(): void {
    $this->expectExceptionMessage('populated installation requires operator review');
    TenantValidationDefaults::plan(self::SHIPPED, self::SHIPPED, TRUE, FALSE);
  }

  /**
   * Missing baseline data must never authorize configuration removal.
   */
  public function testMissingBaselineFailsClosed(): void {
    $this->expectExceptionMessage('Shipped validation defaults are unavailable');
    TenantValidationDefaults::plan(self::SHIPPED, [], FALSE, FALSE);
  }

}
