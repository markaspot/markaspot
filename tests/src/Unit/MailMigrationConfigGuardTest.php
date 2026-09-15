<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Unit;

use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageTransformerException;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\markaspot\Config\EcaModelImportNormalizer;
use Drupal\markaspot\EventSubscriber\ProfileConfigGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/EventSubscriber/ProfileConfigGuardSubscriber.php';
require_once dirname(__DIR__, 3) . '/src/Config/EcaModelImportNormalizer.php';

/**
 * Tests deploy protection for migrated ECA mail configuration.
 *
 * @group markaspot
 */
final class MailMigrationConfigGuardTest extends UnitTestCase {

  /**
   * The guard cannot migrate mail that has not been migrated in active config.
   */
  public function testUnmigratedMailRemainsUnchanged(): void {
    $active = new MemoryStorage();
    $import = new MemoryStorage();
    $eca = [
      'id' => 'legacy',
      'actions' => ['Activity_mail' => [
        'plugin' => 'action_send_email_action',
        'configuration' => ['subject' => 'Tenant subject', 'recipient' => 'tenant@example.test'],
      ]],
      'third_party_settings' => ['modeler_api' => ['modeler_id' => 'bpmn_io']],
    ];
    $model = ['data' => $this->bpmnXml('action_send_email_action', 'tenant-task')];
    foreach ([$active, $import] as $storage) {
      $storage->write('eca.eca.legacy', $eca);
      $storage->write('modeler_api.data_model.eca_bpmn_io_legacy', $model);
    }
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class), $active, 'markaspot', new NullLogger(),
    );
    (new \ReflectionMethod($subscriber, 'protectMigratedMailModels'))->invoke($subscriber, $import);
    $this->assertSame($eca, $import->read('eca.eca.legacy'));
    $this->assertSame($model, $import->read('modeler_api.data_model.eca_bpmn_io_legacy'));
  }

  /**
   * Embedded ECA 3 diagrams stay coherent with migrated imported mail actions.
   */
  public function testEmbeddedMailDiagramIsMigratedInPlace(): void {
    $active = new MemoryStorage();
    $active->write('eca.eca.embedded', [
      'id' => 'embedded',
      'actions' => ['Activity_mail' => [
        'plugin' => 'markaspot_mail_send_notification',
        'configuration' => ['notification_key' => 'confirmation'],
      ]],
    ]);
    $source = new MemoryStorage();
    $source->write('eca.eca.embedded', [
      'id' => 'embedded',
      'actions' => ['Activity_mail' => [
        'plugin' => 'action_send_email_action',
        'configuration' => ['recipient' => 'source@example.test'],
      ]],
      'third_party_settings' => ['modeler_api' => [
        'modeler_id' => 'bpmn_io',
        'storage' => 'third-party',
        'data' => $this->bpmnXml('action_send_email_action', 'source-edited-task'),
      ]],
    ]);
    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class), $active, 'markaspot', new NullLogger(),
    );
    $protect = new \ReflectionMethod($subscriber, 'protectMigratedMailModels');
    $protect->invoke($subscriber, $source);
    $eca = $source->read('eca.eca.embedded');
    $this->assertSame('markaspot_mail_send_notification', $eca['actions']['Activity_mail']['plugin']);
    $xml = $eca['third_party_settings']['modeler_api']['data'];
    $this->assertStringContainsString('markaspot_mail_send_notification', $xml);
    $this->assertStringContainsString('source-edited-task', $xml);
    $this->assertStringContainsString('source@example.test', $xml);
    $this->assertSame([], $source->listAll('modeler_api.data_model.'));
    $protect->invoke($subscriber, $source);
    $this->assertSame($eca, $source->read('eca.eca.embedded'));
  }

  /**
   * Missing and conflicting BPMN targets cannot silently diverge from runtime.
   */
  public function testMissingOrConflictingMailTargetsFailClosed(): void {
    $xml = $this->bpmnXml('action_send_email_action', 'other-task');
    $invalidModels = [
      str_replace('id="Activity_mail"', 'id="Different_id"', $xml),
      str_replace('action_send_email_action', 'tenant_different_plugin', $xml),
      str_replace('<camunda:property name="pluginid" value="action_send_email_action" />', '', $xml),
    ];
    foreach ($invalidModels as $invalidXml) {
      $active = new MemoryStorage();
      $active->write('eca.eca.example', [
        'id' => 'example',
        'actions' => ['Activity_mail' => [
          'plugin' => 'markaspot_mail_send_notification',
          'configuration' => ['notification_key' => 'confirmation'],
        ]],
      ]);
      $source = new MemoryStorage();
      $eca = [
        'id' => 'example',
        'actions' => ['Activity_mail' => ['plugin' => 'action_send_email_action']],
        'third_party_settings' => ['modeler_api' => [
          'modeler_id' => 'bpmn_io',
          'storage' => 'third-party',
          'data' => $invalidXml,
        ]],
      ];
      $source->write('eca.eca.example', $eca);
      $subscriber = new ProfileConfigGuardSubscriber(
        $this->createMock(ModuleExtensionList::class), $active, 'markaspot', new NullLogger(),
      );
      try {
        (new \ReflectionMethod($subscriber, 'protectMigratedMailModels'))->invoke($subscriber, $source);
        $this->fail('An unmatched mail diagram must stop config import.');
      }
      catch (StorageTransformerException $exception) {
        $this->assertStringContainsString('matching mail task IDs/plugins', $exception->getMessage());
        $this->assertSame($eca, $source->read('eca.eca.example'));
      }
    }
  }

  /**
   * Tests stale sync cannot restore legacy runtime actions or BPMN XML.
   */
  public function testMigratedMailModelsSurviveStaleTenantImport(): void {
    $active = new MemoryStorage();
    $active->write('eca.eca.process_confirm_report', [
      'id' => 'process_confirm_report',
      'actions' => [
        'Activity_mail' => [
          'plugin' => 'markaspot_mail_send_notification',
          'configuration' => ['notification_key' => 'report_confirmation'],
        ],
        'Activity_other' => ['plugin' => 'tenant_action'],
      ],
      'third_party_settings' => [
        'modeler_api' => [
          'modeler_id' => 'bpmn_io',
          'data' => 'hash:migrated',
        ],
      ],
    ]);
    $active->write('modeler_api.data_model.eca_bpmn_io_process_confirm_report', [
      'data' => $this->bpmnXml(
        'markaspot_mail_send_notification',
        'active-other-task',
      ),
    ]);

    $import = new MemoryStorage();
    $import->write('eca.eca.process_confirm_report', [
      'actions' => [
        'Activity_mail' => [
          'plugin' => 'action_send_email_action',
          'label' => 'Imported mail label',
          'successors' => [['id' => 'Imported_next', 'condition' => '']],
          'configuration' => [
            'recipient' => 'source@example.test',
            // Legacy text follows the already-migrated notification template.
            'subject' => 'Old subject',
            'message' => 'Old body',
          ],
        ],
        'Activity_other' => ['plugin' => 'tenant_action_changed'],
      ],
      'third_party_settings' => [
        'modeler_api' => [
          'modeler_id' => 'bpmn_io',
          'data' => 'hash:legacy',
        ],
      ],
    ]);
    $import->write('modeler_api.data_model.eca_bpmn_io_process_confirm_report', [
      'data' => $this->bpmnXml(
        'action_send_email_action',
        'imported-other-task',
      ),
    ]);

    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      NULL,
      new EcaModelImportNormalizer(NULL, static fn(array $eca): array => ['module' => ['markaspot_mail', 'modeler_api']]),
    );
    $protect = new \ReflectionMethod($subscriber, 'protectMigratedMailModels');
    $protect->invoke($subscriber, $import);

    $eca = $import->read('eca.eca.process_confirm_report');
    $this->assertSame(
      'markaspot_mail_send_notification',
      $eca['actions']['Activity_mail']['plugin'],
    );
    $this->assertSame(
      'tenant_action_changed',
      $eca['actions']['Activity_other']['plugin'],
    );
    $this->assertSame('Imported mail label', $eca['actions']['Activity_mail']['label']);
    $this->assertSame('Imported_next', $eca['actions']['Activity_mail']['successors'][0]['id']);
    $this->assertSame('source@example.test', $eca['actions']['Activity_mail']['configuration']['recipient']);
    $this->assertSame('report_confirmation', $eca['actions']['Activity_mail']['configuration']['notification_key']);
    $this->assertArrayNotHasKey('subject', $eca['actions']['Activity_mail']['configuration']);
    $model = $import->read('modeler_api.data_model.eca_bpmn_io_process_confirm_report');
    $this->assertStringContainsString('markaspot_mail_send_notification', $model['data']);
    $this->assertStringContainsString('imported-other-task', $model['data']);
    $this->assertStringNotContainsString('active-other-task', $model['data']);
    $this->assertSame(
      'hash:' . md5($model['data']),
      $eca['third_party_settings']['modeler_api']['data'],
    );
    $protect->invoke($subscriber, $import);
    $this->assertSame($eca, $import->read('eca.eca.process_confirm_report'));
    $this->assertSame($model, $import->read('modeler_api.data_model.eca_bpmn_io_process_confirm_report'));
  }

  /**
   * Tests a partly migrated runtime config receives the merged BPMN hash.
   */
  public function testPartlyMigratedImportReceivesMergedBpmnHash(): void {
    $active = new MemoryStorage();
    $active->write('eca.eca.process_confirm_report', [
      'id' => 'process_confirm_report',
      'actions' => [
        'Activity_mail' => [
          'plugin' => 'markaspot_mail_send_notification',
          'configuration' => [
            'object' => 'entity',
            'notification_key' => 'report_confirmation',
            'recipient' => 'active@example.test',
          ],
        ],
      ],
      'third_party_settings' => [
        'modeler_api' => [
          'modeler_id' => 'bpmn_io',
          'data' => 'hash:active',
        ],
      ],
    ]);
    $import = new MemoryStorage();
    $import->write('eca.eca.process_confirm_report', [
      'actions' => [
        'Activity_mail' => [
          'plugin' => 'markaspot_mail_send_notification',
          'configuration' => [
            'object' => 'entity',
            'notification_key' => 'tenant_confirmation',
            'recipient' => 'imported@example.test',
          ],
        ],
      ],
      'third_party_settings' => [
        'modeler_api' => ['data' => 'hash:legacy'],
      ],
    ]);
    $import->write('modeler_api.data_model.eca_bpmn_io_process_confirm_report', [
      'data' => $this->bpmnXml('action_send_email_action', 'imported-other-task'),
    ]);

    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      NULL,
      new EcaModelImportNormalizer(NULL, static fn(array $eca): array => ['module' => ['markaspot_mail', 'modeler_api']]),
    );
    $protect = new \ReflectionMethod($subscriber, 'protectMigratedMailModels');
    $protect->invoke($subscriber, $import);

    $model = $import->read('modeler_api.data_model.eca_bpmn_io_process_confirm_report');
    $eca = $import->read('eca.eca.process_confirm_report');
    $this->assertSame(
      'hash:' . md5($model['data']),
      $eca['third_party_settings']['modeler_api']['data'],
    );
    $this->assertSame(
      'imported@example.test',
      $eca['actions']['Activity_mail']['configuration']['recipient'],
    );
    $this->assertStringContainsString('imported@example.test', $model['data']);
    $this->assertStringContainsString('tenant_confirmation', $model['data']);
  }

  /**
   * Tests a removed default-language override stays removed during import.
   */
  public function testDefaultLanguageMailOverrideStaysRemoved(): void {
    $active = new MemoryStorage();
    $active->write('system.site', ['default_langcode' => 'de']);
    $import = new MemoryStorage();
    $import->createCollection('language.de')->write(
      'markaspot_mail.texts',
      ['report_confirmation' => ['subject' => 'Stale']],
    );

    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      NULL,
      new EcaModelImportNormalizer(NULL, static fn(array $eca): array => ['module' => ['markaspot_mail', 'modeler_api']]),
    );
    $protect = new \ReflectionMethod($subscriber, 'protectDefaultLanguageMailOverride');
    $protect->invoke($subscriber, $import);

    $this->assertFalse(
      $import->createCollection('language.de')->exists('markaspot_mail.texts'),
    );
  }

  /**
   * Tests an unmigrated active override is not removed by config import alone.
   */
  public function testUnmigratedDefaultLanguageMailOverrideIsPreserved(): void {
    $active = new MemoryStorage();
    $active->write('system.site', ['default_langcode' => 'de']);
    $active->createCollection('language.de')->write(
      'markaspot_mail.texts',
      ['report_confirmation' => ['subject' => 'Active']],
    );
    $import = new MemoryStorage();
    $import->createCollection('language.de')->write(
      'markaspot_mail.texts',
      ['report_confirmation' => ['subject' => 'Imported']],
    );

    $subscriber = new ProfileConfigGuardSubscriber(
      $this->createMock(ModuleExtensionList::class),
      $active,
      'markaspot',
      new NullLogger(),
      NULL,
      new EcaModelImportNormalizer(NULL, static fn(array $eca): array => ['module' => ['markaspot_mail', 'modeler_api']]),
    );
    $protect = new \ReflectionMethod($subscriber, 'protectDefaultLanguageMailOverride');
    $protect->invoke($subscriber, $import);

    $this->assertTrue(
      $import->createCollection('language.de')->exists('markaspot_mail.texts'),
    );
  }

  /**
   * Builds a two-task BPMN model with one mail and one unrelated task.
   */
  private function bpmnXml(string $mailPlugin, string $otherName): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<bpmn2:definitions xmlns:bpmn2="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:camunda="http://camunda.org/schema/1.0/bpmn">
  <bpmn2:process id="Process_test">
    <bpmn2:task id="Activity_mail" camunda:modelerTemplate="org.drupal.action.$mailPlugin">
      <bpmn2:extensionElements>
        <camunda:properties>
          <camunda:property name="pluginid" value="$mailPlugin" />
        </camunda:properties>
      </bpmn2:extensionElements>
    </bpmn2:task>
    <bpmn2:task id="Activity_other" name="$otherName" />
  </bpmn2:process>
</bpmn2:definitions>
XML;
  }

}
