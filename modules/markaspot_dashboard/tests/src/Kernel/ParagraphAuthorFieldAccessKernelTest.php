<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage of the field access gate on paragraph field_author.
 *
 * JSON:API serves a label-only user object (the username) for any user
 * reference a viewer may see, so the author must stay hidden from everyone
 * but dashboard managers of the request's jurisdiction. The jurisdiction
 * resolver is stubbed like in GeoreportReadScopeKernelTest; the hook is
 * called directly because markaspot_dashboard is not installed (see
 * InternalRemarkAccessKernelTest).
 *
 * @group markaspot_dashboard
 *
 * @covers ::markaspot_dashboard_entity_field_access
 * @covers ::_markaspot_dashboard_paragraph_author_access
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
  ];

  /**
   * Jurisdiction group of the request under test.
   */
  private const JURISDICTION_ID = 7;

  /**
   * Status note paragraph attached to a service request.
   */
  private Paragraph $note;

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

    require_once dirname(__DIR__, 3) . '/markaspot_dashboard.install';
    require_once dirname(__DIR__, 3) . '/markaspot_dashboard.module';

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    ParagraphsType::create(['id' => 'status', 'label' => 'Status'])->save();
    _markaspot_dashboard_ensure_field_author('status');

    FieldStorageConfig::create([
      'field_name' => 'field_status_notes',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'paragraph'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status_notes',
      'entity_type' => 'node',
      'bundle' => 'service_request',
    ])->save();

    $this->note = Paragraph::create(['type' => 'status', 'field_author' => 1]);
    Node::create([
      'type' => 'service_request',
      'title' => 'Request',
      'field_status_notes' => [$this->note],
    ])->save();

    $processor = $this->createMock(GeoreportProcessorServiceInterface::class);
    $processor->method('resolveNodeJurisdictionId')->willReturn(self::JURISDICTION_ID);
    $processor->method('hasJurisdictionGroups')->willReturn(TRUE);
    $processor->method('isJurisdictionMember')->willReturnCallback(
      fn (?int $jid, $account = NULL): bool => $jid === self::JURISDICTION_ID
        && in_array((int) $account?->id(), $this->members, TRUE)
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
