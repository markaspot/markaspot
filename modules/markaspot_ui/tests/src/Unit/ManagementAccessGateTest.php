<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ui\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_ui\Service\ManagementAccessGate;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Service/ManagementAccessGate.php';

/**
 * Tests the operating-mode gate for the management surface.
 *
 * @group markaspot_ui
 * @coversDefaultClass \Drupal\markaspot_ui\Service\ManagementAccessGate
 */
final class ManagementAccessGateTest extends UnitTestCase {

  /**
   * Tests that self-hosted installations always allow full management.
   *
   * @covers ::allowFullManagement
   */
  public function testSelfHostedAllowsEveryone(): void {
    new Settings(['markaspot_operating_mode' => 'self_hosted']);
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->never())->method('hasPermission');

    $gate = new ManagementAccessGate($account);
    $this->assertTrue($gate->allowFullManagement());
  }

  /**
   * Tests that an unset operating mode defaults to self-hosted.
   *
   * @covers ::allowFullManagement
   */
  public function testDefaultsToSelfHosted(): void {
    new Settings([]);
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->never())->method('hasPermission');

    $gate = new ManagementAccessGate($account);
    $this->assertTrue($gate->allowFullManagement());
  }

  /**
   * Tests that SaaS gates non-trusted accounts out of full management.
   *
   * @covers ::allowFullManagement
   */
  public function testSaasBlocksNonTrustedAccount(): void {
    new Settings(['markaspot_operating_mode' => 'saas']);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('administer nodes')->willReturn(FALSE);

    $gate = new ManagementAccessGate($account);
    $this->assertFalse($gate->allowFullManagement());
  }

  /**
   * Tests that SaaS still allows trusted operators.
   *
   * @covers ::allowFullManagement
   */
  public function testSaasAllowsTrustedOperator(): void {
    new Settings(['markaspot_operating_mode' => 'saas']);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('administer nodes')->willReturn(TRUE);

    $gate = new ManagementAccessGate($account);
    $this->assertTrue($gate->allowFullManagement());
  }

}
