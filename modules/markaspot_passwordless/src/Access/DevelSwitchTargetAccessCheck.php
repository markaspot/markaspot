<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\user\UserInterface;

/**
 * Prevents unsafe Devel impersonation targets.
 */
final class DevelSwitchTargetAccessCheck implements AccessInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks whether the requested Devel switch target is safe.
   */
  public function access(?string $name = NULL): AccessResultInterface {
    if ($name === NULL) {
      // devel.switch_user only renders the selector. Its links enter
      // devel.switch, where the selected target is checked below.
      return AccessResult::allowed();
    }

    $accounts = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['name' => $name]);
    $account = reset($accounts);

    if (!$account instanceof UserInterface) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }

    $result = (int) $account->id() === 1 || $account->isBlocked()
      ? AccessResult::forbidden()
      : AccessResult::allowed();
    return $result->addCacheableDependency($account);
  }

}
