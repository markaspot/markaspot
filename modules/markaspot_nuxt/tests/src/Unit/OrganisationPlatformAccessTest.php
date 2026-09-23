<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the platform boundary independently of role-based access grants.
 */
#[Group('markaspot_nuxt')]
final class OrganisationPlatformAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../markaspot_nuxt.module';
  }

  /**
   * SaaS organisation writes cannot be granted by tenant-admin roles.
   */
  public function testSaasWritesOverrideRoleGrants(): void {
    $this->setPlatform(TRUE);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $org = $this->createMock(EntityInterface::class);
    $org->method('bundle')->willReturn('org');
    $results = [markaspot_nuxt_entity_create_access($account, ['entity_type_id' => 'group'], 'org')];
    foreach (['update', 'delete'] as $operation) {
      $results[] = markaspot_nuxt_group_access($org, $operation, $account);
    }
    foreach ($results as $result) {
      $this->assertTrue($result->isForbidden());
      $this->assertTrue(AccessResult::allowed()->orIf($result)->isForbidden());
      $this->assertSame(0, $result->getCacheMaxAge());
    }
    $this->assertTrue(markaspot_nuxt_group_access($org, 'view', $account)->isNeutral());
    $this->assertTrue(markaspot_nuxt_entity_create_access($account, ['entity_type_id' => 'group'], 'jur')->isNeutral());
  }

  /**
   * Dedicated installations still rely on normal entity permissions.
   */
  public function testDedicatedInstallationsDoNotGainPermissions(): void {
    $this->setPlatform(FALSE);
    $account = $this->createMock(AccountInterface::class);
    $org = $this->createMock(EntityInterface::class);
    $org->method('bundle')->willReturn('org');
    $this->assertTrue(markaspot_nuxt_entity_create_access($account, ['entity_type_id' => 'group'], 'org')->isNeutral());
    foreach (['view', 'update', 'delete'] as $operation) {
      $this->assertTrue(markaspot_nuxt_group_access($org, $operation, $account)->isNeutral());
    }
  }

  /**
   * An operated enterprise showcase manages organisations.
   *
   * It behaves like a dedicated installation although it runs in saas mode.
   */
  public function testEnterpriseShowcaseAllowsOrganisationWrites(): void {
    $this->setPlatform(TRUE, TRUE);
    $account = $this->createMock(AccountInterface::class);
    $org = $this->createMock(EntityInterface::class);
    $org->method('bundle')->willReturn('org');
    $this->assertTrue(markaspot_nuxt_entity_create_access($account, ['entity_type_id' => 'group'], 'org')->isNeutral());
    foreach (['update', 'delete'] as $operation) {
      $this->assertTrue(markaspot_nuxt_group_access($org, $operation, $account)->isNeutral());
    }
  }

  /**
   * Sets the server-side platform detection service.
   */
  private function setPlatform(bool $selfService, bool $showcase = FALSE): void {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->method('isSelfServicePlatform')->willReturn($selfService);
    $resolver->method('allowsEnterpriseFeatures')->willReturn(!$selfService || $showcase);
    $container = new ContainerBuilder();
    $container->set('markaspot_nuxt.feature_scope_resolver', $resolver);
    \Drupal::setContainer($container);
  }

}
