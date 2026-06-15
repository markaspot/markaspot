<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail_inbound\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\InboundMailAccessControlHandler;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\InboundMailReplyService;
use Drupal\markaspot_mail_inbound\Service\MailIntakeFidelity;
use Drupal\markaspot_mail_inbound\Service\PromotableCategoryRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Dashboard REST API for the inbound-mail triage inbox (#482).
 *
 * Deliberately a custom controller, NOT JSON:API: inbound_mail carries
 * unredacted citizen content. The JSON:API resource that core would derive
 * for the entity type is explicitly disabled by the shipped
 * jsonapi_extras resource config for inbound_mail--inbound_mail
 * (config/install + update 11903), so these routes are the only HTTP
 * surface. Routes are cookie-authenticated, permission-gated ("triage
 * inbound mail"), CSRF-protected on mutations (_csrf_request_header_token,
 * the
 * markaspot_group convention), and EVERY entity passes the
 * jurisdiction-scoped InboundMailAccessControlHandler on top of the route
 * permission. The collection additionally scopes its QUERY so a non-global
 * user only ever receives rows of their own jurisdictions (plus unscoped
 * mails, which any triage-permission holder may handle — consistent with
 * the access handler).
 *
 * Errors are JSON with proper status codes; internal exception detail goes
 * to watchdog, never into a response body.
 *
 * @phpstan-consistent-constructor
 */
class InboundMailApiController extends ControllerBase {

  /**
   * Maximum page size.
   */
  protected const MAX_LIMIT = 100;

  /**
   * Snippet length in characters for list rows.
   */
  protected const SNIPPET_LENGTH = 200;

  /**
   * Hard ceiling for a reply body, independent of the max_body_length config.
   */
  protected const REPLY_BODY_HARD_LIMIT = 20000;

  /**
   * Constructs the controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    protected InboundMailPromoter $promoter,
    protected InboundMailReplyService $replyService,
    protected PromotableCategoryRepository $categoryRepository,
    protected MailIntakeFidelity $fidelity,
    protected LoggerChannelInterface $logger,
    protected ?object $scopeValidator = NULL,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('markaspot_mail_inbound.promoter'),
      $container->get('markaspot_mail_inbound.reply'),
      $container->get('markaspot_mail_inbound.category_repository'),
      $container->get('markaspot_mail_inbound.fidelity'),
      $container->get('logger.channel.markaspot_mail_inbound'),
      $container->has('markaspot_group.jurisdiction_scope_validator')
        ? $container->get('markaspot_group.jurisdiction_scope_validator')
        : NULL,
    );
  }

  /**
   * GET /api/inbound-mail — the scoped, paginated triage list.
   */
  public function list(Request $request): JsonResponse {
    $state = trim((string) $request->query->get('state', ''));
    $validStates = [
      InboundMail::STATE_STAGED,
      InboundMail::STATE_PROMOTED,
      InboundMail::STATE_DISCARDED,
    ];
    if ($state !== '' && !in_array($state, $validStates, TRUE)) {
      return $this->errorResponse('Invalid state filter.', 422);
    }
    $page = max(0, (int) $request->query->get('page', 0));
    $limit = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', 25)));

    $storage = $this->entityTypeManager->getStorage('inbound_mail');
    $query = $storage->getQuery()->accessCheck(FALSE);

    // QUERY-LEVEL jurisdiction scoping (not only per-entity access): a
    // non-global user sees rows of their allowed jurisdictions plus
    // unscoped mails. No allowed jurisdictions or missing validator ->
    // unscoped mails only. NULL is reserved for the global bypass.
    $allowed = $this->allowedJurisdictionIds();
    if ($allowed !== NULL) {
      $group = $query->orConditionGroup()->notExists('jurisdiction_id');
      if ($allowed !== []) {
        $group->condition('jurisdiction_id', $allowed, 'IN');
      }
      $query->condition($group);
    }

    if ($state !== '') {
      $query->condition('state', $state);
    }

    $countQuery = clone $query;
    $total = (int) $countQuery->count()->execute();

    if ($state === '') {
      // Staged-first like the admin list builder. The state list_string
      // values sort staged > promoted > discarded descending, so one DESC
      // sort yields the actionable inbox on top.
      $query->sort('state', 'DESC');
    }
    $ids = $query
      ->sort('created', 'DESC')
      ->range($page * $limit, $limit)
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($ids) as $mail) {
      if (!$mail instanceof InboundMail || !$mail->access('view')) {
        // Defense-in-depth: the query scoping and the access handler encode
        // the same rule, so this filter should never drop a row.
        continue;
      }
      $items[] = $this->listItem($mail);
    }

    return new JsonResponse([
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'fidelity' => $this->fidelity->summary(),
    ]);
  }

  /**
   * GET /api/inbound-mail/{inbound_mail} — full detail.
   */
  public function detail(InboundMail $inbound_mail): JsonResponse {
    if (!$inbound_mail->access('view')) {
      return $this->errorResponse('Access denied.', 403);
    }

    $attachments = [];
    foreach ($this->loadAttachmentFiles($inbound_mail) as $file) {
      $attachments[] = [
        'fid' => (int) $file->id(),
        'filename' => $file->getFilename(),
        'filesize' => (int) $file->getSize(),
        'mime' => (string) $file->getMimeType(),
      ];
    }

    [$nid, $requestId] = $this->promotedNodeInfo($inbound_mail);
    $suggestedTid = $inbound_mail->getSuggestedCategoryTid();

    return new JsonResponse([
      'id' => (int) $inbound_mail->id(),
      'from_name' => (string) $inbound_mail->get('from_name')->value,
      'from_address' => $inbound_mail->getFromAddress(),
      'subject' => $inbound_mail->getSubject(),
      'body' => $inbound_mail->getBody(),
      'state' => $inbound_mail->getState(),
      'jurisdiction_id' => $inbound_mail->getJurisdictionId(),
      'jurisdiction_label' => $this->jurisdictionLabel($inbound_mail),
      'created' => (int) $inbound_mail->get('created')->value,
      'changed' => (int) $inbound_mail->get('changed')->value,
      'nid' => $nid,
      'request_id' => $requestId,
      'attachments' => $attachments,
      'suggested_category_tid' => $suggestedTid,
      'suggested_category_label' => $this->categoryLabel($suggestedTid),
      'suggestion_confidence' => $inbound_mail->getSuggestionConfidence(),
      'suggestion_status' => $inbound_mail->getSuggestionStatus(),
    ]);
  }

  /**
   * GET /api/inbound-mail/by-node/{node} — mail backing a service request.
   */
  public function byNode(NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'service_request') {
      return $this->errorResponse('Inbound mail not found for this node.', 404);
    }

    $storage = $this->entityTypeManager->getStorage('inbound_mail');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', (int) $node->id())
      ->condition('state', InboundMail::STATE_PROMOTED)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    $inbound_mail = $id ? $storage->load($id) : NULL;
    if (!$inbound_mail instanceof InboundMail) {
      return $this->errorResponse('Inbound mail not found for this node.', 404);
    }
    if (!$inbound_mail->access('view')) {
      return $this->errorResponse('Access denied.', 403);
    }

    return new JsonResponse([
      'id' => (int) $inbound_mail->id(),
      'nid' => (int) $node->id(),
      'from_name' => (string) $inbound_mail->get('from_name')->value,
      'from_address' => $inbound_mail->getFromAddress(),
      'subject' => $inbound_mail->getSubject(),
      'message_id' => (string) $inbound_mail->get('message_id')->value,
      'thread_message_ids' => $inbound_mail->getThreadMessageIds(),
      'created' => (int) $inbound_mail->get('created')->value,
      'body' => $inbound_mail->getBody(),
    ]);
  }

  /**
   * GET /api/inbound-mail/{inbound_mail}/attachment/{fid} — staged file.
   *
   * Streams the (private/staged) file to an authorized triage user. No
   * direct URLs are ever exposed: this route is the only way to a staged
   * attachment, and it re-checks both entity access and that the fid is one
   * of THIS mail's attachments (no cross-mail file fishing).
   */
  public function attachment(InboundMail $inbound_mail, int $fid): Response {
    if (!$inbound_mail->access('view')) {
      return $this->errorResponse('Access denied.', 403);
    }
    if (!in_array($fid, $inbound_mail->getAttachmentFileIds(), TRUE)) {
      return $this->errorResponse('Attachment not found on this mail.', 404);
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      return $this->errorResponse('Attachment not found.', 404);
    }
    $uri = (string) $file->getFileUri();
    if ($uri === '' || !is_file($uri)) {
      return $this->errorResponse('Attachment not found.', 404);
    }

    $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
    $response = new BinaryFileResponse($uri, 200, [
      'Content-Type' => $mime,
      'X-Content-Type-Options' => 'nosniff',
      'Cache-Control' => 'private, no-cache',
    ], FALSE);
    // Images may preview inline in the triage UI; everything else downloads.
    // (Staging only ever persists sniffed image types, so the else branch is
    // belt-and-braces.)
    $response->setContentDisposition(
      str_starts_with($mime, 'image/')
        ? ResponseHeaderBag::DISPOSITION_INLINE
        : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      // A generated, safe filename (staging never stores client names).
      basename($uri)
    );
    return $response;
  }

  /**
   * POST /api/inbound-mail/{inbound_mail}/promote — categorize & promote.
   */
  public function promote(InboundMail $inbound_mail, Request $request): JsonResponse {
    if (!$inbound_mail->access('update')) {
      return $this->errorResponse('Access denied.', 403);
    }
    if ($inbound_mail->getState() !== InboundMail::STATE_STAGED) {
      return $this->errorResponse('Only a staged mail can be promoted.', 409);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    $categoryTid = (int) (is_array($payload) ? ($payload['category_tid'] ?? 0) : 0);
    if ($categoryTid <= 0) {
      return $this->errorResponse('category_tid is required.', 422);
    }
    // Shared promotability rule (the promote form uses the same repository):
    // the category must belong to the mail's jurisdiction scope. Both coded
    // and codeless categories are promotable; unknown or foreign-jurisdiction
    // category tids are rejected here.
    if (!$this->categoryRepository->isPromotable($categoryTid, $inbound_mail->getJurisdictionId())) {
      return $this->errorResponse('The category is not valid for this jurisdiction.', 422);
    }

    try {
      $node = $this->promoter->promoteToServiceRequest($inbound_mail, $categoryTid);
    }
    catch (\RuntimeException $e) {
      // The promoter throws RuntimeException for the processor-unavailable
      // degradation; state and category were validated above. Metadata-only
      // logging (exception TYPE, the InboundMailReplyService convention): a
      // message could echo mail-derived content.
      $this->logger->error('API promotion of inbound mail @id failed (@type).', [
        '@id' => $inbound_mail->id(),
        '@type' => get_class($e),
      ]);
      return $this->errorResponse('Promotion is unavailable on this install.', 503);
    }
    catch (\Throwable $e) {
      $this->logger->error('API promotion of inbound mail @id failed (@type).', [
        '@id' => $inbound_mail->id(),
        '@type' => get_class($e),
      ]);
      return $this->errorResponse('Promotion failed. The error has been logged.', 500);
    }

    return new JsonResponse([
      'nid' => (int) $node->id(),
      'request_id' => $this->nodeRequestId($node),
      'uuid' => (string) $node->uuid(),
      'ungeolocated' => !$node->hasField('field_geolocation') || $node->get('field_geolocation')->isEmpty(),
    ]);
  }

  /**
   * POST /api/inbound-mail/{inbound_mail}/discard — reject as not-a-report.
   */
  public function discard(InboundMail $inbound_mail): JsonResponse {
    if (!$inbound_mail->access('update')) {
      return $this->errorResponse('Access denied.', 403);
    }
    if ($inbound_mail->getState() !== InboundMail::STATE_STAGED) {
      return $this->errorResponse('Only a staged mail can be discarded.', 409);
    }

    try {
      $this->promoter->discard($inbound_mail);
    }
    catch (\Throwable $e) {
      $this->logger->error('API discard of inbound mail @id failed (@type).', [
        '@id' => $inbound_mail->id(),
        '@type' => get_class($e),
      ]);
      return $this->errorResponse('Discard failed. The error has been logged.', 500);
    }

    return new JsonResponse(['state' => InboundMail::STATE_DISCARDED]);
  }

  /**
   * POST /api/inbound-mail/{inbound_mail}/reply — answer the citizen.
   *
   * Staged mails only: replying to a PROMOTED request happens through the
   * normal status-note / notification flow (which threads via
   * markaspot_mail_inbound_mail_alter()). When outbound mail is unavailable
   * the reply is recorded on the conversation log with sent=false (the
   * "in-system notes only" degradation).
   */
  public function reply(InboundMail $inbound_mail, Request $request): JsonResponse {
    if (!$inbound_mail->access('update')) {
      return $this->errorResponse('Access denied.', 403);
    }
    if ($inbound_mail->getState() !== InboundMail::STATE_STAGED) {
      return $this->errorResponse('Only a staged mail can be replied to here.', 409);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    $body = trim((string) (is_array($payload) ? ($payload['body'] ?? '') : ''));
    if ($body === '') {
      return $this->errorResponse('body is required.', 422);
    }
    // Server-side length limit: the conversation log would otherwise be
    // flooded through this endpoint (the client-side limit is advisory
    // only). Bounded by the shared max_body_length setting, hard-capped at
    // 20000 characters even when the setting is raised or unset.
    $maxLength = min(
      ((int) $this->config('markaspot_mail_inbound.settings')->get('max_body_length')) ?: self::REPLY_BODY_HARD_LIMIT,
      self::REPLY_BODY_HARD_LIMIT
    );
    if (mb_strlen($body) > $maxLength) {
      return $this->errorResponse(sprintf('body exceeds the maximum length of %d characters.', $maxLength), 422);
    }

    try {
      $sent = $this->replyService->sendReply($inbound_mail, $body);
    }
    catch (\Throwable $e) {
      $this->logger->error('API reply for inbound mail @id failed (@type).', [
        '@id' => $inbound_mail->id(),
        '@type' => get_class($e),
      ]);
      return $this->errorResponse('Reply failed. The error has been logged.', 500);
    }

    return new JsonResponse([
      'sent' => $sent,
      'recorded' => TRUE,
    ]);
  }

  /**
   * Builds one list row.
   *
   * @return array<string, mixed>
   *   The row, matching the #482 frontend contract.
   */
  protected function listItem(InboundMail $mail): array {
    [$nid, $requestId] = $this->promotedNodeInfo($mail);
    // Data minimization (GDPR): the citizen's address is NOT part of a list
    // row — the list renders from_name only; the address lives in detail()
    // where a triage user inspects one specific mail.
    $suggestedTid = $mail->getSuggestedCategoryTid();
    return [
      'id' => (int) $mail->id(),
      'from_name' => (string) $mail->get('from_name')->value,
      'subject' => $mail->getSubject(),
      'snippet' => $this->snippet($mail->getBody()),
      'state' => $mail->getState(),
      'jurisdiction_id' => $mail->getJurisdictionId(),
      'jurisdiction_label' => $this->jurisdictionLabel($mail),
      'created' => (int) $mail->get('created')->value,
      'changed' => (int) $mail->get('changed')->value,
      'attachment_count' => count($mail->getAttachmentFileIds()),
      'nid' => $nid,
      'request_id' => $requestId,
      'suggested_category_tid' => $suggestedTid,
      'suggested_category_label' => $this->categoryLabel($suggestedTid),
      'suggestion_confidence' => $mail->getSuggestionConfidence(),
      'suggestion_status' => $mail->getSuggestionStatus(),
    ];
  }

  /**
   * Loads the mail's attachment file entities.
   *
   * @return \Drupal\file\FileInterface[]
   *   The existing managed files referenced by attachment_files.
   */
  protected function loadAttachmentFiles(InboundMail $mail): array {
    $fids = $mail->getAttachmentFileIds();
    if ($fids === []) {
      return [];
    }
    $files = [];
    foreach ($this->entityTypeManager->getStorage('file')->loadMultiple($fids) as $file) {
      if ($file instanceof FileInterface) {
        $files[] = $file;
      }
    }
    return $files;
  }

  /**
   * First ~200 characters of the plain-text body.
   */
  protected function snippet(string $body): string {
    $body = trim($body);
    if (mb_strlen($body) <= self::SNIPPET_LENGTH) {
      return $body;
    }
    return rtrim(mb_substr($body, 0, self::SNIPPET_LENGTH)) . '…';
  }

  /**
   * The mail's jurisdiction group label, or NULL.
   */
  protected function jurisdictionLabel(InboundMail $mail): ?string {
    $group = $mail->get('jurisdiction_id')->entity;
    return $group !== NULL ? (string) $group->label() : NULL;
  }

  /**
   * Returns the label of a taxonomy term by id, or NULL when absent.
   *
   * @param int|null $tid
   *   The term id, or NULL.
   *
   * @return string|null
   *   The term label, or NULL.
   */
  protected function categoryLabel(?int $tid): ?string {
    if ($tid === NULL || $tid <= 0) {
      return NULL;
    }
    try {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      return $term !== NULL ? (string) $term->label() : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Resolves [nid, request_id] of the promoted node, when any.
   *
   * @return array{0: int|null, 1: string|null}
   *   Node id and citizen-facing request id, both NULL while not promoted.
   */
  protected function promotedNodeInfo(InboundMail $mail): array {
    $node = $mail->getServiceRequest();
    if ($node === NULL) {
      return [NULL, NULL];
    }
    return [(int) $node->id(), $this->nodeRequestId($node)];
  }

  /**
   * The node's citizen-facing request id (markaspot_request_id base field).
   */
  protected function nodeRequestId(NodeInterface $node): ?string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      return (string) $node->get('request_id')->value;
    }
    return NULL;
  }

  /**
   * Jurisdiction ids the current user may see, or NULL for global users.
   *
   * @return int[]|null
   *   NULL = no scoping for the global bypass; otherwise the allowed group
   *   ids. Missing validator fails closed to an empty list for non-global
   *   users, so the query only returns unscoped mail.
   */
  protected function allowedJurisdictionIds(): ?array {
    if (InboundMailAccessControlHandler::hasGlobalBypass($this->currentUser)) {
      return NULL;
    }
    if ($this->scopeValidator === NULL || !method_exists($this->scopeValidator, 'getAllowedJurisdictionIds')) {
      return [];
    }
    return array_map('intval', $this->scopeValidator->getAllowedJurisdictionIds($this->currentUser));
  }

  /**
   * Builds a JSON error response (no internal detail).
   */
  protected function errorResponse(string $message, int $status): JsonResponse {
    return new JsonResponse(['error' => $message], $status);
  }

}
