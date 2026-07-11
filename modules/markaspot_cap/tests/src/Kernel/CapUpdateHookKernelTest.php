<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Kernel;

use Drupal\Core\Utility\UpdateException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\markaspot_cap\Support\CapApprovalReadiness;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the real CAP approval field update lifecycle.
 *
 * @group markaspot_cap
 */
#[RunTestsInSeparateProcesses]
final class CapUpdateHookKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'text',
    'filter',
    'field_permissions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'filter']);
    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();

    require_once dirname(__DIR__, 3) . '/markaspot_cap.install';
    require_once dirname(__DIR__, 3) . '/markaspot_cap.module';
  }

  /**
   * A normal existing-site update creates storage before enabling the feed.
   */
  public function testUpdateCreatesApprovalFieldAndReadinessGate(): void {
    $this->assertNull(FieldStorageConfig::loadByName('node', 'field_cap_publish'));

    markaspot_cap_update_11900();

    $storage = FieldStorageConfig::loadByName('node', 'field_cap_publish');
    $field = FieldConfig::loadByName('node', 'service_request', 'field_cap_publish');
    $this->assertInstanceOf(FieldStorageConfig::class, $storage);
    $this->assertInstanceOf(FieldConfig::class, $field);
    $this->assertSame(
      'custom',
      $storage->getThirdPartySetting('field_permissions', 'permission_type'),
    );
    $this->assertSame([['value' => 0]], $field->getDefaultValueLiteral());
    $this->assertTrue(
      $this->container->get('state')->get('markaspot_cap.approval_field_ready'),
    );
  }

  /**
   * Unknown pre-approved rows stop the update and keep the feed disabled.
   */
  public function testUpdateRejectsTruthyLegacyApprovalData(): void {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'service_request',
      'label' => 'Legacy approval',
      'default_value' => [['value' => 1]],
    ])->save();
    Node::create([
      'type' => 'service_request',
      'title' => 'Unknown legacy approval',
      'field_cap_publish' => 1,
    ])->save();
    $this->container->get('state')
      ->set('markaspot_cap.approval_field_ready', TRUE);

    try {
      markaspot_cap_update_11900();
      $this->fail('Truthy legacy approvals must stop automatic adoption.');
    }
    catch (UpdateException $exception) {
      $this->assertStringContainsString('unknown provenance', $exception->getMessage());
      $this->assertNull(
        $this->container->get('state')->get('markaspot_cap.approval_field_ready'),
      );
    }
  }

  /**
   * Legacy Field Permissions grants must not become self-approval rights.
   */
  #[DataProvider('legacyApprovalMutationPermissionProvider')]
  public function testUpdateRejectsLegacyApprovalMutationGrants(string $permission): void {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();

    $role = Role::create([
      'id' => 'unsafe_cap_submitter',
      'label' => 'Unsafe CAP submitter',
    ]);
    $role->grantPermission($permission)->save();
    $this->assertContains($permission, $role->getPermissions());
    $this->container->get('state')
      ->set('markaspot_cap.approval_field_ready', TRUE);

    try {
      markaspot_cap_update_11900();
      $this->fail('Legacy CAP mutation grants must stop automatic adoption.');
    }
    catch (UpdateException $exception) {
      $this->assertStringContainsString(
        'CAP approval field access is unsafe',
        $exception->getMessage(),
      );
      $this->assertStringContainsString(
        sprintf('Unsafe CAP submitter (%s)', $permission),
        $exception->getMessage(),
      );
      $this->assertNull(
        $this->container->get('state')->get('markaspot_cap.approval_field_ready'),
      );
      $this->assertInstanceOf(
        FieldStorageConfig::class,
        FieldStorageConfig::loadByName('node', 'field_cap_publish'),
      );
    }
  }

  /**
   * Install remains fail-closed when a pre-existing role could self-approve.
   */
  public function testInstallRejectsLegacyApprovalMutationGrant(): void {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'service_request',
      'label' => 'Legacy approval',
      'default_value' => [['value' => 0]],
    ])->save();
    $role = Role::create([
      'id' => 'unsafe_cap_installer',
      'label' => 'Unsafe CAP installer',
    ]);
    $role->grantPermission('create field_cap_publish')->save();
    $this->assertContains('create field_cap_publish', $role->getPermissions());
    $this->container->get('state')
      ->set('markaspot_cap.approval_field_ready', TRUE);

    markaspot_cap_install();

    $this->assertFalse((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE, FALSE));
    $this->assertStringContainsString(
      'CAP approval field access is unsafe',
      (string) $this->container->get('state')
        ->get(CapApprovalReadiness::ERROR_STATE),
    );
    $this->assertStringContainsString(
      'Unsafe CAP installer (create field_cap_publish) is not listed',
      (string) $this->container->get('state')
        ->get(CapApprovalReadiness::ERROR_STATE),
    );
  }

  /**
   * Install enables CAP only after all field safety checks pass.
   */
  public function testInstallMarksApprovalFieldReadyWhenConfigurationIsSafe(): void {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'service_request',
      'label' => 'Publish as CAP alert',
      'default_value' => [['value' => 0]],
    ])->save();

    markaspot_cap_install();

    $this->assertTrue(
      $this->container->get('state')->get(CapApprovalReadiness::READY_STATE),
    );
  }

  /**
   * Install remains closed when a same-name field has approved legacy rows.
   */
  public function testInstallRejectsTruthyLegacyApprovalData(): void {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'service_request',
      'label' => 'Legacy approval',
      'default_value' => [['value' => 0]],
    ])->save();
    Node::create([
      'type' => 'service_request',
      'title' => 'Unknown legacy approval',
      'field_cap_publish' => 1,
    ])->save();
    $this->container->get('state')
      ->set('markaspot_cap.approval_field_ready', TRUE);

    markaspot_cap_install();

    $this->assertFalse((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE, FALSE));
    $this->assertStringContainsString(
      'unknown provenance',
      (string) $this->container->get('state')
        ->get(CapApprovalReadiness::ERROR_STATE),
    );
  }

  /**
   * A config-sync install waits until the complete field config is present.
   */
  public function testSyncingInstallDefersThenCronEnablesSafeConfiguration(): void {
    markaspot_cap_install(TRUE);

    $this->assertFalse((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE, FALSE));
    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::PENDING_STATE));

    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'service_request',
      'label' => 'Publish as CAP alert',
      'default_value' => [['value' => 0]],
    ])->save();

    markaspot_cap_cron();

    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE));
    $this->assertNull($this->container->get('state')
      ->get(CapApprovalReadiness::PENDING_STATE));
    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::BOOTSTRAP_AUDITED_STATE));
  }

  /**
   * A recurring audit closes a feed that was ready before role drift.
   */
  public function testCronDisablesPreviouslyReadyFeedAfterUnsafeRoleDrift(): void {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'service_request',
      'label' => 'Publish as CAP alert',
      'default_value' => [['value' => 0]],
    ])->save();
    markaspot_cap_install();
    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE));

    Role::create([
      'id' => 'unsafe_cap_drift',
      'label' => 'Unsafe CAP drift',
    ])->grantPermission('create field_cap_publish')->save();

    markaspot_cap_cron();

    $this->assertFalse((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE, FALSE));
    $this->assertStringContainsString(
      'Unsafe CAP drift (create field_cap_publish) is not listed',
      (string) $this->container->get('state')
        ->get(CapApprovalReadiness::ERROR_STATE),
    );
  }

  /**
   * A config allowlist permits only the explicit staff approval capability.
   */
  public function testUpdateAllowsConfiguredCapApproverRole(): void {
    $this->container->get('config.storage')->write('markaspot_cap.settings', [
      'approval_roles' => ['cap_approver'],
    ]);
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();
    Role::create([
      'id' => 'cap_approver',
      'label' => 'CAP approver',
    ])
      ->grantPermission('view field_cap_publish')
      ->grantPermission('edit field_cap_publish')
      ->save();

    markaspot_cap_update_11900();

    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE));
  }

  /**
   * Later staff approvals do not look like unaudited legacy approval data.
   */
  public function testCronKeepsReadyAfterConfiguredStaffApprovesReport(): void {
    $this->container->get('config.storage')->write('markaspot_cap.settings', [
      'approval_roles' => ['cap_approver'],
    ]);
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'service_request',
      'label' => 'Publish as CAP alert',
      'default_value' => [['value' => 0]],
    ])->save();
    Role::create([
      'id' => 'cap_approver',
      'label' => 'CAP approver',
    ])
      ->grantPermission('view field_cap_publish')
      ->grantPermission('edit field_cap_publish')
      ->save();

    markaspot_cap_install();
    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE));
    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::BOOTSTRAP_AUDITED_STATE));

    Node::create([
      'type' => 'service_request',
      'title' => 'Staff-approved alert',
      'field_cap_publish' => 1,
    ])->save();

    markaspot_cap_cron();

    $this->assertTrue((bool) $this->container->get('state')
      ->get(CapApprovalReadiness::READY_STATE));
  }

  /**
   * Provides every Field Permissions operation that can alter approval.
   */
  public static function legacyApprovalMutationPermissionProvider(): array {
    return [
      'create' => ['create field_cap_publish'],
      'edit own' => ['edit own field_cap_publish'],
      'edit' => ['edit field_cap_publish'],
    ];
  }

}
