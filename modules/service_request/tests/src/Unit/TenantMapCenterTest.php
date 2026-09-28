<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot\Geolocation\TenantMapCenter;
use Drupal\Tests\UnitTestCase;

// The profile namespace is not registered in unit tests.
require_once dirname(__DIR__, 5) . '/src/Geolocation/TenantMapCenter.php';

/**
 * Covers the tenant center that backend location widgets fall back to.
 *
 * @group service_request
 */
final class TenantMapCenterTest extends UnitTestCase {

  /**
   * The instance center wins; 0/0 and invalid values give no center.
   */
  public function testInstanceCenter(): void {
    $this->bootContainer(['center_lat' => '51.6604001', 'center_lng' => '6.9646501']);
    $this->assertSame([51.6604001, 6.9646501], TenantMapCenter::resolve());

    $this->bootContainer(['center_lat' => 0, 'center_lng' => 0]);
    $this->assertNull(TenantMapCenter::resolve());

    $this->bootContainer(['center_lat' => '95', 'center_lng' => '7']);
    $this->assertNull(TenantMapCenter::resolve());

    $this->bootContainer([]);
    $this->assertNull(TenantMapCenter::resolve());
  }

  /**
   * Sets up a container with the given markaspot_nuxt settings, no groups.
   */
  private function bootContainer(array $settings): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')->with('group')->willReturn(FALSE);

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->getConfigFactoryStub(['markaspot_nuxt.settings' => $settings]));
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);
  }

}
