<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;
use Drupal\markaspot_nuxt\Access\VersionHistoryAccessCheck;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the platform gate on the service request version history.
 *
 * @group markaspot_nuxt
 * @coversDefaultClass \Drupal\markaspot_nuxt\Access\VersionHistoryAccessCheck
 */
class VersionHistoryAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates the access check for a stack with or without enterprise features.
   */
  private function createCheck(bool $enterprise): VersionHistoryAccessCheck {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->method('allowsEnterpriseFeatures')->willReturn($enterprise);
    return new VersionHistoryAccessCheck($resolver, $this->createMock(EntityTypeManagerInterface::class));
  }

  /**
   * Creates an account with the given user ID.
   */
  private function account(int $uid, array $roles = ['authenticated']): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn($uid > 0);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

  /**
   * Enterprise stacks leave the decision to the permission requirement.
   *
   * @covers ::check
   */
  public function testEnterpriseStackPassesThrough(): void {
    $check = $this->createCheck(TRUE);
    $this->assertTrue($check->check($this->account(42))->isAllowed());
    $this->assertTrue($check->check($this->account(1))->isAllowed());
  }

  /**
   * The self-service platform keeps the history with the operator.
   *
   * @covers ::check
   */
  public function testSelfServicePlatformAllowsOnlyOperator(): void {
    $check = $this->createCheck(FALSE);
    $this->assertTrue($check->check($this->account(1))->isAllowed());
    $this->assertTrue($check->check($this->account(7, ['authenticated', 'administrator']))->isAllowed());

    $member = $check->check($this->account(42, ['authenticated', 'tenant_admin', 'moderator']));
    $this->assertFalse($member->isAllowed());
    $this->assertContains('user', $member->getCacheContexts());
  }

  /**
   * Core snapshot grants require both current and historical private scope.
   */
  public function testSnapshotsRequirePlatformPermissionAndBothScopes(): void {
    foreach ([
      [TRUE, TRUE, TRUE, TRUE, TRUE],
      [TRUE, FALSE, TRUE, TRUE, FALSE],
      [TRUE, TRUE, FALSE, TRUE, FALSE],
      [TRUE, TRUE, TRUE, FALSE, FALSE],
      [FALSE, TRUE, TRUE, TRUE, FALSE],
    ] as [$enterprise, $current_scope, $old_scope, $permission, $expected]) {
      $account = $this->account(42);
      $account->method('hasPermission')->willReturnCallback(fn(string $name): bool => $permission && $name === 'edit any service_request content');
      $resolver = $this->createMock(FeatureScopeResolver::class);
      $resolver->method('allowsEnterpriseFeatures')->willReturn($enterprise);
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $storage = $this->createMock(EntityStorageInterface::class);
      $current = $this->createMock(NodeInterface::class);
      $historical = $this->createMock(NodeInterface::class);
      foreach ([[$current, $current_scope], [$historical, $old_scope]] as [$node, $allowed]) {
        $node->method('hasField')->with('field_assignee')->willReturn(TRUE);
        $field = $this->createMock(FieldItemListInterface::class);
        $field->method('access')->willReturn($allowed ? AccessResult::allowed() : AccessResult::neutral());
        $node->method('get')->with('field_assignee')->willReturn($field);
      }
      $current->method('access')->willReturn(AccessResult::allowed());
      $manager->method('getStorage')->with('node')->willReturn($storage);
      $storage->method('loadUnchanged')->willReturn($current);
      $result = (new VersionHistoryAccessCheck($resolver, $manager))->accessNode($historical, $account);
      $this->assertSame($expected, $result->isAllowed());
      if (!$expected) {
        $this->assertTrue($result->isForbidden(), 'Core grants cannot override a scope denial.');
      }
    }
  }

}
