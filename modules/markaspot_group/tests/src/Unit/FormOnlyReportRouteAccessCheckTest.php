<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Access\FormOnlyReportRouteAccessCheck;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests additional protection of UUID and converted-node follow-up routes.
 *
 * @group markaspot_group
 */
class FormOnlyReportRouteAccessCheckTest extends UnitTestCase {

  /**
   * Tests that UUID possession cannot override form-only report scope.
   *
   * @dataProvider accessCases
   */
  public function testAccess(string $mode, bool $scope_allowed, bool $node_allowed, bool $converted_node, bool $expected): void {
    $account = new AnonymousUserSession();
    $jurisdiction = $this->createMock(FieldItemListInterface::class);
    $jurisdiction->method('isEmpty')->willReturn(FALSE);
    $jurisdiction->method('getValue')->willReturn([['target_id' => 5]]);
    $organisation = $this->createMock(FieldItemListInterface::class);
    $organisation->method('getValue')->willReturn([['target_id' => 9]]);
    $fields = ['field_jurisdiction' => $jurisdiction, 'field_organisation' => $organisation];
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')->willReturnCallback(static fn(string $name) => $fields[$name]);
    $node->method('getCacheTags')->willReturn(['node:12']);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    $node->expects($mode === 'form_only' && $scope_allowed ? $this->once() : $this->never())
      ->method('access')->with('view', $account, TRUE)->willReturn(AccessResult::allowedIf($node_allowed));
    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->expects($converted_node ? $this->never() : $this->once())
      ->method('loadByProperties')->with(['uuid' => 'receipt-uuid'])->willReturn([$node]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('loadMultiple')->willReturn([5 => $group]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['node', $node_storage], ['group', $group_storage]]);
    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    $container->set('entity_type.manager', $manager);
    $container->set('config.factory', $this->getConfigFactoryStub(['markaspot_open311.settings' => ['jurisdiction_group_type' => 'jur']]));
    \Drupal::setContainer($container);
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('getVisibility')->with(5)->willReturn($mode);
    $visibility->expects($mode === 'form_only' ? $this->once() : $this->never())
      ->method('allowsReportReadFor')->with($account, 5, [9])->willReturn($scope_allowed);
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')->willReturnMap([
      ['node', $converted_node ? $node : NULL],
      ['uuid', 'receipt-uuid'],
    ]);
    $result = (new FormOnlyReportRouteAccessCheck($manager, $visibility))->access($route_match, $account);
    $this->assertSame($expected, $result->isAllowed());
    $this->assertContains('group_list', $result->getCacheTags());
    $this->assertContains('node:12', $result->getCacheTags());
    if ($mode === 'form_only') {
      $this->assertSame(0, $result->getCacheMaxAge());
      $this->assertContains('user', $result->getCacheContexts());
    }
  }

  /**
   * Covers both endpoint parameter shapes and all existing visibility modes.
   */
  public static function accessCases(): array {
    return [
      'receipt UUID denied' => ['form_only', FALSE, TRUE, FALSE, FALSE],
      'scoped staff allowed' => ['form_only', TRUE, TRUE, FALSE, TRUE],
      'entity access still required' => ['form_only', TRUE, FALSE, FALSE, FALSE],
      'AI converted node denied before feature response' => ['form_only', FALSE, TRUE, TRUE, FALSE],
      'AI authorized staff allowed' => ['form_only', TRUE, TRUE, TRUE, TRUE],
      'public preserved' => ['public', FALSE, FALSE, FALSE, TRUE],
      'submission only preserved' => ['submission_only', FALSE, FALSE, FALSE, TRUE],
      'authenticated preserved' => ['authenticated', FALSE, FALSE, FALSE, TRUE],
      'blocked preserved' => ['blocked', FALSE, FALSE, FALSE, TRUE],
    ];
  }

}
