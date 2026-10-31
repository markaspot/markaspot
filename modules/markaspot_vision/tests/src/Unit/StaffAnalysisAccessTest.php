<?php

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_vision\Service\StaffAnalysisAccess;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies session trust and the shipped dashboard capability mapping.
 */
#[CoversClass(StaffAnalysisAccess::class)]
#[Group('markaspot_vision')]
class StaffAnalysisAccessTest extends UnitTestCase {

  /**
   * Checks capabilities against the actual shipped role configuration.
   */
  #[DataProvider('staffProvider')]
  public function testStaffCapabilities(?string $globalRole, ?string $groupRole, bool $expected, string $jurisdictionType = 'jur', array $extraPermissions = []): void {
    $root = dirname(__DIR__, 5);
    $permissions = in_array($globalRole, [NULL, 'administrator'], TRUE) ? [] : Yaml::parseFile($root . '/config/optional/user.role.' . $globalRole . '.yml')['permissions'];
    $permissions = array_merge($permissions, $extraPermissions);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn(42);
    $account->method('hasPermission')->willReturnCallback(
      fn ($permission) => $globalRole === 'administrator' || in_array($permission, $permissions, TRUE));
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('jurisdiction_group_type')->willReturn($jurisdictionType);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('markaspot_open311.settings')->willReturn($config);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->with('group_relationship')->willReturn(TRUE);
    $memberships = [];
    if ($groupRole !== NULL) {
      $path = $groupRole === 'jur-admin' ? '/config/install/' : '/modules/markaspot_group/config/install/';
      $role = Yaml::parseFile($root . $path . 'group.role.' . $groupRole . '.yml');
      $group = $this->createMock(GroupInterface::class);
      $group->method('bundle')->willReturn($role['group_type'] === 'jur' ? $jurisdictionType : $role['group_type']);
      $group->method('hasPermission')
        ->with('view unpublished group_node:service_request entity', $account)
        ->willReturn($role['admin'] || in_array('view unpublished group_node:service_request entity', $role['permissions'], TRUE));
      $membership = $this->createMock(GroupRelationshipInterface::class);
      $membership->method('getGroup')->willReturn($group);
      $memberships[] = $membership;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->with(['entity_id' => 42, 'plugin_id' => 'group_membership'])->willReturn($memberships);
    $entities->method('getStorage')->with('group_relationship')->willReturn($storage);
    $access = new StaffAnalysisAccess($account, $factory, $entities);
    new Settings(['hash_salt' => 'staff-test-salt']);

    $identifier = $access->getRateLimitIdentifier($this->sessionRequest());
    $this->assertSame($expected, $identifier !== NULL);
    if ($expected) {
      $this->assertSame('sid:' . hash_hmac('sha256', 'session-one', 'staff-test-salt'), $identifier);
    }
  }

  /**
   * Global staff and only elevated jurisdiction memberships qualify.
   */
  public static function staffProvider(): iterable {
    foreach (['moderator', 'editorial_board', 'tenant_admin', 'administrator'] as $role) {
      yield $role => [$role, NULL, TRUE];
    }
    yield 'citizen' => [NULL, NULL, FALSE];
    yield 'custom editor' => [NULL, NULL, TRUE, 'jur', ['edit any service_request content']];
    yield 'content administrator' => [NULL, NULL, TRUE, 'jur', ['administer nodes']];
    yield 'own report editor' => [NULL, NULL, FALSE, 'jur', ['edit own service_request content']];
    foreach (['jur-admin', 'jur-moderator', 'jur-editorial', 'jur-tenant_admin'] as $role) {
      yield $role => [NULL, $role, TRUE];
      yield $role . ' custom bundle' => [NULL, $role, TRUE, 'district'];
    }
    foreach (['jur-member', 'jur-org_member', 'org-member', 'org-insider', 'org-moderator'] as $role) {
      yield $role => [NULL, $role, FALSE];
    }
  }

  /**
   * API authentication or forged session context cannot unlock staff budgets.
   */
  #[DataProvider('untrustedSessionProvider')]
  public function testUntrustedSessions(bool $authenticated, bool $hasSession, int $uid, string $sid, bool $throws): void {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('id')->willReturn(42);
    $account->expects($this->never())->method('hasPermission');
    $access = new StaffAnalysisAccess($account, $this->createMock(ConfigFactoryInterface::class), $this->createMock(EntityTypeManagerInterface::class));
    $request = $hasSession ? $this->sessionRequest($uid, $sid, $throws) : Request::create('/api/vision/analyze', 'POST');
    $request->headers->set('X-CSRF-Token', 'not-session-proof');
    $this->assertNull($access->getRateLimitIdentifier($request));
  }

  /**
   * Invalid browser session combinations.
   */
  public static function untrustedSessionProvider(): iterable {
    yield 'anonymous' => [FALSE, TRUE, 42, 'session', FALSE];
    yield 'API key only' => [TRUE, FALSE, 42, 'session', FALSE];
    yield 'missing session uid' => [TRUE, TRUE, 0, 'session', FALSE];
    yield 'different session uid' => [TRUE, TRUE, 99, 'session', FALSE];
    yield 'empty session id' => [TRUE, TRUE, 42, '', FALSE];
    yield 'unavailable session' => [TRUE, TRUE, 42, 'session', TRUE];
  }

  /**
   * Builds a request with a server-owned session.
   */
  private function sessionRequest(int $uid = 42, string $sid = 'session-one', bool $throws = FALSE): Request {
    $session = $this->createMock(SessionInterface::class);
    $session->method('getId')->willReturn($sid);
    if ($throws) {
      $session->method('get')->willThrowException(new \RuntimeException('Session unavailable.'));
    }
    else {
      $session->method('get')->with('uid', 0)->willReturn($uid);
    }
    $request = Request::create('/api/vision/analyze', 'POST');
    $request->setSession($session);
    return $request;
  }

}
