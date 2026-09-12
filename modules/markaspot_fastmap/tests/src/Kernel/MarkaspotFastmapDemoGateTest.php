<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel test for the GDPR demo-compliance gate in hook_node_presave().
 *
 * Verifies markaspot_fastmap_node_presave() forces service_request nodes
 * unpublished while their jurisdiction workspace is in demo state (expiry
 * date set + no Stripe subscription). The gate must run even for admins,
 * even on publish transitions, and must release as soon as Stripe activates.
 *
 * Scope is service_request, never user records: only report visibility is
 * gated, not staff identity.
 *
 * @group markaspot_fastmap
 *
 * @covers ::markaspot_fastmap_node_presave
 * @covers ::_markaspot_fastmap_resolve_jurisdiction_group_for_node
 * @covers ::_markaspot_fastmap_workspace_in_demo_state
 */
#[RunTestsInSeparateProcesses]
class MarkaspotFastmapDemoGateTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

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
    'entity',
    'flexible_permissions',
    'group',
    'options',
    'datetime',
  ];

  /**
   * Permanent (production) jurisdiction group.
   */
  protected Group $permanentGroup;

  /**
   * Demo-state jurisdiction group (expiry set, no Stripe subscription).
   */
  protected Group $demoGroup;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('markaspot_nuxt.feature_scope_resolver', FeatureScopeResolver::class)
      ->setSynthetic(TRUE)
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $feature_scope_resolver = $this->createMock(FeatureScopeResolver::class);
    $feature_scope_resolver->method('isSelfServicePlatform')->willReturn(FALSE);
    $this->container->set('markaspot_nuxt.feature_scope_resolver', $feature_scope_resolver);

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);

    $this->createContentType(['type' => 'service_request', 'name' => 'Service Request']);

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();

    // field_jurisdiction on service_request -> jur group.
    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Jurisdiction',
      'settings' => [
        'handler' => 'default:group',
        'handler_settings' => ['target_bundles' => ['jur' => 'jur']],
      ],
    ])->save();

    // field_expiry_date on jur (timestamp).
    FieldStorageConfig::create([
      'field_name' => 'field_expiry_date',
      'entity_type' => 'group',
      'type' => 'timestamp',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_expiry_date',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Expiry date',
    ])->save();

    // field_stripe_subscription_id on jur (string).
    FieldStorageConfig::create([
      'field_name' => 'field_stripe_subscription_id',
      'entity_type' => 'group',
      'type' => 'string',
      'settings' => ['max_length' => 255],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_stripe_subscription_id',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Stripe subscription id',
    ])->save();

    // field_stripe_customer_id on jur (string). A customer is created before
    // checkout completes, so it must not by itself mark a demo as permanent.
    FieldStorageConfig::create([
      'field_name' => 'field_stripe_customer_id',
      'entity_type' => 'group',
      'type' => 'string',
      'settings' => ['max_length' => 255],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_stripe_customer_id',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Stripe customer id',
    ])->save();

    // Roles used in the tests.
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();

    // Permanent workspace: no expiry, no subscription. Not demo.
    $this->permanentGroup = Group::create([
      'type' => 'jur',
      'label' => 'Permanent Jur',
      'field_stripe_subscription_id' => 'sub_test_permanent',
    ]);
    $this->permanentGroup->save();

    // Demo workspace: expiry +5d, no Stripe subscription.
    $this->demoGroup = Group::create([
      'type' => 'jur',
      'label' => 'Demo Jur',
      'field_expiry_date' => time() + 5 * 86400,
      'field_stripe_customer_id' => 'cus_pending_checkout',
      'field_stripe_subscription_id' => '',
    ]);
    $this->demoGroup->save();

    // Load the module's procedural code without enabling the heavy dependency
    // tree. The demo gate only needs node/group entity API and the logger,
    // both already available in the kernel container.
    // __DIR__ is .../markaspot_fastmap/tests/src/Kernel, so up 3 levels.
    $module_path = dirname(__DIR__, 3) . '/markaspot_fastmap.module';
    require_once $module_path;
  }

  /**
   * Demo workspace: new published service_request is forced unpublished.
   */
  public function testNewReportUnpublishedInDemoMode(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Demo report',
      'status' => 1,
      'field_jurisdiction' => $this->demoGroup->id(),
    ]);

    markaspot_fastmap_node_presave($node);

    $this->assertFalse(
      $node->isPublished(),
      'New service_request in demo workspace must be set unpublished by the demo gate.'
    );
  }

  /**
   * A Stripe customer without a subscription is still a demo workspace.
   *
   * The public settings payload drives the demo badge and the onboarding
   * checklist. It must match the report publication gate exactly, otherwise
   * a pending checkout appears active while all reports remain unpublished.
   */
  public function testPendingCheckoutIsNotPermanentInPublicSettings(): void {
    $settings = ['jurisdiction' => []];

    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $this->demoGroup);

    $this->assertFalse($settings['jurisdiction']['is_permanent']);
    $this->assertTrue($settings['jurisdiction']['has_stripe_customer']);
  }

  /**
   * A completed checkout stays active even if its grace expiry is stale.
   *
   * The public settings payload, reminder loop, and deletion loop all use
   * the same demo predicate. A valid subscription therefore wins over an
   * old expiry timestamp and keeps the workspace safe from demo cleanup.
   */
  public function testSubscriptionWithStaleExpiryIsPermanent(): void {
    $activatedGroup = Group::create([
      'type' => 'jur',
      'label' => 'Activated with stale expiry',
      'field_expiry_date' => time() - 60,
      'field_stripe_subscription_id' => 'sub_active',
    ]);
    $activatedGroup->save();

    $settings = ['jurisdiction' => []];
    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $activatedGroup);

    $this->assertFalse(_markaspot_fastmap_workspace_in_demo_state($activatedGroup));
    $this->assertTrue($settings['jurisdiction']['is_permanent']);
  }

  /**
   * Permanent workspace: new published service_request stays published.
   */
  public function testNewReportPublishedInPermanentMode(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Permanent report',
      'status' => 1,
      'field_jurisdiction' => $this->permanentGroup->id(),
    ]);

    markaspot_fastmap_node_presave($node);

    $this->assertTrue(
      $node->isPublished(),
      'New service_request in permanent workspace must remain published.'
    );
  }

  /**
   * Demo workspace: admin save cannot bypass the gate.
   *
   * Demo compliance is a workspace property, not a submitter property.
   * Even users with 'administer nodes' or 'bypass mas validation' must
   * land in the moderation queue while the workspace is unactivated.
   */
  public function testAdminCannotBypassDemoGate(): void {
    $admin = $this->createUser(['administer nodes'], 'demo_admin', TRUE, [
      'roles' => ['administrator'],
    ]);
    $this->assertInstanceOf(User::class, $admin);

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($admin);
    try {
      $this->assertTrue(
        $this->container->get('current_user')->hasPermission('administer nodes'),
        'Sanity: switched-to user must hold administer nodes.'
      );

      $node = Node::create([
        'type' => 'service_request',
        'title' => 'Admin-saved demo report',
        'status' => 1,
        'field_jurisdiction' => $this->demoGroup->id(),
        'uid' => $admin->id(),
      ]);

      markaspot_fastmap_node_presave($node);

      $this->assertFalse(
        $node->isPublished(),
        'Admin save in demo workspace must not bypass the demo gate.'
      );
    }
    finally {
      $accountSwitcher->switchBack();
    }
  }

  /**
   * Demo workspace: publish transition from status=0 -> status=1 is blocked.
   *
   * Existing unpublished report manually toggled to published by tenant_admin
   * must be re-unpublished by the gate, because the workspace is still demo.
   */
  public function testPublishTransitionBlockedInDemoMode(): void {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Existing unpublished report',
      'status' => 0,
      'field_jurisdiction' => $this->demoGroup->id(),
    ]);
    $node->save();

    // Reload to ensure the saved-state original is the unpublished version.
    $reloaded = Node::load($node->id());
    $this->assertFalse($reloaded->isPublished(), 'Sanity: starts unpublished.');

    // Tenant action: manually publish.
    $reloaded->setPublished();
    $this->assertTrue($reloaded->isPublished(), 'Sanity: in-memory toggled to published.');

    markaspot_fastmap_node_presave($reloaded);

    $this->assertFalse(
      $reloaded->isPublished(),
      'Publish transition in demo workspace must be reverted to unpublished by the demo gate.'
    );
  }

  /**
   * Workspace activation flips the gate from blocking to allowing.
   *
   * Setting field_stripe_subscription_id (and clearing the expiry, per
   * checkout webhook) takes the workspace out of demo state. Re-saving the
   * existing report with status=1 must keep it published.
   */
  public function testReadyToPublishWhenStripeActivates(): void {
    // Step 1: save unpublished in demo state.
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Pending moderation report',
      'status' => 0,
      'field_jurisdiction' => $this->demoGroup->id(),
    ]);
    $node->save();

    // Step 2: Stripe activates the workspace.
    $this->demoGroup->set('field_stripe_subscription_id', 'sub_now_paid');
    $this->demoGroup->set('field_expiry_date', NULL);
    $this->demoGroup->save();

    // Sanity: helper now agrees the workspace is no longer demo.
    $this->assertFalse(
      _markaspot_fastmap_workspace_in_demo_state($this->demoGroup),
      'Workspace must leave demo state once Stripe subscription is recorded.'
    );

    // Step 3: tenant_admin publishes the queued report.
    $reloaded = Node::load($node->id());
    $reloaded->setPublished();

    markaspot_fastmap_node_presave($reloaded);

    $this->assertTrue(
      $reloaded->isPublished(),
      'Activated workspace must allow the existing report to be published.'
    );
  }

  /**
   * NULL-resolver fallback: raw Open311 jurisdiction_id catches the demo group.
   *
   * Reproduces the defense-in-depth path: when a citizen submits a report
   * without field_category and without field_jurisdiction prefilled, the
   * structured resolver returns NULL. The fallback must then load the group
   * from the raw jurisdiction_id query param and still enforce the gate.
   */
  public function testNullResolverFallsBackToRawJurisdictionId(): void {
    $request = Request::create('/api/test');
    $request->query->set('jurisdiction_id', (string) $this->demoGroup->id());
    \Drupal::requestStack()->push($request);

    // Note: deliberately no field_jurisdiction set on the node.
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'No field_jurisdiction set',
      'status' => 1,
    ]);

    markaspot_fastmap_node_presave($node);

    $this->assertFalse(
      $node->isPublished(),
      'Demo gate must fire via the raw-jurisdiction_id fallback when the structured resolver returns NULL.'
    );

    \Drupal::requestStack()->pop();
  }

  /**
   * NULL-resolver fallback rejects non-jurisdiction groups.
   *
   * Defends against an attacker pointing the raw jurisdiction_id at a group
   * that is not a `jur` bundle (e.g. an `org` department). The fallback's
   * _is_jurisdiction_group() guard must reject it so the gate stays inactive
   * and the report is NOT unpublished for the wrong reason.
   */
  public function testNullResolverFallbackRejectsNonJurisdictionGroup(): void {
    // Create an `org` group type and one instance.
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $orgGroup = Group::create(['type' => 'org', 'label' => 'Some Department']);
    $orgGroup->save();

    $request = Request::create('/api/test');
    $request->query->set('jurisdiction_id', (string) $orgGroup->id());
    \Drupal::requestStack()->push($request);

    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Org group pointer',
      'status' => 1,
    ]);

    markaspot_fastmap_node_presave($node);

    $this->assertTrue(
      $node->isPublished(),
      'Demo gate must NOT fire when raw jurisdiction_id points at a non-jur group.'
    );

    \Drupal::requestStack()->pop();
  }

  /**
   * Epoch-0 expiry timestamp is treated as not-in-demo.
   *
   * Edge case: a workspace with field_expiry_date literally set to 0 should
   * not be considered demo-state (epoch is in the past, not a future expiry).
   */
  public function testEpochZeroExpiryIsNotDemo(): void {
    $zeroGroup = Group::create([
      'type' => 'jur',
      'label' => 'Epoch zero',
      'field_expiry_date' => 0,
      'field_stripe_subscription_id' => '',
    ]);
    $zeroGroup->save();

    $this->assertFalse(
      _markaspot_fastmap_workspace_in_demo_state($zeroGroup),
      'Workspace with epoch-0 expiry must NOT be classified as demo state.'
    );
  }

  /**
   * Whitespace-only Stripe subscription id is treated as demo.
   *
   * Edge case: a subscription_id stored as "   " (whitespace) should not be
   * accepted as a valid activation marker. The gate must still fire.
   */
  public function testWhitespaceOnlySubscriptionIsDemo(): void {
    $whitespaceGroup = Group::create([
      'type' => 'jur',
      'label' => 'Whitespace sub id',
      'field_expiry_date' => time() + 86400,
      'field_stripe_subscription_id' => '   ',
    ]);
    $whitespaceGroup->save();

    $this->assertTrue(
      _markaspot_fastmap_workspace_in_demo_state($whitespaceGroup),
      'Whitespace-only subscription id must be treated as empty so the gate still fires.'
    );
  }

}
