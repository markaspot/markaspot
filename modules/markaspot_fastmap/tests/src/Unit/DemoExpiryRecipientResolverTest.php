<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Tests demo-expiry reminder recipient resolution.
 *
 * Demo workspaces are owned by uid 1 (the technical admin) and the 'jur'
 * group type enables creator_membership, so uid 1 is always a member and,
 * being created first, used to win the cron's naive "first member" pick. The
 * reminder then reached the operator and never the workspace creator. These
 * tests pin the corrected rule: the tenant member is the To, uid 1 the Bcc.
 *
 * @group markaspot_fastmap
 *
 * @covers ::_markaspot_fastmap_resolve_reminder_recipients
 */
class DemoExpiryRecipientResolverTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';
  }

  /**
   * The reported bug: uid 1 listed first must not become the To recipient.
   *
   * To = the tenant member (workspace creator), Bcc = uid 1's email.
   */
  public function testTenantIsToAndOwnerIsBcc(): void {
    $owner = $this->makeUser(1, 'admin@example.com');
    $tenant = $this->makeUser(42, 'tester@example.com');

    // Uid 1 first, exactly as creator_membership orders getMembers().
    $result = _markaspot_fastmap_resolve_reminder_recipients([$owner, $tenant]);

    $this->assertNotNull($result);
    $this->assertSame($tenant, $result['to'], 'Tenant member must be the To recipient.');
    $this->assertSame('admin@example.com', $result['bcc'], 'uid 1 must be the Bcc.');
  }

  /**
   * Member order is irrelevant: tenant wins even when listed after uid 1.
   *
   * The first non-uid-1 member with an email is the creator; any further
   * members (e.g. invited colleagues on an upgraded tier) are ignored.
   */
  public function testFirstTenantWinsRegardlessOfOrder(): void {
    $owner = $this->makeUser(1, 'admin@example.com');
    $tenant = $this->makeUser(42, 'tester@example.com');
    $colleague = $this->makeUser(43, 'colleague@example.com');

    $result = _markaspot_fastmap_resolve_reminder_recipients([$owner, $tenant, $colleague]);

    $this->assertNotNull($result);
    $this->assertSame($tenant, $result['to']);
    $this->assertSame('admin@example.com', $result['bcc']);
  }

  /**
   * Only uid 1 present: it becomes the To, with no self-Bcc.
   *
   * Fallback path for a malformed workspace that has no tenant member, so the
   * reminder is delivered rather than silently dropped.
   */
  public function testOwnerOnlyFallsBackToOwnerWithoutBcc(): void {
    $owner = $this->makeUser(1, 'admin@example.com');

    $result = _markaspot_fastmap_resolve_reminder_recipients([$owner]);

    $this->assertNotNull($result);
    $this->assertSame($owner, $result['to']);
    $this->assertSame('', $result['bcc'], 'uid 1 must never Bcc itself.');
  }

  /**
   * Tenant present but no uid 1 member: tenant is To, Bcc stays empty.
   */
  public function testTenantOnlyHasNoBcc(): void {
    $tenant = $this->makeUser(42, 'tester@example.com');

    $result = _markaspot_fastmap_resolve_reminder_recipients([$tenant]);

    $this->assertNotNull($result);
    $this->assertSame($tenant, $result['to']);
    $this->assertSame('', $result['bcc']);
  }

  /**
   * Members without an email are skipped; a tenant lacking one is not To.
   *
   * With only uid 1 carrying an email, delivery falls back to uid 1 and there
   * is no Bcc (the tenant could not be resolved).
   */
  public function testEmaillessTenantIsSkipped(): void {
    $owner = $this->makeUser(1, 'admin@example.com');
    $tenant = $this->makeUser(42, NULL);

    $result = _markaspot_fastmap_resolve_reminder_recipients([$tenant, $owner]);

    $this->assertNotNull($result);
    $this->assertSame($owner, $result['to']);
    $this->assertSame('', $result['bcc']);
  }

  /**
   * No member carries an email: nothing to send, resolver returns NULL.
   */
  public function testNoEmailsReturnsNull(): void {
    $owner = $this->makeUser(1, '');
    $tenant = $this->makeUser(42, NULL);

    $result = _markaspot_fastmap_resolve_reminder_recipients([$owner, $tenant]);

    $this->assertNull($result);
  }

  /**
   * Builds a mocked user with a fixed id and email.
   */
  private function makeUser(int $id, ?string $email): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($id);
    $user->method('getEmail')->willReturn($email);
    return $user;
  }

}
