<?php

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Utility\UpdateException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 5) . '/markaspot.install';

/**
 * Verifies the deny-by-default allowlist respects optional dependencies.
 *
 * @group markaspot_nuxt
 */
#[RunTestsInSeparateProcesses]
class JsonApiAllowlistKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Conditional resources are ignored while available resources stay required.
   */
  public function testConditionalResourcesRemainOptional(): void {
    $module_path = \Drupal::service('extension.list.module')->getPath('markaspot_nuxt');
    $source = new FileStorage(DRUPAL_ROOT . '/' . $module_path . '/config/optional');
    $required_name = 'jsonapi_extras.jsonapi_resource_config.menu--menu';
    $required_data = $source->read($required_name);
    $this->assertIsArray($required_data);

    \Drupal::service('config.storage')->write($required_name, $required_data);
    $this->assertSame(1, _markaspot_assert_nuxt_jsonapi_allowlist());

    \Drupal::service('config.storage')->delete($required_name);
    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage($required_name);
    _markaspot_assert_nuxt_jsonapi_allowlist();
  }

}
