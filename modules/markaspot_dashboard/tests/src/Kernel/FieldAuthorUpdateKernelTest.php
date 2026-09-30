<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage of markaspot_dashboard_update_11902().
 *
 * Fastmap lost paragraph.field_author through a config import whose sync
 * directory never carried it. The update must restore storage and both
 * instances in the shape shipped by markaspot_status_paragraph, stay
 * idempotent and skip bundles a site does not have.
 *
 * markaspot_dashboard is not installed (see InternalRemarkAccessKernelTest);
 * the .install file is loaded and the update called directly.
 *
 * @group markaspot_dashboard
 *
 * @covers ::markaspot_dashboard_update_11902
 */
#[RunTestsInSeparateProcesses]
final class FieldAuthorUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_reference_revisions',
    'file',
    'paragraphs',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');

    require_once dirname(__DIR__, 3) . '/markaspot_dashboard.install';
  }

  /**
   * Restores storage and both instances when the field is missing entirely.
   */
  public function testRestoresFieldAuthorOnBothBundles(): void {
    $this->createParagraphType('status');
    $this->createParagraphType('internal_remark');
    $this->assertNull(FieldStorageConfig::loadByName('paragraph', 'field_author'));

    markaspot_dashboard_update_11902();

    $this->assertNotNull(FieldStorageConfig::loadByName('paragraph', 'field_author'));
    $this->assertNotNull(FieldConfig::loadByName('paragraph', 'status', 'field_author'));
    $this->assertNotNull(FieldConfig::loadByName('paragraph', 'internal_remark', 'field_author'));
    $this->assertMatchesShippedConfig('field.storage.paragraph.field_author');
    $this->assertMatchesShippedConfig('field.field.paragraph.status.field_author');
    $this->assertMatchesShippedConfig('field.field.paragraph.internal_remark.field_author');

    // A second run must neither fail nor duplicate anything.
    markaspot_dashboard_update_11902();
    $this->assertCount(2, \Drupal::entityTypeManager()
      ->getStorage('field_config')
      ->loadByProperties(['entity_type' => 'paragraph', 'field_name' => 'field_author']));
  }

  /**
   * Adds the missing instance next to an existing one and skips absent bundles.
   */
  public function testKeepsExistingInstanceAndSkipsMissingBundle(): void {
    $this->createParagraphType('status');
    _markaspot_dashboard_ensure_field_author('status');
    $existing = FieldConfig::loadByName('paragraph', 'status', 'field_author');
    $this->assertNotNull($existing);

    // internal_remark does not exist on this site.
    markaspot_dashboard_update_11902();

    $this->assertSame($existing->uuid(), FieldConfig::loadByName('paragraph', 'status', 'field_author')?->uuid());
    $this->assertNull(FieldConfig::loadByName('paragraph', 'internal_remark', 'field_author'));
  }

  /**
   * The status report warns while field_author is missing.
   */
  public function testRequirementsWarnUntilFieldAuthorIsRestored(): void {
    $this->createParagraphType('status');
    $this->createParagraphType('internal_remark');

    $requirement = markaspot_dashboard_requirements('runtime')['markaspot_dashboard_field_author'];
    $this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
    $this->assertStringContainsString('status, internal_remark', (string) $requirement['value']);

    markaspot_dashboard_update_11902();

    $requirement = markaspot_dashboard_requirements('runtime')['markaspot_dashboard_field_author'];
    $this->assertSame(RequirementSeverity::OK, $requirement['severity']);
    $this->assertSame([], markaspot_dashboard_requirements('install'));
  }

  /**
   * Asserts active config equals the shipped YAML, ignoring generated keys.
   */
  private function assertMatchesShippedConfig(string $name): void {
    $shipped = (new FileStorage(dirname(__DIR__, 4) . '/markaspot_status_paragraph/config/install'))->read($name);
    $this->assertIsArray($shipped, "Shipped config $name exists.");
    $active = $this->config($name)->getRawData();
    unset($active['uuid'], $active['_core']);
    ksort($active);
    ksort($shipped);
    $this->assertSame($shipped, $active, "Active $name matches the shipped YAML.");
  }

  /**
   * Creates a bare paragraph type.
   */
  private function createParagraphType(string $id): void {
    ParagraphsType::create(['id' => $id, 'label' => $id])->save();
  }

}
