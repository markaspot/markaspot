<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

/**
 * Turns verified OIDC claims into identity-linker input and an MFA decision.
 */
final class OidcClaims {

  /**
   * Claims that prove a second factor, with the values that count.
   *
   * "amr" is set by the IdP that authenticated the user (RFC 8176, for example
   * "hwk" for a passkey). "upstream_amr" is the claim a Keycloak broker
   * forwards from a customer IdP such as ADFS or Entra ID; it only counts for
   * brokered logins, see hasMfa().
   */
  public const DEFAULT_MFA_CLAIMS = [
    'amr' => ['hwk', 'mfa'],
    'upstream_amr' => ['http://schemas.microsoft.com/claims/multipleauthn'],
  ];

  /**
   * Standard OIDC claim names for the identity linker's canonical fields.
   *
   * "acr" is left out on purpose: Keycloak emits "1" or "0" by default, which
   * says nothing about the assurance level. Use "mfa_claims" for OIDC.
   */
  public const DEFAULT_ATTRIBUTE_MAP = [
    'email' => ['email'],
    'first_name' => ['given_name'],
    'last_name' => ['family_name'],
    'full_name' => ['name'],
  ];

  /**
   * Claim naming the broker's upstream IdP (a Keycloak session note).
   */
  private const IDENTITY_PROVIDER_CLAIM = 'identity_provider';

  /**
   * Upstream claim that only counts for brokered logins.
   */
  private const UPSTREAM_CLAIM = 'upstream_amr';

  /**
   * Flattens claims into the attribute shape the identity linker expects.
   *
   * @param array<string, mixed> $claims
   *   Verified ID token claims.
   *
   * @return array<string, array<int, string>>
   *   Claim values as string lists keyed by claim name. Nested objects are
   *   dropped.
   */
  public static function toAttributes(array $claims): array {
    $attributes = [];
    foreach ($claims as $name => $value) {
      if (is_scalar($value)) {
        $attributes[(string) $name] = [is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
        continue;
      }
      if (is_array($value) && array_is_list($value)) {
        $values = [];
        foreach ($value as $item) {
          if (is_scalar($item)) {
            $values[] = (string) $item;
          }
        }
        if ($values !== []) {
          $attributes[(string) $name] = $values;
        }
      }
    }
    return $attributes;
  }

  /**
   * Drops every email claim unless the IdP marked the email verified.
   *
   * An unverified address must neither link an existing account nor be given
   * to a new one. This includes the claims mapped to the email field, so a
   * provider that maps email to "upn" cannot bypass the check.
   *
   * @param array<string, array<int, string>> $attributes
   *   Flattened claims.
   * @param array<string, mixed> $attribute_map
   *   Claim names keyed by canonical field, see ::attributeMap().
   *
   * @return array<string, array<int, string>>
   *   Attributes without an unverified email.
   */
  public static function withoutUnverifiedEmail(array $attributes, array $attribute_map = []): array {
    if (($attributes['email_verified'][0] ?? '') === 'true') {
      return $attributes;
    }
    $mapped = is_array($attribute_map['email'] ?? NULL) ? $attribute_map['email'] : [];
    foreach (array_merge(['email'], $mapped) as $claim) {
      if (is_scalar($claim)) {
        unset($attributes[(string) $claim]);
      }
    }
    return $attributes;
  }

  /**
   * Returns the claim-to-field map for the identity linker.
   *
   * The SAML "attribute_map" is ignored for OIDC because its names differ.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   *
   * @return array<string, array<int, string>>
   *   Claim names keyed by canonical field.
   */
  public static function attributeMap(array $provider): array {
    $configured = $provider['oidc_attribute_map'] ?? NULL;
    return is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_ATTRIBUTE_MAP;
  }

  /**
   * Returns the MFA claim rules of a provider.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   *
   * @return array<string, array<int, string>>
   *   Accepted values keyed by claim name.
   */
  public static function mfaClaims(array $provider): array {
    $configured = $provider['mfa_claims'] ?? NULL;
    if (!is_array($configured) || $configured === []) {
      return self::DEFAULT_MFA_CLAIMS;
    }

    $rules = [];
    foreach ($configured as $claim => $values) {
      if (is_array($values)) {
        $rules[(string) $claim] = array_values(array_map('strval', array_filter($values, 'is_scalar')));
      }
    }
    return $rules;
  }

  /**
   * Checks whether the login proves a second factor.
   *
   * The upstream claim is a user attribute in the broker. It only counts when
   * the login actually came through a brokered IdP, and through the tenant's
   * configured one when a hint is set, so a local account cannot vouch for
   * itself.
   *
   * @param array<string, array<int, string>> $attributes
   *   Flattened claims.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  public static function hasMfa(array $attributes, array $provider): bool {
    $identity_provider = $attributes[self::IDENTITY_PROVIDER_CLAIM][0] ?? '';
    $hint = is_scalar($provider['oidc_idp_hint'] ?? NULL) ? trim((string) $provider['oidc_idp_hint']) : '';

    foreach (self::mfaClaims($provider) as $claim => $accepted) {
      if ($claim === self::UPSTREAM_CLAIM && ($identity_provider === '' || ($hint !== '' && $identity_provider !== $hint))) {
        continue;
      }
      $present = $attributes[$claim] ?? [];
      if (array_intersect($present, $accepted) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
