<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the optional process_apply_group ECA config.
 *
 * @group markaspot_group
 */
class ProcessApplyGroupConfigTest extends UnitTestCase {

  /**
   * Tests optional process_apply_group ECA is disabled by default.
   */
  public function testOptionalEcaIsDisabledByDefault(): void {
    $config = $this->loadProcessApplyGroupConfig();

    $this->assertFalse($config['status']);
  }

  /**
   * Tests category-to-org stamping is guarded by a non-empty GID token.
   */
  public function testCategoryGidTokenGuardProtectsOrgStampingBranch(): void {
    $config = $this->loadProcessApplyGroupConfig();

    $this->assertContains('eca_base', $config['dependencies']['module']);
    $this->assertSame(
      [
        'plugin' => 'eca_token_exists',
        'configuration' => [
          'token_name' => 'node:field_category:entity:field_category_gid:target_id',
          'negate' => FALSE,
        ],
      ],
      $config['conditions']['Condition_category_gid_exists']
    );

    $loadSuccessors = $config['actions']['Activity_1yd51fx']['successors'];
    $this->assertSame('Activity_Apply_Group', $loadSuccessors[0]['id']);
    $this->assertSame('Condition_category_gid_exists', $loadSuccessors[0]['condition']);

    $this->assertSame(
      '[node:field_category:entity:field_category_gid:target_id]',
      $config['actions']['Activity_Apply_Group']['configuration']['group_id']
    );
    $this->assertSame(
      '[node:field_category:entity:field_category_gid:target_id]',
      $config['actions']['Activiy_save_group']['configuration']['field_value']
    );
  }

  /**
   * Tests category updates remove the old group before adding the new one.
   */
  public function testCategoryUpdateReplacesPreviousGroupRelationship(): void {
    $config = $this->loadProcessApplyGroupConfig();

    $this->assertSame(
      [
        'plugin' => 'content_entity:update',
        'label' => 'Update Service Request',
        'configuration' => [
          'type' => 'node service_request',
        ],
        'successors' => [
          [
            'id' => 'Activy_Set_Current_User_For_Update',
            'condition' => 'Condition_category_changed',
          ],
        ],
      ],
      $config['events']['Update_Service_Request']
    );
    $this->assertSame(
      [
        'plugin' => 'eca_entity_field_value_changed',
        'configuration' => [
          'field_name' => 'field_category',
          'negate' => FALSE,
        ],
      ],
      $config['conditions']['Condition_category_changed']
    );
    $this->assertSame(
      [
        'plugin' => 'eca_token_exists',
        'configuration' => [
          'token_name' => 'node:field_category:entity:field_category_gid:target_id',
          'negate' => TRUE,
        ],
      ],
      $config['conditions']['Condition_category_gid_missing']
    );
    $this->assertSame(
      [
        'plugin' => 'eca_token_exists',
        'configuration' => [
          'token_name' => 'original_service_request:field_category:entity:field_category_gid:target_id',
          'negate' => FALSE,
        ],
      ],
      $config['conditions']['Condition_original_category_gid_exists']
    );
    $this->assertSame(
      [
        'plugin' => 'eca_token_exists',
        'configuration' => [
          'token_name' => 'original_service_request:field_category:entity:field_category_gid:target_id',
          'negate' => TRUE,
        ],
      ],
      $config['conditions']['Condition_original_category_gid_missing']
    );

    $this->assertSame(
      [
        [
          'id' => 'Activity_Load_Service_Request_For_Update',
          'condition' => '',
        ],
      ],
      $config['actions']['Activy_Set_Current_User_For_Update']['successors']
    );
    $this->assertSame(
      [
        [
          'id' => 'Activity_Load_Original_Service_Request',
          'condition' => '',
        ],
      ],
      $config['actions']['Activity_Load_Service_Request_For_Update']['successors']
    );
    $this->assertTrue($config['actions']['Activity_Load_Original_Service_Request']['configuration']['unchanged']);
    $this->assertSame(
      [
        [
          'id' => 'Activity_Remove_Old_Group',
          'condition' => 'Condition_original_category_gid_exists',
        ],
        [
          'id' => 'Activity_Apply_Group_Update',
          'condition' => 'Condition_original_category_gid_missing',
        ],
      ],
      $config['actions']['Activity_Load_Original_Service_Request']['successors']
    );

    $removeOldGroup = $config['actions']['Activity_Remove_Old_Group'];
    $this->assertSame('group_remove_content', $removeOldGroup['plugin']);
    $this->assertTrue($removeOldGroup['configuration']['replace_tokens']);
    $this->assertSame('delete', $removeOldGroup['configuration']['operation']);
    $this->assertSame('group_node:service_request', $removeOldGroup['configuration']['content_plugin']);
    $this->assertSame(
      '[original_service_request:field_category:entity:field_category_gid:target_id]',
      $removeOldGroup['configuration']['group_id']
    );
    $this->assertSame(
      [
        [
          'id' => 'Activity_Remove_Old_Organisation_Field_Value',
          'condition' => '',
        ],
      ],
      $removeOldGroup['successors']
    );

    $removeOldField = $config['actions']['Activity_Remove_Old_Organisation_Field_Value'];
    $this->assertSame('eca_set_field_value', $removeOldField['plugin']);
    $this->assertSame('field_organisation.target_id', $removeOldField['configuration']['field_name']);
    $this->assertSame(
      '[original_service_request:field_category:entity:field_category_gid:target_id]',
      $removeOldField['configuration']['field_value']
    );
    $this->assertSame('remove', $removeOldField['configuration']['method']);
    $this->assertFalse($removeOldField['configuration']['save_entity']);
    $this->assertSame(
      [
        [
          'id' => 'Activity_Apply_Group_Update',
          'condition' => 'Condition_category_gid_exists',
        ],
        [
          'id' => 'Activity_Save_Service_Request_After_Group_Update',
          'condition' => 'Condition_category_gid_missing',
        ],
      ],
      $removeOldField['successors']
    );

    $applyUpdateGroup = $config['actions']['Activity_Apply_Group_Update'];
    $this->assertSame('group_add_content', $applyUpdateGroup['plugin']);
    $this->assertTrue($applyUpdateGroup['configuration']['replace_tokens']);
    $this->assertSame('create', $applyUpdateGroup['configuration']['operation']);
    $this->assertSame('group_node:service_request', $applyUpdateGroup['configuration']['content_plugin']);
    $this->assertSame('skip_existing', $applyUpdateGroup['configuration']['add_method']);
    $this->assertSame(
      '[node:field_category:entity:field_category_gid:target_id]',
      $applyUpdateGroup['configuration']['group_id']
    );
    $this->assertSame(
      [
        [
          'id' => 'Activity_Append_Current_Organisation_Field_Value',
          'condition' => 'Condition_category_gid_exists',
        ],
      ],
      $applyUpdateGroup['successors']
    );

    $appendCurrentField = $config['actions']['Activity_Append_Current_Organisation_Field_Value'];
    $this->assertSame('eca_set_field_value', $appendCurrentField['plugin']);
    $this->assertSame('field_organisation.target_id', $appendCurrentField['configuration']['field_name']);
    $this->assertSame(
      '[node:field_category:entity:field_category_gid:target_id]',
      $appendCurrentField['configuration']['field_value']
    );
    $this->assertSame('append', $appendCurrentField['configuration']['method']);
    $this->assertFalse($appendCurrentField['configuration']['save_entity']);
    $this->assertSame(
      [
        [
          'id' => 'Activity_Save_Service_Request_After_Group_Update',
          'condition' => '',
        ],
      ],
      $appendCurrentField['successors']
    );

    $saveServiceRequest = $config['actions']['Activity_Save_Service_Request_After_Group_Update'];
    $this->assertSame('eca_save_entity', $saveServiceRequest['plugin']);
    $this->assertSame('node', $saveServiceRequest['configuration']['object']);
    $this->assertSame([], $saveServiceRequest['successors']);
  }

  /**
   * Tests module source contains the obsolete ECA presave guard.
   */
  public function testModuleContainsObsoleteEcaPresaveGuard(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');

    $this->assertStringContainsString('_markaspot_group_presave_obsolete_eca($entity);', $source);
    $this->assertStringContainsString("return ['process_apply_group'];", $source);
    $this->assertStringContainsString('$entity->setStatus(FALSE);', $source);
  }

  /**
   * Tests update hook disables obsolete ECA through entity storage.
   */
  public function testUpdateHookDisablesObsoleteEcaThroughEntityStorage(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.install');

    $this->assertStringContainsString('function markaspot_group_update_11926(): string', $source);
    $this->assertStringContainsString("->getStorage('eca')", $source);
    $this->assertStringContainsString('$eca->setStatus(FALSE);', $source);
    $this->assertStringContainsString('$eca->save();', $source);
  }

  /**
   * Tests requirements monitor scans ECA subscribed state.
   */
  public function testRequirementsMonitorScansEcaSubscribedState(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_group.install';

    $subscribed = [
      'content_entity:insert' => [
        0 => [
          'process_apply_group' => [
            'Create_Service_Request' => [],
          ],
        ],
      ],
    ];

    $this->assertTrue(_markaspot_group_eca_subscription_contains($subscribed, 'process_apply_group'));
    $this->assertFalse(_markaspot_group_eca_subscription_contains($subscribed, 'other_process'));

    $pruned = _markaspot_group_prune_eca_subscription_id($subscribed, 'process_apply_group');
    $this->assertFalse(_markaspot_group_eca_subscription_contains($pruned, 'process_apply_group'));
    $this->assertSame([], $pruned);

    $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.install');
    $this->assertStringContainsString('function markaspot_group_requirements(string $phase): array', $source);
    $this->assertStringContainsString('function markaspot_group_update_11927(): string', $source);
    $this->assertStringContainsString('function _markaspot_group_rebuild_eca_subscribed_events(): bool', $source);
    $this->assertStringContainsString('function _markaspot_group_prune_obsolete_eca_subscriptions(): int', $source);
    $this->assertStringContainsString('rebuildSubscribedEvents', $source);
    $this->assertStringContainsString('eca.subscribed references {$eca_id}', $source);
  }

  /**
   * Loads the optional process_apply_group ECA config.
   */
  private function loadProcessApplyGroupConfig(): array {
    $moduleRoot = dirname(__DIR__, 3);
    $configPath = $moduleRoot . '/config/optional/eca.eca.process_apply_group.yml';

    $this->assertFileExists($configPath);
    return Yaml::decode(file_get_contents($configPath));
  }

}
