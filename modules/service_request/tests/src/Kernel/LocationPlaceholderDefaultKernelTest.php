<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;

/**
 * Covers removal of the New York placeholder location default.
 *
 * @group service_request
 */
final class LocationPlaceholderDefaultKernelTest extends KernelTestBase {

  private const NAME = 'field.field.node.service_request.field_geolocation';

  private const PLACEHOLDER = [
    [
      'lat' => '40.7370610',
      'lng' => '-73.935242',
      'lat_sin' => NULL,
      'lat_cos' => NULL,
      'lng_rad' => NULL,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'geolocation',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_geolocation',
      'entity_type' => 'node',
      'type' => 'geolocation',
    ])->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    require_once dirname(__DIR__, 3) . '/service_request.install';
  }

  /**
   * A placeholder default in the field and its overrides is removed.
   */
  public function testPlaceholderIsRemovedEverywhere(): void {
    $this->saveField(self::PLACEHOLDER);
    $this->saveOverride('de', ['default_value' => self::PLACEHOLDER]);
    $this->saveOverride('fr', ['default_value' => self::PLACEHOLDER, 'label' => 'Lieu']);

    service_request_update_11022();

    $this->assertSame([], $this->config(self::NAME)->get('default_value'));
    $this->assertTrue($this->override('de')->isNew(), 'An override left empty is deleted.');
    $this->assertNull($this->override('fr')->get('default_value'));
    $this->assertSame('Lieu', $this->override('fr')->get('label'));
  }

  /**
   * A re-saved placeholder without the trailing zero is caught too.
   */
  public function testNormalizedPlaceholderIsRemoved(): void {
    $this->saveField([['lat' => '40.737061', 'lng' => '-73.935242']]);

    service_request_update_11022();

    $this->assertSame([], $this->config(self::NAME)->get('default_value'));
  }

  /**
   * Real tenant defaults stay, in the field and in overrides.
   */
  public function testRealDefaultsStay(): void {
    $bonn = [['lat' => '50.7179', 'lng' => '7.14326']];
    $bleckede = [['lat' => '53.2933058', 'lng' => '10.7299955']];
    $this->saveField($bonn);
    $this->saveOverride('de', ['default_value' => $bleckede]);

    service_request_update_11022();

    $this->assertSame($bonn, $this->config(self::NAME)->get('default_value'));
    $this->assertSame($bleckede, $this->override('de')->get('default_value'));
  }

  /**
   * A second run changes nothing, as the update gate replays hooks.
   */
  public function testUpdateIsIdempotent(): void {
    $this->saveField(self::PLACEHOLDER);
    $this->saveOverride('de', ['default_value' => self::PLACEHOLDER, 'label' => 'Ort']);

    service_request_update_11022();
    $field = $this->config(self::NAME)->getRawData();
    $override = $this->override('de')->get();
    $message = service_request_update_11022();

    $this->assertStringStartsWith('No New York placeholder', $message);
    $this->assertSame($field, $this->config(self::NAME)->getRawData());
    $this->assertSame($override, $this->override('de')->get());
  }

  /**
   * Saves the field config with the given default value.
   */
  private function saveField(array $default_value): void {
    $field = FieldConfig::loadByName('node', 'service_request', 'field_geolocation') ?? FieldConfig::create([
      'field_name' => 'field_geolocation',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Location',
    ]);
    $field->setDefaultValue($default_value)->save();
  }

  /**
   * Saves a language override of the field config.
   */
  private function saveOverride(string $langcode, array $data): void {
    $override = $this->override($langcode);
    foreach ($data as $key => $value) {
      $override->set($key, $value);
    }
    $override->save();
  }

  /**
   * Loads a language override of the field config.
   */
  private function override(string $langcode) {
    return $this->container->get('language_manager')->getLanguageConfigOverride($langcode, self::NAME);
  }

}
