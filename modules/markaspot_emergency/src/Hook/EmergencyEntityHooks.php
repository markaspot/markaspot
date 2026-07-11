<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\markaspot_emergency\EventSubscriber\EmergencySubmissionGuardSubscriber;
use Drupal\markaspot_emergency\Exception\EmergencySubmissionReplayException;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_emergency\Service\EmergencySubmissionIdempotencyLedgerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Entity hooks for transaction-scoped emergency submission guards.
 */
final class EmergencyEntityHooks {

  /**
   * Constructs the entity hook service.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly EmergencyModeService $emergencyService,
    private readonly EmergencySubmissionIdempotencyLedgerInterface $idempotencyLedger,
  ) {}

  /**
   * Locks the prepared emergency scope inside the entity save transaction.
   *
   * Generic entity presave runs after every node-specific presave hook while
   * the SQL storage transaction is already active. This is the narrow
   * persistence boundary where the prepared root lock covers the write through
   * commit.
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface
      || $entity->getEntityTypeId() !== 'node'
      || $entity->bundle() !== 'service_request'
      || !$entity->isNew()) {
      return;
    }

    $request = $this->requestStack->getMainRequest();
    if ($request === NULL) {
      return;
    }
    $context = $request->attributes->get(EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE);
    if (!is_array($context)) {
      return;
    }

    // One JSON:API create arms exactly one primary entity. Removing the context
    // before validation prevents any nested or secondary save from reusing it.
    $request->attributes->remove(EmergencySubmissionGuardSubscriber::CONTEXT_ATTRIBUTE);

    try {
      if (!$entity->hasField('field_category')) {
        throw new \InvalidArgumentException('The emergency submission category is unavailable.');
      }
      $categoryValues = $entity->get('field_category')->getValue();
      $categoryId = count($categoryValues) === 1
        ? (int) ($categoryValues[0]['target_id'] ?? 0)
        : 0;
      if ($categoryId <= 0 || $categoryId !== (int) ($context['category_id'] ?? 0)) {
        throw new \InvalidArgumentException('The emergency submission category changed before persistence.');
      }

      $jurisdictionId = NULL;
      if ($entity->hasField('field_jurisdiction')) {
        $jurisdictionValues = $entity->get('field_jurisdiction')->getValue();
        if (count($jurisdictionValues) > 1) {
          throw new \InvalidArgumentException('The emergency submission jurisdiction is invalid.');
        }
        if ($jurisdictionValues !== []) {
          $jurisdictionId = (int) ($jurisdictionValues[0]['target_id'] ?? 0);
          if ($jurisdictionId <= 0) {
            throw new \InvalidArgumentException('The emergency submission jurisdiction is invalid.');
          }
        }
      }

      $preparedJurisdictionId = $context['jurisdiction_id'] ?? NULL;
      if ($preparedJurisdictionId !== NULL
        && $jurisdictionId !== (int) $preparedJurisdictionId) {
        throw new \InvalidArgumentException('The emergency submission jurisdiction changed before persistence.');
      }

      $this->emergencyService->acquireSubmissionGuard(
        (int) ($context['root_id'] ?? -1),
        isset($context['expected_revision'])
          ? (int) $context['expected_revision']
          : NULL,
        $categoryId,
        is_array($context['category_jurisdiction_ids'] ?? NULL)
          ? $context['category_jurisdiction_ids']
          : [],
        $jurisdictionId,
        is_string($context['expected_status'] ?? NULL)
          ? $context['expected_status']
          : NULL,
        (bool) ($context['require_lite_ui'] ?? FALSE),
        (bool) ($context['require_published_category'] ?? TRUE),
        (bool) ($context['require_lite_compatible_category'] ?? FALSE),
      );

      $idempotencyKey = $context['idempotency_key'] ?? NULL;
      $requestHash = $context['idempotency_request_hash'] ?? NULL;
      if ($idempotencyKey !== NULL || $requestHash !== NULL) {
        if (!is_string($idempotencyKey) || !is_string($requestHash)) {
          throw new \InvalidArgumentException('The emergency idempotency context is invalid.');
        }
        $replayNodeUuid = $this->idempotencyLedger->reserve(
          (int) ($context['root_id'] ?? -1),
          (int) ($context['expected_revision'] ?? 0),
          $idempotencyKey,
          $requestHash,
          $entity->uuid(),
        );
        if ($replayNodeUuid !== NULL) {
          throw new EmergencySubmissionReplayException($replayNodeUuid);
        }
      }
    }
    catch (EmergencySubmissionReplayException $exception) {
      // Entity SQL storage may wrap this exception before the kernel sees it.
      // Keep the trusted UUID on the request so the subscriber can return the
      // original successful JSON:API result without a second node save.
      $request->attributes->set(
        EmergencySubmissionGuardSubscriber::REPLAY_ATTRIBUTE,
        $exception->getNodeUuid(),
      );
      throw $exception;
    }
    catch (\InvalidArgumentException | \LogicException | \RuntimeException $exception) {
      $request->attributes->set(
        EmergencySubmissionGuardSubscriber::FAILURE_ATTRIBUTE,
        $exception->getMessage(),
      );
      throw $exception;
    }
  }

}
