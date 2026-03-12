<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the TierLimit constraint.
 *
 * Note: the count-then-validate pattern has an inherent race window. Two
 * concurrent creates can both pass validation and overshoot the limit by one.
 * This is acceptable for soft monthly limits in the 50-2000 range. Hard
 * enforcement would require a database lock or counter table, which adds
 * complexity disproportionate to the risk.
 */
class TierLimitConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly TierConfigService $tierConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('markaspot_fastmap.tier_config'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof NodeInterface) {
      return;
    }

    if ($value->bundle() !== 'service_request' || !$value->isNew()) {
      return;
    }

    // Admins bypass tier limits.
    if ($this->currentUser->hasPermission('bypass mas validation')
      || $this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    if (!$value->hasField('field_jurisdiction') || $value->get('field_jurisdiction')->isEmpty()) {
      return;
    }

    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $value->get('field_jurisdiction')->entity;
    if (!$group) {
      return;
    }

    // No field_tier means no fastmap, no limits (on-premise).
    if (!$group->hasField('field_tier')) {
      return;
    }

    $tier = !$group->get('field_tier')->isEmpty()
      ? $group->get('field_tier')->value
      : 'free';

    // getLimits() fails closed: unknown tiers fall back to free tier limits.
    // Returns NULL only if tier_limits config is completely missing.
    $tierLimits = $this->tierConfig->getLimits($tier);
    if ($tierLimits === NULL) {
      return;
    }

    $count = $this->tierConfig->countRequests((int) $group->id(), $tierLimits['period']);

    if ($count >= $tierLimits['limit']) {
      $messageProperty = $tierLimits['period'] === 'total'
        ? 'totalLimitMessage'
        : 'monthlyLimitMessage';

      $this->context->addViolation($constraint->{$messageProperty}, [
        '@limit' => $tierLimits['limit'],
      ]);
    }
  }

}
