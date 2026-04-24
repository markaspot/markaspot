<?php

namespace Drupal\Tests\markaspot\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Asserts niche submodules stay opt-in, not auto-installed by the profile.
 *
 * Policy recorded 2026-04-24: tenants must explicitly opt into niche
 * markaspot_* submodules via `drush pm:install`. They must NOT be listed
 * in markaspot.info.yml dependencies or auto-enabled by hook_update_N,
 * otherwise every existing install silently picks them up on next cim.
 *
 * @group markaspot
 */
class ProfilePolicyTest extends TestCase {

  /**
   * Modules that exist inside the profile but must stay opt-in.
   *
   * @return array
   *   Test cases keyed by module machine name.
   */
  public static function optInSubmoduleProvider(): array {
    return [
      'markaspot_facility' => ['markaspot_facility'],
    ];
  }

  /**
   * The profile's info.yml MUST NOT list opt-in submodules as deps.
   *
   * @dataProvider optInSubmoduleProvider
   */
  public function testProfileDependenciesDoNotIncludeOptInSubmodule(string $module): void {
    $info = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot.info.yml');
    $dependencies = $info['dependencies'] ?? [];
    $this->assertNotContains(
      $module,
      $dependencies,
      sprintf('%s must NOT be a profile dependency — it is opt-in per policy.', $module)
    );
  }

  /**
   * Hook_update_N in markaspot.install MUST NOT auto-install opt-in modules.
   *
   * @dataProvider optInSubmoduleProvider
   */
  public function testInstallFileDoesNotAutoInstallOptInSubmodule(string $module): void {
    $install_file = dirname(__DIR__, 3) . '/markaspot.install';
    $contents = file_get_contents($install_file);
    $this->assertNotFalse($contents, 'markaspot.install should be readable.');

    $pattern = sprintf('/install\s*\([^)]*[\'"]%s[\'"]/', preg_quote($module, '/'));
    $this->assertSame(
      0,
      preg_match($pattern, $contents),
      sprintf(
        'markaspot.install must NOT call module_installer->install([...%s...]) — tenants opt in manually.',
        $module
      )
    );
  }

  /**
   * Sanity: the submodule directory must still exist in the profile tree.
   *
   * If this fails the module was deleted outright. Remove the entry from
   * optInSubmoduleProvider() instead of re-adding it to profile deps.
   *
   * @dataProvider optInSubmoduleProvider
   */
  public function testOptInSubmoduleDirectoryExists(string $module): void {
    $path = dirname(__DIR__, 3) . '/modules/' . $module;
    $this->assertDirectoryExists(
      $path,
      sprintf('Expected %s to exist as an opt-in submodule.', $module)
    );
    $this->assertFileExists(
      $path . '/' . $module . '.info.yml',
      sprintf('%s must have a valid info.yml.', $module)
    );
  }

}
