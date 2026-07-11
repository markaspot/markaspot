<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_cap\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves that CAP publication approval fails closed at field access.
 *
 * JSON:API uses entity field access during denormalization. These tests use
 * real field_permissions services and field entities rather than controller
 * mocks, so a citizen cannot set the staff-owned approval flag directly.
 *
 * @group markaspot_cap
 */
#[RunTestsInSeparateProcesses]
final class CapPublishFieldAccessKernelTest extends KernelTestBase {

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
    FieldStorageConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
      'third_party_settings' => [
        'field_permissions' => ['permission_type' => 'custom'],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cap_publish',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Publish as CAP alert',
      'default_value' => [['value' => 0]],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Citizen and unprivileged authenticated accounts cannot set approval.
   */
  public function testCitizenCreateCannotSetCapApproval(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Citizen report',
      'field_cap_publish' => 1,
    ]);

    foreach ([
      $this->account([], 0, ['anonymous']),
      $this->account(['create service_request content'], 7, ['authenticated']),
    ] as $account) {
      $access = $node->get('field_cap_publish')->access('edit', $account, TRUE);
      $this->assertTrue($access->isForbidden());
    }
  }

  /**
   * The default is off and only explicit staff permission enables it.
   */
  public function testDefaultIsOffAndExplicitStaffMayApprove(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Unapproved report',
    ]);

    $this->assertSame(0, $node->get('field_cap_publish')->value);
    $staff = $this->account(
      ['create field_cap_publish'],
      8,
      ['authenticated'],
    );
    $access = $node->get('field_cap_publish')->access('edit', $staff, TRUE);
    $this->assertFalse($access->isForbidden());
  }

  /**
   * Builds an account with deterministic roles and permissions.
   *
   * @param string[] $permissions
   *   Granted permission strings.
   * @param int $uid
   *   Account ID.
   * @param string[] $roles
   *   Role IDs, excluding the administrator bypass role.
   */
  private function account(
    array $permissions,
    int $uid,
    array $roles,
  ): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    return $account;
  }

}
