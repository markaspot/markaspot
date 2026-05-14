<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_validation\Plugin\Validation\Geo\GeoJsonBoundary;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the TierLimit constraint.
 *
 * Note: new published-count creates and publish transitions are re-checked
 * under a jurisdiction lock in markaspot_fastmap_node_presave(). This
 * validator provides user-facing violations before the save path.
 */
class TierLimitConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly TierConfigService $tierConfig,
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
    protected readonly ?RequestStack $requestStack = NULL,
    protected readonly ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
    protected readonly ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('markaspot_fastmap.tier_config'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->has('markaspot_group.hierarchy_resolver')
        ? $container->get('markaspot_group.hierarchy_resolver')
        : NULL,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof NodeInterface) {
      return;
    }

    if ($value->bundle() !== 'service_request') {
      return;
    }

    // Admins bypass tier limits.
    if ($this->currentUser->hasPermission('bypass mas validation')
      || $this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    $group = $this->resolveJurisdictionGroup($value);
    if (!$group) {
      return;
    }

    // No field_tier means no fastmap, no limits (on-premise).
    if (!$group->hasField('field_tier')) {
      return;
    }

    // Empty field_tier means the workspace has not yet completed Stripe
    // checkout. While in demo state (expiry set, no Stripe subscription),
    // tier-limit enforcement is bypassed so the user can finish the
    // onboarding/checkout flow without hitting fake "Free" caps. The hourly
    // cron uses ->exists('field_tier') to mirror this skip.
    if ($group->get('field_tier')->isEmpty()) {
      if ($this->isWorkspaceInDemoState($group)) {
        return;
      }
      // Fall back to free limits as a defensive guard for the unexpected
      // "no tier + no demo expiry" combo (should never happen post-11922).
      $tier = 'free';
    }
    else {
      $tier = $group->get('field_tier')->value;
    }

    // getLimits() fails closed: unknown tiers fall back to free tier limits.
    // Returns NULL only if tier_limits config is completely missing.
    $tierLimits = $this->tierConfig->getLimits($tier);
    if ($tierLimits === NULL || !empty($tierLimits['unlimited'])) {
      return;
    }

    // Determine what kind of operation this is.
    // Load the unchanged entity from storage when Drupal has not attached the
    // original entity to detect publish transitions reliably.
    $isPublishTransition = FALSE;
    if (!$value->isNew() && $value->isPublished()) {
      $original = $value->getOriginal()
        ?? $this->getEntityTypeManager()->getStorage('node')->loadUnchanged($value->id());
      if ($original && !$original->isPublished()) {
        $isPublishTransition = TRUE;
      }
    }

    if ($tierLimits['period'] === 'published') {
      // For published-count limits: only block unpublished->published
      // transitions via validation. New node creation is handled silently
      // by hook_node_presave (sets status=0 instead of rejecting).
      if (!$isPublishTransition) {
        return;
      }
    }
    else {
      // Legacy monthly/total: block new node creation.
      if (!$value->isNew()) {
        return;
      }
    }

    $count = $this->tierConfig->countRequests((int) $group->id(), $tierLimits['period']);

    if ($count >= $tierLimits['limit']) {
      $messageProperty = match ($tierLimits['period']) {
        'published' => 'publishedLimitMessage',
        'total' => 'totalLimitMessage',
        default => 'monthlyLimitMessage',
      };

      $this->context->addViolation($constraint->{$messageProperty}, [
        '@limit' => $tierLimits['limit'],
      ]);
    }
  }

  /**
   * Resolves the jurisdiction group before node_insert hooks can stamp it.
   */
  protected function resolveJurisdictionGroup(NodeInterface $node): ?GroupInterface {
    $categoryRootInvalid = FALSE;
    $categoryRootId = $this->resolveCategoryRootJurisdictionId($node, $categoryRootInvalid);
    $boundaryJurisdictionId = $this->resolveBoundaryJurisdictionId($node);
    $requestJurisdictionId = $this->getRequestJurisdictionId();

    if ($categoryRootInvalid) {
      $this->context->addViolation(TierLimitConstraint::JURISDICTION_MISMATCH_MESSAGE);
      return NULL;
    }

    if ($boundaryJurisdictionId) {
      if ($this->resolveAllowedJurisdictionId($boundaryJurisdictionId, $categoryRootId, FALSE) === NULL) {
        $this->context->addViolation(TierLimitConstraint::JURISDICTION_MISMATCH_MESSAGE);
        return NULL;
      }
      return $this->loadJurisdictionGroup($boundaryJurisdictionId);
    }

    if ($requestJurisdictionId) {
      $allowedRequestJurisdictionId = $this->resolveAllowedJurisdictionId(
        $requestJurisdictionId,
        $categoryRootId,
        $boundaryJurisdictionId,
      );
      if ($allowedRequestJurisdictionId) {
        return $this->loadJurisdictionGroup($allowedRequestJurisdictionId);
      }
    }

    if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
      $field = $node->get('field_jurisdiction');
      $group = $field->entity;
      $fieldJurisdictionId = $group instanceof GroupInterface
        ? (int) $group->id()
        : (int) $field->target_id;
      if ($fieldJurisdictionId && $this->resolveAllowedJurisdictionId($fieldJurisdictionId, $categoryRootId, $boundaryJurisdictionId)) {
        return $this->isJurisdictionGroup($group)
          ? $group
          : $this->loadJurisdictionGroup($fieldJurisdictionId);
      }
      $this->context->addViolation(TierLimitConstraint::JURISDICTION_MISMATCH_MESSAGE);
      return NULL;
    }

    return $categoryRootId ? $this->loadJurisdictionGroup($categoryRootId) : NULL;
  }

  /**
   * Resolves the root jurisdiction from the service category reference.
   */
  protected function resolveCategoryRootJurisdictionId(NodeInterface $node, bool &$invalidHierarchy = FALSE): ?int {
    $invalidHierarchy = FALSE;

    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return NULL;
    }

    $term = $node->get('field_category')->entity;
    if (!$term || !$term->hasField('field_jurisdiction') || $term->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $jurisdictionId = (int) $term->get('field_jurisdiction')->target_id;
    if ($jurisdictionId <= 0) {
      return NULL;
    }

    if ($this->hierarchyResolver) {
      $rootId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
      if ($rootId === NULL) {
        $invalidHierarchy = TRUE;
      }
      return $rootId;
    }

    return $jurisdictionId;
  }

  /**
   * Reads a jurisdiction_id submitted alongside API requests.
   */
  protected function getRequestJurisdictionId(): ?int {
    $request = $this->requestStack?->getCurrentRequest();
    if (!$request) {
      return NULL;
    }

    $jurisdictionId = $request->request->get('jurisdiction_id')
      ?? $request->query->get('jurisdiction_id')
      ?? $this->getJsonRequestJurisdictionId($request->getContent());

    return $jurisdictionId ? (int) $jurisdictionId : NULL;
  }

  /**
   * Reads jurisdiction_id from JSON request content.
   */
  protected function getJsonRequestJurisdictionId(string|false $content): ?int {
    if (!$content) {
      return NULL;
    }

    $data = json_decode($content, TRUE);
    if (!is_array($data)) {
      return NULL;
    }

    $jurisdictionId = $data['jurisdiction_id']
      ?? $data['jurisdiction']
      ?? $data['gid']
      ?? NULL;

    return $jurisdictionId ? (int) $jurisdictionId : NULL;
  }

  /**
   * Ensures a request jurisdiction matches the category root when known.
   */
  protected function resolveAllowedJurisdictionId(int $jurisdictionId, ?int $categoryRootId, int|false $boundaryJurisdictionId): ?int {
    if ($boundaryJurisdictionId) {
      return $boundaryJurisdictionId === $jurisdictionId ? $jurisdictionId : NULL;
    }

    if ($categoryRootId === NULL) {
      return $jurisdictionId;
    }

    if ($jurisdictionId === $categoryRootId) {
      return $jurisdictionId;
    }

    if (!$this->hierarchyResolver) {
      return NULL;
    }

    if ($this->hierarchyResolver->getRootJurisdictionId($jurisdictionId) !== $categoryRootId) {
      return NULL;
    }

    return $boundaryJurisdictionId === FALSE || $boundaryJurisdictionId === $jurisdictionId
      ? $jurisdictionId
      : NULL;
  }

  /**
   * Loads a jurisdiction group by ID.
   */
  protected function loadJurisdictionGroup(int $jurisdictionId): ?GroupInterface {
    $group = $this->getEntityTypeManager()
      ->getStorage('group')
      ->load($jurisdictionId);

    return $this->isJurisdictionGroup($group)
      ? $group
      : NULL;
  }

  /**
   * Gets the entity type manager.
   */
  protected function getEntityTypeManager(): EntityTypeManagerInterface {
    if (!$this->entityTypeManager) {
      throw new \LogicException('Entity type manager is required for tier limit validation.');
    }

    return $this->entityTypeManager;
  }

  /**
   * Resolves the most specific boundary jurisdiction for the node location.
   */
  protected function resolveBoundaryJurisdictionId(NodeInterface $node): int|false {
    if (!$node->hasField('field_geolocation') || $node->get('field_geolocation')->isEmpty()) {
      return FALSE;
    }

    $geolocation = $node->get('field_geolocation')->first();
    $lat = (float) $geolocation->get('lat')->getValue();
    $lng = (float) $geolocation->get('lng')->getValue();
    if ($lat === 0.0 && $lng === 0.0) {
      return FALSE;
    }

    $groupStorage = $this->getEntityTypeManager()->getStorage('group');
    $groupIds = $groupStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->getJurisdictionGroupType())
      ->exists('field_boundary')
      ->execute();

    if (empty($groupIds)) {
      return FALSE;
    }

    $matchedIds = [];
    $groups = $groupStorage->loadMultiple($groupIds);
    foreach ($groups as $group) {
      if (!$this->isJurisdictionGroup($group)) {
        continue;
      }
      if (!$group->hasField('field_parent_jurisdiction') || $group->get('field_parent_jurisdiction')->isEmpty()) {
        continue;
      }
      if (!$group->hasField('field_boundary') || $group->get('field_boundary')->isEmpty()) {
        continue;
      }

      $boundary = GeoJsonBoundary::fromJson((string) $group->get('field_boundary')->value);
      if ($boundary && $boundary->contains($lng, $lat)) {
        $matchedIds[] = (int) $group->id();
      }
    }

    return $this->resolveDeepestMatchedJurisdictionId($matchedIds, $groups);
  }

  /**
   * Returns the deepest jurisdiction ID from matching boundary groups.
   *
   * @param int[] $matchedIds
   *   Matching jurisdiction IDs.
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups
   *   Loaded group entities keyed by ID.
   */
  protected function resolveDeepestMatchedJurisdictionId(array $matchedIds, array $groups): int|false {
    if (empty($matchedIds)) {
      return FALSE;
    }
    if (count($matchedIds) === 1) {
      return $matchedIds[0];
    }

    $parentIdsInSet = [];
    foreach ($groups as $group) {
      if (!$group instanceof GroupInterface || !in_array((int) $group->id(), $matchedIds, TRUE)) {
        continue;
      }
      if ($group->hasField('field_parent_jurisdiction') && !$group->get('field_parent_jurisdiction')->isEmpty()) {
        $parentIdsInSet[(int) $group->get('field_parent_jurisdiction')->target_id] = TRUE;
      }
    }

    foreach ($matchedIds as $id) {
      if (!isset($parentIdsInSet[$id])) {
        return $id;
      }
    }

    return $matchedIds[0];
  }

  /**
   * Gets the configured jurisdiction group type.
   */
  protected function getJurisdictionGroupType(): string {
    if (!$this->configFactory) {
      return 'jur';
    }
    return $this->configFactory
      ->get('markaspot_open311.settings')
      ->get('jurisdiction_group_type') ?: 'jur';
  }

  /**
   * Checks whether a group uses the configured jurisdiction type.
   */
  protected function isJurisdictionGroup(mixed $group): bool {
    return $group instanceof GroupInterface
      && $group->bundle() === $this->getJurisdictionGroupType();
  }

  /**
   * Determines whether the given workspace is in the demo state.
   *
   * Mirrors the procedural helper _markaspot_fastmap_workspace_in_demo_state()
   * in markaspot_fastmap.module so this plugin can be unit-tested without
   * loading the .module file.
   */
  protected function isWorkspaceInDemoState(GroupInterface $group): bool {
    if (!$group->hasField('field_expiry_date') || $group->get('field_expiry_date')->isEmpty()) {
      return FALSE;
    }
    if ((int) $group->get('field_expiry_date')->value <= 0) {
      return FALSE;
    }
    if (!$group->hasField('field_stripe_subscription_id')) {
      return TRUE;
    }
    if ($group->get('field_stripe_subscription_id')->isEmpty()) {
      return TRUE;
    }
    return trim((string) $group->get('field_stripe_subscription_id')->value) === '';
  }

}
