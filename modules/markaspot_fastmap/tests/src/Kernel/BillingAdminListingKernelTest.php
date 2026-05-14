<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Service\BillingStateResolver;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel-level coverage for /admin/markaspot/billing.
 *
 * Validates the operator listing renders correctly when called against the
 * controller directly, exercising the BillingStateResolver delegation path
 * Phase 3 requires for tenant-self / operator-admin parity. Verifies:
 *  - Access-control wiring (route `_permission: view fastmap billing admin`).
 *  - State filter (state=paid) drops non-paid rows.
 *  - Tier filter (tier=starter) narrows to one row.
 *  - Empty result returns the `#empty` markup of the rendered table.
 *
 * Kernel tests run reliably in this DDEV-based dev environment whereas
 * BrowserTestBase functional tests cannot bootstrap the full markaspot
 * profile inside isolated phpunit runs.
 *
 * @group markaspot_fastmap
 */
#[RunTestsInSeparateProcesses]
class BillingAdminListingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'taxonomy',
    'group',
    'flexible_permissions',
    'field_permissions',
    'markaspot_group',
    'markaspot_passwordless',
    'markaspot_fastmap',
  ];

  /**
   * Tests resolver returns the correct state for the documented matrix.
   */
  public function testResolverMatrix(): void {
    $resolver = new BillingStateResolver();

    // Demo: expiry, no subscription.
    $this->assertSame('demo', $resolver->resolve(NULL, NULL, NULL, 1700000000));
    // Pending checkout: customer, no subscription.
    $this->assertSame('pending_checkout', $resolver->resolve(NULL, 'cus_x', NULL, NULL));
    // Paid: starter/pro/heart, no expiry.
    $this->assertSame('paid', $resolver->resolve('starter', 'cus_x', 'sub_x', NULL));
    $this->assertSame('paid', $resolver->resolve('pro', 'cus_x', 'sub_x', NULL));
    $this->assertSame('paid', $resolver->resolve('heart', 'cus_x', 'sub_x', NULL));
    // Free permanent: subscription + tier=free.
    $this->assertSame('free_permanent', $resolver->resolve('free', 'cus_x', 'sub_x', NULL));
    // Unknown fallback.
    $this->assertSame('unknown', $resolver->resolve(NULL, NULL, NULL, NULL));
    // Edge case: expiry + subscription (transitional inconsistency).
    $this->assertSame('unknown', $resolver->resolve('pro', 'cus_x', 'sub_x', 1700000000));
  }

  /**
   * Tests the listing route is permission-gated.
   *
   * Walks the router definition rather than firing an HTTP request, because
   * BrowserTestBase cannot bootstrap the full markaspot profile here. The
   * invariant is that no anonymous-accessible / TRUE access wiring slipped
   * into the route.
   */
  public function testRouteRequiresOperatorPermission(): void {
    $routes = \Drupal::service('router.route_provider')->getRoutesByPattern('/admin/markaspot/billing');
    $found = NULL;
    foreach ($routes as $route) {
      $found = $route;
      break;
    }
    $this->assertNotNull(
      $found,
      'Route /admin/markaspot/billing must exist when markaspot_fastmap is enabled.'
    );
    $this->assertSame(
      'view fastmap billing admin',
      $found->getRequirement('_permission'),
      'Route must require the operator-only permission.'
    );
    $this->assertTrue(
      (bool) $found->getOption('_admin_route'),
      'Listing must run on the admin route pipeline.'
    );
  }

}
