<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\markaspot_sso\Service\OidcClaims;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests claim flattening and the MFA decision.
 */
#[Group('markaspot_sso')]
final class OidcClaimsTest extends UnitTestCase {

  /**
   * Scalars and lists become string lists, nested objects are dropped.
   */
  public function testToAttributes(): void {
    $attributes = OidcClaims::toAttributes([
      'sub' => 'subject-1',
      'email_verified' => TRUE,
      'auth_time' => 1790000000,
      'amr' => ['hwk'],
      'groups' => ['a', ['nested'], 'b'],
      'address' => ['locality' => 'Bonn'],
      'empty' => [],
    ]);

    $this->assertSame([
      'sub' => ['subject-1'],
      'email_verified' => ['true'],
      'auth_time' => ['1790000000'],
      'amr' => ['hwk'],
      'groups' => ['a', 'b'],
    ], $attributes);
  }

  /**
   * The login patterns observed in the Keycloak broker spike.
   *
   * @param array<string, mixed> $claims
   *   ID token claims.
   * @param bool $expected
   *   Expected MFA decision.
   */
  #[DataProvider('brokerPatternProvider')]
  public function testMfaDecisionForBrokerPatterns(array $claims, bool $expected): void {
    $attributes = OidcClaims::toAttributes($claims);
    $this->assertSame($expected, OidcClaims::hasMfa($attributes, OidcClaims::mfaClaims([])));
  }

  /**
   * Spike scenarios A2, B, C1, C2 and a password-only local login.
   *
   * @return array<string, array{0: array<string, mixed>, 1: bool}>
   *   Claims and the expected decision.
   */
  public static function brokerPatternProvider(): array {
    $mfa = 'http://schemas.microsoft.com/claims/multipleauthn';
    $password = 'http://schemas.microsoft.com/ws/2008/06/identity/authenticationmethod/password';
    return [
      'local passkey' => [['amr' => ['hwk']], TRUE],
      'local password' => [['amr' => ['pwd']], FALSE],
      'ADFS with MFA' => [['amr' => [], 'upstream_amr' => [$mfa]], TRUE],
      'ADFS without MFA, first login' => [['amr' => [], 'upstream_amr' => [$password]], FALSE],
      'ADFS without MFA, passkey step-up' => [['amr' => ['hwk'], 'upstream_amr' => [$password]], TRUE],
      'no claims' => [[], FALSE],
    ];
  }

  /**
   * Configured rules replace the defaults, for example Entra ID direct.
   */
  public function testConfiguredRulesReplaceDefaults(): void {
    $rules = OidcClaims::mfaClaims(['mfa_claims' => ['amr' => ['mfa', 3]]]);

    $this->assertSame(['amr' => ['mfa', '3']], $rules);
    $this->assertTrue(OidcClaims::hasMfa(['amr' => ['pwd', 'mfa']], $rules));
    $this->assertFalse(OidcClaims::hasMfa(['amr' => ['hwk']], $rules));
  }

}
