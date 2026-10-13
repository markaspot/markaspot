<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\markaspot_ai\Service\SentimentService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clears AI sentiment reasoning when an account is cancelled.
 */
final class SentimentUserCancelHooks {

  public function __construct(
    #[Autowire(service: 'markaspot_ai.sentiment')]
    private readonly SentimentService $sentiment,
  ) {}

  /**
   * Implements hook_user_cancel().
   *
   * The reassign and unpublish methods keep the reports but rewrite them via
   * node bulk updates (owner to anonymous, or status) that do not touch the
   * analysed text, so the reasoning, which can quote the reporter, would
   * survive. Runs first: node's reassign implementation rewrites the owner
   * immediately for small sets, after which the reports could no longer be
   * found by their author. user_cancel_delete deletes the reports, which
   * markaspot_ai_node_delete() covers; user_cancel_block keeps everything
   * published and untouched.
   */
  #[Hook('user_cancel', order: Order::First)]
  public function userCancel(array $edit, UserInterface $account, string $method): void {
    if (in_array($method, ['user_cancel_reassign', 'user_cancel_block_unpublish'], TRUE)) {
      $this->sentiment->clearReasoningForAuthor((int) $account->id());
    }
  }

}
