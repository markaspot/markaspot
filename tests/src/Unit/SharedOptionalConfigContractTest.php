<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';
require_once dirname(__DIR__, 3) . '/markaspot.install';

/**
 * Tests shared optional config installed by profile update hooks.
 *
 * @group markaspot
 */
final class SharedOptionalConfigContractTest extends UnitTestCase {

  /**
   * Tests the contractor role actions are shipped as profile-owned config.
   */
  public function testContractorRoleActions(): void {
    $source = new FileStorage(dirname(__DIR__, 3) . '/config/optional');
    $definitions = [
      'system.action.user_add_role_action.contractor' => 'user_add_role_action',
      'system.action.user_remove_role_action.contractor' => 'user_remove_role_action',
    ];

    foreach ($definitions as $config_name => $plugin_id) {
      $action = $source->read($config_name);
      $this->assertIsArray($action);
      $this->assertSame($plugin_id, $action['plugin']);
      $this->assertSame('contractor', $action['configuration']['rid']);
      $this->assertContains('user.role.contractor', $action['dependencies']['config']);
      $this->assertArrayNotHasKey('uuid', $action);
    }
  }

  /**
   * Tests the shared Gin user-switch block and its optional dependencies.
   */
  public function testGinUserSwitchBlock(): void {
    $source = new FileStorage(dirname(__DIR__, 3) . '/config/optional');
    $block = $source->read('block.block.gin_benutzerwechseln');

    $this->assertIsArray($block);
    $this->assertTrue($block['status']);
    $this->assertSame('gin', $block['theme']);
    $this->assertSame('content', $block['region']);
    $this->assertSame('devel_switch_user', $block['plugin']);
    $this->assertSame(['devel', 'system', 'user'], $block['dependencies']['module']);
    $this->assertSame(['gin'], $block['dependencies']['theme']);
    $this->assertSame(['administrator' => 'administrator'], $block['visibility']['user_role']['roles']);
    $this->assertSame('/admin/content/management', $block['visibility']['request_path']['pages']);
    $this->assertArrayNotHasKey('uuid', $block);
  }

  /**
   * Tests equivalent existing Gin blocks are preserved across config IDs.
   */
  public function testEquivalentGinUserSwitchBlockIsDetected(): void {
    $active = new MemoryStorage();
    $active->write('block.block.gin_switchuser', [
      'theme' => 'gin',
      'plugin' => 'devel_switch_user',
      'region' => 'content',
      'visibility' => ['tenant' => 'custom'],
    ]);
    $active->write('block.block.olivero_switchuser', [
      'theme' => 'olivero',
      'plugin' => 'devel_switch_user',
    ]);

    $this->assertSame(
      'block.block.gin_switchuser',
      _markaspot_find_gin_user_switch_block($active),
    );
  }

  /**
   * Tests a stale tenant import cannot delete newly installed shared config.
   */
  public function testSharedConfigSurvivesStaleTenantImport(): void {
    $source = new FileStorage(dirname(__DIR__, 3) . '/config/optional');
    $config_names = [
      'system.action.user_add_role_action.contractor',
      'system.action.user_remove_role_action.contractor',
      'block.block.gin_benutzerwechseln',
    ];
    $active = new MemoryStorage();
    foreach ($config_names as $config_name) {
      $active->write($config_name, ['uuid' => $config_name] + $source->read($config_name));
    }
    $import = new MemoryStorage();

    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      $this->createMock(ProfileExtensionList::class),
    );
    $shipped = new \ReflectionProperty($subscriber, 'shippedConfigNames');
    $shipped->setValue($subscriber, array_combine($config_names, $config_names));
    $protect = new \ReflectionMethod($subscriber, 'protectShippedConfig');
    $protect->invoke($subscriber, $import);

    foreach ($config_names as $config_name) {
      $this->assertSame($active->read($config_name), $import->read($config_name));
    }
  }

  /**
   * Tests stale sync cannot restore unsafe user-switch configuration.
   */
  public function testUserSwitchHardeningSurvivesStaleTenantImport(): void {
    $active = new MemoryStorage();
    $active->write('block.block.gin_switchuser', [
      'theme' => 'gin',
      'plugin' => 'devel_switch_user',
      'region' => 'header',
    ]);
    $import = new MemoryStorage();
    $import->write('user.role.editorial_board', [
      'is_admin' => FALSE,
      'permissions' => ['access content', 'switch users'],
    ]);
    $import->write('user.role.administrator', [
      'is_admin' => TRUE,
      'permissions' => ['switch users'],
    ]);

    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      $this->createMock(ProfileExtensionList::class),
    );
    $protect = new \ReflectionMethod($subscriber, 'protectUserSwitching');
    $protect->invoke($subscriber, $import);

    $this->assertSame(
      ['access content'],
      $import->read('user.role.editorial_board')['permissions'],
    );
    $this->assertSame(
      ['switch users'],
      $import->read('user.role.administrator')['permissions'],
    );
    $block = $import->read('block.block.gin_switchuser');
    $this->assertTrue($block['status']);
    $this->assertSame('content', $block['region']);
    $this->assertSame(0, $block['weight']);
    $this->assertSame(
      ['administrator' => 'administrator'],
      $block['visibility']['user_role']['roles'],
    );
    $this->assertSame(
      '/admin/content/management',
      $block['visibility']['request_path']['pages'],
    );
  }

  /**
   * Tests the repair update cannot realign contractor permissions.
   */
  public function testRepairUpdateIsScopedToSharedConfig(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/markaspot.install');
    $this->assertIsString($install);
    $matched = preg_match(
      '/function markaspot_update_11935\(\): string \{(?<body>.*?)^\}/ms',
      $install,
      $matches,
    );

    $this->assertSame(1, $matched);
    $this->assertStringContainsString('_markaspot_ensure_contractor_role_actions()', $matches['body']);
    $this->assertStringContainsString('_markaspot_ensure_gin_user_switch_block()', $matches['body']);
    $this->assertStringNotContainsString('_markaspot_ensure_contractor_roles()', $matches['body']);
  }

  /**
   * Tests the follow-up update applies the shared impersonation policy.
   */
  public function testUserSwitchHardeningUpdateContract(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/markaspot.install');
    $this->assertIsString($install);
    $matched = preg_match(
      '/function markaspot_update_11936\(\): string \{(?<body>.*?)^\}/ms',
      $install,
      $matches,
    );

    $this->assertSame(1, $matched);
    $this->assertStringContainsString('_markaspot_harden_user_switching()', $matches['body']);
  }

}
