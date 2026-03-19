<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles FastMap workspace creation with email verification.
 *
 * Flow: POST create-workspace -> stores pending record -> sends verification
 * email -> GET verify/{token} -> provisions workspace -> redirects to dashboard.
 */
class FastMapWorkspaceController extends ControllerBase {

  /**
   * Verification token time-to-live in seconds (48 hours).
   */
  private const VERIFICATION_TTL = 172800;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The workspace provisioning service.
   *
   * @var \Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface
   */
  protected WorkspaceProvisioningServiceInterface $provisioning;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The FastMap logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $fastmapLogger;

  /**
   * The expirable key-value store factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected KeyValueExpirableFactoryInterface $keyValueExpirable;

  /**
   * The flood control service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  /**
   * The group membership loader (NULL if group module not installed).
   *
   * @var mixed|null
   */
  protected mixed $membershipLoader = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    $instance->provisioning = $container->get('markaspot_fastmap.workspace_provisioning');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->fastmapLogger = $container->get('logger.channel.markaspot_fastmap');
    $instance->keyValueExpirable = $container->get('keyvalue.expirable');
    $instance->flood = $container->get('flood');
    if ($container->has('group.membership_loader')) {
      $instance->membershipLoader = $container->get('group.membership_loader');
    }
    return $instance;
  }

  /**
   * POST /api/fastmap/create-workspace.
   *
   * Validates input, stores a pending record and sends a verification email.
   * The workspace is NOT created until the token is verified.
   */
  public function createWorkspace(Request $request): JsonResponse {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (!$data) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    // Validate service key.
    $config = $this->config('markaspot_fastmap.settings');
    $expectedKey = $config->get('service_key');
    $apiKey = $data['service_key'] ?? $request->query->get('service_key');
    if (!$expectedKey || !$apiKey || !hash_equals($expectedKey, (string) $apiKey)) {
      return new JsonResponse(['error' => 'Invalid API key'], 403);
    }

    // Validate required fields.
    $name = mb_substr(trim($data['name'] ?? ''), 0, 255);
    $slug = trim($data['slug'] ?? '');
    $email = trim($data['email'] ?? '');
    $categories = $data['categories'] ?? [];

    if (!$name || !$slug) {
      return new JsonResponse(['error' => 'name and slug are required'], 400);
    }

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse(['error' => 'A valid email address is required'], 400);
    }

    if (!preg_match('/^[a-z0-9-]{2,30}$/', $slug)) {
      return new JsonResponse(['error' => 'slug must be 2-30 chars, lowercase alphanumeric and hyphens'], 400);
    }

    if (empty($categories) || !is_array($categories)) {
      return new JsonResponse(['error' => 'categories must be provided'], 400);
    }

    // Check slug uniqueness early.
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    $existing = $groupStorage->loadByProperties(['field_slug' => $slug]);
    if (!empty($existing)) {
      return new JsonResponse(['error' => 'Slug already taken'], 409);
    }

    // Check for existing pending request with same slug.
    $pendingExists = $this->database->select('markaspot_fastmap_pending', 'p')
      ->fields('p', ['id'])
      ->where("JSON_UNQUOTE(JSON_EXTRACT(p.workspace_data, '$.slug')) = :slug", [':slug' => $slug])
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($pendingExists) {
      return new JsonResponse(['error' => 'A pending request for this slug already exists'], 409);
    }

    // Generate verification token.
    $token = bin2hex(random_bytes(32));

    // Validate optional custom statuses.
    $statuses = $data['statuses'] ?? NULL;
    if ($statuses !== NULL) {
      if (!is_array($statuses)) {
        $statuses = NULL;
      }
      else {
        $validMappings = ['initial', 'open', 'closed'];
        $statuses = array_filter($statuses, function ($s) use ($validMappings) {
          return is_array($s)
            && !empty($s['name']) && is_string($s['name'])
            && !empty($s['hex']) && is_string($s['hex']) && preg_match('/^#[0-9a-fA-F]{6}$/', $s['hex'])
            && !empty($s['icon']) && is_string($s['icon']) && preg_match('/^i-[a-z0-9-]+$/', $s['icon'])
            && !empty($s['mapping']) && in_array($s['mapping'], $validMappings, TRUE);
        });
        $mappingsPresent = array_unique(array_column($statuses, 'mapping'));
        if (count(array_intersect($validMappings, $mappingsPresent)) < 3) {
          $statuses = NULL;
        }
        else {
          $statuses = array_values(array_slice($statuses, 0, 10));
        }
      }
    }

    // Validate optional status translations: Record<lang, string[]>.
    $statusTranslations = [];
    if (isset($data['status_translations']) && is_array($data['status_translations'])) {
      foreach ($data['status_translations'] as $lang => $names) {
        if (!is_string($lang) || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang) || !is_array($names)) {
          continue;
        }
        $sanitized = [];
        foreach ($names as $statusName) {
          if (is_string($statusName)) {
            $sanitized[] = mb_substr(strip_tags(trim($statusName)), 0, 255);
          }
        }
        if (!empty($sanitized)) {
          $statusTranslations[$lang] = $sanitized;
        }
      }
    }

    // Validate optional start page translations: Record<lang, {title, body}>.
    $startPageTranslations = [];
    if (isset($data['start_page_translations']) && is_array($data['start_page_translations'])) {
      foreach ($data['start_page_translations'] as $lang => $content) {
        if (!is_string($lang) || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang) || !is_array($content)) {
          continue;
        }
        $transTitle = mb_substr(strip_tags(trim((string) ($content['title'] ?? ''))), 0, 255);
        $transBody = mb_substr(trim((string) ($content['body'] ?? '')), 0, 2000);
        if ($transTitle && $transBody) {
          $startPageTranslations[$lang] = [
            'title' => $transTitle,
            'body' => $transBody,
          ];
        }
      }
    }

    // Store all workspace data for later provisioning.
    $workspaceData = [
      'name' => $name,
      'slug' => $slug,
      'email' => $email,
      'categories' => $categories,
      'lat' => max(-90.0, min(90.0, (float) ($data['lat'] ?? 0))),
      'lng' => max(-180.0, min(180.0, (float) ($data['lng'] ?? 0))),
      'zoom' => (int) ($data['zoom'] ?? 13),
      'template' => $data['template'] ?? 'civic-report',
      'language' => $data['language'] ?? '',
      'boundary' => $this->validateBoundarySize($data['boundary'] ?? NULL),
      'statuses' => $statuses,
      'status_translations' => $statusTranslations ?: NULL,
      'start_page' => isset($data['start_page']) && is_array($data['start_page'])
        ? [
          'title' => mb_substr((string) ($data['start_page']['title'] ?? ''), 0, 255),
          'body' => mb_substr((string) ($data['start_page']['body'] ?? ''), 0, 2000),
        ]
        : NULL,
      'start_page_translations' => $startPageTranslations ?: NULL,
      'demo' => !empty($data['demo']),
    ];

    try {
      $this->database->insert('markaspot_fastmap_pending')
        ->fields([
          'token' => $token,
          'email' => $email,
          'workspace_data' => json_encode($workspaceData, JSON_UNESCAPED_UNICODE),
          'created' => time(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->fastmapLogger->error('Failed to store pending workspace: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to create pending workspace'], 500);
    }

    // Build verify URL: prefer frontend_base_url from request (set by Nuxt
    // proxy) if it matches the configured verify_base_url origin, then config,
    // then fall back to request host.
    $verifyBaseUrl = $config->get('verify_base_url');
    $frontendBase = $data['frontend_base_url'] ?? '';
    if ($frontendBase && preg_match('#^https?://#', $frontendBase)) {
      $configuredOrigin = $verifyBaseUrl ? parse_url($verifyBaseUrl, PHP_URL_HOST) : '';
      $requestedHost = parse_url($frontendBase, PHP_URL_HOST);
      if ($configuredOrigin && $requestedHost === $configuredOrigin) {
        $verifyUrl = rtrim($frontendBase, '/') . '/start/verify/' . $token;
      }
    }
    if (!isset($verifyUrl) && $verifyBaseUrl) {
      $verifyUrl = rtrim($verifyBaseUrl, '/') . '/start/verify/' . $token;
    }
    elseif (!isset($verifyUrl)) {
      $verifyUrl = $request->getSchemeAndHttpHost() . '/start/verify/' . $token;
    }

    $cleanupDays = (int) ($config->get('cleanup_days') ?? 7);
    $language = $workspaceData['language'] ?: 'en';

    // Send verification email. If it fails, roll back the pending row.
    $sent = $this->sendVerificationEmail($email, $verifyUrl, $name, $slug, $language, $cleanupDays);

    if (!$sent) {
      $this->database->delete('markaspot_fastmap_pending')
        ->condition('token', $token)
        ->execute();

      $this->fastmapLogger->error('Verification email failed for @email (slug: @slug). Pending row removed.', [
        '@email' => $email,
        '@slug' => $slug,
      ]);

      return new JsonResponse(['error' => 'Failed to send verification email. Please try again.'], 503);
    }

    // Clean up expired pending requests.
    $this->cleanupExpired();

    return new JsonResponse([
      'slug' => $slug,
      'name' => $name,
      'status' => 'pending',
      'message' => 'Check your email to verify the workspace.',
    ], 202);
  }

  /**
   * GET /start/verify/{token}.
   *
   * Looks up the pending record, provisions the workspace, then redirects.
   */
  public function verifyWorkspace(string $token): JsonResponse|TrustedRedirectResponse {
    $wantsJsonResponse = $this->wantsJsonVerifyResponse();
    $record = $this->database->select('markaspot_fastmap_pending', 'p')
      ->fields('p')
      ->condition('token', $token)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$record) {
      // Token may have been consumed by a prefetch/preload from the email
      // client. Check if the workspace was already provisioned by looking
      // for a verified record or matching group.
      $verified = $this->database->select('markaspot_fastmap_verified', 'v')
        ->fields('v', ['slug'])
        ->condition('token', $token)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($verified) {
        $baseUrl = $this->config('markaspot_fastmap.settings')->get('workspace_base_url');
        $loginToken = $this->createLoginTokenForVerifiedWorkspace($verified);

        if ($baseUrl && !$wantsJsonResponse) {
          return $this->buildWorkspaceRedirectResponse($verified, NULL, $loginToken);
        }

        $response = ['slug' => $verified];
        if ($loginToken) {
          $response['login_token'] = $loginToken;
        }
        return new JsonResponse($response, 200);
      }

      return new JsonResponse(['error' => 'Invalid or expired verification token'], 404);
    }

    // Check expiration using a fixed 48-hour security window.
    $config = $this->config('markaspot_fastmap.settings');

    if ((time() - (int) $record['created']) > self::VERIFICATION_TTL) {
      $this->database->delete('markaspot_fastmap_pending')
        ->condition('id', $record['id'])
        ->execute();
      return new JsonResponse(['error' => 'Verification token has expired'], 410);
    }

    $workspaceData = json_decode($record['workspace_data'], TRUE);
    if (!$workspaceData) {
      return new JsonResponse(['error' => 'Corrupted workspace data'], 500);
    }

    // Wrap verify-provision-delete in a transaction to prevent race conditions.
    $transaction = $this->database->startTransaction();
    try {
      $result = $this->provisioning->provisionWorkspace($workspaceData);

      // Move from pending to verified (allows re-verify after prefetch).
      $this->database->delete('markaspot_fastmap_pending')
        ->condition('id', $record['id'])
        ->execute();

      $this->database->merge('markaspot_fastmap_verified')
        ->keys(['token' => $token])
        ->fields([
          'slug' => $result['slug'],
          'created' => time(),
        ])
        ->execute();

      $this->fastmapLogger->info('Workspace provisioned via email verification: @slug (group @id)', [
        '@slug' => $result['slug'],
        '@id' => $result['group_id'],
      ]);

      // Generate a short-lived one-time login token so the user is
      // automatically logged in after email verification.
      // Uses the same keyvalue.expirable store as the dev switch-token flow,
      // but with a production-safe 5-minute TTL and no devel-module guard.
      $loginToken = $this->createWorkspaceLoginToken((int) $result['user_id'], $result['slug']);

      // Redirect to workspace dashboard or return JSON.
      $baseUrl = $config->get('workspace_base_url');
      if ($baseUrl && !$wantsJsonResponse) {
        // Clean up verified rows older than 24 hours.
        $this->cleanupVerified();
        return $this->buildWorkspaceRedirectResponse($result['slug'], (int) $result['group_id'], $loginToken);
      }

      $response = [
        'id' => $result['group_id'],
        'slug' => $result['slug'],
        'name' => $result['name'],
        'url' => $result['url'],
        'categories' => $result['categories'],
        'status' => 'provisioned',
      ];
      if ($loginToken) {
        $response['login_token'] = $loginToken;
      }

      // Clean up verified rows older than 24 hours.
      $this->cleanupVerified();

      return new JsonResponse($response, 201);
    }
    catch (\RuntimeException $e) {
      $transaction->rollBack();
      // Slug taken race condition or other provisioning error.
      return new JsonResponse(['error' => $e->getMessage()], 409);
    }
  }

  /**
   * Validates boundary size, returns NULL if too large (max 512KB).
   */
  private function validateBoundarySize(mixed $boundary): mixed {
    if ($boundary === NULL) {
      return NULL;
    }
    $encoded = json_encode($boundary);
    if ($encoded === FALSE || strlen($encoded) > 512 * 1024) {
      return NULL;
    }
    return $boundary;
  }

  /**
   * Returns TRUE when verify should respond with JSON instead of redirecting.
   */
  private function wantsJsonVerifyResponse(): bool {
    $request = \Drupal::request();
    if (!$request) {
      return FALSE;
    }

    $modeHeader = strtolower((string) $request->headers->get('X-Fastmap-Response-Mode', ''));
    if ($modeHeader === 'json') {
      return TRUE;
    }

    return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'application/json');
  }

  /**
   * Creates a short-lived workspace login token.
   */
  private function createWorkspaceLoginToken(int $uid, string $slug): ?string {
    try {
      $loginToken = bin2hex(random_bytes(32));
      $store = $this->keyValueExpirable->get('markaspot_fastmap_login_tokens');
      $store->setWithExpire($loginToken, [
        'uid' => $uid,
        'slug' => $slug,
      ], 300);
      return $loginToken;
    }
    catch (\Exception $e) {
      // Non-fatal: workspace is provisioned, user just won't be auto-logged in.
      $this->fastmapLogger->warning('Could not generate login token for @slug: @msg', [
        '@slug' => $slug,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Re-issues a login token for an already verified workspace when possible.
   */
  private function createLoginTokenForVerifiedWorkspace(string $slug): ?string {
    $userId = $this->resolveVerifiedWorkspaceUserId($slug);
    if (!$userId) {
      return NULL;
    }

    return $this->createWorkspaceLoginToken($userId, $slug);
  }

  /**
   * Resolves the tenant admin user ID for a provisioned workspace slug.
   */
  private function resolveVerifiedWorkspaceUserId(string $slug): ?int {
    $groupStorage = $this->entityTypeManager()->getStorage('group');
    $groups = $groupStorage->loadByProperties(['field_slug' => $slug]);
    $group = reset($groups);
    if (!$group) {
      return NULL;
    }

    $relationshipStorage = $this->entityTypeManager()->getStorage('group_relationship');
    $memberships = $relationshipStorage->loadByProperties([
      'gid' => $group->id(),
      'plugin_id' => 'group_membership',
    ]);

    foreach ($memberships as $membership) {
      if (!$membership->hasField('group_roles') || !$membership->hasField('entity_id')) {
        continue;
      }

      $roleIds = array_column($membership->get('group_roles')->getValue(), 'target_id');
      if (!in_array('jur-tenant_admin', $roleIds, TRUE)) {
        continue;
      }

      $targetId = (int) ($membership->get('entity_id')->target_id ?? 0);
      if ($targetId > 0) {
        return $targetId;
      }
    }

    return NULL;
  }

  /**
   * Builds the workspace redirect response and exposes the login token header.
   */
  private function buildWorkspaceRedirectResponse(string $slug, ?int $groupId = NULL, ?string $loginToken = NULL): TrustedRedirectResponse {
    $baseUrl = (string) $this->config('markaspot_fastmap.settings')->get('workspace_base_url');
    $redirectUrl = str_replace('{slug}', $slug, $baseUrl);
    if ($groupId !== NULL) {
      $redirectUrl = str_replace('{id}', (string) $groupId, $redirectUrl);
    }

    if ($loginToken) {
      $redirectUrl .= '#login_token=' . $loginToken;
    }

    $redirect = new TrustedRedirectResponse($redirectUrl);
    if ($loginToken) {
      $redirect->headers->set('X-Login-Token', $loginToken);
    }

    return $redirect;
  }

  /**
   * POST /api/fastmap/claim-login-token.
   *
   * Exchanges a one-time login token (issued during workspace verification)
   * for a Drupal session cookie. Single-use, 5-minute TTL.
   *
   * This endpoint is publicly accessible by design: security relies entirely
   * on the token's 256-bit entropy and single-use guarantee.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with authenticated user data on success.
   */
  public function claimLoginToken(Request $request): JsonResponse {
    // Flood control: max 10 attempts per IP per hour.
    $ip = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed('fastmap_claim_token', 10, 3600, $ip)) {
      return new JsonResponse(['error' => 'Too many attempts. Try again later.'], 429);
    }
    $this->flood->register('fastmap_claim_token', 3600, $ip);

    $data = json_decode($request->getContent(), TRUE);
    $token = trim($data['token'] ?? '');

    if (!$token || !preg_match('/^[0-9a-f]{64}$/', $token)) {
      return new JsonResponse(['error' => 'Invalid token format'], 400);
    }

    $store = $this->keyValueExpirable->get('markaspot_fastmap_login_tokens');
    $tokenData = $store->get($token);

    if (!$tokenData) {
      return new JsonResponse(['error' => 'Invalid or expired login token'], 401);
    }

    // Consume token immediately (single-use guarantee).
    $store->delete($token);

    $uid = (int) ($tokenData['uid'] ?? 0);
    if (!$uid) {
      return new JsonResponse(['error' => 'Token data corrupted'], 500);
    }

    $userStorage = $this->entityTypeManager()->getStorage('user');
    $user = $userStorage->load($uid);

    if (!$user || $user->isBlocked()) {
      return new JsonResponse(['error' => 'User account not available'], 403);
    }

    try {
      // Establish a full Drupal session for the user.
      // user_login_finalize() handles session migration, login hooks, and
      // the login timestamp in one canonical call.
      user_login_finalize($user);

      $this->fastmapLogger->info('Login token claimed for uid @uid (workspace @slug).', [
        '@uid' => $uid,
        '@slug' => $tokenData['slug'] ?? '?',
      ]);

      // Load membership data for the frontend auth state.
      $groups = [];
      if ($this->membershipLoader) {
        try {
          $memberships = $this->membershipLoader->loadByUser($user);
          foreach ($memberships as $membership) {
            $group = $membership->getGroup();
            $groupRoles = [];
            foreach ($membership->getRoles() as $role) {
              $groupRoles[] = ['id' => $role->id(), 'label' => $role->label()];
            }
            $groups[] = [
              'id' => $group->id(),
              'uuid' => $group->uuid(),
              'label' => $group->label(),
              'type' => $group->bundle(),
              'roles' => $groupRoles,
            ];
          }
        }
        catch (\Exception $e) {
          // Non-fatal: return user without group data.
          $this->fastmapLogger->warning('Could not load groups for login token claim: @msg', [
            '@msg' => $e->getMessage(),
          ]);
        }
      }

      return new JsonResponse([
        'success' => TRUE,
        'user' => [
          'uid' => $user->id(),
          'name' => $user->getAccountName(),
          'email' => $user->getEmail(),
          'roles' => $user->getRoles(),
          'groups' => $groups,
        ],
      ]);
    }
    catch (\Exception $e) {
      $this->fastmapLogger->error('Login token claim failed for uid @uid: @msg', [
        '@uid' => $uid,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Login failed'], 500);
    }
  }

  /**
   * Sends a verification email for workspace creation.
   */
  private function sendVerificationEmail(string $email, string $verifyUrl, string $name, string $slug, string $langcode, int $cleanupDays): bool {
    $config = $this->config('markaspot_fastmap.settings');
    $from = $config->get('mail_from') ?: NULL;
    $siteName = $this->config('system.site')->get('name') ?: 'FastMap';

    $params = [
      'workspace_name' => $name,
      'workspace_slug' => $slug,
      'verify_url' => $verifyUrl,
      'site_name' => $siteName,
      'cleanup_days' => (string) $cleanupDays,
    ];

    try {
      $result = $this->mailManager->mail(
        'markaspot_fastmap',
        'workspace_verification',
        $email,
        $langcode,
        $params,
        $from,
        TRUE
      );

      return $result['result'] ?? FALSE;
    }
    catch (\Exception $e) {
      $this->fastmapLogger->error('Failed to send verification email: @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Removes expired pending workspace requests.
   */
  private function cleanupExpired(): void {
    $config = $this->config('markaspot_fastmap.settings');
    $cleanupDays = (int) ($config->get('cleanup_days') ?? 7);
    $cutoff = time() - ($cleanupDays * 86400);

    $this->database->delete('markaspot_fastmap_pending')
      ->condition('created', $cutoff, '<')
      ->execute();
  }

  /**
   * Removes verified workspace records older than 24 hours.
   *
   * These records only exist to handle email-client prefetch scenarios
   * and have no long-term value.
   */
  private function cleanupVerified(): void {
    $cutoff = time() - 86400;

    $this->database->delete('markaspot_fastmap_verified')
      ->condition('created', $cutoff, '<')
      ->execute();
  }

}
