<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceDeletionService;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoints of the self-service workspace deletion.
 *
 * - request: the tenant admin's browser session (through the Nuxt proxy).
 * - peek/confirm: server-to-server from the Nuxt layer with the service key,
 *   because only that layer holds the Stripe credentials. The one-time token
 *   from the confirmation mail is the proof of the admin's mailbox.
 */
class WorkspaceDeletionController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  private const REQUEST_FLOOD_EVENT = 'markaspot_fastmap.workspace_deletion_request';
  private const REQUEST_FLOOD_LIMIT = 5;
  private const REQUEST_FLOOD_WINDOW = 3600;

  /**
   * The workspace deletion service.
   */
  protected WorkspaceDeletionService $deletion;

  /**
   * The flood service.
   */
  protected FloodInterface $flood;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->deletion = $container->get('markaspot_fastmap.workspace_deletion');
    $instance->flood = $container->get('flood');
    return $instance;
  }

  /**
   * Access: tenant admins of exactly this workspace.
   */
  public function requestAccess(string $group, AccountInterface $account): AccessResultInterface {
    $entity = $this->loadWorkspace($group);
    if (!$entity) {
      return AccessResult::forbidden('Workspace not found.')->setCacheMaxAge(0);
    }
    return AccessResult::allowedIf($this->deletion->isWorkspaceAdmin($entity, $account))
      ->setCacheMaxAge(0);
  }

  /**
   * POST /api/fastmap/workspace-deletion/request/{group}.
   *
   * Body: {slug: string, frontend_base_url?: string}.
   */
  public function request(string $group, Request $request): JsonResponse {
    $uid = (string) $this->currentUser()->id();
    if (!$this->flood->isAllowed(self::REQUEST_FLOOD_EVENT, self::REQUEST_FLOOD_LIMIT, self::REQUEST_FLOOD_WINDOW, $uid)) {
      return $this->json(['error' => 'too_many_requests'], 429);
    }
    $this->flood->register(self::REQUEST_FLOOD_EVENT, self::REQUEST_FLOOD_WINDOW, $uid);

    $entity = $this->loadWorkspace($group);
    $data = json_decode($request->getContent(), TRUE);
    if (!$entity || !is_array($data) || !is_string($data['slug'] ?? NULL)) {
      return $this->json(['error' => 'invalid_request'], 400);
    }

    $base = $this->frontendBase($request, is_string($data['frontend_base_url'] ?? NULL) ? $data['frontend_base_url'] : '');
    $result = $this->deletion->request($entity, $this->currentUser(), $data['slug'], $base);

    return match ($result['status']) {
      'sent' => $this->json([
        'status' => 'sent',
        'email' => $result['email'],
        'expires_in' => WorkspaceDeletionService::TOKEN_TTL,
      ], 202),
      'slug_mismatch' => $this->json(['error' => 'slug_mismatch'], 422),
      'not_eligible' => $this->json(['error' => 'not_eligible', 'reason' => $result['reason']], 409),
      'no_email' => $this->json(['error' => 'no_email'], 409),
      default => $this->json(['error' => 'mail_failed'], 502),
    };
  }

  /**
   * POST /api/fastmap/workspace-deletion/peek (service key).
   *
   * Body: {token: string}. Does not redeem the token.
   */
  public function peek(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!$this->hasValidServiceKey($request)) {
      return $this->json(['error' => 'forbidden'], 403);
    }
    $resolved = is_array($data) && is_string($data['token'] ?? NULL) ? $this->deletion->peek($data['token']) : NULL;
    if ($resolved === NULL) {
      return $this->json(['error' => 'invalid_or_expired'], 404);
    }
    $group = $resolved['group'];
    return $this->json([
      'group_id' => (int) $group->id(),
      'slug' => $this->stringField($group, 'field_slug'),
      'label' => (string) $group->label(),
      'tier' => $this->stringField($group, 'field_tier'),
      'stripe_customer_id' => $this->stringField($group, 'field_stripe_customer_id') ?: NULL,
      'stripe_subscription_id' => $this->stringField($group, 'field_stripe_subscription_id') ?: NULL,
      'grace_days' => $this->deletion->graceDays(),
    ]);
  }

  /**
   * POST /api/fastmap/workspace-deletion/confirm (service key).
   *
   * Body: {token, subscriptions_cancelled: bool, support_email?: string}.
   */
  public function confirm(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!$this->hasValidServiceKey($request)) {
      return $this->json(['error' => 'forbidden'], 403);
    }
    if (!is_array($data) || !is_string($data['token'] ?? NULL)) {
      return $this->json(['error' => 'invalid_or_expired'], 404);
    }
    $result = $this->deletion->confirm(
      $data['token'],
      ($data['subscriptions_cancelled'] ?? FALSE) === TRUE,
      is_string($data['support_email'] ?? NULL) ? $data['support_email'] : NULL,
    );

    return match ($result['status']) {
      'scheduled' => $this->json(['status' => 'scheduled', 'scheduled_for' => $result['scheduled_for']]),
      'subscription_active' => $this->json(['error' => 'subscription_active'], 409),
      'busy' => $this->json(['error' => 'busy'], 409),
      default => $this->json(['error' => 'invalid_or_expired'], 404),
    };
  }

  /**
   * Picks the origin for the confirmation link.
   *
   * The Nuxt layer passes its own origin; it is accepted only when its host
   * matches the configured verify_base_url, so a forged value cannot point
   * the link at a foreign site. Same rule as the signup verification link.
   */
  private function frontendBase(Request $request, string $requested): string {
    $configured = (string) ($this->config('markaspot_fastmap.settings')->get('verify_base_url') ?? '');
    if ($requested !== '' && $configured !== '' && $this->origin($requested) !== NULL
      && $this->origin($requested) === $this->origin($configured)) {
      return $this->origin($requested);
    }
    return $configured !== '' ? $configured : $request->getSchemeAndHttpHost();
  }

  /**
   * Returns scheme://host[:port] of an http(s) URL, or NULL.
   */
  private function origin(string $url): ?string {
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || empty($parts['host'])) {
      return NULL;
    }
    return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

  /**
   * Validates the X-Service-Key header against config.
   */
  private function hasValidServiceKey(Request $request): bool {
    $expected = (string) ($this->config('markaspot_fastmap.settings')->get('service_key') ?? '');
    $given = (string) ($request->headers->get('X-Service-Key') ?? '');
    return $expected !== '' && $given !== '' && hash_equals($expected, $given);
  }

  /**
   * Loads a jurisdiction workspace by ID or slug.
   */
  private function loadWorkspace(string $identifier): ?GroupInterface {
    $id = $this->resolveJurisdictionId($identifier);
    if ($id === NULL) {
      return NULL;
    }
    $group = $this->entityTypeManager()->getStorage('group')->load($id);
    return $this->isJurisdictionGroup($group) ? $group : NULL;
  }

  /**
   * Returns a trimmed string field value, or ''.
   */
  private function stringField(GroupInterface $group, string $field): string {
    return $group->hasField($field) && !$group->get($field)->isEmpty() ? trim((string) $group->get($field)->value) : '';
  }

  /**
   * JSON response that is never cached.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private']);
  }

}
