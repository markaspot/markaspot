<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage of the jurisdiction staff gates in markaspot_dashboard.
 *
 * JSON:API serves a label-only user object (the username) for any user
 * reference a viewer may see, so the paragraph author must stay hidden from
 * everyone but dashboard managers of the request's jurisdiction. Internal
 * remarks follow the same rule: self-signup sites grant the remark
 * permissions through a global role to every workspace admin. The jurisdiction
 * resolver is stubbed like in GeoreportReadScopeKernelTest; the hook is
 * called directly because markaspot_dashboard is not installed (see
 * InternalRemarkAccessKernelTest).
 *
 * @group markaspot_dashboard
 *
 * @covers ::markaspot_dashboard_entity_field_access
 * @covers ::_markaspot_dashboard_paragraph_author_access
 * @covers ::_markaspot_dashboard_jurisdiction_staff_access
 * @covers ::markaspot_dashboard_entity_access
 */
#[RunTestsInSeparateProcesses]
final class ParagraphAuthorFieldAccessKernelTest extends KernelTestBase {

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
    'entity_reference_revisions',
    'file',
    'paragraphs',
    'options',
    'entity',
    'flexible_permissions',
    'group',
  ];

  /**
   * Jurisdiction group of the request under test.
   */
  private const JURISDICTION_ID = 7;

  /**
   * Jurisdiction a request is handed over to.
   */
  private const HANDOVER_JURISDICTION_ID = 8;

  /**
   * Jurisdiction the request currently resolves to.
   */
  private int $currentJurisdiction = self::JURISDICTION_ID;

  /**
   * Uids that are members of the handover jurisdiction.
   *
   * @var int[]
   */
  private array $handoverMembers = [];

  /**
   * Status note paragraph attached to a service request.
   */
  private Paragraph $note;

  /**
   * Internal remark paragraph attached to the same service request.
   */
  private Paragraph $remark;

  /**
   * The service request node.
   */
  private Node $request;

  /**
   * Uids that are members of the request's jurisdiction.
   *
   * @var int[]
   */
  private array $members = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');
    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['group']);

    require_once dirname(__DIR__, 3) . '/markaspot_dashboard.install';
    require_once dirname(__DIR__, 3) . '/markaspot_dashboard.module';

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    ParagraphsType::create(['id' => 'internal_remark', 'label' => 'Internal remark'])->save();
    _markaspot_dashboard_ensure_field_author('status');
    _markaspot_dashboard_ensure_field_author('internal_remark');

    $org_type = GroupType::create(['id' => 'org', 'label' => 'Organisation']);
    $org_type->save();
    $relationship_types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    if (!$relationship_types->load('org-group_membership')) {
      $relationship_types->createFromPlugin($org_type, 'group_membership')->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_organisation',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_organisation',
      'entity_type' => 'node',
      'bundle' => 'service_request',
    ])->save();

    foreach (['field_status_notes', 'field_internal_remark'] as $field_name) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference_revisions',
        'settings' => ['target_type' => 'paragraph'],
        'cardinality' => -1,
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'service_request',
      ])->save();
    }

    $this->note = Paragraph::create(['type' => 'status', 'field_author' => 1]);
    $this->remark = Paragraph::create(['type' => 'internal_remark', 'field_author' => 1]);
    $this->request = Node::create([
      'type' => 'service_request',
      'title' => 'Request',
      'field_status_notes' => [$this->note],
      'field_internal_remark' => [$this->remark],
    ]);
    $this->request->save();

    $processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $processor->method('resolveNodeJurisdictionId')->willReturnCallback(fn (): int => $this->currentJurisdiction);
    $processor->method('hasJurisdictionGroups')->willReturn(TRUE);
    $processor->method('isJurisdictionMember')->willReturnCallback(
      fn (?int $jid, $account = NULL): bool => in_array(
        (int) $account?->id(),
        $jid === self::HANDOVER_JURISDICTION_ID ? $this->handoverMembers : ($jid === self::JURISDICTION_ID ? $this->members : []),
        TRUE,
      )
    );
    $this->container->set('markaspot_open311.processor', $processor);
  }

  /**
   * Anonymous visitors never see the author.
   */
  public function testAnonymousIsForbidden(): void {
    $this->assertForbidden('view', new AnonymousUserSession());
  }

  /**
   * A self-signup tenant admin of another workspace is forbidden.
   *
   * This is the fastmap case: every workspace founder holds the global
   * dashboard permissions, so only jurisdiction membership separates them.
   */
  public function testManagerOfForeignJurisdictionIsForbidden(): void {
    $this->assertForbidden('view', $this->account(20, [
      'access open311 advanced properties',
      'view field_internal_remark',
    ]));
  }

  /**
   * A member without dashboard manager access is forbidden.
   */
  public function testMemberWithoutManagerPermissionIsForbidden(): void {
    $this->members = [21];
    $this->assertForbidden('view', $this->account(21, ['view field_internal_remark']));
  }

  /**
   * A dashboard manager in the request's jurisdiction sees the author.
   */
  public function testManagerOfRequestJurisdictionIsAllowed(): void {
    $this->members = [22];
    $this->assertNotForbidden('view', $this->account(22, ['access open311 advanced properties']));
  }

  /**
   * Site operators see authors across jurisdictions.
   */
  public function testOperatorIsAllowed(): void {
    $this->assertNotForbidden('view', $this->account(23, ['administer nodes']));
  }

  /**
   * Nobody may edit the author, not even members or operators.
   */
  public function testEditIsAlwaysForbidden(): void {
    $this->members = [22];
    $this->assertForbidden('edit', $this->account(22, ['access open311 advanced properties']));
    $this->assertForbidden('edit', $this->account(23, ['administer nodes']));
    $this->assertForbidden('edit', $this->account(1, ['administer nodes']));
  }

  /**
   * Without an entity context (filters, sorts) the author is forbidden.
   */
  public function testMissingEntityContextIsForbidden(): void {
    $definition = $this->note->get('field_author')->getFieldDefinition();
    $result = markaspot_dashboard_entity_field_access('view', $definition, $this->account(24, ['access open311 advanced properties']));
    $this->assertTrue($result->isForbidden());
  }

  /**
   * A tenant admin of another workspace cannot read internal remarks.
   */
  public function testRemarkForbiddenForForeignTenantAdmin(): void {
    $account = $this->account(30, ['view field_internal_remark', 'edit field_internal_remark']);
    $this->assertTrue($this->remarkAccess($account)->isForbidden());
    $this->assertTrue($this->remarkFieldAccess($account)->isForbidden());
  }

  /**
   * Staff of the request's jurisdiction read internal remarks.
   */
  public function testRemarkVisibleToStaffOfRequestJurisdiction(): void {
    $this->members = [31];
    $account = $this->account(31, ['view field_internal_remark']);
    $this->assertFalse($this->remarkAccess($account)->isForbidden());
    $this->assertFalse($this->remarkFieldAccess($account)->isForbidden());
  }

  /**
   * Members without the remark permission still cannot read remarks.
   */
  public function testRemarkForbiddenForMemberWithoutPermission(): void {
    $this->members = [32];
    $account = $this->account(32, ['access open311 advanced properties']);
    $this->assertTrue($this->remarkAccess($account)->isForbidden());
    $this->assertTrue($this->remarkFieldAccess($account)->isForbidden());
  }

  /**
   * Site operators read internal remarks across jurisdictions.
   */
  public function testRemarkVisibleToOperator(): void {
    $account = $this->account(33, ['administer nodes', 'view field_internal_remark']);
    $this->assertFalse($this->remarkAccess($account)->isForbidden());
    $this->assertFalse($this->remarkFieldAccess($account)->isForbidden());
  }

  /**
   * Members of the responsible organisation read remarks and authors.
   *
   * Covers organisation-only staff who are not members of the jurisdiction.
   */
  public function testOrganisationMemberOfRequestIsAllowed(): void {
    $member = User::create(['name' => 'org-staff']);
    $member->save();
    $organisation = Group::create(['type' => 'org', 'label' => 'Works department']);
    $organisation->save();
    $organisation->addMember($member);
    $this->request->set('field_organisation', $organisation)->save();

    $account = $this->account((int) $member->id(), [
      'view field_internal_remark',
      'access open311 advanced properties',
    ]);
    $this->assertFalse($this->remarkAccess($account)->isForbidden());
    $this->assertFalse($this->remarkFieldAccess($account)->isForbidden());
    $this->assertNotForbidden('view', $account);

    $outsider = $this->account(51, ['view field_internal_remark']);
    $this->assertTrue($this->remarkAccess($outsider)->isForbidden());
  }

  /**
   * After a handover the new jurisdiction sees earlier remarks.
   *
   * Staff of the previous jurisdiction lose them unless they are members of
   * the new one: access follows the current assignment, not where a remark
   * was written.
   */
  public function testHandoverMovesRemarkAccessToNewJurisdiction(): void {
    $this->members = [60];
    $this->handoverMembers = [61];
    $previous = $this->account(60, ['view field_internal_remark']);
    $next = $this->account(61, ['view field_internal_remark']);
    $this->assertFalse($this->remarkAccess($previous)->isForbidden());
    $this->assertTrue($this->remarkAccess($next)->isForbidden());

    $this->currentJurisdiction = self::HANDOVER_JURISDICTION_ID;

    $this->assertTrue($this->remarkAccess($previous)->isForbidden());
    $this->assertTrue($this->remarkFieldAccess($previous)->isForbidden());
    $this->assertFalse($this->remarkAccess($next)->isForbidden());
    $this->assertFalse($this->remarkFieldAccess($next)->isForbidden());
  }

  /**
   * A moderator without any membership no longer reads remarks.
   */
  public function testModeratorWithoutMembershipIsForbidden(): void {
    $account = $this->account(62, ['view field_internal_remark', 'edit field_internal_remark']);
    $this->assertTrue($this->remarkAccess($account)->isForbidden());
    $this->assertTrue($this->remarkFieldAccess($account)->isForbidden());
  }

  /**
   * Runs the entity access hook for viewing the remark paragraph.
   */
  private function remarkAccess(AccountInterface $account) {
    return markaspot_dashboard_entity_access(Paragraph::load($this->remark->id()), 'view', $account);
  }

  /**
   * Runs the field access hook for the request's remark reference field.
   */
  private function remarkFieldAccess(AccountInterface $account) {
    $items = Node::load($this->request->id())->get('field_internal_remark');
    return markaspot_dashboard_entity_field_access('view', $items->getFieldDefinition(), $account, $items);
  }

  /**
   * Asserts the hook forbids the operation for the account.
   */
  private function assertForbidden(string $operation, AccountInterface $account): void {
    $this->assertTrue($this->check($operation, $account)->isForbidden(), "$operation must be forbidden.");
  }

  /**
   * Asserts the hook leaves the operation to other access checks.
   */
  private function assertNotForbidden(string $operation, AccountInterface $account): void {
    $this->assertFalse($this->check($operation, $account)->isForbidden(), "$operation must not be forbidden.");
  }

  /**
   * Runs the field access hook for the note's author field.
   */
  private function check(string $operation, AccountInterface $account) {
    $items = Paragraph::load($this->note->id())->get('field_author');
    return markaspot_dashboard_entity_field_access($operation, $items->getFieldDefinition(), $account, $items);
  }

  /**
   * Builds an account mock with the given uid and permissions.
   *
   * @param int $uid
   *   The user id.
   * @param string[] $permissions
   *   Permissions the account holds.
   */
  private function account(int $uid, array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn(['authenticated']);
    $account->method('hasPermission')->willReturnCallback(
      fn (string $permission): bool => in_array($permission, $permissions, TRUE)
    );
    return $account;
  }

}
