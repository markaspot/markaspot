<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Service\FormOnlyReportQueryScope;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests API-key aggregates never inherit a key owner's internal read rights.
 *
 * @group markaspot_group
 */
class FormOnlyReportQueryScopeTest extends UnitTestCase {

  /**
   * Custom and default API credentials use the anonymous visibility policy.
   */
  public function testApiKeyRequestsUseAnonymousAccount(): void {
    foreach (['x-custom-key', 'api-key', 'apikey', 'x-api-key'] as $header) {
      $request = new Request();
      $request->headers->set($header, 'test-key');
      $this->assertViewer($request, TRUE);
    }
    $this->assertViewer(new Request(['custom_key' => 'test-key']), TRUE);
    $this->assertViewer(new Request(), FALSE);
  }

  /**
   * Verifies which account reaches the shared SQL policy.
   */
  private function assertViewer(Request $request, bool $anonymous): void {
    $scope = $this->getMockBuilder(FormOnlyReportQueryScope::class)
      ->setConstructorArgs([
        $this->createMock(WorkspaceVisibilityInterface::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(EntityFieldManagerInterface::class),
        $this->createMock(Connection::class),
        $this->getConfigFactoryStub(['services_api_key_auth.settings' => [
          'api_key_request_header_name' => 'x-custom-key',
          'api_key_get_parameter_name' => 'custom_key',
        ]]),
      ])->onlyMethods(['getSqlRestrictionFor'])->getMock();
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $scope->expects($this->once())->method('getSqlRestrictionFor')
      ->willReturnCallback(function (AccountInterface $viewer) use ($anonymous, $account): string {
        $this->assertSame($anonymous, $viewer->isAnonymous());
        if (!$anonymous) {
          $this->assertSame($account, $viewer);
        }
        return ' AND 1 = 0';
      });
    $this->assertSame(' AND 1 = 0', $scope->getSqlRestrictionForRequest($request, $account));
  }

}
