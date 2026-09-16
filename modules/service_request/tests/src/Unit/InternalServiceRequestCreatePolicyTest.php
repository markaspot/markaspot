<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\service_request\Access\InternalServiceRequestCreatePolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tests the authenticated staff-session policy for internal report creation.
 */
#[CoversClass(InternalServiceRequestCreatePolicy::class)]
final class InternalServiceRequestCreatePolicyTest extends UnitTestCase {

  /**
   * Tests role, permission, and session boundaries.
   *
   * @param bool $authenticated
   *   Whether the account is authenticated.
   * @param string[] $roles
   *   Account roles.
   * @param string[] $permissions
   *   Account permissions.
   * @param int|null $session_uid
   *   The session account ID, or NULL for no session.
   * @param bool $expected
   *   The expected policy result.
   */
  #[DataProvider('policyCases')]
  public function testPolicy(
    bool $authenticated,
    array $roles,
    array $permissions,
    ?int $session_uid,
    bool $expected,
  ): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('id')->willReturn(42);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));

    $request = Request::create('/jsonapi/node/service_request', 'POST');
    if ($session_uid !== NULL) {
      $session = $this->createMock(SessionInterface::class);
      $session->method('get')->with('uid', 0)->willReturn($session_uid);
      $request->setSession($session);
    }

    self::assertSame(
      $expected,
      InternalServiceRequestCreatePolicy::allows($account, $request),
    );
  }

  /**
   * Provides security boundary cases.
   *
   * @return array<string, array{bool, string[], string[], int|null, bool}>
   *   Policy cases.
   */
  public static function policyCases(): array {
    return [
      'moderator session' => [TRUE, ['authenticated', 'moderator'], [], 42, TRUE],
      'administrator and contractor session' => [TRUE, ['authenticated', 'administrator', 'contractor'], [], 42, TRUE],
      'administer nodes session' => [TRUE, ['authenticated'], ['administer nodes'], 42, TRUE],
      'edit any session' => [TRUE, ['authenticated'], ['edit any service_request content'], 42, TRUE],
      'anonymous' => [FALSE, ['anonymous'], [], 0, FALSE],
      'citizen session' => [TRUE, ['authenticated'], [], 42, FALSE],
      'contractor session' => [TRUE, ['authenticated', 'contractor'], [], 42, FALSE],
      'contractor with staff permission' => [TRUE, ['authenticated', 'contractor'], ['administer nodes'], 42, FALSE],
      'API key staff account without session' => [TRUE, ['authenticated', 'moderator'], [], NULL, FALSE],
      'mismatched session' => [TRUE, ['authenticated', 'moderator'], [], 7, FALSE],
    ];
  }

}
