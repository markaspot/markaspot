<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Service;

/**
 * Guards the one-time wording-placeholder migration for mail texts.
 *
 * Notification templates are editable tenant configuration. The migration
 * must only replace the exact historical shipped defaults, never a template
 * an operator has adjusted even slightly. Strict comparison is intentional:
 * normalizing missing fields, ordering, or values would risk treating an
 * authored template as boilerplate.
 */
final class NotificationTextWordingMigrationGuard {

  /**
   * Returns the replacement only when current data is exactly legacy data.
   *
   * @param array<string, mixed> $current
   *   Active configuration or a language override.
   * @param array<string, mixed> $legacyDefault
   *   The historical shipped default for the same config scope.
   * @param array<string, mixed> $replacement
   *   The current shipped template containing runtime placeholders.
   *
   * @return array<string, mixed>|null
   *   The replacement data when safe to write, or NULL when current data is
   *   already current or has been authored locally.
   */
  public function replacementForExactLegacyDefault(array $current, array $legacyDefault, array $replacement): ?array {
    return $current === $legacyDefault ? $replacement : NULL;
  }

}
