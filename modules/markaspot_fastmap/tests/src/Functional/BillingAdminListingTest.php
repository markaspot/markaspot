<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;

/**
 * Functional coverage for /admin/markaspot/billing.
 *
 * Asserts the operator-only listing is reachable by administrator + uid=1
 * only, returns one row per jurisdiction, and applies state + tier filters
 * via query string. Verifies tenant_admin (the per-group role) does NOT see
 * the page — billing is operator turf.
 *
 * NOTE: This functional test depends on the full markaspot install profile
 * being installable under PHPUnit's BrowserTestBase. On dev environments
 * where the test database cannot bootstrap the full profile, the equivalent
 * access + filter assertions live in BillingAdminListingKernelTest at the
 * Kernel layer, which is the gate that must stay green in CI.
 *
 * @group markaspot_fastmap
 * @group skip-no-ci
 */
class BillingAdminListingTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'group',
    'markaspot_group',
    'markaspot_passwordless',
    'field_permissions',
    'taxonomy',
    'markaspot_fastmap',
  ];

  /**
   * Created group entities, keyed by label.
   *
   * @var array<string, \Drupal\group\Entity\GroupInterface>
   */
  protected array $groups = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install the jur group type and required billing fields if not present.
    if (!GroupType::load('jur')) {
      GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    }
    $this->ensureField('field_tier', 'list_string', [
      'allowed_values' => [
        'free' => 'free',
        'starter' => 'starter',
        'pro' => 'pro',
        'heart' => 'heart',
      ],
    ]);
    $this->ensureField('field_stripe_customer_id', 'string');
    $this->ensureField('field_stripe_subscription_id', 'string');
    $this->ensureField('field_expiry_date', 'timestamp');
    $this->ensureField('field_billing_email', 'string');
    $this->ensureField('field_slug', 'string');

    // Create test groups across all 5 lifecycle states.
    $this->groups['demo_one'] = $this->createJur('Demo One', 'demo-one', [
      'field_expiry_date' => strtotime('+30 days'),
    ]);
    $this->groups['demo_two'] = $this->createJur('Demo Two', 'demo-two', [
      'field_expiry_date' => strtotime('+14 days'),
    ]);
    $this->groups['pending'] = $this->createJur('Pending Checkout', 'pending', [
      'field_stripe_customer_id' => 'cus_pending_001',
    ]);
    $this->groups['paid_starter'] = $this->createJur('Paid Starter', 'paid-starter', [
      'field_tier' => 'starter',
      'field_stripe_customer_id' => 'cus_starter_001',
      'field_stripe_subscription_id' => 'sub_starter_001',
      'field_billing_email' => 'starter@example.com',
    ]);
    $this->groups['paid_pro'] = $this->createJur('Paid Pro', 'paid-pro', [
      'field_tier' => 'pro',
      'field_stripe_customer_id' => 'cus_pro_001',
      'field_stripe_subscription_id' => 'sub_pro_001',
      'field_billing_email' => 'pro@example.com',
    ]);
  }

  /**
   * Ensures a field is attached to the jur group bundle.
   */
  protected function ensureField(string $name, string $type, array $settings = []): void {
    $storage = \Drupal::entityTypeManager()->getStorage('field_storage_config');
    if (!$storage->load('group.' . $name)) {
      $storage->create([
        'field_name' => $name,
        'entity_type' => 'group',
        'type' => $type,
        'settings' => $settings,
      ])->save();
    }
    $configStorage = \Drupal::entityTypeManager()->getStorage('field_config');
    if (!$configStorage->load('group.jur.' . $name)) {
      $configStorage->create([
        'field_name' => $name,
        'entity_type' => 'group',
        'bundle' => 'jur',
        'label' => $name,
      ])->save();
    }
  }

  /**
   * Creates a jur group with the supplied billing field values.
   */
  protected function createJur(string $label, string $slug, array $fields): Group {
    $values = ['type' => 'jur', 'label' => $label, 'field_slug' => $slug];
    foreach ($fields as $key => $value) {
      $values[$key] = $value;
    }
    $group = Group::create($values);
    $group->save();
    return $group;
  }

  /**
   * Anonymous users are denied.
   */
  public function testAnonymousIsDenied(): void {
    $this->drupalGet(Url::fromRoute('markaspot_fastmap.billing_admin'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A tenant_admin (group role on jur) is denied.
   *
   * Models the actual threat: a user with the jur-tenant_admin group role on
   * one of their own jur groups, but NO site-level privileges. They must NOT
   * be able to load /admin/markaspot/billing — the operator listing crosses
   * tenant boundaries and is reserved for the platform operator.
   */
  public function testTenantAdminIsDenied(): void {
    // Ensure the jur-tenant_admin group role exists. The role normally ships
    // via markaspot_group config; create a fixture in the test scope so we
    // do not depend on full profile install.
    if (!GroupRole::load('jur-tenant_admin')) {
      GroupRole::create([
        'id' => 'jur-tenant_admin',
        'label' => 'Tenant administrator',
        'group_type' => 'jur',
        'scope' => 'individual',
      ])->save();
    }

    // Create a user with NO site-level privileges.
    $tenantAdmin = $this->drupalCreateUser([]);

    // Add the user to an existing jur group as a tenant_admin.
    $group = $this->groups['paid_starter'];
    $group->addMember($tenantAdmin, ['group_roles' => ['jur-tenant_admin']]);

    $this->drupalLogin($tenantAdmin);
    $this->drupalGet(Url::fromRoute('markaspot_fastmap.billing_admin'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Operator sees all rows.
   */
  public function testOperatorSeesAllRows(): void {
    $operator = $this->drupalCreateUser(['view fastmap billing admin']);
    $this->drupalLogin($operator);
    $this->drupalGet(Url::fromRoute('markaspot_fastmap.billing_admin'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Demo One');
    $this->assertSession()->pageTextContains('Paid Pro');
  }

  /**
   * State=paid filter excludes demo and pending rows.
   */
  public function testStateFilterPaid(): void {
    $operator = $this->drupalCreateUser(['view fastmap billing admin']);
    $this->drupalLogin($operator);
    $this->drupalGet(
      Url::fromRoute('markaspot_fastmap.billing_admin'),
      ['query' => ['state' => 'paid']]
    );
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Paid Starter');
    $this->assertSession()->pageTextNotContains('Demo One');
  }

  /**
   * Tier=starter filter narrows to one row.
   */
  public function testTierFilterStarter(): void {
    $operator = $this->drupalCreateUser(['view fastmap billing admin']);
    $this->drupalLogin($operator);
    $this->drupalGet(
      Url::fromRoute('markaspot_fastmap.billing_admin'),
      ['query' => ['tier' => 'starter']]
    );
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Paid Starter');
    $this->assertSession()->pageTextNotContains('Paid Pro');
  }

}
