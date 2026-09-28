<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ui\Kernel;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests that Gin Login URLs reach the browser without the request host.
 *
 * An internal request (health check, Nuxt server) must not leave its host in
 * a cached login page.
 */
#[Group('markaspot_ui')]
class GinLoginPathTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'toolbar', 'gin_login'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['gin_login']);
    require_once dirname(__DIR__, 3) . '/markaspot_ui.module';
    // Bootstrap as an internal request would: request host and base URL.
    $request = Request::create('http://127.0.0.1:8080/user/login');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $GLOBALS['base_url'] = 'http://127.0.0.1:8080';
  }

  /**
   * The wallpaper base path in drupalSettings is root-relative.
   */
  public function testWallpaperPathIsRootRelative(): void {
    $module_path = $this->container->get('extension.list.module')->getPath('gin_login');
    $settings['gin_login']['path'] = 'http://127.0.0.1:8080/' . $module_path;

    markaspot_ui_js_settings_alter($settings, new AttachedAssets());

    $this->assertStringStartsWith('/', $settings['gin_login']['path']);
    $this->assertStringEndsWith('/' . $module_path, $settings['gin_login']['path']);
    $this->assertStringNotContainsString('127.0.0.1', $settings['gin_login']['path']);
  }

  /**
   * Settings without Gin Login stay untouched.
   */
  public function testSettingsWithoutGinLoginAreUntouched(): void {
    $settings['gin']['darkmode'] = 'auto';
    $expected = $settings;

    markaspot_ui_js_settings_alter($settings, new AttachedAssets());

    $this->assertSame($expected, $settings);
  }

  /**
   * The logo fix runs right after Gin Login's pinned preprocess function.
   */
  public function testLogoPreprocessRunsAfterGinLogin(): void {
    $registry = $this->container->get('theme.registry')->get();
    $this->assertContains('gin_login_preprocess_ginlogin', $registry['page__user__login']['preprocess functions']);

    markaspot_ui_theme_registry_alter($registry);
    markaspot_ui_theme_registry_alter($registry);

    $functions = $registry['page__user__login']['preprocess functions'];
    $gin_login = array_search('gin_login_preprocess_ginlogin', $functions, TRUE);
    $this->assertSame('markaspot_ui_preprocess_gin_login_icon', $functions[$gin_login + 1]);
    $this->assertCount(1, array_keys($functions, 'markaspot_ui_preprocess_gin_login_icon', TRUE));
  }

  /**
   * A custom logo rendered for an internal request gets a root-relative URL.
   */
  public function testCustomLogoIsRootRelative(): void {
    $this->config('gin_login.settings')
      ->set('logo', ['use_default' => FALSE, 'path' => 'public://branding/custom.icon.svg'])
      ->save();
    $variables = [];

    gin_login_preprocess_ginlogin($variables);
    $this->assertStringContainsString('127.0.0.1', $variables['icon_path']);

    markaspot_ui_preprocess_gin_login_icon($variables);

    $this->assertStringStartsWith('/', $variables['icon_path']);
    $this->assertStringEndsWith('/branding/custom.icon.svg', $variables['icon_path']);
    $this->assertStringNotContainsString('127.0.0.1', $variables['icon_path']);
  }

}
