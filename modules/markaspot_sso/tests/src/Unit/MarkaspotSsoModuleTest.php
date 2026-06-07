<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests markaspot_sso module hooks.
 *
 * @group markaspot_sso
 */
class MarkaspotSsoModuleTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/markaspot_sso.module';
  }

  /**
   * Tests frontend-safe SSO provider settings are exposed for a jurisdiction.
   */
  public function testSettingsAlterAddsFrontendSafeProviders(): void {
    $manager = new class {
      /**
       * The jurisdiction ID received by the service.
       */
      public int $jurisdictionId = 0;

      /**
       * Returns frontend-safe providers.
       *
       * @return array<int, array{id: string, label: string}>
       *   Provider data.
       */
      public function providersForJurisdiction(int $jurisdiction_id): array {
        $this->jurisdictionId = $jurisdiction_id;

        return [
          [
            'id' => 'keycloak',
            'label' => 'Stadt-Login',
          ],
        ];
      }

    };

    $container = new ContainerBuilder();
    $container->set('markaspot_sso.provider_manager', $manager);
    \Drupal::setContainer($container);

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('14');

    $settings = [];
    markaspot_sso_markaspot_nuxt_settings_alter($settings, $group);

    $this->assertSame(14, $manager->jurisdictionId);
    $this->assertSame([
      'providers' => [
        [
          'id' => 'keycloak',
          'label' => 'Stadt-Login',
        ],
      ],
    ], $settings['sso']);
  }

  /**
   * Tests the settings alter hook noops without a jurisdiction group.
   */
  public function testSettingsAlterNoopsWithoutGroup(): void {
    $container = new ContainerBuilder();
    $container->set('markaspot_sso.provider_manager', new class {

        /**
         * Returns no providers.
         *
         * @return array<int, array{id: string, label: string}>
         *   Provider data.
         */
      public function providersForJurisdiction(int $jurisdiction_id): array {
            return [];
      }

    });
    \Drupal::setContainer($container);

    $settings = [];
    markaspot_sso_markaspot_nuxt_settings_alter($settings);

    $this->assertArrayNotHasKey('sso', $settings);
  }

}
