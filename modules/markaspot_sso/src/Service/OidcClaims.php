<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

/**
 * Turns verified OIDC claims into identity-linker attributes and MFA signals.
 */
final class OidcClaims {

  /**
   * Claims that prove a second factor, with the values that count.
   *
   * "amr" is set by the IdP that authenticated the user (RFC 8176, for example
   * "hwk" for a passkey). "upstream_amr" is the claim a Keycloak broker
   * forwards from a customer IdP such as ADFS or Entra ID. It must only be
   * writable by the broker, never by the user.
   */
  public const DEFAULT_MFA_CLAIMS = [
    'amr' => ['hwk', 'mfa'],
    'upstream_amr' => ['http://schemas.microsoft.com/claims/multipleauthn'],
  ];

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
   * Checks whether any MFA claim carries an accepted value.
   *
   * @param array<string, array<int, string>> $attributes
   *   Flattened claims.
   * @param array<string, array<int, string>> $mfa_claims
   *   Accepted values keyed by claim name.
   */
  public static function hasMfa(array $attributes, array $mfa_claims): bool {
    foreach ($mfa_claims as $claim => $accepted) {
      $present = $attributes[$claim] ?? [];
      if (array_intersect($present, $accepted) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
