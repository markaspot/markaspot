<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Kernel;

use Drupal\Core\Session\AccountInterface;

/**
 * Test stand-in for markaspot_group's JurisdictionScopeValidator.
 *
 * The kernel tests register this under the real service id
 * (markaspot_group.jurisdiction_scope_validator) so the optionally-injected
 * consumers (InboundMailAccessControlHandler, InboundMailApiController)
 * exercise the SCOPED code path without booting the full markaspot_group
 * stack. The consumers duck-type the service (?object +
 * getAllowedJurisdictionIds()), matching the module's optional-injection
 * pattern, so no markaspot_group class needs to be autoloadable here.
 */
final class StubJurisdictionScopeValidator {

  /**
   * Allowed jurisdiction group ids keyed by uid.
   *
   * @var array<int, int[]>
   */
  public static array $map = [];

  /**
   * Mirrors JurisdictionScopeValidator::getAllowedJurisdictionIds().
   *
   * @return int[]
   *   The allowed jurisdiction group ids for the account.
   */
  public function getAllowedJurisdictionIds(AccountInterface $account): array {
    return self::$map[(int) $account->id()] ?? [];
  }

}
