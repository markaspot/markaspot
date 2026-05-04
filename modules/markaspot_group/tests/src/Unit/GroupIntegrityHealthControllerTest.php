<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_group\Controller\GroupIntegrityHealthController;
use Drupal\markaspot_group\Service\GroupIntegrityChecker;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the group integrity health controller.
 *
 * @group markaspot_group
 */
class GroupIntegrityHealthControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Tests that controller output preserves counts and truncated details.
   */
  public function testChecksReturnStructuredRows(): void {
    $controller = new GroupIntegrityHealthController(new TestHealthGroupIntegrityChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    ));

    $response = $controller->checks(Request::create('/api/admin/health/group-integrity?limit=1'));
    $payload = json_decode($response->getContent() ?: '', TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayHasKey('checked_at', $payload);
    $this->assertFalse($payload['checks']['jur_field_missing_relationship']['passed']);
    $this->assertSame(2, $payload['checks']['jur_field_missing_relationship']['count']);
    $this->assertSame(1, $payload['checks']['jur_field_missing_relationship']['truncated_count']);
    $this->assertSame([
      'entity_id' => 96,
      'field_jurisdiction_target_id' => 1,
    ], $payload['checks']['jur_field_missing_relationship']['rows'][0]);
  }

  /**
   * Tests that access is limited to Drupal administrators and user 1.
   */
  public function testAccessRequiresDrupalAdministrator(): void {
    $controller = new GroupIntegrityHealthController(new TestHealthGroupIntegrityChecker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
    ));

    $admin = $this->createConfiguredMock(AccountInterface::class, [
      'id' => '23',
      'getRoles' => ['authenticated', 'administrator'],
    ]);
    $tenantAdmin = $this->createConfiguredMock(AccountInterface::class, [
      'id' => '24',
      'getRoles' => ['authenticated'],
    ]);
    $userOne = $this->createConfiguredMock(AccountInterface::class, [
      'id' => '1',
      'getRoles' => ['authenticated'],
    ]);

    $this->assertTrue($controller->access($admin)->isAllowed());
    $this->assertTrue($controller->access($userOne)->isAllowed());
    $this->assertFalse($controller->access($tenantAdmin)->isAllowed());
  }

}

/**
 * Test double for deterministic health rows.
 */
class TestHealthGroupIntegrityChecker extends GroupIntegrityChecker {

  /**
   * {@inheritdoc}
   */
  public function check(): array {
    return [
      'jur_field_missing_relationship' => [
        'description' => 'service_request field_jurisdiction values without matching jur group relationship.',
        'rows' => [
          ['entity_id' => 96, 'field_jurisdiction_target_id' => 1],
          ['entity_id' => 99, 'field_jurisdiction_target_id' => 1],
        ],
      ],
      'relationship_missing_group' => [
        'description' => 'Group relationships whose gid no longer exists.',
        'rows' => [],
      ],
    ];
  }

}
