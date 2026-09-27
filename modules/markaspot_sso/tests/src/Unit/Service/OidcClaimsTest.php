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
    $this->assertSame($expected, OidcClaims::hasMfa($attributes, ['oidc_idp_hint' => 'bonn-adfs']));
  }

  /**
   * Spike scenarios A2, B, C1, C2 and a password-only local login.
   *
   * @return array<string, array{0: array<string, mixed>, 1: bool}>
   *   Claims and the expected decision.
   */
  public static function brokerPatternProvider(): array {
    $mfa = ['upstream_amr' => ['http://schemas.microsoft.com/claims/multipleauthn']];
    $password = ['upstream_amr' => ['http://schemas.microsoft.com/ws/2008/06/identity/authenticationmethod/password']];
    $bonn = ['identity_provider' => 'bonn-adfs'];
    return [
      'local passkey' => [['amr' => ['hwk']], TRUE],
      'local password' => [['amr' => ['pwd']], FALSE],
      'ADFS with MFA' => [$bonn + $mfa, TRUE],
      'ADFS without MFA, first login' => [$bonn + $password, FALSE],
      'ADFS without MFA, passkey step-up' => [$bonn + $password + ['amr' => ['hwk']], TRUE],
      'local account that set upstream_amr itself' => [$mfa + ['amr' => ['pwd']], FALSE],
      'MFA from another broker IdP than the tenant' => [$mfa + ['identity_provider' => 'other-idp'], FALSE],
      'no claims' => [[], FALSE],
    ];
  }

  /**
   * Configured rules replace the defaults, for example Entra ID direct.
   */
  public function testConfiguredRulesReplaceDefaults(): void {
    $rules = OidcClaims::mfaClaims(['mfa_claims' => ['amr' => ['mfa', 3]]]);

    $this->assertSame(['amr' => ['mfa', '3']], $rules);
    $provider = ['mfa_claims' => ['amr' => ['mfa']]];
    $this->assertTrue(OidcClaims::hasMfa(['amr' => ['pwd', 'mfa']], $provider));
    $this->assertFalse(OidcClaims::hasMfa(['amr' => ['hwk']], $provider));
  }

  /**
   * Without a tenant hint any brokered IdP may vouch, a local account never.
   */
  public function testUpstreamClaimWithoutHint(): void {
    $mfa = ['upstream_amr' => ['http://schemas.microsoft.com/claims/multipleauthn']];

    $this->assertTrue(OidcClaims::hasMfa($mfa + ['identity_provider' => ['any-idp']], []));
    $this->assertFalse(OidcClaims::hasMfa($mfa, []));
  }

  /**
   * Only a verified email reaches the identity linker.
   */
  public function testUnverifiedEmailIsDropped(): void {
    $email = ['email' => ['a@example.test']];

    $this->assertSame($email + ['email_verified' => ['true']], OidcClaims::withoutUnverifiedEmail($email + ['email_verified' => ['true']]));
    $this->assertSame(['email_verified' => ['false']], OidcClaims::withoutUnverifiedEmail($email + ['email_verified' => ['false']]));
    $this->assertSame([], OidcClaims::withoutUnverifiedEmail($email));
  }

  /**
   * A claim mapped to the email field is dropped as well when unverified.
   */
  public function testUnverifiedMappedEmailIsDropped(): void {
    $map = ['email' => ['upn']];
    $claims = ['upn' => ['victim@example.test'], 'email' => ['a@example.test']];

    $this->assertSame(['email_verified' => ['false']], OidcClaims::withoutUnverifiedEmail($claims + ['email_verified' => ['false']], $map));
    $this->assertSame([], OidcClaims::withoutUnverifiedEmail($claims, $map));
    $this->assertSame($claims + ['email_verified' => ['true']], OidcClaims::withoutUnverifiedEmail($claims + ['email_verified' => ['true']], $map));
  }

  /**
   * OIDC uses its own claim names, never the SAML attribute map.
   */
  public function testAttributeMap(): void {
    $this->assertSame(OidcClaims::DEFAULT_ATTRIBUTE_MAP, OidcClaims::attributeMap(['attribute_map' => ['email' => ['mail']]]));
    $this->assertSame(['email' => ['upn']], OidcClaims::attributeMap(['oidc_attribute_map' => ['email' => ['upn']]]));
    $this->assertArrayNotHasKey('assurance_level', OidcClaims::DEFAULT_ATTRIBUTE_MAP);
  }

}
