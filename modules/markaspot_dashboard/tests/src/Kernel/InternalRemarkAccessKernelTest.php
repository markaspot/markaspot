<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\User;

/**
 * Kernel coverage of the authorship gate on internal_remark paragraphs.
 *
 * The pure-mock unit test (InternalRemarkAccessTest) only exercises the bare
 * permission check on a mocked entity. This kernel test wires up REAL paragraph
 * entities with a real field_author, so the second-layer gate (author-or-admin
 * on update/delete) and the create-access hook are exercised against the same
 * entity-field API the JSON:API path would call into.
 *
 * The accounts themselves are still mocked so the test does not need to drag
 * in `field_permissions` (and the rest of the profile dependency tree) just
 * to register one permission string.
 *
 * Hooks are invoked directly — markaspot_dashboard is not installed because
 * its full dependency graph (node, group, markaspot_open311, markaspot_nuxt,
 * jsonapi_extras) is irrelevant to the access-hook contract. The fastmap
 * kernel test in this profile uses the same require_once-and-call pattern.
 *
 * Hardening landed in 896e17c6.
 *
 * @group markaspot_dashboard
 *
 * @covers ::markaspot_dashboard_entity_access
 * @covers ::markaspot_dashboard_entity_create_access
 */
final class InternalRemarkAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_reference_revisions',
    'file',
    'paragraphs',
  ];

  /**
   * Permission strings used in the matrix.
   */
  private const PERM_EDIT = 'edit field_internal_remark';
  private const PERM_ADMIN_NODES = 'administer nodes';

  /**
   * The internal_remark paragraph used by every authorship test.
   */
  private Paragraph $remark;

  /**
   * Uid of the paragraph's author.
   */
  private int $authorUid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'user', 'field', 'filter']);

    // Two bundles so we can prove the gate is bundle-scoped.
    ParagraphsType::create([
      'id' => 'internal_remark',
      'label' => 'Internal remark',
    ])->save();
    ParagraphsType::create([
      'id' => 'status',
      'label' => 'Status',
    ])->save();

    // field_author (entity_reference -> user) on the internal_remark bundle.
    FieldStorageConfig::create([
      'field_name' => 'field_author',
      'entity_type' => 'paragraph',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_author',
      'entity_type' => 'paragraph',
      'bundle' => 'internal_remark',
      'label' => 'Author',
      'settings' => [
        'handler' => 'default:user',
        'handler_settings' => [],
      ],
    ])->save();

    // Reserve uid 1 (superuser bypass) so test users get real, gated uids.
    User::create(['uid' => 1, 'name' => 'admin-uid1'])->save();
    $author = User::create(['name' => 'alice-author']);
    $author->save();
    $this->authorUid = (int) $author->id();

    $this->remark = Paragraph::create([
      'type' => 'internal_remark',
      'field_author' => $this->authorUid,
    ]);
    $this->remark->save();

    // Load the module's procedural code; the hook implementations are the
    // System Under Test and the module is intentionally not enabled (its
    // dependency tree — node, groups, jsonapi_extras — is not relevant to
    // the access-hook contract).
    require_once dirname(__DIR__, 3) . '/markaspot_dashboard.module';
  }

  /**
   * Builds a mock account with deterministic permission and id.
   *
   * @param string[] $permissions
   *   Permissions held by the account.
   * @param int $uid
   *   Uid the mock should report. The default (0) ensures account!=author.
   */
  private function makeAccount(array $permissions, int $uid = 0): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $p): bool => in_array($p, $permissions, TRUE));
    $account->method('id')->willReturn($uid);
    return $account;
  }

  /**
   * Update by a non-author staff member with the bare grant is forbidden.
   *
   * Core IDOR scenario: dispatcher Bob must not be able to silently rewrite
   * dispatcher Alice's remark via a direct JSON:API PATCH that skips the
   * custom InternalRemarkController.
   */
  public function testUpdateForbiddenForNonAuthorStaff(): void {
    $intruder = $this->makeAccount([self::PERM_EDIT], uid: $this->authorUid + 100);

    $result = markaspot_dashboard_entity_access($this->remark, 'update', $intruder);

    $this->assertTrue(
      $result->isForbidden(),
      'Non-author staff editor must be forbidden from updating the remark.'
    );
  }

  /**
   * Author with the edit permission may update their own remark.
   *
   * The hook returns AccessResult::neutral() — i.e. it does not deny — and
   * leaves the final decision to default entity access.
   */
  public function testUpdateNeutralForAuthorWithPermission(): void {
    $author = $this->makeAccount([self::PERM_EDIT], uid: $this->authorUid);

    $result = markaspot_dashboard_entity_access($this->remark, 'update', $author);

    $this->assertFalse($result->isForbidden(), 'Author update must not be forbidden.');
    $this->assertTrue($result->isNeutral(), 'Author update must come back neutral.');
  }

  /**
   * `administer nodes` lets any user update any remark.
   */
  public function testUpdateNeutralForAdministerNodes(): void {
    $admin = $this->makeAccount(
      [self::PERM_EDIT, self::PERM_ADMIN_NODES],
      uid: $this->authorUid + 200
    );

    $result = markaspot_dashboard_entity_access($this->remark, 'update', $admin);

    $this->assertFalse(
      $result->isForbidden(),
      'A user holding `administer nodes` must be allowed to update any internal_remark.'
    );
    $this->assertTrue($result->isNeutral());
  }

  /**
   * Update gate fires before authorship: no permission is still forbidden.
   *
   * Belt-and-braces: even the author cannot pass the gate without
   * `edit field_internal_remark`. The authorship branch must not silently
   * grant access in isolation.
   */
  public function testUpdateForbiddenWithoutEditPermissionEvenIfAuthor(): void {
    $author = $this->makeAccount(permissions: [], uid: $this->authorUid);

    $result = markaspot_dashboard_entity_access($this->remark, 'update', $author);

    $this->assertTrue(
      $result->isForbidden(),
      'Without `edit field_internal_remark` even the author cannot update.'
    );
  }

  /**
   * Delete by a non-author staff member with the bare grant is forbidden.
   */
  public function testDeleteForbiddenForNonAuthorStaff(): void {
    $intruder = $this->makeAccount([self::PERM_EDIT], uid: $this->authorUid + 100);

    $result = markaspot_dashboard_entity_access($this->remark, 'delete', $intruder);

    $this->assertTrue(
      $result->isForbidden(),
      'Non-author staff editor must be forbidden from deleting the remark.'
    );
  }

  /**
   * Author with edit permission may delete their own remark.
   */
  public function testDeleteNeutralForAuthorWithPermission(): void {
    $author = $this->makeAccount([self::PERM_EDIT], uid: $this->authorUid);

    $result = markaspot_dashboard_entity_access($this->remark, 'delete', $author);

    $this->assertFalse($result->isForbidden(), 'Author delete must not be forbidden.');
    $this->assertTrue($result->isNeutral(), 'Author delete must come back neutral.');
  }

  /**
   * `administer nodes` lets any user delete any remark.
   */
  public function testDeleteNeutralForAdministerNodes(): void {
    $admin = $this->makeAccount(
      [self::PERM_EDIT, self::PERM_ADMIN_NODES],
      uid: $this->authorUid + 200
    );

    $result = markaspot_dashboard_entity_access($this->remark, 'delete', $admin);

    $this->assertFalse(
      $result->isForbidden(),
      'A user holding `administer nodes` must be allowed to delete any internal_remark.'
    );
    $this->assertTrue($result->isNeutral());
  }

  /**
   * Author binding survives an entity reload from storage.
   *
   * Regression net for the `field_author->target_id` read path the hook uses
   * — if the field name or property ever changes, this catches it even when
   * the in-memory entity still happens to satisfy the gate.
   */
  public function testAuthorMatchAfterReload(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $storage->resetCache();
    $reloaded = $storage->load($this->remark->id());

    $author = $this->makeAccount([self::PERM_EDIT], uid: $this->authorUid);

    $result = markaspot_dashboard_entity_access($reloaded, 'update', $author);

    $this->assertTrue($result->isNeutral(), 'Reloaded paragraph must still resolve its author for the gate.');
  }

  /**
   * Anonymous-style account (uid 0) with no permissions is forbidden.
   *
   * Specifically guards against the edge case where the author field is
   * empty/0 on the paragraph and uid 0 on the account — those must NOT be
   * treated as matching authorship.
   */
  public function testZeroAuthorDoesNotMatchAnonymousUid(): void {
    $authorless = Paragraph::create(['type' => 'internal_remark']);
    $authorless->save();

    $account = $this->makeAccount([self::PERM_EDIT], uid: 0);
    $result = markaspot_dashboard_entity_access($authorless, 'update', $account);

    $this->assertTrue(
      $result->isForbidden(),
      'A paragraph with no author must never accidentally match uid 0.'
    );
  }

  /**
   * Hook_entity_create_access blocks creation without edit permission.
   *
   * Calls the hook directly. The full createAccess() dispatcher would
   * require markaspot_dashboard to be enabled (and thus its whole profile
   * dependency tree); the hook contract is identical either way.
   */
  public function testCreateForbiddenWithoutEditPermission(): void {
    $account = $this->makeAccount(permissions: []);

    $result = markaspot_dashboard_entity_create_access(
      $account,
      ['entity_type_id' => 'paragraph'],
      'internal_remark'
    );

    $this->assertTrue(
      $result->isForbidden(),
      'Users without `edit field_internal_remark` must be blocked from creating internal_remark paragraphs.'
    );
  }

  /**
   * Hook_entity_create_access allows creation with edit permission.
   */
  public function testCreateNeutralWithEditPermission(): void {
    $account = $this->makeAccount([self::PERM_EDIT]);

    $result = markaspot_dashboard_entity_create_access(
      $account,
      ['entity_type_id' => 'paragraph'],
      'internal_remark'
    );

    $this->assertFalse(
      $result->isForbidden(),
      'Users with `edit field_internal_remark` must not be blocked by this hook.'
    );
  }

  /**
   * Hook_entity_create_access leaves other paragraph bundles alone.
   */
  public function testCreateOnOtherBundleIsNeutral(): void {
    $account = $this->makeAccount(permissions: []);

    $result = markaspot_dashboard_entity_create_access(
      $account,
      ['entity_type_id' => 'paragraph'],
      'status'
    );

    $this->assertTrue(
      $result->isNeutral(),
      'The create-access hook must remain neutral for non-internal_remark bundles.'
    );
  }

  /**
   * Hook_entity_create_access leaves other entity types alone.
   */
  public function testCreateOnOtherEntityTypeIsNeutral(): void {
    $account = $this->makeAccount(permissions: []);

    $result = markaspot_dashboard_entity_create_access(
      $account,
      ['entity_type_id' => 'node'],
      'internal_remark'
    );

    $this->assertTrue(
      $result->isNeutral(),
      'The create-access hook must remain neutral for non-paragraph entity types.'
    );
  }

}
