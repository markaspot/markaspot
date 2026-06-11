<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Jurisdiction-scoped access control for inbound_mail entities (#482).
 *
 * Closes the Phase 1 gap where the "triage inbound mail" permission was
 * global: a staged mail contains unredacted citizen content, so a triage
 * user may only see and act on mail of jurisdictions they are a member of.
 *
 * Rules per operation (view / update / delete):
 * - The "triage inbound mail" permission is always required.
 * - Global users (uid 1 or the site-wide "administrator" role, the same
 *   bypass markaspot_group's controllers use) skip the jurisdiction scope.
 * - A mail WITH a jurisdiction additionally requires membership in that
 *   jurisdiction group, resolved through markaspot_group's
 *   JurisdictionScopeValidator. The validator is injected OPTIONALLY
 *   (markaspot_group is a hard transitive dependency via markaspot_open311,
 *   but the optional-service pattern keeps the handler degradable, mirroring
 *   InboundMailPromoter); when it is unavailable the check degrades to
 *   permission-only.
 * - A mail WITHOUT a jurisdiction (single-tenant installs, unrouted
 *   mailboxes) is permission-only: any triage-permission holder may handle
 *   it, consistent with the API list scoping in InboundMailApiController.
 *
 * @phpstan-consistent-constructor
 */
class InboundMailAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * The triage permission gating every entity operation.
   */
  public const TRIAGE_PERMISSION = 'triage inbound mail';

  /**
   * Constructs the handler.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The inbound_mail entity type definition.
   * @param object|null $scopeValidator
   *   markaspot_group's JurisdictionScopeValidator, or NULL when the service
   *   is unavailable. Duck-typed (?object) like the promoter's optional
   *   processor so the handler never hard-references a foreign class.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    protected ?object $scopeValidator = NULL,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->has('markaspot_group.jurisdiction_scope_validator')
        ? $container->get('markaspot_group.jurisdiction_scope_validator')
        : NULL,
    );
  }

  /**
   * Whether the account bypasses jurisdiction scoping entirely.
   *
   * Mirrors the profile's established global-admin detection (uid 1 or the
   * site-wide "administrator" role; see GroupMembersController). Public and
   * static so InboundMailApiController applies the identical bypass to its
   * collection query scoping — one source of truth for "who is global".
   */
  public static function hasGlobalBypass(AccountInterface $account): bool {
    return (int) $account->id() === 1
      || in_array('administrator', $account->getRoles(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    assert($entity instanceof InboundMail);

    if (!in_array($operation, ['view', 'update', 'delete'], TRUE)) {
      // Unknown operations stay neutral (no grant, hooks may still allow).
      return AccessResult::neutral()->cachePerPermissions();
    }

    if (!$account->hasPermission(self::TRIAGE_PERMISSION)) {
      // Intentionally forbidden (not neutral) so other modules' access hooks
      // cannot widen access to pre-moderation citizen PII.
      return AccessResult::forbidden('The "triage inbound mail" permission is required.')
        ->cachePerPermissions();
    }

    // Global users and unscoped mails: permission is sufficient. The result
    // varies per user (membership), so cache per user and on the entity
    // (its jurisdiction_id may change on re-routing).
    $jurisdictionId = $entity->getJurisdictionId();
    if ($jurisdictionId <= 0 || static::hasGlobalBypass($account)) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheContexts(['user'])
        ->addCacheableDependency($entity);
    }

    if ($this->scopeValidator === NULL || !method_exists($this->scopeValidator, 'getAllowedJurisdictionIds')) {
      // Degraded mode (validator unavailable): permission-only, matching the
      // documented Phase 1 behavior. markaspot_group is a hard transitive
      // dependency in this profile, so this branch is a safety net.
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheContexts(['user'])
        ->addCacheableDependency($entity);
    }

    $allowed = $this->scopeValidator->getAllowedJurisdictionIds($account);
    if (in_array($jurisdictionId, array_map('intval', $allowed), TRUE)) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheContexts(['user'])
        ->addCacheableDependency($entity);
    }

    // Intentionally forbidden (not neutral) so other modules' access hooks
    // cannot widen access to pre-moderation citizen PII.
    return AccessResult::forbidden('The user is not a member of the mail\'s jurisdiction.')
      ->cachePerPermissions()
      ->addCacheContexts(['user'])
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Mails are created by the ingestion pipeline (system context, no access
    // checks). Interactive creation is reserved to triage-permission holders.
    return AccessResult::allowedIfHasPermission($account, self::TRIAGE_PERMISSION);
  }

}
