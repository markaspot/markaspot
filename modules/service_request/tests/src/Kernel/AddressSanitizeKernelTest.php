<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Covers markup sanitization of citizen-supplied address components.
 *
 * Field_address is writable by anonymous users through the Open311 API and
 * JSON:API; _service_request_sanitize_address() strips markup on every save
 * so no write path can persist an executable tag.
 *
 * @group service_request
 */
#[RunTestsInSeparateProcesses]
final class AddressSanitizeKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'address',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'user', 'field', 'node']);

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'node',
      'type' => 'address',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Address',
    ])->save();

    require_once dirname(__DIR__, 3) . '/service_request.module';
  }

  /**
   * Markup in any free-text component is stripped; country_code is preserved.
   */
  public function testSanitizeStripsMarkupFromAddressComponents(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Broken streetlight',
      'field_address' => [
        'country_code' => 'DE',
        'address_line1' => '<img src=x onerror=alert(1)>Hauptstr 1',
        'locality' => 'Köln <script>alert(2)</script>',
        'given_name' => '<b>Max</b>',
        'postal_code' => '50667',
      ],
    ]);

    _service_request_sanitize_address($node);

    $address = $node->get('field_address')->first();
    $this->assertSame('Hauptstr 1', $address->address_line1);
    $this->assertStringNotContainsString('<', $address->locality);
    $this->assertSame('Max', $address->given_name);
    $this->assertSame('50667', $address->postal_code);
    $this->assertSame('DE', $address->country_code);
  }

  /**
   * Entity-encoded markup is decoded and stripped, not left as literal text.
   *
   * Strip_tags() alone ignores "&lt;img&gt;"; decoding first matches the
   * Open311 addressParser() sanitizer and closes the double-encoding bypass.
   */
  public function testSanitizeStripsEntityEncodedMarkup(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Encoded payload',
      'field_address' => [
        'country_code' => 'DE',
        'address_line1' => '&lt;img src=x onerror=alert(1)&gt;Hauptstr 1',
        'postal_code' => '50667',
      ],
    ]);

    _service_request_sanitize_address($node);

    $address = $node->get('field_address')->first();
    $this->assertStringNotContainsString('&lt;', $address->address_line1);
    $this->assertStringNotContainsString('img', $address->address_line1);
    $this->assertSame('Hauptstr 1', $address->address_line1);
  }

  /**
   * An empty field_address is a no-op, not an error.
   */
  public function testSanitizeSkipsEmptyAddress(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'No address',
    ]);

    _service_request_sanitize_address($node);

    $this->assertTrue($node->get('field_address')->isEmpty());
  }

}
