<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Validation;

/**
 * Validates the optional, approved multilingual workspace claim.
 */
final class WorkspaceClaim {

  /**
   * Validates complete translations without silently truncating approved copy.
   *
   * @param mixed $claims
   *   Language-keyed plain text, or NULL/empty for the existing fallback.
   * @param string[] $languages
   *   Languages explicitly selected through the workspace categories.
   *
   * @return array<string, string>
   *   Trimmed claims, or an empty array when no custom claim was selected.
   *
   * @throws \RuntimeException
   *   When claims are malformed, incomplete or too long for the header.
   */
  public static function normalize(mixed $claims, array $languages): array {
    if ($claims === NULL || $claims === []) {
      return [];
    }
    if (!is_array($claims) || count($claims) !== count($languages)) {
      throw new \RuntimeException('Workspace claims must cover all selected languages');
    }
    $normalized = [];
    foreach ($languages as $language) {
      $claim = $claims[$language] ?? NULL;
      if (!is_string($claim) || !mb_check_encoding($claim, 'UTF-8') || preg_match('/[<>\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $claim)) {
        throw new \RuntimeException('Workspace claims must be single-line plain text');
      }
      $claim = trim($claim);
      if ($claim === '' || mb_strlen($claim) > 40) {
        throw new \RuntimeException('Workspace claims must contain 1-40 characters');
      }
      $normalized[$language] = $claim;
    }
    return $normalized;
  }

}
