<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsConflictException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsForbiddenException;
use Drupal\markaspot_dashboard\Service\Exception\MailTextsNotFoundException;
use Drupal\markaspot_dashboard\Service\MailTextsServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST endpoints for the Nuxt dashboard's mail-text editor.
 *
 * Thin controller: request parsing and exception-to-status-code mapping
 * only. Validation, config I/O, token-catalog generation and ECA-reference
 * scanning all live in MailTextsService, so they are unit-testable without
 * a bootstrapped kernel. All four routes require the 'administer markaspot
 * mail texts' permission (see markaspot_dashboard.routing.yml); the three
 * mutating routes (PUT/DELETE/preview) additionally require the CSRF
 * header token, mirroring SplitController's convention.
 *
 * @phpstan-consistent-constructor
 */
class MailTextsController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * $currentUser is assigned in the body, not promoted: ControllerBase
   * already declares an untyped `$currentUser` property (via its
   * currentUser() getter), and redeclaring it as a typed promoted property
   * is a fatal "type must not be defined" error. Mirrors SplitController's
   * constructor for the same reason.
   */
  public function __construct(
    protected MailTextsServiceInterface $mailTextsService,
    AccountProxyInterface $currentUser,
  ) {
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_dashboard.mail_texts'),
      $container->get('current_user'),
    );
  }

  /**
   * GET /api/dashboard/mail-texts.
   */
  public function catalog(): JsonResponse {
    return new JsonResponse($this->mailTextsService->getCatalog());
  }

  /**
   * PUT /api/dashboard/mail-texts/{key}.
   */
  public function save(string $key, Request $request): JsonResponse {
    $payload = $this->decodeBody($request);
    if ($payload === FALSE) {
      return $this->errorResponse('Invalid JSON body.', 400);
    }

    try {
      $result = $this->mailTextsService->saveText($key, $payload, $this->currentUser);
    }
    catch (\InvalidArgumentException $e) {
      return $this->errorResponse($e->getMessage(), 422);
    }

    return new JsonResponse($result);
  }

  /**
   * DELETE /api/dashboard/mail-texts/{key}.
   */
  public function delete(string $key): JsonResponse {
    try {
      $deleted = $this->mailTextsService->deleteText($key, $this->currentUser);
    }
    catch (\InvalidArgumentException $e) {
      return $this->errorResponse($e->getMessage(), 422);
    }
    catch (MailTextsNotFoundException $e) {
      return $this->errorResponse($e->getMessage(), 404);
    }
    catch (MailTextsForbiddenException $e) {
      return $this->errorResponse($e->getMessage(), 403);
    }
    catch (MailTextsConflictException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'referenced_by' => $e->getReferencedBy(),
      ], 409);
    }

    return new JsonResponse(['deleted' => $deleted]);
  }

  /**
   * POST /api/dashboard/mail-texts/preview.
   */
  public function preview(Request $request): JsonResponse {
    $payload = $this->decodeBody($request);
    if ($payload === FALSE) {
      return $this->errorResponse('Invalid JSON body.', 400);
    }

    return new JsonResponse($this->mailTextsService->preview($payload));
  }

  /**
   * Decodes a JSON request body into an array.
   *
   * @return array<string, mixed>|false
   *   The decoded body (an empty array for an empty/absent body), or FALSE
   *   when the body is present but not a JSON object.
   */
  private function decodeBody(Request $request): array|false {
    $raw = (string) $request->getContent();
    if (trim($raw) === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : FALSE;
  }

  /**
   * Builds a JSON error response.
   */
  private function errorResponse(string $message, int $status): JsonResponse {
    return new JsonResponse(['error' => $message], $status);
  }

}
