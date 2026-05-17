<?php

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests access hardening for internal remark paragraphs.
 *
 * @group markaspot_dashboard
 */
class InternalRemarkAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../markaspot_dashboard.module';

    $container = new ContainerBuilder();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a paragraph mock.
   */
  private function createParagraph(string $bundle): EntityInterface {
    $paragraph = $this->createMock(EntityInterface::class);
    $paragraph->method('getEntityTypeId')->willReturn('paragraph');
    $paragraph->method('bundle')->willReturn($bundle);
    return $paragraph;
  }

  /**
   * Builds an account mock.
   *
   * @param string[] $permissions
   *   Permission strings granted to the account.
   */
  private function createAccount(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

  /**
   * Authenticated users without staff permission cannot view internal remarks.
   */
  public function testViewIsForbiddenWithoutInternalRemarkPermission(): void {
    $result = markaspot_dashboard_entity_access(
          $this->createParagraph('internal_remark'),
          'view',
          $this->createAccount([])
      );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Staff view permission leaves parent entity access in control.
   */
  public function testViewPermissionKeepsAccessNeutral(): void {
    $result = markaspot_dashboard_entity_access(
          $this->createParagraph('internal_remark'),
          'view',
          $this->createAccount(['view field_internal_remark'])
      );

    $this->assertTrue($result->isNeutral());
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Updating internal remarks requires the staff edit permission.
   */
  public function testUpdateIsForbiddenWithoutInternalRemarkEditPermission(): void {
    $result = markaspot_dashboard_entity_access(
          $this->createParagraph('internal_remark'),
          'update',
          $this->createAccount(['view field_internal_remark'])
      );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Non-internal paragraph bundles are left untouched.
   */
  public function testOtherParagraphBundlesStayNeutral(): void {
    $result = markaspot_dashboard_entity_access(
          $this->createParagraph('status'),
          'view',
          $this->createAccount([])
      );

    $this->assertTrue($result->isNeutral());
  }

}
