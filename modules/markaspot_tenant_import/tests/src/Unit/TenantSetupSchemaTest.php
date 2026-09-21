<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\markaspot_tenant_import\Service\TenantSetupSchema;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies canonical remark repair, dependency planning, and preservation.
 */
#[Group('markaspot_tenant_import')]
final class TenantSetupSchemaTest extends UnitTestCase {

  /**
   * Active configuration and selected canonical schema sources.
   */
  private MemoryStorage $active;
  /**
   * Canonical configuration sources.
   */
  private array $sources;
  /**
   * Configuration selections passed to the installer.
   */
  private array $installed = [];
  /**
   * Whether the installer satisfies the requested postcondition.
   */
  private bool $installSucceeds = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->active = new MemoryStorage();
    foreach (['node.type.service_request', 'paragraphs.paragraphs_type.status'] as $name) {
      $this->active->write($name, ['id' => $name]);
    }
    $profile = dirname(__DIR__, 5);
    $remarks = new MemoryStorage();
    foreach ([
      'config/install' => ['field.storage.node.field_internal_remark'],
      'config/optional' => ['field.field.node.service_request.field_internal_remark'],
    ] as $directory => $names) {
      $source = new FileStorage($profile . '/' . $directory);
      foreach ($names as $name) {
        $data = $source->read($name);
        $this->assertIsArray($data);
        $remarks->write($name, $data);
      }
    }
    $this->sources = [
      [
        'storage' => new FileStorage($profile . '/modules/markaspot_status_paragraph/config/install'),
        'required' => TRUE,
        'required_names' => ['field.field.paragraph.internal_remark.field_internal_remark_text'],
      ],
      ['storage' => $remarks, 'required' => TRUE],
    ];
  }

  /**
   * Builds a stateful installer double around the real config dependency plan.
   */
  private function schema(): TenantSetupSchema {
    $installer = $this->createMock(ConfigInstallerInterface::class);
    $installer->method('installOptionalConfig')->willReturnCallback(function (StorageInterface $source): void {
      $this->installed[] = $source->listAll();
      if ($this->installSucceeds) {
        foreach ($source->listAll() as $name) {
          $this->assertFalse($this->active->exists($name), 'Existing configuration must never be sent to the installer.');
          $this->active->write($name, $source->read($name));
        }
      }
    });
    return new TenantSetupSchema($this->active, $installer, $this->sources, [
      'node', 'paragraphs', 'text', 'user', 'field', 'field_permissions',
      'entity_reference_revisions', 'media', 'image', 'file', 'markaspot_media',
    ]);
  }

  /**
   * Missing remarks use shipped config without enabling optional products.
   */
  public function testCanonicalRemarksArePlannedAndRepairedOnce(): void {
    $schema = $this->schema();
    $preview = $schema->prepare();
    foreach ([
      'paragraphs.paragraphs_type.internal_remark',
      'field.storage.paragraph.field_internal_remark_text',
      'field.field.paragraph.internal_remark.field_internal_remark_text',
      'field.field.paragraph.internal_remark.field_author',
      'field.field.paragraph.status.field_author',
      'field.storage.node.field_internal_remark',
      'field.field.node.service_request.field_internal_remark',
    ] as $name) {
      $this->assertContains($name, $preview['missing']);
    }
    $this->assertSame([], $this->installed);
    $result = $schema->prepare(TRUE);
    $this->assertSame($preview['missing'], $result['created']);
    $this->assertSame([], $result['unavailable_optional']);
    $field = 'field.field.node.service_request.field_internal_remark';
    $data = $this->active->read($field);
    $data['label'] = 'Operator decision';
    $this->active->write($field, $data);
    $repeat = $schema->prepare(TRUE);
    $this->assertSame([], $repeat['created']);
    $this->assertSame('Operator decision', $this->active->read($field)['label']);
    $this->assertCount(1, $this->installed);
  }

  /**
   * Report media includes its image field and storage, not just the reference.
   */
  public function testCanonicalMediaImageFieldsAreIncluded(): void {
    $profile = dirname(__DIR__, 5);
    $this->active->write('media.type.request_image', ['id' => 'request_image']);
    $this->sources[] = [
      'storage' => new FileStorage($profile . '/modules/markaspot_media/config/install'),
      'required' => TRUE,
    ];
    $result = $this->schema()->prepare(TRUE);
    $this->assertContains('field.field.media.request_image.field_media_image', $result['applicable']);
    $this->assertContains('field.storage.media.field_media_image', $result['created']);
    $this->assertContains('field.field.node.service_request.field_request_media', $result['created']);
  }

  /**
   * A missing external prerequisite fails before any configuration is written.
   */
  public function testMissingRequiredPrerequisiteIsRejectedBeforeMutation(): void {
    $this->active->delete('node.type.service_request');
    try {
      $this->schema()->prepare(TRUE);
      $this->fail('A content type must exist before schema repair.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('Missing prerequisite node.type.service_request', $error->getMessage());
      $this->assertSame([], $this->installed);
    }
  }

  /**
   * Optional feature dependencies are reported, never activated implicitly.
   */
  public function testDisabledOptionalProviderIsReportedWithoutPartialImport(): void {
    $source = new MemoryStorage();
    $source->write('field.field.node.service_request.field_optional', [
      'dependencies' => ['module' => ['markaspot_ai']],
    ]);
    $this->sources[] = ['storage' => $source, 'required' => FALSE];
    $result = $this->schema()->prepare(TRUE);
    $this->assertArrayHasKey('field.field.node.service_request.field_optional', $result['unavailable_optional']);
    $this->assertFalse($this->active->exists('field.field.node.service_request.field_optional'));
  }

  /**
   * Required missing dependencies cannot be hidden as optional unavailability.
   */
  public function testDisabledRequiredProviderFailsClosed(): void {
    $source = new MemoryStorage();
    $source->write('field.field.node.service_request.field_required', [
      'dependencies' => ['module' => ['missing_provider']],
    ]);
    $this->sources[] = ['storage' => $source, 'required' => TRUE];
    $this->expectExceptionMessage('Module missing_provider is not enabled');
    $this->schema()->prepare(TRUE);
  }

  /**
   * An installer silently skipping a field cannot be reported as successful.
   */
  public function testUninstalledSchemaFailsPostcondition(): void {
    $this->installSucceeds = FALSE;
    $this->expectExceptionMessage('Reporting schema postcondition failed');
    $this->schema()->prepare(TRUE);
  }

  /**
   * Required shipped roots must not disappear from the expected contract.
   */
  public function testMissingCanonicalRootSourceFailsPreflight(): void {
    $this->sources[] = [
      'storage' => new MemoryStorage(),
      'required' => TRUE,
      'required_names' => ['field.field.node.service_request.field_required'],
    ];
    $this->expectExceptionMessage('Required shipped schema source is unavailable');
    $this->schema()->prepare();
  }

}
