<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests moderator publication access without a global status permission.
 */
#[Group('service_request')]
final class ModeratorPublicationAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node',
    'service_request', 'service_request_status_access_test',
  ];

  /**
   * Disable uid 1 bypass so every account exercises its actual permissions.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);
    foreach (['service_request', 'article'] as $bundle) {
      NodeType::create(['type' => $bundle, 'name' => $bundle])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'integer',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Test jurisdiction',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Test status',
      'settings' => ['handler' => 'default:node'],
    ])->save();
    $this->container->get('config.storage')->write('markaspot_open311.settings', ['status_note_auto_create' => FALSE]);
  }

  /**
   * Status works on new reports; other bundles and admin fields stay protected.
   */
  public function testNewRequestStatusAndAdministrativeBoundaries(): void {
    $moderator = $this->account('moderator', TRUE);
    $node = Node::create(['type' => 'service_request', 'uid' => $moderator->id()]);
    $this->assertTrue($node->get('status')->access('edit', $moderator));
    $this->assertFalse($node->get('uid')->access('edit', $moderator));
    $this->assertFalse($node->get('created')->access('edit', $moderator));
    $this->assertFalse($moderator->hasPermission('administer node published status'));
    $this->assertFalse($moderator->hasPermission('administer nodes'));
    $this->assertFalse($moderator->hasPermission('bypass node access'));
    $article = Node::create(['type' => 'article']);
    $this->assertFalse($article->get('status')->access('edit', $moderator));
    $access = $node->get('status')->access('edit', $moderator, TRUE);
    $this->assertContains('user.roles', $access->getCacheContexts());
    $this->assertSame(0, $access->getCacheMaxAge());
  }

  /**
   * The role alone cannot grant creation, nor can non-moderators set status.
   */
  public function testMissingCreatePermissionAndNonModeratorStayDenied(): void {
    $moderator = $this->account('moderator', FALSE);
    $other = $this->account('other_staff', TRUE);
    $node = Node::create(['type' => 'service_request']);
    $this->assertFalse($node->get('status')->access('edit', $moderator));
    $this->assertFalse($node->get('status')->access('edit', $other));
  }

  /**
   * An existing entity denial remains decisive, including combined PATCHes.
   */
  public function testPersistedJurisdictionControlsStatusAccess(): void {
    $moderator = $this->account('moderator', TRUE);
    $owned = $this->request($moderator, 1);
    $foreign = $this->request($moderator, 2);
    $this->assertTrue($owned->get('status')->access('edit', $moderator));
    $this->assertFalse($foreign->get('status')->access('edit', $moderator));

    // The mutated entity would pass the test jurisdiction policy, but its
    // persisted source must still deny the publication-field change.
    $foreign->set('field_jurisdiction', 1);
    $foreign->setUnpublished();
    $this->assertFalse($foreign->get('status')->access('edit', $moderator));
    $foreign->original = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($foreign->id());
    $this->assertFalse($foreign->get('status')->access('edit', $moderator));
  }

  /**
   * Deployments without the moderator role do not acquire one from this hook.
   */
  public function testAbsentModeratorRoleIsNotCreated(): void {
    $other = $this->account('other_staff', TRUE);
    $node = Node::create(['type' => 'service_request']);
    $this->assertFalse($node->get('status')->access('edit', $other));
    $this->assertNull(Role::load('moderator'));
  }

  /**
   * Builds an account with explicit, limited entity permissions.
   */
  private function account(string $role_id, bool $can_create): UserInterface {
    $permissions = ['access content', 'edit own service_request content', 'create article content'];
    if ($can_create) {
      $permissions[] = 'create service_request content';
    }
    Role::create(['id' => $role_id, 'label' => $role_id, 'permissions' => $permissions])->save();
    $user = User::create(['name' => $role_id, 'status' => 1, 'roles' => [$role_id]]);
    $user->save();
    return $user;
  }

  /**
   * Saves and reloads a report without an in-memory original snapshot.
   */
  private function request(UserInterface $owner, int $jurisdiction): Node {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Publication access test',
      'uid' => $owner->id(),
      'field_jurisdiction' => $jurisdiction,
    ]);
    $node->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

}
