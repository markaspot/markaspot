<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Account names for workspace owners and invited members.
 *
 * The account name is the user label that colleagues see in team lists,
 * assignee pickers and status note authors. It must not be the e-mail
 * address: names are readable and built from the public workspace slug,
 * "<slug>-admin" for owners and "<slug>-member-<4 hex>" for invited members.
 * The name is set once. It does not follow a later slug change, and an
 * account in several workspaces keeps the slug of the first one.
 */
final class WorkspaceAccountName {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a free owner name: "<slug>-admin", then "-admin-2", "-admin-3".
   */
  public function forOwner(string $slug): string {
    $base = $this->fit(self::normalizeSlug($slug), '-admin-99') . '-admin';
    for ($i = 1; $i < 100; $i++) {
      $name = $i === 1 ? $base : $base . '-' . $i;
      if (!$this->isTaken($name)) {
        return $name;
      }
    }
    return $this->forMember($slug);
  }

  /**
   * Returns a free member name: "<slug>-member-<4 hex>".
   */
  public function forMember(string $slug): string {
    $base = $this->fit(self::normalizeSlug($slug), '-member-ffffffff') . '-member-';
    for ($length = 4; $length <= 8; $length++) {
      for ($try = 0; $try < 5; $try++) {
        $name = $base . substr(bin2hex(random_bytes(4)), 0, $length);
        if (!$this->isTaken($name)) {
          return $name;
        }
      }
    }
    throw new \RuntimeException('No free account name found.');
  }

  /**
   * Whether an account name exposes an e-mail address.
   */
  public static function exposesMail(string $name, string $mail): bool {
    $name = mb_strtolower(trim($name));
    return ($mail !== '' && $name === mb_strtolower(trim($mail)))
      || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $name) === 1;
  }

  /**
   * Reduces a slug to the characters an account name keeps.
   */
  private static function normalizeSlug(string $slug): string {
    $slug = trim(preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower($slug)) ?? '', '-');
    return $slug !== '' ? $slug : 'workspace';
  }

  /**
   * Shortens the slug so that slug plus suffix fit the name length.
   */
  private function fit(string $slug, string $longestSuffix): string {
    $room = UserInterface::USERNAME_MAX_LENGTH - strlen($longestSuffix);
    return rtrim(substr($slug, 0, $room), '-');
  }

  /**
   * Whether an account already uses the name.
   */
  private function isTaken(string $name): bool {
    // The name column compares case-insensitively, like username validation.
    return $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name]) !== [];
  }

}
