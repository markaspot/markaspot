<?php

declare(strict_types=1);

namespace Drupal\markaspot_boilerplate\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\markaspot_boilerplate\Access\BoilerplateAccess;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Rejects foreign, malformed and reassigned template scopes.
 */
final class BoilerplateScopeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the scope validator.
   */
  public function __construct(private readonly AccountInterface $account) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('current_user'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if ($value instanceof NodeInterface) {
      foreach (BoilerplateAccess::writeErrors($value, $this->account) as $error) {
        $this->context->addViolation($error);
      }
    }
  }

}
