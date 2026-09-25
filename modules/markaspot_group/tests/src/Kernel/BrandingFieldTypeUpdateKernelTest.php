<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that update 11953 converges the jur logo and font fields on file.
 *
 * @group markaspot_group
 */
#[RunTestsInSeparateProcesses]
final class BrandingFieldTypeUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'entity',
    'flexible_permissions',
    'group',
    'language',
  ];

  /**
   * The legacy types update 11907 used to create.
   */
  private const LEGACY_TYPES = [
    'field_logo_light' => 'image',
    'field_logo_dark' => 'image',
    'field_font_heading' => 'string',
    'field_font_body' => 'string',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'field', 'group', 'language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();

    foreach (self::LEGACY_TYPES as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'group',
        'type' => $type,
        'cardinality' => 1,
      ])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'group', 'bundle' => 'jur', 'label' => $name])->save();
    }
    EntityFormDisplay::create([
      'targetEntityType' => 'group',
      'bundle' => 'jur',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_font_heading', ['type' => 'string_textfield'])->save();

    require_once dirname(__DIR__, 3) . '/markaspot_group.install';
  }

  /**
   * Empty legacy fields become the shipped file fields.
   */
  public function testEmptyLegacyFieldsAreRecreatedAsFile(): void {
    $message = markaspot_group_update_11953();

    $this->assertStringContainsString('Recreated or completed as file fields', $message);
    $this->assertStringNotContainsString('Left unchanged', $message);
    foreach (array_keys(self::LEGACY_TYPES) as $name) {
      $this->assertSame('file', FieldStorageConfig::loadByName('group', $name)->getType(), $name);
      $this->assertSame(_markaspot_group_branding_storage_uuids()[$name], FieldStorageConfig::loadByName('group', $name)->uuid(), $name);
      $shipped = _markaspot_group_branding_field_config($name);
      $field = FieldConfig::loadByName('group', 'jur', $name);
      $this->assertSame($shipped['uuid'], $field->uuid(), $name);
      $this->assertSame($shipped['settings']['file_extensions'], $field->getSetting('file_extensions'), $name);
      $this->assertSame('file_generic', EntityFormDisplay::load('group.jur.default')->getComponent($name)['type'], $name);
    }
    $this->assertSame('svg png', FieldConfig::loadByName('group', 'jur', 'field_logo_light')->getSetting('file_extensions'));
    $this->assertSame('woff2 woff ttf otf', FieldConfig::loadByName('group', 'jur', 'field_font_body')->getSetting('file_extensions'));

    $this->assertSame('Jurisdiction logo and font fields already use the file type.', markaspot_group_update_11953());
  }

  /**
   * A legacy field with values is left alone and reported.
   */
  public function testLegacyFieldWithValuesIsKept(): void {
    Group::create(['type' => 'jur', 'label' => 'Krefeld', 'uid' => 1, 'field_font_heading' => 'Inter'])->save();

    $message = markaspot_group_update_11953();

    $this->assertStringContainsString('Left unchanged because they hold values: field_font_heading (string', $message);
    $this->assertSame('string', FieldStorageConfig::loadByName('group', 'field_font_heading')->getType());
    $this->assertSame('file', FieldStorageConfig::loadByName('group', 'field_logo_light')->getType());
    $this->assertSame('Inter', Group::load(1)->get('field_font_heading')->value);
  }

  /**
   * A file storage without its jur field gets the field and widget.
   */
  public function testMissingJurFieldIsCompleted(): void {
    markaspot_group_update_11953();
    // The optional-config state of a fresh install without the jur type: a
    // file storage that never had a jur field. Deleting the last field also
    // deletes its storage, so recreate the storage alone.
    FieldConfig::loadByName('group', 'jur', 'field_logo_dark')->delete();
    $this->container->get('module_handler')->loadInclude('field', 'inc', 'field.purge');
    field_purge_batch(100);
    FieldStorageConfig::create(_markaspot_group_branding_file_storage('field_logo_dark'))->save();
    $this->assertNull(FieldConfig::loadByName('group', 'jur', 'field_logo_dark'));
    $this->assertSame('file', FieldStorageConfig::loadByName('group', 'field_logo_dark')->getType());

    $message = markaspot_group_update_11953();

    $this->assertSame('Recreated or completed as file fields: field_logo_dark.', $message);
    $this->assertSame('svg png', FieldConfig::loadByName('group', 'jur', 'field_logo_dark')->getSetting('file_extensions'));
    $this->assertSame('file_generic', EntityFormDisplay::load('group.jur.default')->getComponent('field_logo_dark')['type']);
  }

  /**
   * Translated labels survive the recreation of the field.
   */
  public function testTranslatedLabelsArePreserved(): void {
    $override = $this->container->get('language_manager')->getLanguageConfigOverride('de', 'field.field.group.jur.field_logo_light');
    $override->set('label', 'Logo (hell)')->set('description', 'Helles Logo')->save();

    markaspot_group_update_11953();

    $override = $this->container->get('language_manager')->getLanguageConfigOverride('de', 'field.field.group.jur.field_logo_light');
    $this->assertSame('Logo (hell)', $override->get('label'));
    $this->assertSame('Helles Logo', $override->get('description'));
    $this->assertSame('file', FieldStorageConfig::loadByName('group', 'field_logo_light')->getType());
  }

}
