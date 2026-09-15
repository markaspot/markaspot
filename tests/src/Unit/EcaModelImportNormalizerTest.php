<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageTransformerException;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\markaspot\Config\EcaModelImportNormalizer;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/Config/EcaModelImportNormalizer.php';
require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';

/**
 * Tests the ECA 2 to ECA 3 import boundary without writing active config.
 *
 * @group markaspot
 */
final class EcaModelImportNormalizerTest extends UnitTestCase {

  /**
   * Converts metadata and keeps executable and diagram edits from the source.
   */
  public function testLegacyPairAndSecondTransform(): void {
    $source = $this->legacySource();
    $before = $source->read('eca.eca.example');
    $normalizer = $this->normalizer();
    $normalizer->normalize($source);
    $eca = $source->read('eca.eca.example');
    $model = $source->read('modeler_api.data_model.eca_bpmn_io_example');
    $this->assertFalse($source->exists('eca.model.example'));
    $this->assertSame('eca_bpmn_io_example', $model['id']);
    $this->assertSame('legacy-model-uuid', $model['uuid']);
    $this->assertSame(['uuid', 'langcode', 'status', 'dependencies', 'id', 'data'], array_keys($model));
    $this->assertSame(['modeler_id', 'data', 'label', 'documentation', 'tags', 'version'], array_keys($eca['third_party_settings']['modeler_api']));
    $this->assertSame('hash:' . md5($model['data']), $eca['third_party_settings']['modeler_api']['data']);
    $this->assertSame('Source diagram', $eca['third_party_settings']['modeler_api']['label']);
    $this->assertArrayNotHasKey('changelog', $eca['third_party_settings']['modeler_api']);
    $this->assertSame($before['actions'], $eca['actions']);
    $this->assertSame($before['uuid'], $eca['uuid']);
    $this->assertFalse($eca['status']);
    $this->assertSame('tenant_form', $eca['events']['form']['configuration']['form_ids']);
    $this->assertSame('other_field', $eca['events']['other']['configuration']['form_id']);
    $this->assertStringContainsString('name="form_ids"', $model['data']);
    $this->assertStringContainsString('name="form_id"><camunda:string>keep', $model['data']);
    $this->assertStringContainsString('source-edited-task', $model['data']);
    $this->assertArrayNotHasKey('label', $eca);
    $this->assertNull($eca['template']);
    $normalizer->normalize($source);
    $this->assertSame($eca, $source->read('eca.eca.example'));
    $this->assertSame($model, $source->read('modeler_api.data_model.eca_bpmn_io_example'));
  }

  /**
   * New-format source wins over even a malformed leftover legacy diagram.
   */
  public function testExplicitNewFormatSourceWins(): void {
    $source = $this->legacySource();
    $this->normalizer()->normalize($source);
    $eca = $source->read('eca.eca.example');
    $source->write('eca.model.example', ['modeldata' => 'broken legacy']);
    $this->normalizer()->normalize($source);
    $this->assertSame($eca, $source->read('eca.eca.example'));
    $this->assertFalse($source->exists('eca.model.example'));
  }

  /**
   * A hand-authored model without a diagram uses the fallback modeler.
   */
  public function testHandAuthoredFallback(): void {
    $source = new MemoryStorage();
    $source->write('eca.eca.manual', ['id' => 'manual', 'label' => 'Manual', 'actions' => []]);
    $this->normalizer()->normalize($source);
    $this->assertSame([
      'modeler_id' => 'fallback',
      'label' => 'Manual',
    ], $source->read('eca.eca.manual')['third_party_settings']['modeler_api']);
    $this->assertSame([], $source->listAll('modeler_api.data_model.'));
  }

  /**
   * Deleting an executable model cannot recreate it from its orphan diagram.
   */
  public function testDeliberateModelDeletion(): void {
    $source = $this->legacySource();
    $source->delete('eca.eca.example');
    $this->normalizer()->normalize($source);
    $this->assertSame([], $source->listAll());
  }

  /**
   * Invalid legacy XML leaves even temporary source storage untouched.
   */
  public function testMalformedSourceFailsWithoutWrites(): void {
    $source = $this->legacySource();
    $source->write('eca.model.example', ['modeldata' => '<broken>']);
    $before = $source->read('eca.eca.example');
    try {
      $this->normalizer()->normalize($source);
      $this->fail('Malformed legacy XML must stop config import.');
    }
    catch (StorageTransformerException $exception) {
      $this->assertStringContainsString('eca.eca.example', $exception->getMessage());
      $this->assertSame($before, $source->read('eca.eca.example'));
      $this->assertTrue($source->exists('eca.model.example'));
      $this->assertSame([], $source->listAll('modeler_api.data_model.'));
    }
  }

  /**
   * A known diagram-backed model must not silently turn into a fallback.
   */
  public function testMissingDiagramFails(): void {
    $source = $this->legacySource();
    $source->delete('eca.model.example');
    $this->expectException(StorageTransformerException::class);
    $this->normalizer()->normalize($source);
  }

  /**
   * A partial new-format import cannot erase the active diagram.
   */
  public function testMissingNewFormatDiagramFails(): void {
    $source = $this->legacySource();
    $this->normalizer()->normalize($source);
    $source->delete('modeler_api.data_model.eca_bpmn_io_example');
    $this->expectException(StorageTransformerException::class);
    $this->normalizer()->normalize($source);
  }

  /**
   * Missing optional parser services must abort, rather than lose a diagram.
   */
  public function testUnavailableParserFails(): void {
    $this->expectException(StorageTransformerException::class);
    (new EcaModelImportNormalizer())->normalize($this->legacySource());
  }

  /**
   * An ECA 2 active configuration does not activate the migration guard.
   */
  public function testEcaTwoIsUnaffected(): void {
    $source = $this->legacySource();
    $active = $this->legacySource();
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class), $active, 'markaspot', new NullLogger(),
    );
    (new \ReflectionMethod($subscriber, 'protectMigratedEcaModels'))->invoke($subscriber, $source);
    $this->assertSame($active->read('eca.eca.example'), $source->read('eca.eca.example'));
    $this->assertTrue($source->exists('eca.model.example'));
  }

  /**
   * Newly imported custom modules do not need to exist in the active runtime.
   */
  public function testSourceDependenciesArePreserved(): void {
    $eca = [
      'dependencies' => [
        'module' => ['tenant_new_module', 'modeler_api', 'modeler_api'],
        'config' => ['field.storage.node.new_field'],
        'enforced' => ['module' => ['tenant_enforced']],
      ],
      'third_party_settings' => ['modeler_api' => ['modeler_id' => 'bpmn_io']],
      'actions' => [
        ['plugin' => 'tenant_new_action'],
        ['plugin' => 'markaspot_mail_send_notification'],
      ],
    ];
    $normalizer = new EcaModelImportNormalizer();
    $result = $normalizer->updateDependencies($eca);
    $this->assertSame(['markaspot_mail', 'modeler_api', 'tenant_new_module'], $result['dependencies']['module']);
    $this->assertSame($eca['dependencies']['config'], $result['dependencies']['config']);
    $this->assertSame($eca['dependencies']['enforced'], $result['dependencies']['enforced']);
    $this->assertSame($result, $normalizer->updateDependencies($result));
  }

  /**
   * The subscriber must propagate conversion failures past its general catch.
   */
  public function testSubscriberFailsClosed(): void {
    $source = $this->legacySource();
    $source->write('eca.model.example', ['modeldata' => '<invalid>']);
    $active = new MemoryStorage();
    $active->write('eca.eca.example', [
      'third_party_settings' => ['modeler_api' => ['modeler_id' => 'bpmn_io']],
    ]);
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class), $active, 'markaspot', new NullLogger(), NULL, $this->normalizer(),
    );
    $this->expectException(StorageTransformerException::class);
    $subscriber->onImportTransform(new StorageTransformEvent($source));
  }

  /**
   * An unknown legacy modeler must not be relabeled as BPMN.
   */
  public function testUnknownLegacyModelerFails(): void {
    $source = $this->legacySource();
    $raw = $source->read('eca.model.example');
    $raw['modeller'] = 'tenant_custom_modeler';
    $source->write('eca.model.example', $raw);
    $this->expectException(StorageTransformerException::class);
    $this->normalizer()->normalize($source);
  }

  /**
   * A newly shipped default keeps both its executable config and its diagram.
   */
  public function testShippedEcaPairSurvivesStaleSource(): void {
    $active = $this->legacySource();
    $this->normalizer()->normalize($active);
    $active->write('eca.model.example', ['modeldata' => 'obsolete']);
    $active->write('eca.eca.tenant_deleted', ['id' => 'tenant_deleted']);
    $source = new MemoryStorage();
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class), $active, 'markaspot', new NullLogger(), NULL, $this->normalizer(),
    );
    (new \ReflectionProperty($subscriber, 'shippedConfigNames'))->setValue($subscriber, [
      'eca.eca.example' => 'eca.eca.example',
      'eca.model.example' => 'eca.model.example',
    ]);
    (new \ReflectionMethod($subscriber, 'protectShippedConfig'))->invoke($subscriber, $source);
    (new \ReflectionMethod($subscriber, 'protectMigratedEcaModels'))->invoke($subscriber, $source);
    $this->assertSame($active->read('eca.eca.example'), $source->read('eca.eca.example'));
    $this->assertSame($active->read('modeler_api.data_model.eca_bpmn_io_example'), $source->read('modeler_api.data_model.eca_bpmn_io_example'));
    $this->assertFalse($source->exists('eca.model.example'));
    $this->assertFalse($source->exists('eca.eca.tenant_deleted'));
  }

  /**
   * Existing migrated data-model identity wins over an old diagram's UUID.
   */
  public function testActiveDataModelIdentityIsRetained(): void {
    $active = new MemoryStorage();
    $active->write('modeler_api.data_model.eca_bpmn_io_example', [
      'uuid' => 'migrated-active-uuid', 'langcode' => 'en',
    ]);
    $source = $this->legacySource();
    $normalizer = new EcaModelImportNormalizer(
      static fn(string $xml): array => ['status' => TRUE, 'label' => 'Source diagram'],
      NULL,
      $active,
    );
    $normalizer->normalize($source);
    $model = $source->read('modeler_api.data_model.eca_bpmn_io_example');
    $this->assertSame('migrated-active-uuid', $model['uuid']);
    $this->assertSame('en', $model['langcode']);
    $this->assertSame(['uuid' => 'migrated-active-uuid', 'langcode' => 'en'], $active->read('modeler_api.data_model.eca_bpmn_io_example'));
  }

  /**
   * Missing legacy UUIDs still produce portable, repeatable exported identity.
   */
  public function testGeneratedIdentityIsDeterministic(): void {
    $first = $this->legacySource();
    $raw = $first->read('eca.model.example');
    unset($raw['uuid']);
    $first->write('eca.model.example', $raw);
    $second = clone $first;
    $this->normalizer()->normalize($first);
    $this->normalizer()->normalize($second);
    $model = $first->read('modeler_api.data_model.eca_bpmn_io_example');
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $model['uuid']);
    $this->assertSame($model, $second->read('modeler_api.data_model.eca_bpmn_io_example'));
  }

  /**
   * The source global policy can replace active separate data with inline data.
   */
  public function testLegacyEmbeddedStorageUsesSourcePolicy(): void {
    $source = $this->legacySource();
    $source->write('modeler_api.settings', [
      'owner_modeler' => ['eca' => ['bpmn_io' => ['storage' => 'third-party']]],
    ]);
    $active = new MemoryStorage();
    $active->write('modeler_api.settings', [
      'owner_modeler' => ['eca' => ['bpmn_io' => ['storage' => 'separate']]],
    ]);
    $normalizer = new EcaModelImportNormalizer(
      static fn(string $xml): array => ['status' => TRUE, 'label' => 'Embedded'],
      NULL, $active,
    );
    $normalizer->normalize($source);
    $eca = $source->read('eca.eca.example');
    $this->assertStringContainsString('<bpmn:definitions', $eca['third_party_settings']['modeler_api']['data']);
    $this->assertFalse($source->exists('eca.model.example'));
    $this->assertSame([], $source->listAll('modeler_api.data_model.'));
    $normalizer->normalize($source);
    $this->assertSame($eca, $source->read('eca.eca.example'));
  }

  /**
   * A migrated per-model storage override survives legacy source imports.
   */
  public function testActivePerModelStorageOverrideIsRetained(): void {
    $source = $this->legacySource();
    $source->write('modeler_api.settings', [
      'owner_modeler' => ['eca' => ['bpmn_io' => ['storage' => 'separate']]],
    ]);
    $active = new MemoryStorage();
    $active->write('eca.eca.example', [
      'third_party_settings' => ['modeler_api' => ['storage' => 'third-party']],
    ]);
    (new EcaModelImportNormalizer(
      static fn(string $xml): array => ['status' => TRUE, 'label' => 'Embedded'],
      NULL, $active,
    ))->normalize($source);
    $metadata = $source->read('eca.eca.example')['third_party_settings']['modeler_api'];
    $this->assertSame('third-party', $metadata['storage']);
    $this->assertStringContainsString('<bpmn:definitions', $metadata['data']);
    $this->assertSame([], $source->listAll('modeler_api.data_model.'));
  }

  /**
   * Active global policy is used only when the source settings are absent.
   */
  public function testActiveGlobalStorageFallback(): void {
    foreach ([FALSE, TRUE] as $hasSourceSettings) {
      $source = $this->legacySource();
      if ($hasSourceSettings) {
        $source->write('modeler_api.settings', []);
      }
      $active = new MemoryStorage();
      $active->write('modeler_api.settings', [
        'owner_modeler' => ['eca' => ['bpmn_io' => ['storage' => 'third-party']]],
      ]);
      (new EcaModelImportNormalizer(
        static fn(string $xml): array => ['status' => TRUE, 'label' => 'Source'],
        NULL, $active,
      ))->normalize($source);
      $data = $source->read('eca.eca.example')['third_party_settings']['modeler_api']['data'];
      $this->assertSame($hasSourceSettings, str_starts_with($data, 'hash:'));
      $this->assertSame($hasSourceSettings, $source->exists('modeler_api.data_model.eca_bpmn_io_example'));
    }
  }

  /**
   * A no-storage policy cannot silently discard an imported legacy diagram.
   */
  public function testNoStorageLegacyConversionFailsClosed(): void {
    $source = $this->legacySource();
    $source->write('modeler_api.settings', [
      'owner_modeler' => ['eca' => ['bpmn_io' => ['storage' => 'none']]],
    ]);
    $this->expectException(StorageTransformerException::class);
    $this->normalizer()->normalize($source);
  }

  /**
   * Collaboration diagrams can contain multiple supported BPMN processes.
   */
  public function testMultipleBpmnProcessesArePreserved(): void {
    $source = $this->legacySource();
    $legacy = $source->read('eca.model.example');
    $legacy['modeldata'] = str_replace('</bpmn:definitions>', '<bpmn:process id="second_process"/></bpmn:definitions>', $legacy['modeldata']);
    $source->write('eca.model.example', $legacy);
    $this->normalizer()->normalize($source);
    $xml = $source->read('modeler_api.data_model.eca_bpmn_io_example')['data'];
    $this->assertStringContainsString('id="second_process"', $xml);
    $this->assertStringContainsString('id="example"', $xml);
  }

  /**
   * Supplies an isolated parser and dependency calculator.
   */
  private function normalizer(): EcaModelImportNormalizer {
    return new EcaModelImportNormalizer(
      static fn(string $xml): array => [
        'status' => TRUE,
        'label' => 'Source diagram',
        'version' => '1.2',
        'tags' => ['source'],
        'documentation' => 'Source documentation',
        'changelog' => '',
      ],
      static fn(array $eca): array => ['module' => ['modeler_api']],
    );
  }

  /**
   * Builds source with independent diagram and executable tenant edits.
   */
  private function legacySource(): MemoryStorage {
    $source = new MemoryStorage();
    $source->write('eca.eca.example', [
      'id' => 'example', 'uuid' => 'tenant-uuid', 'modeller' => 'bpmn_io',
      'status' => FALSE,
      'label' => 'Old label',
      'actions' => ['task' => ['plugin' => 'tenant_action', 'label' => 'source-edited-runtime']],
      'events' => [
        'form' => ['plugin' => 'form:submit', 'configuration' => ['form_id' => 'tenant_form']],
        'other' => ['plugin' => 'custom:event', 'configuration' => ['form_id' => 'other_field']],
      ],
    ]);
    $source->write('eca.model.example', ['uuid' => 'legacy-model-uuid', 'langcode' => 'de', 'modeldata' => <<<'XML'
<?xml version="1.0"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:camunda="http://camunda.org/schema/1.0/bpmn">
  <bpmn:process id="example" name="Source diagram">
    <bpmn:startEvent id="form"><bpmn:extensionElements>
      <camunda:properties><camunda:property name="pluginid" value="form:submit"/></camunda:properties>
      <camunda:field name="form_id"><camunda:string>tenant_form</camunda:string></camunda:field>
    </bpmn:extensionElements></bpmn:startEvent>
    <bpmn:task id="task" name="source-edited-task"><bpmn:extensionElements>
      <camunda:properties><camunda:property name="pluginid" value="tenant_action"/></camunda:properties>
      <camunda:field name="form_id"><camunda:string>keep</camunda:string></camunda:field>
    </bpmn:extensionElements></bpmn:task>
  </bpmn:process>
</bpmn:definitions>
XML,
    ]);
    return $source;
  }

}
