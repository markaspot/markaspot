<?php

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Guards the cross-tenant report re-homing authorization check.
 *
 * Markaspot_group_node_presave() must refuse to move an existing
 * service_request into a jurisdiction the acting user does not manage.
 * Without the guard, a staff user of one tenant could repoint
 * field_jurisdiction (e.g. via JSON:API, which exposes the field without a
 * field-level access check) to a foreign tenant and publish content under
 * that tenant's name.
 *
 * The enforcement lives in the procedural presave hook and its helper, both of
 * which call the static GroupMembership::loadByUser() and the service
 * container, so they cannot be exercised in a plain PHPUnit unit test. This
 * test locks the guard's structure in place (mirroring the source-assertion
 * approach already used for other procedural sync logic in this module). The
 * behavioural proof is a live check on a seeded multi-jurisdiction instance:
 * a jur-1 editorial user is blocked from moving a jur-1 report into jur-5,
 * while the same user moving it into their own jur-4 and a jur-5 admin moving
 * it into jur-5 both succeed.
 *
 * @group markaspot_group
 */
class ServiceRequestJurisdictionAuthzTest extends UnitTestCase {

  /**
   * The module source under assertion.
   *
   * @var string
   */
  protected string $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_group.module');
  }

  /**
   * The authorization helper mirrors the tenant-settings access boundary.
   */
  public function testHelperEnforcesManagedJurisdictionScope(): void {
    $helperPos = strpos($this->source, 'function _markaspot_group_user_can_manage_jurisdiction(AccountInterface $account, int $jurisdiction_id): bool');
    $this->assertNotFalse($helperPos, 'Authorization helper must exist.');

    // User 1 and Drupal administrators bypass (also covers drush/migrations).
    $this->assertStringContainsString("(int) \$account->id() === 1 || in_array('administrator', \$account->getRoles(), TRUE)", $this->source);

    // Only service-request-managing jurisdiction roles qualify.
    $this->assertStringContainsString("\$group_type . '-tenant_admin'", $this->source);
    $this->assertStringContainsString("'jur-tenant_admin'", $this->source);
    $this->assertStringContainsString("\$group_type . '-editorial'", $this->source);
    $this->assertStringContainsString("'jur-editorial'", $this->source);

    // Membership scope is hierarchy-aware (self + descendants).
    $this->assertStringContainsString('GroupMembershipEntity::loadByUser($user, $manage_roles)', $this->source);
    $this->assertStringContainsString('$hierarchy_resolver->getDescendantIds((int) $group->id())', $this->source);
    $this->assertStringContainsString('in_array($jurisdiction_id, $scope_ids, TRUE)', $this->source);
  }

  /**
   * Presave blocks unauthorized cross-jurisdiction moves of existing reports.
   */
  public function testPresaveGuardsCrossJurisdictionMove(): void {
    // The change branch only runs for existing nodes, so citizen intake
    // (isNew) never reaches the guard.
    $branchPos = strpos($this->source, "if (!\$node->isNew() && \$node->hasField('field_jurisdiction')) {");
    $guardPos = strpos($this->source, '_markaspot_group_unauthorized_jurisdiction_target($node, \Drupal::currentUser()) !== NULL');
    $throwPos = strpos($this->source, "throw new AccessDeniedHttpException('You are not authorized to move this report into the selected jurisdiction.')");
    $syncPos = strpos($this->source, '$node->_markaspot_group_jur_sync_needed = TRUE;');

    $this->assertNotFalse($branchPos, 'Change branch must be limited to existing nodes.');
    $this->assertNotFalse($guardPos, 'Guard must call the set-based authorization predicate.');
    $this->assertNotFalse($throwPos, 'Guard must reject unauthorized moves.');
    $this->assertNotFalse($syncPos);

    // Guard runs inside the branch and before the sync is scheduled.
    $this->assertGreaterThan($branchPos, $guardPos);
    $this->assertLessThan($throwPos, $guardPos);
    $this->assertLessThan($syncPos, $throwPos);
  }

  /**
   * The authorization predicate compares the full jurisdiction target set.
   */
  public function testAuthorizationComparesFullTargetSet(): void {
    $predicatePos = strpos($this->source, 'function _markaspot_group_unauthorized_jurisdiction_target(NodeInterface $node, AccountInterface $account): ?int');
    $this->assertNotFalse($predicatePos, 'Shared set-based predicate must exist.');

    // New reports (citizen intake) are exempt.
    $this->assertStringContainsString('if ($node->isNew()) {', $this->source);

    // Compares the multi-value target set, not just the first delta, so a
    // foreign jurisdiction appended behind the unchanged own one is caught.
    $this->assertStringContainsString("_markaspot_group_field_target_ids(\$node, 'field_jurisdiction')", $this->source);
    $this->assertStringContainsString('foreach (array_diff($new_ids, $original_ids) as $added) {', $this->source);
    $this->assertStringContainsString('if (!_markaspot_group_user_can_manage_jurisdiction($account, (int) $added)) {', $this->source);

    // Stand-alone validation (before save) falls back to the persisted entity.
    $this->assertStringContainsString('$node->original ?? \Drupal::entityTypeManager()', $this->source);
  }

  /**
   * The exception import is present so the guard resolves at runtime.
   */
  public function testAccessDeniedExceptionIsImported(): void {
    $this->assertStringContainsString('use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;', $this->source);
  }

  /**
   * A field constraint gives validated write paths a clean 422.
   */
  public function testJurisdictionAuthzConstraintIsAttachedAndBacked(): void {
    // Constraint attached to field_jurisdiction on service_request.
    $this->assertStringContainsString("\$fields['field_jurisdiction']->addConstraint('ServiceRequestJurisdictionAuthz');", $this->source);

    // Backing helper delegates to the shared set-based predicate.
    $backingPos = strpos($this->source, 'function _markaspot_group_service_request_jurisdiction_move_authorized(NodeInterface $node): bool');
    $this->assertNotFalse($backingPos, 'Constraint backing helper must exist.');
    $this->assertStringContainsString('return _markaspot_group_unauthorized_jurisdiction_target($node, \Drupal::currentUser()) === NULL;', $this->source);

    // Constraint + validator plugin classes exist.
    $dir = dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/';
    $this->assertFileExists($dir . 'ServiceRequestJurisdictionAuthzConstraint.php');
    $this->assertFileExists($dir . 'ServiceRequestJurisdictionAuthzConstraintValidator.php');
    $validator = file_get_contents($dir . 'ServiceRequestJurisdictionAuthzConstraintValidator.php');
    $this->assertStringContainsString('\_markaspot_group_service_request_jurisdiction_move_authorized($node)', $validator);
  }

}
