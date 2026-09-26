<?php

declare(strict_types=1);

namespace Drupal\markaspot_group\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\OrganisationManagementAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates jurisdiction-scoped organisation writes.
 */
class OrgJurisdictionManagementConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the organisation jurisdiction management validator.
   */
  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly OrganisationManagementAccess $managementAccess,
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
    protected readonly ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('markaspot_group.organisation_management_access'),
      $container->get('entity_type.manager'),
      $container->get('markaspot_group.organisation_hierarchy_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof OrgJurisdictionManagementConstraint
      || !$value instanceof GroupInterface
      || $value->bundle() !== 'org'
      || !$value->hasField('field_jurisdiction')
      || $value->get('field_jurisdiction')->isEmpty()) {
      return;
    }

    // Anonymous programmatic contexts, including Drush updatedb and
    // provisioning, carry uid 0. HTTP create access still denies anonymous
    // writes before JSON:API validation.
    if ((int) $this->currentUser->id() === 0
      || $this->managementAccess->hasBypass($this->currentUser)) {
      return;
    }

    $jurisdictionId = (int) $value->get('field_jurisdiction')->target_id;
    // Managing the new jurisdiction is not enough to take an organisation,
    // its members and its requests out of another tenant.
    if ($this->movesToAnotherTenant($value, $jurisdictionId)) {
      $this->context->buildViolation($constraint->crossTenantMessage)
        ->atPath('field_jurisdiction')
        ->addViolation();
      return;
    }
    if ($jurisdictionId > 0
      && $this->managementAccess->canManageJurisdiction($this->currentUser, $jurisdictionId)) {
      return;
    }

    $this->context->buildViolation($constraint->message)
      ->atPath('field_jurisdiction')
      ->addViolation();
  }

  /**
   * Whether a saved organisation changes its root jurisdiction.
   */
  protected function movesToAnotherTenant(GroupInterface $organisation, int $jurisdictionId): bool {
    if ($organisation->isNew()
      || $this->entityTypeManager === NULL
      || $this->hierarchyResolver === NULL) {
      return FALSE;
    }
    $original = $this->entityTypeManager->getStorage('group')->loadUnchanged($organisation->id());
    if (!$original instanceof GroupInterface
      || !$original->hasField('field_jurisdiction')
      || $original->get('field_jurisdiction')->isEmpty()) {
      return FALSE;
    }
    $originalJurisdictionId = (int) $original->get('field_jurisdiction')->target_id;
    if ($originalJurisdictionId === $jurisdictionId) {
      return FALSE;
    }
    return $this->hierarchyResolver->getRootJurisdictionId($originalJurisdictionId)
      !== $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
  }

}
