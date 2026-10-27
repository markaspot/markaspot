<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 3) . '/markaspot_open311.install';

/**
 * Tests that Open311 accepts api_key as POST and query parameter.
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class ApiKeyParameterUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'services_api_key_auth',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['services_api_key_auth']);
  }

  /**
   * The header-only module default gains the POST and query names.
   */
  public function testEmptyParameterNamesAreFilled(): void {
    $settings = $this->config('services_api_key_auth.settings');
    $this->assertSame('', (string) $settings->get('api_key_post_parameter_name'));

    markaspot_open311_update_11813();

    $settings = $this->config('services_api_key_auth.settings');
    $this->assertSame('api_key', $settings->get('api_key_post_parameter_name'));
    $this->assertSame('api_key', $settings->get('api_key_get_parameter_name'));
    $this->assertSame('api_key', $settings->get('api_key_request_header_name'));
  }

  /**
   * Names an operator configured stay as they are.
   */
  public function testConfiguredNamesAreKept(): void {
    $this->config('services_api_key_auth.settings')
      ->set('api_key_request_header_name', 'apikey')
      ->set('api_key_post_parameter_name', 'token')
      ->set('api_key_get_parameter_name', '')
      ->save();

    $this->assertSame(['api_key_get_parameter_name'], _markaspot_open311_ensure_api_key_parameters());
    $this->assertSame([], _markaspot_open311_ensure_api_key_parameters());

    $settings = $this->config('services_api_key_auth.settings');
    $this->assertSame('apikey', $settings->get('api_key_request_header_name'));
    $this->assertSame('token', $settings->get('api_key_post_parameter_name'));
    $this->assertSame('api_key', $settings->get('api_key_get_parameter_name'));
  }

}
