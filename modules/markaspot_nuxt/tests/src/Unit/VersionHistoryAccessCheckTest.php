<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
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
    return new VersionHistoryAccessCheck($resolver);
  }

  /**
   * Creates an account with the given user ID.
   */
  private function account(int $uid, array $roles = ['authenticated']): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
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

}
