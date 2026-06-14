<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\InboundMailAccessControlHandler;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Kernel test for the jurisdiction-scoped inbound_mail access handler (#482).
 *
 * Proves the Phase 2 scoping contract:
 * - a jur member with the triage permission may view/update/delete the
 *   jurisdiction's mail,
 * - a triage holder OUTSIDE the jurisdiction is denied,
 * - a global admin (administrator role) bypasses the scope,
 * - a mail WITHOUT a jurisdiction is permission-only,
 * - without the triage permission everything is denied,
 * - without the scope validator scoped mail fails closed.
 *
 * The scope validator is the registered stub (see
 * StubJurisdictionScopeValidator), exercising the container-built handler
 * end-to-end via $entity->access().
 *
 * @group markaspot_mail_inbound
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class InboundMailAccessHandlerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'options',
    'taxonomy',
    'file',
    'entity',
    'flexible_permissions',
    'group',
    'markaspot_mail_inbound',
  ];

  /**
   * The first jurisdiction group id.
   */
  protected int $gidA;

  /**
   * The second jurisdiction group id.
   */
  protected int $gidB;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The real service id, so InboundMailAccessControlHandler::createInstance
    // picks the stub up exactly like the production validator. Must be
    // public: nothing references it at compile time, and a private
    // unreferenced definition is removed — $container->has() would then be
    // FALSE and the handler would exercise the fail-closed fallback instead
    // of the scoped path.
    $container->register('markaspot_group.jurisdiction_scope_validator', StubJurisdictionScopeValidator::class)
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('inbound_mail');
    $this->installConfig(['user', 'group']);

    StubJurisdictionScopeValidator::$map = [];

    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    // Consume uid 1: core's super-user policy grants it everything, which
    // would short-circuit every denial assertion below.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();
    $a = Group::create(['type' => 'jur', 'label' => 'City A']);
    $a->save();
    $this->gidA = (int) $a->id();
    $b = Group::create(['type' => 'jur', 'label' => 'City B']);
    $b->save();
    $this->gidB = (int) $b->id();

    Role::create(['id' => 'triage', 'label' => 'Triage'])
      ->grantPermission('triage inbound mail')
      ->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
  }

  /**
   * Creates a user with roles and registers their jurisdiction scope.
   *
   * @param string[] $roles
   *   Role ids.
   * @param int[] $jurisdictions
   *   Allowed jurisdiction gids for the stub validator.
   */
  protected function user(array $roles, array $jurisdictions = []): UserInterface {
    $user = User::create([
      'name' => 'user' . random_int(100000, 999999),
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    StubJurisdictionScopeValidator::$map[(int) $user->id()] = $jurisdictions;
    return $user;
  }

  /**
   * Creates a saved inbound mail in the given jurisdiction (0 = none).
   */
  protected function mail(int $gid): InboundMail {
    $values = [
      'from_address' => 'citizen@example.org',
      'subject' => 'Broken light',
      'body' => ['value' => 'A streetlight is broken.', 'format' => 'plain_text'],
      'state' => InboundMail::STATE_STAGED,
    ];
    if ($gid > 0) {
      $values['jurisdiction_id'] = ['target_id' => $gid];
    }
    /** @var \Drupal\markaspot_mail_inbound\Entity\InboundMail $mail */
    $mail = $this->container->get('entity_type.manager')->getStorage('inbound_mail')->create($values);
    $mail->save();
    return $mail;
  }

  /**
   * A jur member with the permission may handle the jurisdiction's mail.
   */
  public function testMemberWithPermissionIsAllowed(): void {
    $member = $this->user(['triage'], [$this->gidA]);
    $mail = $this->mail($this->gidA);

    foreach (['view', 'update', 'delete'] as $op) {
      $this->assertTrue($mail->access($op, $member), "Member may $op their jurisdiction's mail.");
    }

    $result = $mail->access('view', $member, TRUE);
    $this->assertTrue($result->isAllowed());
    $this->assertContains($this->membershipCacheTag($member), $result->getCacheTags());
  }

  /**
   * A triage holder outside the jurisdiction is denied.
   */
  public function testOutsiderWithPermissionIsDenied(): void {
    $outsider = $this->user(['triage'], [$this->gidB]);
    $mail = $this->mail($this->gidA);

    foreach (['view', 'update', 'delete'] as $op) {
      $this->assertFalse($mail->access($op, $outsider), "An outsider may NOT $op a foreign jurisdiction's mail.");
    }

    $result = $mail->access('view', $outsider, TRUE);
    $this->assertTrue($result->isForbidden());
    $this->assertContains($this->membershipCacheTag($outsider), $result->getCacheTags());
  }

  /**
   * A global administrator bypasses the jurisdiction scope.
   */
  public function testGlobalAdminBypassesScope(): void {
    $admin = $this->user(['administrator'], []);

    $this->assertTrue($this->mail($this->gidA)->access('view', $admin));
    $this->assertTrue($this->mail($this->gidB)->access('update', $admin));
    $this->assertTrue($this->mail(0)->access('delete', $admin));
  }

  /**
   * A mail without a jurisdiction is permission-only.
   */
  public function testUnscopedMailIsPermissionOnly(): void {
    $memberless = $this->user(['triage'], []);
    $mail = $this->mail(0);

    $this->assertTrue($mail->access('view', $memberless), 'Any triage holder may handle unscoped mail.');
    $this->assertTrue($mail->access('update', $memberless));
  }

  /**
   * Without the triage permission everything is denied.
   */
  public function testWithoutPermissionEverythingIsDenied(): void {
    $noPermission = $this->user([], [$this->gidA]);

    $this->assertFalse($this->mail($this->gidA)->access('view', $noPermission), 'Jur membership without the permission grants nothing.');
    $this->assertFalse($this->mail(0)->access('view', $noPermission), 'Unscoped mail still requires the permission.');
  }

  /**
   * Without the scope validator scoped mail fails closed for non-global users.
   */
  public function testFailsClosedForScopedMailWithoutValidator(): void {
    $member = $this->user(['triage'], [$this->gidA]);
    $outsider = $this->user(['triage'], [$this->gidB]);
    $mail = $this->mail($this->gidA);

    $entityType = $this->container->get('entity_type.manager')->getDefinition('inbound_mail');
    $degraded = new InboundMailAccessControlHandler($entityType, NULL);
    $this->assertFalse(
      $degraded->access($mail, 'view', $member),
      'Validator unavailable: even an intended member cannot access scoped mail because membership cannot be proven.'
    );
    $this->assertFalse(
      $degraded->access($mail, 'view', $outsider),
      'Validator unavailable: foreign scoped mail stays denied.'
    );

    $this->assertTrue($degraded->access($this->mail(0), 'view', $outsider), 'Unscoped mail remains permission-only.');
    $admin = $this->user(['administrator'], []);
    $this->assertTrue($degraded->access($mail, 'view', $admin), 'Global admins keep the explicit bypass.');

    $noPermission = $this->user([], []);
    $this->assertFalse($degraded->access($mail, 'view', $noPermission));
  }

  /**
   * Group membership cache tag for one account.
   */
  protected function membershipCacheTag(UserInterface $account): string {
    return 'group_relationship_list:plugin:group_membership:entity:' . $account->id();
  }

}
