<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_health\Unit\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\markaspot_health\Plugin\HealthCheck\ContentWithoutJurisdictionCheck;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the content-without-jurisdiction detector.
 *
 * @group markaspot_health
 * @coversDefaultClass \Drupal\markaspot_health\Plugin\HealthCheck\ContentWithoutJurisdictionCheck
 */
class ContentWithoutJurisdictionCheckTest extends UnitTestCase {

  /**
   * Plugin definition fixture used across tests.
   *
   * @var array<string, mixed>
   */
  protected array $definition = [
    'id' => 'content_without_jurisdiction',
    'label' => 'Service requests without jurisdiction',
    'severity' => 'warning',
    'fix_hint' => 'fix.',
    'fix_url' => NULL,
  ];

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenNodeModuleAbsent(): void {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('node')->willReturn(FALSE);

    $plugin = new ContentWithoutJurisdictionCheck([], 'content_without_jurisdiction', $this->definition, $etm);
    $this->assertTrue($plugin->run()->passed);
  }

  /**
   * @covers ::run
   */
  public function testRunSkipsWhenServiceRequestBundleAbsent(): void {
    $bundleQuery = $this->createMock(QueryInterface::class);
    $bundleQuery->method('accessCheck')->willReturnSelf();
    $bundleQuery->method('execute')->willReturn(['page' => 'page', 'article' => 'article']);

    $bundleStorage = $this->createMock(EntityStorageInterface::class);
    $bundleStorage->method('getQuery')->willReturn($bundleQuery);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->willReturnMap([
      ['node', $this->createMock(EntityStorageInterface::class)],
      ['node_type', $bundleStorage],
    ]);

    $plugin = new ContentWithoutJurisdictionCheck([], 'content_without_jurisdiction', $this->definition, $etm);
    $result = $plugin->run();

    $this->assertTrue($result->passed);
    $this->assertStringContainsString('service_request bundle not present', $result->message);
  }

  /**
   * @covers ::run
   */
  public function testRunFailsWhenOrphansFound(): void {
    [$nodeStorage, $bundleStorage, $fieldStorage] = $this->buildStorageStubs(['service_request' => 'service_request']);

    $countQuery = $this->createMock(QueryInterface::class);
    $countQuery->method('accessCheck')->willReturnSelf();
    $countQuery->method('condition')->willReturnSelf();
    $countQuery->method('notExists')->willReturnSelf();
    $countQuery->method('count')->willReturnSelf();
    $countQuery->method('execute')->willReturn(9);
    $nodeStorage->method('getQuery')->willReturn($countQuery);

    $fieldStorage->method('loadByProperties')->willReturn(['anything' => 'truthy']);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->willReturnMap([
      ['node', $nodeStorage],
      ['node_type', $bundleStorage],
      ['field_config', $fieldStorage],
    ]);

    $plugin = new ContentWithoutJurisdictionCheck([], 'content_without_jurisdiction', $this->definition, $etm);
    $result = $plugin->run();

    $this->assertFalse($result->passed);
    $this->assertSame(9, $result->count);
    $this->assertStringContainsString('9 service request', $result->message);
  }

  /**
   * Helper to create the three storage stubs used in the success path.
   */
  private function buildStorageStubs(array $bundles): array {
    $bundleQuery = $this->createMock(QueryInterface::class);
    $bundleQuery->method('accessCheck')->willReturnSelf();
    $bundleQuery->method('execute')->willReturn($bundles);

    $bundleStorage = $this->createMock(EntityStorageInterface::class);
    $bundleStorage->method('getQuery')->willReturn($bundleQuery);

    return [
      $this->createMock(EntityStorageInterface::class),
      $bundleStorage,
      $this->createMock(EntityStorageInterface::class),
    ];
  }

}
