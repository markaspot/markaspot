<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_stats\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\FormOnlyReportQueryScope;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_stats\Controller\AnnualStatsController;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the real controller factory, jurisdiction resolver and viewer policy.
 *
 * @group markaspot_stats
 */
final class AnnualStatsAccessTest extends UnitTestCase {

  /**
   * Public credentials cannot borrow the authenticated API owner's access.
   */
  #[DataProvider('viewerRequests')]
  public function testWorkspaceDenialPrecedesDatabaseAccess(bool $public_key, array $query): void {
    $request = new Request($query);
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('query');
    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->expects($this->once())->method('getScopeJurisdictionIds')->with(1)->willReturn([1, 4]);
    $scope = $this->createMock(FormOnlyReportQueryScope::class);
    $scope->expects($this->never())->method('getSqlRestrictionForRequest');
    $account = $this->createMock(AccountInterface::class);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->expects($this->once())->method('requestUsesPublicApiKey')->with($request)->willReturn($public_key);
    $visibility->expects($this->once())->method('allowsReadFor')
      ->with($this->callback(fn($viewer): bool => $public_key ? $viewer instanceof AnonymousUserSession : $viewer === $account), 1)
      ->willReturn(FALSE);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(1);
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    if (($query['jurisdiction_id'] ?? '') === 'amsterdam') {
      $storage->expects($this->once())->method('loadByProperties')
        ->with(['type' => 'jur', 'field_slug' => 'amsterdam', 'status' => 1])->willReturn([$group]);
      $storage->expects($this->never())->method('load');
    }
    else {
      $storage->expects($this->once())->method('load')->with(1)->willReturn($group);
    }
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($storage);
    $container = new ContainerBuilder();
    foreach ([
      'database' => $database,
      'markaspot_group.hierarchy_resolver' => $hierarchy,
      'markaspot_group.form_only_report_query_scope' => $scope,
      'markaspot_group.workspace_visibility' => $visibility,
      'config.factory' => $this->getConfigFactoryStub(['markaspot_open311.settings' => ['jurisdiction_group_type' => 'jur']]),
      'entity_type.manager' => $manager,
      'current_user' => $account,
    ] as $id => $service) {
      $container->set($id, $service);
    }
    \Drupal::setContainer($container);
    $controller = AnnualStatsController::create($container);
    $this->expectException(AccessDeniedHttpException::class);
    $controller->getStats($request);
  }

  /**
   * Covers normal users, public keys, slugs and conflicting scope parameters.
   */
  public static function viewerRequests(): array {
    return [
      'authenticated' => [FALSE, ['jurisdiction' => '1']],
      'public key' => [TRUE, ['jurisdiction' => '1', 'api_key' => 'synthetic-public-key']],
      'primary slug wins' => [TRUE, ['jurisdiction_id' => 'amsterdam', 'jurisdiction' => '99']],
      'empty primary falls back' => [FALSE, ['jurisdiction_id' => '', 'jurisdiction' => '1']],
    ];
  }

}
