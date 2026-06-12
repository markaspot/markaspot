<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Gates JSON:API user resources against anonymous enumeration.
 */
final class UserJsonApiAccessCheck implements AccessInterface {

  /**
   * Checks access for JSON:API user resource routes.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden()
        ->addCacheContexts(['user.roles:anonymous']);
    }

    return AccessResult::allowed()
      ->addCacheContexts(['user.roles:anonymous']);
  }

}
