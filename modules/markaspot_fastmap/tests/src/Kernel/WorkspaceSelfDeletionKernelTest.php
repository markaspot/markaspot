<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_fastmap\Service\WorkspaceDeletionService;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the self-service deletion of a FastMap workspace.
 *
 * @covers \Drupal\markaspot_fastmap\Service\WorkspaceDeletionService
 */
#[TestGroup('markaspot_fastmap')]
#[RunTestsInSeparateProcesses]
final class WorkspaceSelfDeletionKernelTest extends KernelTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'taxonomy',
    'options',
    'datetime',
    'language',
    'color_field',
    'geolocation',
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'flexible_permissions',
    'group',
    'gnode',
    'field_permissions',
    'markaspot_validation',
    'markaspot_group',
    'markaspot_nuxt',
    'markaspot_passwordless',
    'markaspot_tenant_admin',
    'markaspot_fastmap',
  ];

  /**
   * The workspace under test.
   */
  private GroupInterface $workspace;

  /**
   * Its tenant admin.
   */
  private UserInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entityTypes = [
      'user', 'node', 'file', 'taxonomy_term', 'paragraph',
      'group', 'group_relationship', 'group_config_wrapper',
    ];
    foreach ($entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'CivicSpot')->set('mail', 'site@example.test')->save();

    $this->createField('user', 'user', 'field_all_groups_member', 'boolean');
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'mail' => 'operator@example.test', 'status' => 1])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction', 'creator_membership' => FALSE])->save();
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    foreach (['tenant_admin', 'member', 'org_member'] as $role) {
      GroupRole::create([
        'id' => 'jur-' . $role,
        'label' => $role,
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
    }
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    foreach (['group_membership', 'group_node:service_request'] as $pluginId) {
      if (!$relationshipTypes->load('jur-' . str_replace(':', '-', $pluginId))) {
        $relationshipTypes->createFromPlugin(GroupType::load('jur'), $pluginId)->save();
      }
    }
    if (!$relationshipTypes->load('org-group_membership')) {
      $relationshipTypes->createFromPlugin(GroupType::load('org'), 'group_membership')->save();
    }
    $this->createField('group', 'org', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $groupFields = [
      'field_slug' => 'string',
      'field_tier' => 'string',
      'field_visibility' => 'string',
      'field_stripe_customer_id' => 'string',
      'field_stripe_subscription_id' => 'string',
      'field_boundary' => 'text_long',
      'field_nuxt_config' => 'text_long',
      'field_expiry_date' => 'timestamp',
    ];
    foreach ($groupFields as $name => $type) {
      $this->createField('group', 'jur', $name, $type);
    }
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', ['target_type' => 'group']);

    foreach (['service_category', 'service_status', 'internal_status'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
      $this->createField('taxonomy_term', $vid, 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    }
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    $this->createField('paragraph', 'status', 'field_status_term', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', ['target_type' => 'group']);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->workspace = $this->createWorkspace('Pothole Patrol', 'pothole-patrol', 'pro', 'sub_123');
    $this->admin = $this->createUser('admin@example.test');
    $this->workspace->addMember($this->admin, ['group_roles' => ['jur-tenant_admin']]);
  }

  /**
   * Only a tenant admin of this very workspace counts as its admin.
   */
  public function testOnlyOwnTenantAdminQualifies(): void {
    $service = $this->service();
    $this->assertTrue($service->isWorkspaceAdmin($this->workspace, $this->admin));

    $member = $this->createUser('member@example.test');
    $this->workspace->addMember($member, ['group_roles' => ['jur-member']]);
    $this->assertFalse($service->isWorkspaceAdmin($this->workspace, $member));

    $other = $this->createWorkspace('Other', 'other-ws', 'free');
    $foreignAdmin = $this->createUser('foreign@example.test');
    $other->addMember($foreignAdmin, ['group_roles' => ['jur-tenant_admin']]);
    $this->assertFalse($service->isWorkspaceAdmin($this->workspace, $foreignAdmin));
  }

  /**
   * A wrong slug and a tier set up by hand stop the request without a mail.
   */
  public function testRequestGuards(): void {
    $service = $this->service();
    $this->assertSame('slug_mismatch', $service->request($this->workspace, $this->admin, 'pothole', 'https://fastmap.test')['status']);

    $heart = $this->createWorkspace('Heart', 'heart-ws', 'heart');
    $heart->addMember($this->admin, ['group_roles' => ['jur-tenant_admin']]);
    $result = $service->request($heart, $this->admin, 'heart-ws', 'https://fastmap.test');
    $this->assertSame(['status' => 'not_eligible', 'reason' => 'support'], $result);

    $parent = $this->createWorkspace('Parent', 'parent-ws', 'free');
    $child = $this->createWorkspace('Child', 'child-ws', 'free');
    $child->set('field_parent_jurisdiction', $parent->id())->save();
    $this->assertSame('support', $service->ineligibilityReason($parent));

    $this->assertSame([], $this->getMails());
  }

  /**
   * Request, confirm and teardown after the grace period.
   */
  public function testConfirmedDeletionGoesOfflineAndIsTornDownAfterGracePeriod(): void {
    $service = $this->service();
    // Phone keyboards capitalize the first letter; that must not fail.
    $result = $service->request($this->workspace, $this->admin, ' Pothole-Patrol ', 'https://fastmap.test');
    $this->assertSame(['status' => 'sent', 'email' => 'a***@example.test'], $result);
    $token = $this->tokenFromMail();

    // Peeking does not redeem.
    $this->assertSame((int) $this->workspace->id(), (int) $service->peek($token)['group']->id());
    $this->assertNotNull($service->peek($token));

    // A stored subscription must be cancelled first; the link stays valid.
    $this->assertSame(['status' => 'subscription_active'], $service->confirm($token, FALSE));
    $this->assertNotNull($service->peek($token));

    $confirmed = $service->confirm($token, TRUE, 'help@example.test');
    $this->assertSame('scheduled', $confirmed['status']);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertSame($now + 7 * 86400, $confirmed['scheduled_for']);

    $group = $this->reloadWorkspace();
    $this->assertFalse($group->isPublished());
    $this->assertSame('blocked', $group->get('field_visibility')->value);
    $this->assertTrue($group->get('field_stripe_subscription_id')->isEmpty());
    $this->assertSame('free', $group->get('field_tier')->value);

    // The link works once, and a second request is refused while pending.
    $this->assertSame(['status' => 'invalid'], $service->confirm($token, TRUE));
    $this->assertSame(['status' => 'not_eligible', 'reason' => 'pending'], $service->request($group, $this->admin, 'pothole-patrol', 'https://fastmap.test'));

    $mails = $this->getMails();
    $this->assertSame(['workspace_deletion_confirm', 'workspace_deletion_scheduled', 'workspace_deletion_operator'], array_column($mails, 'key'));
    $this->assertSame('admin@example.test', $mails[1]['to']);
    $this->assertSame('operator@example.test', $mails[2]['to']);
    $this->assertSame('help@example.test', $mails[1]['params']['support_email']);
    $this->assertTrue($mails[1]['params']['paid']);

    // Nothing happens before the grace period ends.
    $this->assertSame(0, $service->processDue($confirmed['scheduled_for'] - 1));
    $this->assertNotNull($this->reloadWorkspace());

    $this->assertSame(1, $service->processDue($confirmed['scheduled_for']));
    $this->assertNull($this->reloadWorkspace());
    $this->assertNull($service->getSchedule((int) $this->workspace->id()));
  }

  /**
   * Support accounts in a workspace outlive its teardown; its members do not.
   */
  public function testTeardownKeepsPlatformAccounts(): void {
    $role = Role::create(['id' => 'editorial_board', 'label' => 'Editorial board']);
    $role->grantPermission('administer users')->save();
    $support = $this->createUser('support@example.test');
    $support->addRole('editorial_board');
    $support->save();
    $member = $this->createUser('member@example.test');
    $this->workspace->addMember($support, ['group_roles' => ['jur-member']]);
    $this->workspace->addMember($member, ['group_roles' => ['jur-member']]);

    $service = $this->service();
    $service->request($this->workspace, $this->admin, 'pothole-patrol', 'https://fastmap.test');
    $confirmed = $service->confirm($this->tokenFromMail(), TRUE);
    $this->assertSame(1, $service->processDue($confirmed['scheduled_for']));

    $users = $this->container->get('entity_type.manager')->getStorage('user');
    $users->resetCache();
    $this->assertNotNull($users->load($support->id()));
    $this->assertNull($users->load($member->id()));
    $this->assertNull($users->load($this->admin->id()));
  }

  /**
   * Restoring brings the workspace back and keeps it out of the teardown.
   */
  public function testRestoreCancelsScheduledDeletion(): void {
    $service = $this->service();
    $service->request($this->workspace, $this->admin, 'pothole-patrol', 'https://fastmap.test');
    $scheduled = $service->confirm($this->tokenFromMail(), TRUE)['scheduled_for'];

    $this->assertTrue($service->restore($this->reloadWorkspace()));
    $group = $this->reloadWorkspace();
    $this->assertTrue($group->isPublished());
    $this->assertSame('public', $group->get('field_visibility')->value);
    $this->assertSame('free', $group->get('field_tier')->value);
    $this->assertFalse($service->restore($group));

    $this->assertSame(0, $service->processDue($scheduled + 1));
    $this->assertNotNull($this->reloadWorkspace());
  }

  /**
   * A demo expiry is suspended while the deletion is scheduled.
   */
  public function testDemoExpiryIsSuspendedAndRestored(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $demo = $this->createWorkspace('Demo', 'demo-ws', '');
    $demo->set('field_expiry_date', $now + 3600)->save();
    $demo->addMember($this->admin, ['group_roles' => ['jur-tenant_admin']]);

    $service = $this->service();
    $this->assertNull($service->ineligibilityReason($demo));
    $service->request($demo, $this->admin, 'demo-ws', 'https://fastmap.test');
    $this->assertSame('scheduled', $service->confirm($this->tokenFromMail(), FALSE)['status']);

    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache();
    $scheduled = $storage->load($demo->id());
    $this->assertTrue($scheduled->get('field_expiry_date')->isEmpty());
    // Without a stored address the site mail is the restore contact.
    $mails = $this->getMails(['key' => 'workspace_deletion_scheduled']);
    $this->assertSame('site@example.test', end($mails)['params']['support_email']);
    $this->assertFalse(end($mails)['params']['paid'], 'A demo without subscription gets no subscription paragraph.');

    $this->assertTrue($service->restore($scheduled));
    $storage->resetCache();
    $this->assertGreaterThanOrEqual($now + 2 * 86400, (int) $storage->load($demo->id())->get('field_expiry_date')->value);
  }

  /**
   * The mails render from the shipped templates, translated where shipped.
   *
   * Needs this branch's .module and mail YAML; in a profile worktree the
   * installed module provides them, so run it on the merged checkout.
   */
  public function testDeletionMailsRender(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $german = $this->createUser('de@example.test');
    $german->set('preferred_langcode', 'de')->save();
    $this->workspace->addMember($german, ['group_roles' => ['jur-tenant_admin']]);

    $service = $this->service();
    $service->request($this->workspace, $german, 'pothole-patrol', 'https://fastmap.test');
    $confirmMail = $this->getMails(['key' => 'workspace_deletion_confirm'])[0];
    $this->assertStringContainsString('Pothole Patrol', $confirmMail['subject']);
    $this->assertStringContainsString($confirmMail['params']['confirm_url'], $confirmMail['body']);
    $this->assertStringContainsString('Sie haben die Löschung', $confirmMail['body']);
    // Mail bodies are wrapped; compare on collapsed whitespace.
    $this->assertStringContainsString('nach 7 Tagen', preg_replace('/\s+/', ' ', $confirmMail['body']));
    $this->assertStringNotContainsString('@minutes', $confirmMail['body']);

    $service->confirm(substr($confirmMail['params']['confirm_url'], -64), TRUE, 'help@example.test');
    $scheduledMail = $this->getMails(['key' => 'workspace_deletion_scheduled'])[0];
    $this->assertStringContainsString('help@example.test', $scheduledMail['body']);
    $scheduledBody = preg_replace('/\s+/', ' ', $scheduledMail['body']);
    $this->assertStringContainsString('Ihr Abonnement ist beendet', $scheduledBody);
    $this->assertMatchesRegularExpression('/Am \d{2}\.\d{2}\.\d{4} wird er/', $scheduledBody);
    $operatorMail = $this->getMails(['key' => 'workspace_deletion_operator'])[0];
    $this->assertStringContainsString('drush markaspot:workspace-restore ' . $this->workspace->id(), $operatorMail['body']);
  }

  /**
   * A workspace brought back online by hand is never torn down.
   */
  public function testWorkspaceOnlineAgainIsNotTornDown(): void {
    $service = $this->service();
    $service->request($this->workspace, $this->admin, 'pothole-patrol', 'https://fastmap.test');
    $scheduled = $service->confirm($this->tokenFromMail(), TRUE)['scheduled_for'];

    $this->reloadWorkspace()->setPublished()->save();
    $this->assertSame(0, $service->processDue($scheduled + 1));
    $this->assertNotNull($this->reloadWorkspace());
    $this->assertNull($service->getSchedule((int) $this->workspace->id()));
  }

  /**
   * A token stops working when its requester is no longer tenant admin.
   */
  public function testTokenRequiresRequesterToStayAdmin(): void {
    $service = $this->service();
    $service->request($this->workspace, $this->admin, 'pothole-patrol', 'https://fastmap.test');
    $token = $this->tokenFromMail();

    $this->workspace->removeMember($this->admin);
    $this->assertNull($service->peek($token));
    $this->assertSame(['status' => 'invalid'], $service->confirm($token, TRUE));
    $this->assertTrue($this->reloadWorkspace()->isPublished());
  }

  /**
   * Builds the service from the container's dependencies.
   *
   * Built directly because profile worktrees run kernel tests against the
   * installed module's service definitions.
   */
  private function service(): WorkspaceDeletionService {
    return new WorkspaceDeletionService(
      $this->container->get('entity_type.manager'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('keyvalue'),
      $this->container->get('lock'),
      $this->container->get('plugin.manager.mail'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('date.formatter'),
      $this->container->get('logger.channel.markaspot_fastmap'),
      $this->container->get('markaspot_fastmap.workspace_provisioning'),
      $this->container->get('entity_field.manager'),
    );
  }

  /**
   * Extracts the token from the last confirmation mail.
   */
  private function tokenFromMail(): string {
    $mails = array_values(array_filter($this->getMails(), static fn(array $mail) => $mail['key'] === 'workspace_deletion_confirm'));
    $this->assertNotEmpty($mails);
    $url = end($mails)['params']['confirm_url'];
    $this->assertMatchesRegularExpression('#^https://fastmap\.test/delete-workspace\#token=[0-9a-f]{64}$#', $url);
    return substr($url, -64);
  }

  /**
   * Reloads the workspace from storage, or NULL once it is gone.
   */
  private function reloadWorkspace(): ?GroupInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache();
    return $storage->load($this->workspace->id());
  }

  /**
   * Creates a workspace.
   */
  private function createWorkspace(string $label, string $slug, string $tier, string $subscription = ''): GroupInterface {
    $group = Group::create([
      'type' => 'jur',
      'label' => $label,
      'field_slug' => $slug,
      'field_tier' => $tier,
      'field_visibility' => 'public',
      'field_stripe_customer_id' => $subscription !== '' ? 'cus_123' : NULL,
      'field_stripe_subscription_id' => $subscription !== '' ? $subscription : NULL,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates an active user.
   */
  private function createUser(string $mail): UserInterface {
    $user = User::create(['name' => $mail, 'mail' => $mail, 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * Creates one configurable field and its storage.
   */
  private function createField(string $entityType, string $bundle, string $fieldName, string $type, array $storageSettings = []): void {
    if (!FieldStorageConfig::loadByName($entityType, $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => $entityType,
        'type' => $type,
        'settings' => $storageSettings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $fieldName,
    ])->save();
  }

}
