<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_validation\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests 'bypass mas validation' applies only in the account's jurisdictions.
 *
 * Runs the real constraint set of a service_request (as JSON:API and the
 * Open311 POST do before saving) for a citizen, a moderator of the target
 * tenant and a moderator of a foreign tenant.
 */
#[TestGroup('markaspot_validation')]
#[RunTestsInSeparateProcesses]
final class ValidationBypassMembershipScopeKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'filter',
    'text',
    'node',
    'taxonomy',
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    'geolocation',
    'markaspot_group',
    'markaspot_validation',
  ];

  /**
   * Disable uid 1 bypass so every account exercises its actual permissions.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * The target tenant (at its limits).
   */
  private Group $tenantB;

  /**
   * Moderator who belongs to tenant B.
   */
  private UserInterface $ownModerator;

  /**
   * Moderator who belongs to tenant A only.
   */
  private UserInterface $foreignModerator;

  /**
   * Citizen account without bypass.
   */
  private UserInterface $citizen;

  /**
   * Category used by all reports.
   */
  private Term $category;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'group', 'markaspot_validation']);

    FieldStorageConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_all_groups_member',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'All groups member',
    ])->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    Role::create(['id' => 'moderator', 'label' => 'Moderator'])
      ->grantPermission('bypass mas validation')
      ->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $relationshipTypes = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationshipTypes->load('jur-group_membership')) {
      $relationshipTypes->createFromPlugin(GroupType::load('jur'), 'group_membership')->save();
    }

    // Hierarchy lookups on group saves need the parent reference.
    FieldStorageConfig::create([
      'field_name' => 'field_parent_jurisdiction',
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_parent_jurisdiction',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Parent jurisdiction',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_boundary',
      'entity_type' => 'group',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_boundary',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Boundary',
    ])->save();

    Vocabulary::create(['vid' => 'service_category', 'name' => 'Categories'])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    if (!$relationshipTypes->load('jur-group_node-service_request')) {
      $relationshipTypes->createFromPlugin(GroupType::load('jur'), 'group_node:service_request')->save();
    }
    $this->createField('field_jurisdiction', 'entity_reference', ['target_type' => 'group'], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createField('field_category', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->createField('field_e_mail', 'email');
    $this->createField('field_geolocation', 'geolocation', [], 1, [['lat' => 52.5, 'lng' => 13.4]]);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $tenantA = Group::create(['type' => 'jur', 'label' => 'Tenant A']);
    $tenantA->save();
    $this->tenantB = Group::create(['type' => 'jur', 'label' => 'Tenant B']);
    $this->tenantB->save();

    $this->ownModerator = $this->account('own-moderator', ['moderator']);
    $this->tenantB->addMember($this->ownModerator);
    $this->foreignModerator = $this->account('foreign-moderator', ['moderator']);
    $tenantA->addMember($this->foreignModerator);
    $this->citizen = $this->account('citizen', []);

    $this->category = Term::create(['vid' => 'service_category', 'name' => 'Pothole']);
    $this->category->save();

    $this->config('markaspot_validation.settings')
      ->set('multiple_reports', TRUE)
      ->set('max_count', 1)
      ->set('defaultLocation', 1)
      ->set('duplicate_check', TRUE)
      ->set('hint', FALSE)
      ->set('unit', 'meters')
      ->set('radius', 50)
      ->set('days', 10)
      // No global service-area polygon: ValidLatLon stays out of the way.
      ->set('wkt', '')
      ->save();
  }

  /**
   * The 24 h e-mail limit applies to foreign moderators like to citizens.
   */
  public function testMultipleReportsLimit(): void {
    $this->saveExistingReport([
      'field_e_mail' => 'repeat@example.com',
      'field_geolocation' => ['lat' => 50.0, 'lng' => 8.0],
    ]);
    $values = ['field_e_mail' => 'repeat@example.com', 'field_geolocation' => ['lat' => 48.0, 'lng' => 11.0]];

    $this->assertViolationOn('field_e_mail', $this->citizen, $values);
    $this->assertViolationOn('field_e_mail', $this->foreignModerator, $values);
    $this->assertNoViolationOn('field_e_mail', $this->ownModerator, $values);
  }

  /**
   * The default-location rule applies to foreign moderators like to citizens.
   */
  public function testDefaultLocation(): void {
    $values = ['field_geolocation' => ['lat' => 52.5, 'lng' => 13.4]];

    $this->assertViolationOn('field_geolocation', $this->citizen, $values, 'Please select the address');
    $this->assertViolationOn('field_geolocation', $this->foreignModerator, $values, 'Please select the address');
    $this->assertNoViolationOn('field_geolocation', $this->ownModerator, $values);
  }

  /**
   * The duplicate check applies to foreign moderators like to citizens.
   */
  public function testDoublePost(): void {
    $location = ['lat' => 50.93, 'lng' => 6.95];
    $this->saveExistingReport(['field_geolocation' => $location, 'field_e_mail' => 'first@example.com']);
    $values = ['field_geolocation' => $location];

    $this->assertViolationOn('field_geolocation', $this->citizen, $values);
    $this->assertViolationOn('field_geolocation', $this->foreignModerator, $values);
    $this->assertNoViolationOn('field_geolocation', $this->ownModerator, $values);
  }

  /**
   * A foreign jurisdiction appended behind the own one still counts.
   */
  public function testEveryTargetJurisdictionMustBeInScope(): void {
    $values = [
      'field_geolocation' => ['lat' => 52.5, 'lng' => 13.4],
      'field_jurisdiction' => [['target_id' => $this->tenantB->id()], ['target_id' => 999]],
    ];
    $this->assertViolationOn('field_geolocation', $this->ownModerator, $values, 'Please select the address');
  }

  /**
   * Without any target the bypass is refused once jurisdictions exist.
   */
  public function testUnplacedReportIsValidatedForStaff(): void {
    $values = ['field_geolocation' => ['lat' => 52.5, 'lng' => 13.4], 'field_jurisdiction' => []];
    $this->assertViolationOn('field_geolocation', $this->ownModerator, $values, 'Please select the address');
  }

  /**
   * A district moderator filing into the tenant root keeps the bypass.
   *
   * The boundary places such a report in the district after validation.
   */
  public function testDistrictModeratorBypassesInOwnTenantTree(): void {
    $district = Group::create([
      'type' => 'jur',
      'label' => 'District B',
      'field_parent_jurisdiction' => $this->tenantB->id(),
    ]);
    $district->save();
    $districtModerator = $this->account('district-moderator', ['moderator']);
    $district->addMember($districtModerator);
    $values = ['field_geolocation' => ['lat' => 52.5, 'lng' => 13.4]];

    $this->assertNoViolationOn('field_geolocation', $districtModerator, $values);
    $this->assertViolationOn('field_geolocation', $this->foreignModerator, $values, 'Please select the address');
  }

  /**
   * A single-root site has no foreign tenant: the bypass stays global.
   *
   * Staff there may work without any group membership.
   */
  public function testSingleRootSiteKeepsTheGlobalBypass(): void {
    foreach (Group::loadMultiple() as $group) {
      if ((int) $group->id() !== (int) $this->tenantB->id()) {
        $group->delete();
      }
    }
    drupal_static_reset();
    $values = ['field_geolocation' => ['lat' => 52.5, 'lng' => 13.4]];
    $unaffiliated = $this->account('unaffiliated-moderator', ['moderator']);

    $this->assertNoViolationOn('field_geolocation', $unaffiliated, $values);
    $this->assertNoViolationOn('field_geolocation', $this->ownModerator, $values + ['field_jurisdiction' => []]);
    $this->assertViolationOn('field_geolocation', $this->citizen, $values, 'Please select the address');
  }

  /**
   * Asserts the report gets at least one violation on a field.
   */
  private function assertViolationOn(string $field, UserInterface $account, array $values, ?string $contains = NULL): void {
    $messages = $this->violationMessages($field, $account, $values);
    $this->assertNotEmpty($messages, sprintf('%s must not bypass the %s validation.', $account->getAccountName(), $field));
    if ($contains !== NULL) {
      $this->assertStringContainsString($contains, implode("\n", $messages));
    }
  }

  /**
   * Asserts the report has no violation on a field.
   */
  private function assertNoViolationOn(string $field, UserInterface $account, array $values): void {
    $this->assertSame([], $this->violationMessages($field, $account, $values), sprintf('%s should bypass the %s validation.', $account->getAccountName(), $field));
  }

  /**
   * Validates a new report for tenant B as the given account.
   *
   * @return string[]
   *   Violation messages on the field.
   */
  private function violationMessages(string $field, UserInterface $account, array $values): array {
    $this->container->get('current_user')->setAccount($account);
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'New report',
      'uid' => $account->id(),
      'field_jurisdiction' => [['target_id' => $this->tenantB->id()]],
      'field_category' => [['target_id' => $this->category->id()]],
    ]);
    $messages = [];
    foreach ($node->validate()->getByField($field) as $violation) {
      $messages[] = strip_tags((string) $violation->getMessage());
    }
    return $messages;
  }

  /**
   * Stores an earlier published report in tenant B.
   */
  private function saveExistingReport(array $values): NodeInterface {
    $past = \Drupal::time()->getRequestTime() - 3600;
    $node = Node::create($values + [
      'type' => 'service_request',
      'title' => 'Earlier report',
      'uid' => $this->citizen->id(),
      'status' => 1,
      'created' => $past,
      'changed' => $past,
      'field_jurisdiction' => [['target_id' => $this->tenantB->id()]],
      'field_category' => [['target_id' => $this->category->id()]],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates an account with the given Drupal roles.
   */
  private function account(string $name, array $roles): UserInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Creates a field on service_request.
   */
  private function createField(string $name, string $type, array $storageSettings = [], int $cardinality = 1, array $defaultValue = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $storageSettings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => $name,
      'default_value' => $defaultValue,
    ])->save();
  }

}
