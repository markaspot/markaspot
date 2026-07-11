<?php

declare(strict_types=1);

namespace Drupal\markaspot_emergency\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\markaspot_emergency\Service\EmergencyModeService;
use Drupal\markaspot_emergency\Service\EmergencySubmissionIdempotencyLedgerInterface;
use Drupal\markaspot_emergency\Service\EmergencySubmissionReplayResponderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Scopes citizen JSON:API creates and pins emergency status revisions.
 */
final class EmergencySubmissionGuardSubscriber implements EventSubscriberInterface {

  public const CONTEXT_ATTRIBUTE = '_markaspot_emergency_submission_context';

  public const FAILURE_ATTRIBUTE = '_markaspot_emergency_submission_failure';

  /**
   * Request attribute containing a committed duplicate result UUID.
   */
  public const REPLAY_ATTRIBUTE = '_markaspot_emergency_submission_replay';

  private const MAX_DOCUMENT_BYTES = 64 * 1024;

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly EmergencyModeService $emergencyService,
    private readonly AccountInterface $currentUser,
    private readonly ?EmergencySubmissionIdempotencyLedgerInterface $idempotencyLedger = NULL,
    private readonly ?EmergencySubmissionReplayResponderInterface $replayResponder = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 0],
      KernelEvents::EXCEPTION => ['onException', 100],
    ];
  }

  /**
   * Runs a bounded root-scoped preflight for a citizen JSON:API create.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if ($request->getMethod() !== 'POST'
      || $request->attributes->get('_route') !== 'jsonapi.node--service_request.collection.post') {
      return;
    }

    $rootHeader = $this->singleHeader($request, 'X-Markaspot-Emergency-Root');
    $revisionHeader = $this->singleHeader($request, 'X-Markaspot-Emergency-Revision');
    $statusHeader = $this->singleHeader($request, 'X-Markaspot-Emergency-Status');
    $idempotencyKey = $this->singleHeader(
      $request,
      'X-Markaspot-Emergency-Idempotency-Key',
    );
    $isRevisionPinned = $rootHeader !== NULL
      || $revisionHeader !== NULL
      || $statusHeader !== NULL;
    // A privileged operator may create or import content outside the citizen
    // intake flow. Everyone else is server-side scoped even if a custom client
    // removes the Lite revision headers.
    if (!$isRevisionPinned
      && $idempotencyKey === NULL
      && $this->currentUser->hasPermission('administer emergency mode')) {
      return;
    }

    if ($isRevisionPinned
      && (!is_string($rootHeader)
        || !preg_match('/^\d{1,16}$/', $rootHeader)
        || !is_string($revisionHeader)
        || !preg_match('/^\d{1,16}$/', $revisionHeader)
        || ($statusHeader !== NULL
          && (!is_string($statusHeader)
            || !in_array($statusHeader, ['active', 'off'], TRUE))))) {
      throw new BadRequestHttpException('Emergency root, revision, or expected status is invalid.');
    }

    $rootId = $isRevisionPinned ? (int) $rootHeader : NULL;
    $revision = $isRevisionPinned ? (int) $revisionHeader : NULL;
    $expectedStatus = $isRevisionPinned ? ($statusHeader ?? 'active') : NULL;
    if ($isRevisionPinned
      && ($rootId < 0 || $revision < 0 || ($expectedStatus === 'active' && $revision === 0))) {
      throw new BadRequestHttpException('Emergency root, revision, or expected status is invalid.');
    }
    if ($idempotencyKey !== NULL
      && (!$isRevisionPinned
        || $expectedStatus !== 'active'
        || !$this->isUuid($idempotencyKey))) {
      throw new BadRequestHttpException('The emergency idempotency key is only valid for an active Lite submission.');
    }
    $declaredLength = $request->headers->get('Content-Length');
    if (is_string($declaredLength)
      && ctype_digit($declaredLength)
      && (int) $declaredLength > self::MAX_DOCUMENT_BYTES) {
      throw new HttpException(413, 'The emergency JSON:API document is too large.');
    }
    $content = $this->readBoundedContent($request);

    try {
      $document = json_decode($content, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new BadRequestHttpException('The emergency JSON:API document is invalid.', $exception);
    }
    if (!is_array($document)) {
      throw new BadRequestHttpException('The emergency JSON:API document is invalid.');
    }

    $relationships = $document['data']['relationships'] ?? NULL;
    $category = is_array($relationships)
      ? ($relationships['field_category']['data'] ?? NULL)
      : NULL;
    $hasJurisdictionRelationship = is_array($relationships)
      && array_key_exists('field_jurisdiction', $relationships);
    $jurisdictions = $hasJurisdictionRelationship
      ? ($relationships['field_jurisdiction']['data'] ?? NULL)
      : NULL;
    if (($document['data']['type'] ?? NULL) !== 'node--service_request'
      || !is_array($category)
      || ($category['type'] ?? NULL) !== 'taxonomy_term--service_category') {
      throw new BadRequestHttpException('The emergency JSON:API relationships are invalid.');
    }
    $jurisdiction = NULL;
    if (is_array($jurisdictions)) {
      if (isset($jurisdictions['type'], $jurisdictions['id'])) {
        // Existing dashboard creates use the JSON:API to-one shape.
        $jurisdiction = $jurisdictions;
      }
      elseif (count($jurisdictions) === 1 && is_array(reset($jurisdictions))) {
        // Lite uses the field's current one-item list shape.
        $jurisdiction = reset($jurisdictions);
      }
    }
    if ($hasJurisdictionRelationship && !is_array($jurisdiction)) {
      throw new BadRequestHttpException('The emergency JSON:API relationships are invalid.');
    }
    $categoryUuid = $category['id'] ?? NULL;
    if (!is_string($categoryUuid) || !$this->isUuid($categoryUuid)) {
      throw new BadRequestHttpException('The emergency JSON:API relationship identifiers are invalid.');
    }
    $relationshipJurisdictionUuid = NULL;
    if (is_array($jurisdiction)) {
      $relationshipJurisdictionUuid = $jurisdiction['id'] ?? NULL;
      if (!is_string($relationshipJurisdictionUuid)
        || !$this->isUuid($relationshipJurisdictionUuid)
        || !is_string($jurisdiction['type'] ?? NULL)
        || !str_starts_with($jurisdiction['type'], 'group--')) {
        throw new BadRequestHttpException('The emergency JSON:API relationship identifiers are invalid.');
      }
    }
    if ($isRevisionPinned && $expectedStatus === 'active') {
      if ($relationshipJurisdictionUuid === NULL) {
        throw new BadRequestHttpException('A Lite emergency submission requires one verified jurisdiction.');
      }
    }
    if ($isRevisionPinned
      && $expectedStatus === 'off'
      && $hasJurisdictionRelationship) {
      throw new BadRequestHttpException('A normal offline replay must not pin an emergency jurisdiction.');
    }
    $jurisdictionUuid = $relationshipJurisdictionUuid;

    $requestHash = NULL;
    if ($idempotencyKey !== NULL) {
      // Preserve exact JSON:API bytes. A key reused with a semantically
      // similar but not byte-identical document fails closed rather than
      // silently selecting one of two citizen reports.
      $requestHash = hash('sha256', $content);
      try {
        $replayNodeUuid = $this->idempotencyLedger()->findReplay(
          (int) $rootId,
          (int) $revision,
          $idempotencyKey,
          $requestHash,
        );
      }
      catch (\InvalidArgumentException | \LogicException | \RuntimeException $exception) {
        throw new ConflictHttpException($exception->getMessage(), $exception);
      }
      if ($replayNodeUuid !== NULL) {
        $event->setResponse(
          $this->replayResponder()->buildResponse($request, $replayNodeUuid),
        );
        return;
      }
    }

    try {
      $context = $this->emergencyService->prepareSubmissionGuard(
        $rootId,
        $revision,
        $categoryUuid,
        $jurisdictionUuid,
        $expectedStatus,
      );
    }
    catch (\InvalidArgumentException | \LogicException | \RuntimeException $exception) {
      throw new ConflictHttpException($exception->getMessage(), $exception);
    }

    if ($idempotencyKey !== NULL) {
      if (($context['expected_status'] ?? NULL) !== 'active'
        || ($context['require_lite_ui'] ?? NULL) !== TRUE
        || !is_string($requestHash)) {
        throw new ConflictHttpException('The active Lite emergency submission could not be prepared.');
      }
      $context['idempotency_key'] = strtolower($idempotencyKey);
      $context['idempotency_request_hash'] = $requestHash;
    }

    // Entity SQL storage opens its transaction before presave. Carry only the
    // server-resolved IDs and client pin to the final generic entity_presave
    // hook so the root lock covers the actual write through commit or rollback.
    $request->attributes->set(self::CONTEXT_ATTRIBUTE, $context);
  }

  /**
   * Preserves the public 409 contract after SQL storage wraps hook exceptions.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $replayNodeUuid = $request->attributes->get(self::REPLAY_ATTRIBUTE);
    if (is_string($replayNodeUuid) && $this->isUuid($replayNodeUuid)) {
      $request->attributes->remove(self::REPLAY_ATTRIBUTE);
      try {
        $event->setResponse(
          $this->replayResponder()->buildResponse($request, $replayNodeUuid),
        );
        return;
      }
      catch (\Throwable $exception) {
        $event->setThrowable(new ConflictHttpException(
          'The prior emergency submission is no longer available for replay.',
          $exception,
        ));
        return;
      }
    }

    $message = $request->attributes->get(self::FAILURE_ATTRIBUTE);
    if (is_string($message) && $message !== '') {
      $event->setThrowable(new ConflictHttpException(
        $message,
        $event->getThrowable(),
      ));
    }
  }

  /**
   * Reads at most one byte beyond the accepted JSON:API document size.
   */
  private function readBoundedContent(Request $request): string {
    $stream = $request->getContent(TRUE);
    if (!is_resource($stream)) {
      throw new BadRequestHttpException('The emergency JSON:API document is unreadable.');
    }

    $content = stream_get_contents($stream, self::MAX_DOCUMENT_BYTES + 1);
    if (!is_string($content)) {
      throw new BadRequestHttpException('The emergency JSON:API document is unreadable.');
    }
    if (strlen($content) > self::MAX_DOCUMENT_BYTES) {
      throw new HttpException(413, 'The emergency JSON:API document is too large.');
    }
    return $content;
  }

  /**
   * Reads one unambiguous emergency request header.
   */
  private function singleHeader(Request $request, string $name): ?string {
    $values = $request->headers->all($name);
    if (count($values) > 1) {
      throw new BadRequestHttpException('Emergency request headers must not be repeated.');
    }
    $value = $values[0] ?? NULL;
    return is_string($value) ? $value : NULL;
  }

  /**
   * Validates canonical UUID relationship identifiers.
   */
  private function isUuid(string $value): bool {
    return (bool) preg_match(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
      $value,
    );
  }

  /**
   * Returns the required ledger service with a fail-closed test fallback.
   */
  private function idempotencyLedger(): EmergencySubmissionIdempotencyLedgerInterface {
    if ($this->idempotencyLedger === NULL) {
      throw new \RuntimeException('Emergency idempotency storage is unavailable.');
    }
    return $this->idempotencyLedger;
  }

  /**
   * Returns the required replay responder with a fail-closed test fallback.
   */
  private function replayResponder(): EmergencySubmissionReplayResponderInterface {
    if ($this->replayResponder === NULL) {
      throw new \RuntimeException('Emergency idempotency replay is unavailable.');
    }
    return $this->replayResponder;
  }

}
