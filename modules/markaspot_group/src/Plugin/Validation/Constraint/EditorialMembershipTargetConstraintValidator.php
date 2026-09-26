<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\markaspot_group\Service\EditorialOrgMembership;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates new organisation memberships written by editors.
 *
 * The Group UI lets any organisation admin add any account. Editors hold the
 * admin role in every organisation of their tenant, so the member matrix rule
 * applies here as well: outside their tenant-admin scope they add only
 * accounts of their own tenant that are no peers.
 */
class EditorialMembershipTargetConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly EditorialOrgMembership $editorialMembership,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('markaspot_group.editorial_org_membership'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EditorialMembershipTargetConstraint
      || !$value instanceof GroupRelationshipInterface
      || !$value->isNew()
      || $value->getPluginId() !== 'group_membership'
      || EditorialOrgMembership::isWriting()) {
      return;
    }
    $organisation = $value->getGroup();
    $target = $value->getEntity();
    if (!$organisation instanceof GroupInterface
      || $organisation->bundle() !== 'org'
      || !$target instanceof UserInterface) {
      return;
    }
    if (!$this->editorialMembership->mayAddMember($this->currentUser, $organisation, $target)) {
      $this->context->buildViolation($constraint->message)
        ->atPath('entity_id')
        ->addViolation();
    }
  }

}
